// Service canal Facebook : Pages des vendeurs et leur messagerie Messenger, par l'API officielle de Meta.
// - Messenger : catalogue (rayons, carrousels d'articles, photos), lien personnel de commande, livraison, vendeur ;
// - commentaires sous les publications : réponse privée avec l'article et son prix, réponse publique courte ;
// - publications et stories demandées par le vendeur (« bayam publier … facebook ») ;
// - connexion des Pages par le vendeur (Facebook Login), confirmations de commande.
// Sans état en mémoire (bases et Redis) : autant d'instances que nécessaire, chaque discussion traitée dans l'ordre.

import { createHash, createHmac, timingSafeEqual } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import type { JsMsg } from '@nats-io/jetstream';
import Fastify from 'fastify';
import { createClient, type RedisClientType } from 'redis';
import { adressePublique } from '../commun/adresse.ts';
import { creerPool, migrer } from '../commun/base.ts';
import { assurerFluxFacebook, connecterBus, consommer, FLUX_FACEBOOK, SUJET_FACEBOOK } from '../commun/bus.ts';
import { CommerceWordpress, type Boutique, type Produit } from '../commun/commerce.ts';
import { exiger, lire, nombre } from '../commun/config.ts';
import { journal, texteErreur } from '../commun/journal.ts';
import { ordonnanceur } from '../commun/ordre.ts';
import { PROMOS } from '../canal-whatsapp/catalogue.ts';
import { dessinerStory } from '../canal-whatsapp/vitrine.ts';
import { METADONNEE_BAYAM, type EvenementFacebook } from '../passerelle/facebook.ts';
import { cleDepuis } from './coffre.ts';
import { Donnees, type Page } from './donnees.ts';
import { ErreurGraph, Graph } from './graph.ts';
import * as messenger from './messenger.ts';
import { decider, type Decision } from './routeur.ts';

const log = journal('canal-facebook');
const jetonService = exiger('BAYAM_SERVICE_JETON');
const urlWordpress = lire('BAYAM_WP_INTERNE', 'http://wordpress');
const urlCanalWhatsapp = lire('BAYAM_CANAL_WHATSAPP', 'http://canal-whatsapp:8091');
// Sans application Meta (identifiant et secret), le service démarre mais ne fait rien : rien n'arrive de Facebook.
const appId = lire('FACEBOOK_APP_ID', '');
const secretApp = lire('FACEBOOK_APP_SECRET', '');
const configure = Boolean(appId && secretApp);
const graph = new Graph(lire('FACEBOOK_GRAPH_URL', 'https://graph.facebook.com/v27.0'), appId, secretApp);

const pool = creerPool(exiger('CANAL_FACEBOOK_BASE'));
const appliquees = await migrer(pool, 'canal_facebook', fileURLToPath(new URL('./migrations', import.meta.url)));
if (appliquees.length) {
  log.info('migrations appliquées', { appliquees });
}
const donnees = new Donnees(pool, cleDepuis(exiger('FACEBOOK_CLE')));
const redis: RedisClientType = createClient({ url: lire('REDIS_URL', 'redis://redis:6379') });
redis.on('error', (e: unknown) => log.erreur('Redis', { erreur: texteErreur(e) }));
await redis.connect();
const commerce = new CommerceWordpress(`${urlWordpress}/wp-json/bayam/v1/interne`, jetonService, redis);

const REPRISE_S = 2 * 3600; // le vendeur a répondu lui-même : le service se tait 2 h dans cette discussion
const ALERTE_S = 30 * 60; // un message libre du client prévient le vendeur au plus une fois par demi-heure

const empreinte = (texte: string) => createHash('sha1').update(texte).digest('hex').slice(0, 24);

/** Alerte au vendeur dans son WhatsApp (boîte d'envoi du canal WhatsApp), si son numéro est relié à BAYAM. */
async function alerteVendeur(b: Boutique, texte: string, cle: string): Promise<void> {
  if (!b.chat_vendeur) {
    return;
  }
  const r = await fetch(`${urlCanalWhatsapp}/interne/notifications`, {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'x-bayam-service': jetonService },
    body: JSON.stringify({ cle: `fb-${empreinte(cle)}`, session: `boutique-${b.id}`, chat: b.chat_vendeur, texte }),
    signal: AbortSignal.timeout(5000),
  }).catch((e: unknown) => {
    log.alerte('alerte au vendeur non déposée', { vendeur: b.id, erreur: texteErreur(e) });
    return null;
  });
  if (r && !r.ok) {
    log.alerte('alerte au vendeur refusée', { vendeur: b.id, statut: r.status });
  }
}

/** Erreur de Meta sur une Page : jeton révoqué -> Page désactivée et vendeur prévenu ; sinon l'erreur remonte. */
async function erreurDePage(e: unknown, p: Page, b: Boutique | null): Promise<never> {
  if (e instanceof ErreurGraph && e.jetonInvalide) {
    await donnees.desactiverPage(p.page);
    if (b) {
      await alerteVendeur(b, `⚠️ Facebook a déconnecté votre Page « ${p.nom} » de BAYAM (mot de passe changé ou accès retiré). Écrivez *bayam facebook* pour la reconnecter.`, `deconnexion-${p.page}-${Date.now()}`);
    }
    log.alerte('Page désactivée : jeton refusé par Meta', { page: p.page });
  }
  throw e;
}

async function envoyer(p: Page, b: Boutique, client: string, envois: messenger.Envoi[], cle: string): Promise<void> {
  for (const [i, e] of envois.entries()) {
    if (e.type === 'au_vendeur') {
      await alerteVendeur(b, e.texte, `${cle}-${i}`);
      continue;
    }
    await graph.envoyer(p.page, p.jeton, { id: client }, { ...e.message, metadata: METADONNEE_BAYAM }).catch((x: unknown) => erreurDePage(x, p, b));
  }
}

async function produitsDe(vendeur: number, ids: number[]): Promise<Produit[]> {
  return (await Promise.all(ids.map((id) => commerce.produit(vendeur, id)))).filter((p): p is Produit => p !== null);
}

/** Choix ou message d'un client dans Messenger. */
async function pourClient(d: Extract<Decision, { route: 'client' }>, evt: string): Promise<void> {
  const p = await donnees.page(d.page);
  const b = p ? await commerce.boutique(p.vendeur) : null;
  if (!p || !b) {
    return; // Page inconnue ou boutique fermée
  }
  // Message tapé : rien si le vendeur a désactivé les réponses automatiques ou vient de répondre lui-même.
  if (d.libre && (!b.actif || (await redis.exists(`fb:reprise:${d.page}:${d.client}`)))) {
    return;
  }
  const base = (await adressePublique()) || b.base_publique;
  const a = d.action;
  let envois: messenger.Envoi[];
  switch (a.type) {
    case 'menu':
      envois = messenger.menu(b);
      break;
    case 'rayon': {
      const rayon = a.rayon === 0
        ? { id: 0, nom: PROMOS, nombre: b.promos }
        : (b.rayons.find((r) => r.id === a.rayon) ?? { id: a.rayon, nom: 'Ce rayon', nombre: 0 });
      envois = messenger.pageRayon(rayon, await commerce.rayon(b.id, a.rayon, a.page, messenger.PAR_PAGE), a.page, base);
      break;
    }
    case 'produit': {
      const produit = await commerce.produit(b.id, a.produit);
      const jeton = produit?.en_stock ? await donnees.creerJeton({ vendeur: b.id, produit: produit.id, page: p.page, client: d.client }) : null;
      envois = messenger.produit(b, produit, jeton ? `${base}/commander/${jeton}` : null, base);
      break;
    }
    case 'photos':
      envois = messenger.photos(await commerce.produit(b.id, a.produit), base);
      break;
    case 'publication': {
      const pub = await donnees.publication(a.publication);
      envois = messenger.articlesPublication(pub && pub.page === p.page ? await produitsDe(b.id, pub.produits) : [], base);
      break;
    }
    case 'commandes':
      // Messenger ne donne pas le numéro du client : ses commandes se suivent dans la discussion où elles sont confirmées.
      envois = messenger.vendeur(b, p.nom);
      break;
    case 'livraison':
      envois = messenger.livraison(b);
      break;
    case 'vendeur':
      envois = messenger.vendeur(b, p.nom);
      break;
    case 'question': {
      // Plusieurs messages à la suite : un seul accusé de réception et une seule alerte par demi-heure.
      const premier = await redis.set(`fb:alerte:${d.page}:${d.client}`, '1', { condition: 'NX', expiration: { type: 'EX', value: ALERTE_S } });
      envois = premier ? messenger.question(b, p.nom, a.extrait) : [];
      break;
    }
  }
  await envoyer(p, b, d.client, envois, evt);
  log.info('messenger', { page: d.page, action: a.type, envois: envois.length });
}

/** Commentaire d'un acheteur intéressé : réponse privée (l'article, son prix, des boutons) puis réponse publique. */
async function pourCommentaire(d: Extract<Decision, { route: 'commentaire' }>): Promise<void> {
  const p = await donnees.page(d.page);
  const b = p ? await commerce.boutique(p.vendeur) : null;
  const c = d.commentaire;
  const auteur = String(c.from?.id ?? '');
  const deja = `fb:commentaire:${c.post_id}:${auteur}`;
  if (!p || !b || !b.actif || (await redis.exists(deja))) {
    return; // une seule réponse par personne et par publication en 24 h
  }
  const pub = await donnees.publicationDuPost(String(c.post_id));
  const produits = pub ? await produitsDe(b.id, pub.produits) : [];
  const designe = messenger.articleDesigne(c.message ?? '', produits);
  const nom = String(c.from?.name ?? '');
  try {
    await graph.envoyer(p.page, p.jeton, { comment_id: String(c.comment_id) }, { ...messenger.reponsePrivee(b, nom, produits, designe, pub?.id ?? null), metadata: METADONNEE_BAYAM });
  } catch (e) {
    if (e instanceof ErreurGraph && e.definitive && !e.jetonInvalide) {
      // Déjà répondu, commentaire supprimé ou trop ancien : pas de réponse publique qui annoncerait un message absent.
      log.alerte('réponse privée refusée par Meta', { page: p.page, code: e.code });
      return;
    }
    await erreurDePage(e, p, b);
  }
  await redis.set(deja, '1', { expiration: { type: 'EX', value: 86_400 } });
  await graph.repondreCommentaire(String(c.comment_id), p.jeton, messenger.reponsePublique(nom, String(c.comment_id)))
    .catch((e: unknown) => log.alerte('réponse publique impossible', { page: p.page, erreur: texteErreur(e) }));
  log.info('commentaire', { page: p.page, article: designe?.id ?? null, publication: pub?.id ?? null });
}

async function traiter(msg: JsMsg): Promise<void> {
  let e: EvenementFacebook;
  try {
    e = JSON.parse(new TextDecoder().decode(msg.data)) as EvenementFacebook;
  } catch {
    log.alerte('événement illisible écarté', { sujet: msg.subject, seq: msg.seq });
    msg.term();
    return;
  }
  const d = decider(e, Math.floor(Date.now() / 1000));
  if (d.route === 'ignore' || !configure || (await donnees.dejaTraite(e.id))) {
    msg.ack();
    return;
  }
  try {
    if (d.route === 'reprise') {
      await redis.set(`fb:reprise:${d.page}:${d.client}`, '1', { expiration: { type: 'EX', value: REPRISE_S } });
    } else if (d.route === 'commentaire') {
      await pourCommentaire(d);
    } else {
      await pourClient(d, e.id);
    }
  } catch (x) {
    if (!(x instanceof ErreurGraph && x.definitive)) {
      throw x; // passagère : le bus redistribue plus tard
    }
    // Définitive (client hors de la fenêtre de 24 h, permission manquante…) : réessayer ne servirait à rien.
    log.alerte('envoi refusé par Meta', { page: e.page, code: x.code, statut: x.statut });
  }
  await donnees.marquerTraite(e.id);
  msg.ack();
}

/** Discussion d'un événement : un client d'une Page, ou une personne qui commente une publication. */
function cleDiscussion(msg: JsMsg): string {
  try {
    const e = JSON.parse(new TextDecoder().decode(msg.data)) as EvenementFacebook;
    if (e.type === 'commentaire') {
      return `${e.page}|${e.donnees.post_id}|${e.donnees.from?.id}`;
    }
    return `${e.page}|${e.donnees.message?.is_echo ? e.donnees.recipient?.id : e.donnees.sender?.id}`;
  } catch {
    return `seq|${msg.seq}`;
  }
}

// --- Connexion des Pages ---------------------------------------------------------------------------------------

/** Lien de connexion signé par WordPress (jeton de service), valable jusqu'à son échéance. */
function etatValide(v: string, e: string, s: string): number | null {
  if (!/^\d{1,10}$/.test(v) || !/^\d{1,12}$/.test(e) || Number(e) < Date.now() / 1000) {
    return null;
  }
  const attendue = createHmac('sha256', jetonService).update(`facebook:${v}.${e}`).digest('hex');
  return attendue.length === s.length && timingSafeEqual(Buffer.from(attendue), Buffer.from(s)) ? Number(v) : null;
}

/** Pages de l'utilisateur Facebook rattachées au vendeur : enregistrées, abonnées, accueil Messenger réglé. */
async function connecterPages(vendeur: number, jetonUtilisateur: string): Promise<string[]> {
  const b = await commerce.boutique(vendeur);
  if (!b) {
    throw new Error('boutique inconnue');
  }
  const pages = (await graph.pages(jetonUtilisateur)).filter((p) => !p.tasks || p.tasks.includes('MESSAGING'));
  for (const p of pages) {
    await donnees.enregistrerPage({ page: p.id, vendeur, nom: p.name, jeton: p.access_token });
    await graph.abonner(p.id, p.access_token);
    await graph.accueil(p.access_token, messenger.salutation(b));
  }
  if (pages.length) {
    const noms = pages.map((p) => `« ${p.name} »`).join(', ');
    await alerteVendeur(b, `✅ *Facebook relié à BAYAM* : ${noms}.\nVos clients ont maintenant le catalogue dans Messenger, une réponse privée à chaque commentaire « prix ? », et vous pouvez publier : *bayam publier robes facebook*.`, `connexion-${vendeur}-${Date.now()}`);
  }
  log.info('Pages connectées', { vendeur, pages: pages.length });
  return pages.map((p) => p.name);
}

const echapper = (t: string) => t.replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
function pageHtml(titre: string, texte: string): string {
  return `<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${echapper(titre)}</title>
<style>body{margin:0;font-family:system-ui,sans-serif;background:#F7FAF9;color:#0B2545;display:grid;place-items:center;min-height:100vh}main{max-width:420px;margin:24px;padding:32px;background:#fff;border-radius:24px;box-shadow:0 8px 32px rgba(11,37,69,.1)}h1{font-size:1.4rem;color:#0A7A55}p{line-height:1.5}</style></head>
<body><main><h1>${echapper(titre)}</h1><p>${echapper(texte)}</p></main></body></html>`;
}

// --- Files : publications, confirmations ------------------------------------------------------------------------

/** Publie ce que le vendeur a demandé : une photo, un album (plusieurs articles) ou des stories. */
async function publier(l: { id: string; vendeur: number; page: string; type: 'publication' | 'story'; produits: number[] }): Promise<Record<string, unknown>> {
  const p = await donnees.page(l.page);
  const b = await commerce.boutique(l.vendeur);
  if (!p || !b) {
    throw Object.assign(new Error('Page déconnectée ou boutique inconnue'), { definitive: true });
  }
  const produits = (await produitsDe(l.vendeur, l.produits)).filter((x) => x.en_stock && x.photo).slice(0, 10);
  if (!produits.length) {
    throw Object.assign(new Error('aucun article en stock avec photo'), { definitive: true });
  }
  const images = await Promise.all(produits.map((x) => commerce.image(x.photo)));
  let publication: string;
  try {
    if (l.type === 'story') {
      // Une story par article (visible 24 h), visuel vertical avec le prix ; photo jamais publiée avant (règle de Meta).
      const ids: string[] = [];
      for (const [i, x] of produits.entries()) {
        const visuel = await dessinerStory({ boutique: b.nom, nom: x.nom, ...messenger.detailsStory(x), options: x.options, appel: messenger.APPEL_STORY, image: images[i] });
        ids.push(await graph.story(p.page, p.jeton, (await graph.photo(p.page, p.jeton, visuel, '', false)).id));
      }
      publication = ids[0];
    } else if (produits.length === 1) {
      if (!images[0]) {
        throw new Error('photo de l’article indisponible sur le site'); // passagère : nouvel essai plus tard
      }
      const r = await graph.photo(p.page, p.jeton, images[0], messenger.textePublication(b, produits, p.page), true);
      publication = r.post_id ?? `${p.page}_${r.id}`;
    } else {
      const photos: string[] = [];
      for (const image of images) {
        if (image) {
          photos.push((await graph.photo(p.page, p.jeton, image, '', false)).id);
        }
      }
      if (!photos.length) {
        throw new Error('photos des articles indisponibles sur le site');
      }
      publication = await graph.album(p.page, p.jeton, messenger.textePublication(b, produits, p.page), photos);
    }
  } catch (e) {
    return erreurDePage(e, p, b);
  }
  await alerteVendeur(b, l.type === 'story'
    ? `✅ ${produits.length} story(s) publiée(s) sur votre Page « ${p.nom} », visibles 24 h.`
    : `✅ Publié sur votre Page « ${p.nom} » : https://www.facebook.com/${publication}\nChaque commentaire « prix ? » reçoit une réponse privée avec l'article.`, `publication-${l.id}`);
  return { statut: 'publie', publication, publie: new Date() };
}

/** Confirmation de commande déposée par WordPress, envoyée dans la discussion Messenger du client. */
async function confirmer(n: { id: string; page: string; client: string; texte: string }): Promise<Record<string, unknown>> {
  const p = await donnees.page(n.page);
  if (!p) {
    throw Object.assign(new Error('Page déconnectée'), { definitive: true });
  }
  // Messenger n'affiche pas le gras de WhatsApp (*…*) : on le retire.
  await graph.envoyer(p.page, p.jeton, { id: n.client }, { text: n.texte.replace(/\*([^*\n]+)\*/g, '$1').slice(0, 2000), metadata: METADONNEE_BAYAM });
  return { statut: 'envoye', envoye: new Date(), texte: '' };
}

// --- Démarrage ---------------------------------------------------------------------------------------------------

const dansLOrdre = ordonnanceur(redis, log, 'canal-facebook');
let enCours = 0;
const MAX_EN_COURS = nombre('CANAL_PARALLELISME', 32);

const nc = await connecterBus(lire('NATS_URL', 'nats://nats:4222'), 'canal-facebook');
await assurerFluxFacebook(nc, nombre('NATS_REPLIQUES', 1));
const flot = await consommer(nc, FLUX_FACEBOOK, 'canal-facebook', SUJET_FACEBOOK);

const app = Fastify({ bodyLimit: 20_000 });
const autorise = (valeur: unknown) => {
  const a = Buffer.from(String(valeur ?? ''));
  const b = Buffer.from(jetonService);
  return a.length === b.length && timingSafeEqual(a, b);
};
app.addHook('onRequest', async (req, reply) => {
  if (req.url.startsWith('/interne/') && !autorise(req.headers['x-bayam-service'])) {
    return reply.code(401).send({ erreur: 'jeton de service invalide' });
  }
});
app.get('/sante', async (_req, reply) => {
  const bus = !nc.isClosed();
  return reply.code(bus && redis.isReady ? 200 : 503).send({ bus, redis: redis.isReady, configure, enCours });
});

// Connexion d'une Page : lien signé (WordPress) -> fenêtre Facebook -> retour avec un code -> Pages enregistrées.
app.get<{ Querystring: { v?: string; e?: string; s?: string } }>('/facebook/connexion', async (req, reply) => {
  const { v = '', e = '', s = '' } = req.query;
  const base = await adressePublique();
  if (!configure || !base) {
    return reply.code(503).type('text/html').send(pageHtml('Bientôt disponible', 'La connexion Facebook n\'est pas encore ouverte sur BAYAM. Réessayez plus tard.'));
  }
  if (etatValide(v, e, s) === null) {
    return reply.code(403).type('text/html').send(pageHtml('Lien expiré', 'Ce lien de connexion a expiré. Écrivez « bayam facebook » sur WhatsApp pour en recevoir un nouveau.'));
  }
  return reply.redirect(graph.urlConnexion(`${base}/facebook/retour`, `${v}.${e}.${s}`));
});
app.get<{ Querystring: { code?: string; state?: string; error?: string } }>('/facebook/retour', async (req, reply) => {
  const [v = '', e = '', s = ''] = String(req.query.state ?? '').split('.');
  const vendeur = etatValide(v, e, s);
  if (vendeur === null || !req.query.code) {
    return reply.code(req.query.error ? 200 : 403).type('text/html').send(pageHtml('Connexion annulée', 'Aucune Page n\'a été reliée. Écrivez « bayam facebook » sur WhatsApp pour recommencer.'));
  }
  try {
    const noms = await connecterPages(vendeur, await graph.jetonUtilisateur(req.query.code, `${await adressePublique()}/facebook/retour`));
    return reply.type('text/html').send(noms.length
      ? pageHtml('Page reliée à BAYAM ✅', `${noms.join(', ')} : vos clients ont maintenant votre catalogue dans Messenger. Vous pouvez fermer cette page.`)
      : pageHtml('Aucune Page choisie', 'Relancez la connexion et cochez la Page de votre boutique (il faut en être administrateur).'));
  } catch (x) {
    log.erreur('connexion Facebook impossible', { vendeur, erreur: texteErreur(x) });
    return reply.code(502).type('text/html').send(pageHtml('Connexion impossible', 'Facebook n\'a pas répondu comme prévu. Réessayez dans quelques minutes.'));
  }
});

app.get<{ Params: { id: string } }>('/interne/vendeurs/:id/pages', async (req) => donnees.pagesDuVendeur(Number(req.params.id)));
// Connexion par un jeton utilisateur collé à la main (essais, assistance) : même suite que la fenêtre Facebook.
app.post<{ Params: { id: string }; Body: { jeton?: string } }>('/interne/vendeurs/:id/pages', async (req, reply) => {
  if (!configure || !req.body?.jeton) {
    return reply.code(400).send({ erreur: configure ? 'jeton manquant' : 'Facebook non configuré' });
  }
  return { pages: await connecterPages(Number(req.params.id), await graph.jetonLongue(req.body.jeton)) };
});
app.post<{ Body: { vendeur?: number; produits?: number[]; type?: string } }>('/interne/publications', async (req, reply) => {
  const { vendeur, produits, type = 'publication' } = req.body ?? {};
  if (!Number.isInteger(vendeur) || !Array.isArray(produits) || !produits.length || produits.length > 10 || !produits.every(Number.isInteger) || !['publication', 'story'].includes(type)) {
    return reply.code(400).send({ erreur: 'demande invalide' });
  }
  const pages = (await donnees.pagesDuVendeur(Number(vendeur))).filter((p) => p.actif).map((p) => p.page);
  await donnees.demanderPublications(Number(vendeur), pages, type as 'publication' | 'story', produits);
  return reply.code(202).send({ pages: pages.length });
});
app.get<{ Params: { jeton: string } }>('/interne/jetons/:jeton', async (req, reply) => {
  const j = await donnees.ouvrirJeton(req.params.jeton);
  return j ? reply.send(j) : reply.code(404).send({ erreur: 'lien expiré ou inconnu' });
});
app.post<{ Body: { cle?: string; page?: string; client?: string; texte?: string } }>('/interne/notifications', async (req, reply) => {
  const n = req.body ?? {};
  if (!/^[\w:-]{1,100}$/.test(n.cle ?? '') || !/^\d{1,20}$/.test(n.page ?? '') || !/^\d{1,30}$/.test(n.client ?? '') || !n.texte || n.texte.length > 4000) {
    return reply.code(400).send({ erreur: 'notification invalide' });
  }
  await donnees.deposerNotification({ cle: n.cle!, page: n.page!, client: n.client!, texte: n.texte });
  return reply.code(202).send({ ok: true });
});
await app.listen({ host: '0.0.0.0', port: nombre('PORT', 8092) });
log.info('à l\'écoute', { port: nombre('PORT', 8092), configure, parallelisme: MAX_EN_COURS });

const menage = setInterval(() => void donnees.nettoyer().catch((e: unknown) => log.erreur('ménage', { erreur: texteErreur(e) })), 3_600_000);
let filesOccupees = false;
const files = setInterval(() => {
  if (filesOccupees || arret || !configure) {
    return;
  }
  filesOccupees = true;
  const echec = (nature: string) => (l: { id: string }, essais: number, definitif: boolean, e: unknown) =>
    log[definitif ? 'erreur' : 'alerte'](`${nature} en échec`, { id: l.id, essais, definitif, erreur: texteErreur(e) });
  void Promise.all([
    donnees.file('publications', 'vendeur, page, type, produits', publier, 6, echec('publication')),
    donnees.file('notifications', 'page, client, texte', confirmer, 8, echec('confirmation')),
  ])
    .catch((e: unknown) => log.erreur('files', { erreur: texteErreur(e) }))
    .finally(() => {
      filesOccupees = false;
    });
}, 2000);

let arret = false;
for (const signal of ['SIGTERM', 'SIGINT'] as const) {
  process.once(signal, async () => {
    arret = true;
    clearInterval(menage);
    clearInterval(files);
    await flot.close();
    while (enCours > 0) {
      await new Promise((ok) => setTimeout(ok, 100));
    }
    await app.close();
    await nc.drain();
    await Promise.all([pool.end(), redis.quit()]);
    process.exit(0);
  });
}

for await (const msg of flot) {
  if (arret) {
    msg.nak();
    continue;
  }
  while (enCours >= MAX_EN_COURS) {
    await new Promise((ok) => setTimeout(ok, 20));
  }
  enCours++;
  void dansLOrdre(cleDiscussion(msg), msg, traiter).finally(() => enCours--);
}
