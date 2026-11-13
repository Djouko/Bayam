// Service canal Facebook : Pages des vendeurs et leur messagerie Messenger, par l'API officielle de Meta.
// - Messenger : catalogue (rayons, carrousels d'articles, photos), lien personnel de commande, livraison, vendeur ;
// - commentaires sous les publications : réponse privée avec l'article et son prix, réponse publique courte ;
// - publications et stories demandées par le vendeur (« bayam publier … facebook ») ;
// - connexion des Pages par le vendeur (Facebook Login), confirmations de commande.
// Sans état en mémoire (bases et Redis) : autant d'instances que nécessaire, chaque discussion traitée dans l'ordre.

import { createHash, createHmac, randomBytes, timingSafeEqual } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import type { JsMsg } from '@nats-io/jetstream';
import Fastify from 'fastify';
import { createClient, type RedisClientType } from 'redis';
import { adressePublique } from '../commun/adresse.ts';
import { creerPool, migrer } from '../commun/base.ts';
import { assurerFluxFacebook, connecterBus, consommer, FLUX_FACEBOOK, SUJET_FACEBOOK } from '../commun/bus.ts';
import { CommerceWordpress, type Boutique, type Produit, type ReponseCopilote } from '../commun/commerce.ts';
import { exiger, lire, nombre } from '../commun/config.ts';
import { Fonctions, messageFonction } from '../commun/fonctions.ts';
import { journal, texteErreur } from '../commun/journal.ts';
import { ordonnanceur } from '../commun/ordre.ts';
import { appel } from '../canal-whatsapp/appel.ts';
import { PROMOS, type EnvoiPret } from '../canal-whatsapp/catalogue.ts';
import * as menuVendeur from '../canal-whatsapp/menu-vendeur.ts';
import { dessinerStory } from '../canal-whatsapp/vitrine.ts';
import { MARQUE } from '../passerelle/filtre.ts';
import { METADONNEE_BAYAM, type EvenementFacebook } from '../passerelle/facebook.ts';
import { chiffrer, cleDepuis, dechiffrer } from './coffre.ts';
import { Donnees, type Page } from './donnees.ts';
import { ABONNEMENTS, ErreurGraph, Graph } from './graph.ts';
import { ClientRetours } from '../commun/retours.ts';
import * as messenger from './messenger.ts';
import { decider, lireActionFacebook, type Decision } from './routeur.ts';

const log = journal('canal-facebook');
const jetonService = exiger('BAYAM_SERVICE_JETON');
const urlWordpress = lire('BAYAM_WP_INTERNE', 'http://wordpress');
const urlCanalWhatsapp = lire('BAYAM_CANAL_WHATSAPP', 'http://canal-whatsapp:8091');
// Sans application Meta (identifiant et secret), le service démarre mais ne fait rien : rien n'arrive de Facebook.
const appId = lire('FACEBOOK_APP_ID', '');
const secretApp = lire('FACEBOOK_APP_SECRET', '');
const configure = Boolean(appId && secretApp);
const jetonVerification = lire('FACEBOOK_JETON_VERIFICATION', '');
// Clé des liens signés (connexion de Page, espace vendeur), distincte du jeton de l'API interne.
const cleLiens = exiger('BAYAM_CLE_LIENS');
const COOKIE_ETAT = 'bayam_fb_etat';
const HOTES_MEDIAS_META = ['fbcdn.net', 'fbsbx.com', 'facebook.com'];
const PHOTO_MAX = 8 * 1024 * 1024;
const graph = new Graph(lire('FACEBOOK_GRAPH_URL', 'https://graph.facebook.com/v27.0'), appId, secretApp, lire('FACEBOOK_CONFIG_ID', ''));

const pool = creerPool(exiger('CANAL_FACEBOOK_BASE'));
const appliquees = await migrer(pool, 'canal_facebook', fileURLToPath(new URL('./migrations', import.meta.url)));
if (appliquees.length) {
  log.info('migrations appliquées', { appliquees });
}
const cleCoffre = cleDepuis(exiger('FACEBOOK_CLE'));
const donnees = new Donnees(pool, cleCoffre);
const redis: RedisClientType = createClient({ url: lire('REDIS_URL', 'redis://redis:6379') });
redis.on('error', (e: unknown) => log.erreur('Redis', { erreur: texteErreur(e) }));
await redis.connect();
const fonctions = new Fonctions(redis);
const commerce = new CommerceWordpress(`${urlWordpress}/wp-json/bayam/v1/interne`, jetonService, redis);
const retours = new ClientRetours(lire('BAYAM_RETOURS', 'http://retours:8093'), jetonService);

const REPRISE_S = 2 * 3600; // le vendeur a répondu lui-même : le service se tait 2 h dans cette discussion
const RETOUR_S = 3 * 86_400; // client qui revient après 3 jours sans écrire : il retrouve le menu de la boutique
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

/** Dernière liste numérotée envoyée à ce client : sa réponse « 2 » la désigne (24 h, comme la fenêtre de Meta). */
async function retenirChoix(page: string, client: string, ids: string[]): Promise<void> {
  await redis.set(`fb:choix:${page}:${client}`, JSON.stringify(ids), { expiration: { type: 'EX', value: 86_400 } });
}

async function envoyer(p: Page, b: Boutique, client: string, envois: messenger.Envoi[], cle: string): Promise<void> {
  for (const [i, e] of envois.entries()) {
    if (e.type === 'au_vendeur') {
      await alerteVendeur(b, e.texte, `${cle}-${i}`);
      continue;
    }
    if (e.type === 'choix') {
      await retenirChoix(p.page, client, e.ids);
      continue;
    }
    if (e.type === 'fichier') {
      await graph.envoyerImage(p.page, p.jeton, { id: client }, Buffer.from(e.donnees, 'base64'), e.mimetype, METADONNEE_BAYAM).catch((x: unknown) => erreurDePage(x, p, b));
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
  // Messenger en maintenance : un message clair, une fois par heure et par client ; signaler un souci reste possible.
  const etat = await fonctions.etat('facebook.messenger', b.id);
  if (etat.etat !== 'actif' && d.action.type !== 'signaler') {
    if (await redis.set(`fb:maintenance:${d.page}:${d.client}`, '1', { condition: 'NX', expiration: { type: 'EX', value: 3600 } })) {
      await envoyer(p, b, d.client, [messenger.texteSimple(messageFonction(etat))], evt);
    }
    return;
  }
  // Dernière venue du client : premier message, ou retour après plusieurs jours, lui montre la boutique.
  const vu = await redis.getSet(`fb:vu:${d.page}:${d.client}`, String(Math.floor(Date.now() / 1000)));
  await redis.expire(`fb:vu:${d.page}:${d.client}`, 60 * 86_400);
  const nouveau = !vu || Date.now() / 1000 - Number(vu) > RETOUR_S;
  const base = (await adressePublique()) || b.base_publique;
  let a = d.action;
  if (a.type === 'numero') {
    const ids = JSON.parse((await redis.get(`fb:choix:${d.page}:${d.client}`)) ?? '[]') as string[];
    const choisie = ids[a.numero - 1] ? lireActionFacebook(ids[a.numero - 1]) : null;
    // Numéro sans liste en mémoire (trop ancienne, ou un chiffre dans la conversation) : un message comme un autre.
    a = choisie ?? { type: 'question', extrait: String(a.numero) };
  }
  let envois: messenger.Envoi[];
  switch (a.type) {
    case 'menu':
      envois = messenger.menu(b, base);
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
      const banal = messenger.banal(a.extrait);
      if (nouveau) {
        // Premier message ou retour après plusieurs jours : la boutique, et le vendeur prévenu s'il y a une vraie question.
        envois = [...messenger.menu(b, base), ...(banal ? [] : [messenger.alerteQuestion(p.nom, a.extrait)])];
        break;
      }
      // Conversation en cours : « merci », « ok » n'appellent rien ; une vraie question prévient le vendeur, avec un
      // seul accusé de réception et une seule alerte par demi-heure.
      const premier = !banal && (await redis.set(`fb:alerte:${d.page}:${d.client}`, '1', { condition: 'NX', expiration: { type: 'EX', value: ALERTE_S } }));
      envois = premier ? [messenger.accuse(b), messenger.alerteQuestion(p.nom, a.extrait)] : [];
      break;
    }
    case 'numero':
      envois = [];
      break;
    case 'aide':
      envois = messenger.aide(b);
      break;
    case 'signaler':
      envois = [messenger.texteSimple(await retours.signaler({
        type: a.genre, texte: a.texte || '(sans détail)', canal: 'messenger', auteur: 'client Messenger', identifiant: `${d.page}:${d.client}`, vendeur: b.id,
        contact: { canal: 'messenger', page: d.page, client: d.client },
      }))];
      break;
  }
  await envoyer(p, b, d.client, envois, evt);
  log.info('messenger', { page: d.page, action: a.type, envois: envois.length });
}

/** Commentaire d'un acheteur intéressé : réponse privée (l'article, son prix, des boutons) puis réponse publique. */
async function pourCommentaire(d: Extract<Decision, { route: 'commentaire' }>): Promise<void> {
  const p = await donnees.page(d.page);
  const b = p ? await commerce.boutique(p.vendeur) : null;
  const c = d.commentaire;
  // Auteur inconnu (Meta le cache parfois) : chaque commentaire compte alors pour lui-même.
  const auteur = String(c.from?.id ?? `commentaire-${c.comment_id}`);
  const deja = `fb:commentaire:${c.post_id}:${auteur}`;
  if (!p || !b || !b.actif || (await redis.exists(deja))) {
    return; // une seule réponse par personne et par publication en 24 h
  }
  const pub = await donnees.publicationDuPost(String(c.post_id));
  const produits = pub ? await produitsDe(b.id, pub.produits) : [];
  const designe = messenger.articleDesigne(c.message ?? '', produits);
  const nom = String(c.from?.name ?? '');
  try {
    const reponse = messenger.reponsePrivee(b, nom, produits, designe);
    const envoi = await graph.envoyer(p.page, p.jeton, { comment_id: String(c.comment_id) }, { ...reponse.message, metadata: METADONNEE_BAYAM });
    if (envoi.recipient_id) {
      await retenirChoix(p.page, envoi.recipient_id, reponse.choix); // sa réponse « 1 » suit la liste de la réponse privée
    }
  } catch (e) {
    if (e instanceof ErreurGraph && e.definitive && !e.jetonInvalide) {
      // Déjà répondu, commentaire supprimé ou trop ancien : pas de réponse publique qui annoncerait un message absent.
      log.alerte('réponse privée refusée par Meta', { page: p.page, code: e.code });
      return;
    }
    await erreurDePage(e, p, b);
  }
  await redis.set(deja, '1', { expiration: { type: 'EX', value: 86_400 } });
  if (!p.permissions.includes('pages_manage_engagement')) {
    log.info('commentaire', { page: p.page, article: designe?.id ?? null, publique: 'autorisation absente' });
    return; // réponse privée envoyée ; la réponse publique attend que le vendeur accorde l'autorisation
  }
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
    } else if (d.route === 'liaison') {
      await lierVendeur(d);
    } else if (d.route === 'commentaire') {
      if ((await fonctions.etat('facebook.commentaires')).etat !== 'actif') {
        msg.nak(300_000); // réponses aux commentaires en maintenance : le commentaire attend la réouverture (7 jours permis)
        return;
      }
      await pourCommentaire(d);
    } else {
      const vendeur = await donnees.vendeurDe(d.page, d.client);
      await (vendeur ? pourVendeur(d, vendeur, e.id) : pourClient(d, e.id));
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

// --- Espace vendeur dans Messenger : le même menu et les mêmes commandes que sur WhatsApp ---------------------------

const signatureEspace = (vendeur: number, echeance: number) => createHmac('sha256', cleLiens).update(`fbvendeur:${vendeur}.${echeance}`).digest('hex').slice(0, 32);

/** Lien (24 h, un seul compte) qui fait du compte Messenger qui l'ouvre l'espace vendeur sur cette Page. */
function lienEspace(vendeur: number, page: string): string {
  const echeance = Math.floor(Date.now() / 1000) + 86_400;
  return `https://m.me/${page}?ref=vendeur-${vendeur}-${echeance}-${signatureEspace(vendeur, echeance)}`;
}

async function lierVendeur(d: Extract<Decision, { route: 'liaison' }>): Promise<void> {
  const p = await donnees.page(d.page);
  const b = p ? await commerce.boutique(p.vendeur) : null;
  const m = /^vendeur-(\d{1,10})-(\d{1,12})-([0-9a-f]{32})$/.exec(d.ref);
  if (!p || !b) {
    return;
  }
  const attendue = m ? signatureEspace(Number(m[1]), Number(m[2])) : '';
  const valide = m && Number(m[1]) === p.vendeur && Number(m[2]) > Date.now() / 1000 && timingSafeEqual(Buffer.from(attendue), Buffer.from(m[3]));
  // Usage unique : le premier compte qui l'ouvre devient l'espace vendeur ; un lien transféré ou copié ne sert plus.
  const utilisation = `${p.page}:${d.client}`;
  const premier = valide && (await redis.set(`fb:espace:${m[3]}`, utilisation, { condition: 'NX', expiration: { type: 'EX', value: 2 * 86_400 } })) === 'OK';
  if (!valide || (!premier && (await redis.get(`fb:espace:${m[3]}`)) !== utilisation)) {
    await envoyer(p, b, d.client, [messenger.texteSimple('Ce lien d\'espace vendeur a expiré ou n\'est pas le vôtre. Le propriétaire de la boutique en reçoit un nouveau avec « bayam facebook ».')], d.ref);
    return;
  }
  await donnees.lierVendeur(p.page, d.client, p.vendeur);
  log.info('espace vendeur relié', { page: p.page, vendeur: p.vendeur });
  await envoyer(p, b, d.client, [messenger.texteSimple(`✅ Votre espace vendeur BAYAM est prêt ici, pour ${b.nom}.\nÉcrivez « bayam » pour votre menu (stock, ventes, publications, bilan…), ou « bayam aide » pour toutes les commandes. Vos clients voient toujours la boutique normalement.`)], d.ref);
}

/** Messages du menu (écrits pour WhatsApp) et réponses du Copilote, mis en forme pour Messenger. */
function pourMessenger(envois: EnvoiPret[], reponses: ReponseCopilote[]): messenger.Envoi[] {
  const propre = (t: string) => t.replaceAll(MARQUE, '').replace(/\*([^*\n]+)\*/g, '$1').replace('Touchez Choisir, ou répondez par le numéro.', 'Répondez par le numéro.');
  const sortie: messenger.Envoi[] = [];
  for (const e of envois) {
    if (e.type === 'texte') {
      sortie.push(messenger.texteSimple(propre(e.texte)));
    } else if (e.type === 'liste') {
      const lignes = e.sections.flatMap((s) => s.lignes.map((l) => `${l.titre}${l.description ? ` (${l.description})` : ''}`));
      sortie.push(messenger.texteSimple(propre(`${e.titre}\n${e.texte}\n\n${lignes.join('\n')}`)));
    } else if (e.type === 'image') {
      sortie.push({ type: 'fichier', donnees: e.donnees, mimetype: 'image/jpeg' }, ...(e.legende.replaceAll(MARQUE, '').trim() ? [messenger.texteSimple(propre(e.legende))] : []));
    }
  }
  for (const r of reponses) {
    if (r.image) {
      sortie.push({ type: 'fichier', donnees: r.image, mimetype: r.type || 'image/jpeg' }, ...(r.legende?.trim() ? [messenger.texteSimple(propre(r.legende))] : []));
    } else if (r.texte) {
      sortie.push(messenger.texteSimple(propre(r.texte)));
    }
  }
  return sortie;
}

/**
 * Photo envoyée par le vendeur dans Messenger (nouvel article), téléchargée pour WordPress : seulement depuis les
 * serveurs de fichiers de Meta, sans redirection, lecture arrêtée au-delà de 8 Mo (jamais une adresse interne).
 */
async function photoMessenger(url: string): Promise<string> {
  const adresse = new URL(url);
  if (adresse.protocol !== 'https:' || !HOTES_MEDIAS_META.some((h) => adresse.hostname === h || adresse.hostname.endsWith(`.${h}`))) {
    throw new Error(`photo Messenger refusée (hôte ${adresse.hostname})`);
  }
  const r = await fetch(adresse, { redirect: 'error', signal: AbortSignal.timeout(20_000) });
  if (!r.ok || !r.body || Number(r.headers.get('content-length') ?? 0) > PHOTO_MAX) {
    throw new Error(`photo Messenger illisible (${r.status})`);
  }
  const morceaux: Buffer[] = [];
  let taille = 0;
  for await (const morceau of r.body) {
    taille += morceau.length;
    if (taille > PHOTO_MAX) {
      throw new Error('photo Messenger trop lourde');
    }
    morceaux.push(Buffer.from(morceau));
  }
  return Buffer.concat(morceaux).toString('base64');
}

/**
 * Le vendeur écrit à sa Page depuis son compte relié : « bayam » ouvre son menu (mêmes parcours que sur WhatsApp,
 * réponses par numéro), « bayam … » exécute une commande du Copilote, une photo crée un article pendant le parcours.
 */
async function pourVendeur(d: Extract<Decision, { route: 'client' }>, vendeur: number, evt: string): Promise<void> {
  const p = await donnees.page(d.page);
  const b = p ? await commerce.boutique(vendeur) : null;
  if (!p || !b) {
    return;
  }
  const cle = `fb:menu-vendeur:${vendeur}`;
  const brutEtat = await redis.get(cle);
  const etat = brutEtat ? (JSON.parse(brutEtat) as menuVendeur.Etat) : null;
  const demande = appel(d.brut.texte);
  const outils = { articles: (recherche: string, page: number) => commerce.articles(vendeur, recherche, page, 8), destinations: () => commerce.destinations(vendeur) };
  const menuDemande = d.brut.choix?.startsWith('bayam:v:') || (demande !== null && /^(menu)?$/i.test(demande)) || (etat && demande === null);
  const etatMenu = await fonctions.etat('whatsapp.menu', vendeur);
  if (menuDemande && etatMenu.etat !== 'actif') {
    await redis.del(cle);
    await envoyer(p, b, d.client, [messenger.texteSimple(messageFonction(etatMenu))], evt);
    return;
  }
  let pas: menuVendeur.Pas | null = null;
  let reponses: ReponseCopilote[] = [];
  try {
    if (d.brut.choix?.startsWith('bayam:v:')) {
      pas = await menuVendeur.avancer(etat ?? { etape: 'menu', options: [] }, { type: 'choix', id: d.brut.choix }, outils);
    } else if (demande !== null && /^(menu)?$/i.test(demande)) {
      pas = menuVendeur.menu();
    } else if (demande !== null) {
      await redis.del(cle); // commande tapée en entier : le parcours en cours s'arrête
      reponses = await commerce.copilote(vendeur, demande, { retour: true });
    } else if (etat) {
      pas = await menuVendeur.avancer(etat, d.brut.photo ? { type: 'photo', url: d.brut.photo } : { type: 'texte', texte: d.brut.texte }, outils);
    } else {
      reponses = [{ texte: 'Écrivez « bayam » pour votre menu, ou « bayam aide » pour toutes les commandes.' }];
    }
    if (pas) {
      await (pas.etat ? redis.set(cle, JSON.stringify(pas.etat), { expiration: { type: 'EX', value: 900 } }) : redis.del(cle));
      const c = pas.commande;
      if (c) {
        // Jamais rejouée automatiquement (une vente ne doit pas compter deux fois).
        reponses = 'publication' in c
          ? await commerce.publier(vendeur, c.publication, true)
          : await commerce.copilote(vendeur, c.texte, { retour: true, photoBase64: c.photo ? await photoMessenger(c.photo) : undefined });
      }
    }
  } catch (x) {
    log.erreur('commande du vendeur dans Messenger non exécutée', { vendeur, erreur: texteErreur(x) });
    reponses = [{ texte: '⚠️ L\'action n\'a pas pu se faire. Réessayez dans un instant (écrivez « bayam »).' }];
  }
  const envois = pourMessenger(pas?.envois ?? [], reponses);
  await envoyer(p, b, d.client, envois, evt);
  log.info('espace vendeur', { page: d.page, vendeur, envois: envois.length });
}

/** Discussion d'un événement : un client d'une Page, ou une personne qui commente une publication. */
function cleDiscussion(msg: JsMsg): string {
  try {
    const e = JSON.parse(new TextDecoder().decode(msg.data)) as EvenementFacebook;
    if (e.type === 'commentaire') {
      return `${e.page}|${e.donnees.post_id}|${e.donnees.from?.id ?? e.donnees.comment_id}`;
    }
    return `${e.page}|${e.donnees.message?.is_echo ? e.donnees.recipient?.id : e.donnees.sender?.id}`;
  } catch {
    return `seq|${msg.seq}`;
  }
}

/**
 * Adresse des événements déclarée à Meta par le service lui-même, et redéclarée quand l'adresse publique change
 * (tunnel relancé) ou quand Meta a coupé l'abonnement (une heure d'échecs). Rien à régler à la main dans Meta.
 */
let declaration = { adresse: '', lue: 0 };
async function declarerAdresse(): Promise<void> {
  const base = await adressePublique();
  const adresse = `${base}/webhooks/facebook`;
  // Même adresse vérifiée il y a moins de 10 minutes : rien à demander à Meta.
  if (!configure || !jetonVerification || !base || (adresse === declaration.adresse && Date.now() - declaration.lue < 600_000)) {
    return;
  }
  const actuel = (await graph.abonnementsApplication()).find((a) => a.object === 'page');
  const champs = new Set(actuel?.fields.map((f) => f.name));
  if (!actuel?.active || actuel.callback_url !== adresse || ABONNEMENTS.some((c) => !champs.has(c))) {
    await graph.abonnerApplication(adresse, jetonVerification);
    log.info('adresse des événements déclarée à Meta', { adresse });
  }
  declaration = { adresse, lue: Date.now() };
}

// --- Connexion des Pages ---------------------------------------------------------------------------------------

/** Lien de connexion signé par WordPress (clé des liens), valable jusqu'à son échéance. */
function etatValide(v: string, e: string, s: string): number | null {
  if (!/^\d{1,10}$/.test(v) || !/^\d{1,12}$/.test(e) || Number(e) < Date.now() / 1000) {
    return null;
  }
  const attendue = createHmac('sha256', cleLiens).update(`facebook:${v}.${e}`).digest('hex');
  return attendue.length === s.length && timingSafeEqual(Buffer.from(attendue), Buffer.from(s)) ? Number(v) : null;
}

/**
 * Pages de l'utilisateur Facebook rattachées au vendeur : enregistrées avec les autorisations accordées, abonnées,
 * bouton Démarrer et menu permanent réglés. Une autorisation décochée est expliquée au vendeur (ce qui ne marchera pas).
 */
async function connecterPages(vendeur: number, jetonUtilisateur: string, choisies: string[] | null = null): Promise<{ noms: string[]; manquent: string[] }> {
  const b = await commerce.boutique(vendeur);
  if (!b) {
    throw new Error('boutique inconnue');
  }
  const accordees = await graph.autorisations(jetonUtilisateur);
  const gerees = await pagesGerees(jetonUtilisateur);
  const pages = choisies ? gerees.filter((p) => choisies.includes(p.id)) : gerees;
  // Pages de ce compte Facebook reliées auparavant et décochées cette fois : le vendeur n'en veut plus dans BAYAM.
  const avant = await donnees.pagesDuVendeur(vendeur);
  for (const p of gerees.filter((g) => choisies && !choisies.includes(g.id) && avant.some((a) => a.page === g.id))) {
    await graph.retirer(p.id, p.access_token).catch((e: unknown) => log.alerte('retrait chez Meta incomplet', { page: p.id, erreur: texteErreur(e) }));
    await donnees.supprimerPage(p.id);
  }
  for (const p of pages) {
    await donnees.enregistrerPage({ page: p.id, vendeur, nom: p.name, jeton: p.access_token, permissions: accordees });
    await graph.abonner(p.id, p.access_token);
    await graph.accueil(p.access_token, messenger.menuPermanent());
  }
  const manquent = messenger.autorisationsManquantes(accordees);
  if (pages.length) {
    const noms = pages.map((p) => `« ${p.name} »`).join(', ');
    const enTrop = pages.length > 1 ? `\nUne Page qui n'est pas celle de la boutique ? *bayam facebook retirer ${pages[pages.length - 1].name}*` : '';
    const avertissement = manquent.length
      ? `\n⚠️ Facebook n'a pas autorisé BAYAM à ${manquent.map((a) => messenger.AUTORISATIONS[a]).join(', ')}. Pour tout activer : *bayam facebook*, puis acceptez toutes les autorisations demandées.`
      : '';
    await alerteVendeur(b, `✅ *Facebook relié à BAYAM* : ${noms}.\nVos clients ont maintenant le catalogue dans Messenger (menu toujours visible), une réponse privée à chaque commentaire « prix ? », et vous pouvez publier : *bayam publier robes facebook*.${avertissement}${enTrop}`, `connexion-${vendeur}-${Date.now()}`);
  }
  log.info('Pages connectées', { vendeur, pages: pages.length, manquent });
  return { noms: pages.map((p) => p.name), manquent };
}

/** Pages que ce compte Facebook peut gérer dans Messenger. */
async function pagesGerees(jetonUtilisateur: string) {
  return (await graph.pages(jetonUtilisateur)).filter((p) => !p.tasks || p.tasks.includes('MESSAGING'));
}

const echapper = (t: string) => t.replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
const STYLE = 'body{margin:0;font-family:system-ui,sans-serif;background:#F7FAF9;color:#0B2545;display:grid;place-items:center;min-height:100vh}'
  + 'main{max-width:420px;margin:24px;padding:32px;background:#fff;border-radius:24px;box-shadow:0 8px 32px rgba(11,37,69,.1)}h1{font-size:1.4rem;color:#0A7A55}p{line-height:1.5}'
  + 'label{display:flex;align-items:center;gap:14px;padding:16px;margin:10px 0;border:2px solid #E2E8F0;border-radius:16px;font-size:1.05rem;cursor:pointer}'
  + 'label:has(input:checked){border-color:#0E9F6E;background:#E6F7F0}input{width:24px;height:24px;accent-color:#0E9F6E}'
  + 'button{width:100%;margin-top:16px;padding:16px;border:0;border-radius:16px;background:#0E9F6E;color:#fff;font-size:1.05rem;font-weight:600}';

function pageHtml(titre: string, texte: string, lien?: { adresse: string; libelle: string }): string {
  const bouton = lien ? `<a href="${echapper(lien.adresse)}"><button type="button">${echapper(lien.libelle)}</button></a>` : '';
  return `<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${echapper(titre)}</title>
<style>${STYLE}</style></head><body><main><h1>${echapper(titre)}</h1><p>${echapper(texte)}</p>${bouton}</main></body></html>`;
}

/** Choix des Pages à relier, quand le compte Facebook en gère plusieurs : celles déjà reliées sont cochées. */
function pageChoix(cle: string, pages: { id: string; name: string }[], reliees: string[]): string {
  const cases = pages.map((p) => `<label><input type="checkbox" name="page" value="${echapper(p.id)}"${reliees.includes(p.id) ? ' checked' : ''}>${echapper(p.name)}</label>`).join('');
  return `<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Vos Pages</title>
<style>${STYLE}</style></head><body><main><h1>Quelles Pages relier à BAYAM ?</h1>
<p>Cochez la ou les Pages de votre boutique. BAYAM répondra à leurs clients dans Messenger et sous leurs publications ; les autres ne sont pas touchées.</p>
<form method="get" action="/facebook/choisir"><input type="hidden" name="c" value="${echapper(cle)}">${cases}<button type="submit">Relier les Pages cochées</button></form></main></body></html>`;
}

// --- Files : publications, confirmations ------------------------------------------------------------------------

/** Publie ce que le vendeur a demandé : une photo, un album (plusieurs articles) ou des stories. */
async function publier(l: { id: string; vendeur: number; page: string; type: 'publication' | 'story'; produits: number[] }): Promise<Record<string, unknown>> {
  const p = await donnees.page(l.page);
  const b = await commerce.boutique(l.vendeur);
  if (!p || !b) {
    throw Object.assign(new Error('Page déconnectée ou boutique inconnue'), { definitive: true });
  }
  if (!p.permissions.includes('pages_manage_posts')) {
    await alerteVendeur(b, `⚠️ Facebook n'a pas autorisé BAYAM à publier sur votre Page « ${p.nom} ». Écrivez *bayam facebook*, touchez le lien et acceptez toutes les autorisations demandées.`, `publication-refusee-${l.id}`);
    throw Object.assign(new Error('autorisation de publier absente'), { definitive: true });
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
  // Lien officiel de la publication (celui que Facebook affiche) ; à défaut, l'adresse par identifiant.
  const lien = l.type === 'story' ? null : await graph.permalien(publication, p.jeton).catch(() => null);
  await alerteVendeur(b, l.type === 'story'
    ? `✅ ${produits.length} story(s) publiée(s) sur votre Page « ${p.nom} », visibles 24 h.`
    : `✅ Publié sur votre Page « ${p.nom} » : ${lien ?? `https://www.facebook.com/${publication}`}\nChaque commentaire « prix ? » reçoit une réponse privée avec l'article.`, `publication-${l.id}`);
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
  const etatConnexion = await fonctions.etat('facebook.connexion');
  if (etatConnexion.etat !== 'actif') {
    return reply.code(503).type('text/html').send(pageHtml('Maintenance', messageFonction(etatConnexion)));
  }
  const vendeurLien = etatValide(v, e, s);
  if (vendeurLien === null) {
    return reply.code(403).type('text/html').send(pageHtml('Lien expiré', 'Ce lien de connexion a expiré. Écrivez « bayam facebook » sur WhatsApp pour en recevoir un nouveau.'));
  }
  // État de connexion aléatoire, à usage unique, lié au navigateur par un cookie : un lien transféré ou une
  // réponse de Facebook rejouée dans un autre navigateur ne relie aucune Page (protection CSRF de l'OAuth).
  const nonce = randomBytes(18).toString('base64url');
  await redis.set(`fb:etat:${nonce}`, String(vendeurLien), { expiration: { type: 'EX', value: 900 } });
  return reply.header('set-cookie', `${COOKIE_ETAT}=${nonce}; Path=/facebook; Max-Age=900; HttpOnly; Secure; SameSite=Lax`)
    .redirect(graph.urlConnexion(`${base}/facebook/retour`, nonce));
});
app.get<{ Querystring: { code?: string; state?: string; error?: string } }>('/facebook/retour', async (req, reply) => {
  const etat = String(req.query.state ?? '');
  const cookie = new RegExp(`(?:^|;\\s*)${COOKIE_ETAT}=([\\w-]{24})(?:;|$)`).exec(String(req.headers.cookie ?? ''))?.[1];
  const enregistre = /^[\w-]{24}$/.test(etat) && cookie === etat ? await redis.getDel(`fb:etat:${etat}`) : null;
  const vendeur = enregistre && /^\d{1,10}$/.test(enregistre) ? Number(enregistre) : null;
  if (vendeur === null || !req.query.code) {
    return reply.code(req.query.error ? 200 : 403).type('text/html').send(pageHtml('Connexion annulée', 'Aucune Page n\'a été reliée. Écrivez « bayam facebook » sur WhatsApp pour recommencer.'));
  }
  try {
    const jeton = await graph.jetonUtilisateur(req.query.code, `${await adressePublique()}/facebook/retour`);
    const gerees = await pagesGerees(jeton);
    if (gerees.length > 1) {
      // Jeton gardé chiffré 15 minutes, le temps de cocher ; seule la clé aléatoire passe par le navigateur.
      const cle = randomBytes(18).toString('base64url');
      await redis.set(`fb:choix:${cle}`, chiffrer(JSON.stringify({ vendeur, jeton }), cleCoffre), { expiration: { type: 'EX', value: 900 } });
      const reliees = (await donnees.pagesDuVendeur(vendeur)).map((p) => p.page);
      return reply.type('text/html').send(pageChoix(cle, gerees, reliees));
    }
    return reply.type('text/html').send(await resultatConnexion(vendeur, jeton, null));
  } catch (x) {
    log.erreur('connexion Facebook impossible', { vendeur, erreur: texteErreur(x) });
    return reply.code(502).type('text/html').send(pageHtml('Connexion impossible', 'Facebook n\'a pas répondu comme prévu. Réessayez dans quelques minutes.'));
  }
});
app.get<{ Querystring: { c?: string; page?: string | string[] } }>('/facebook/choisir', async (req, reply) => {
  const cle = String(req.query.c ?? '');
  const brut = /^[\w-]{24}$/.test(cle) ? await redis.getDel(`fb:choix:${cle}`) : null;
  if (!brut) {
    return reply.code(403).type('text/html').send(pageHtml('Lien expiré', 'Ce choix a expiré. Écrivez « bayam facebook » sur WhatsApp pour recommencer.'));
  }
  const { vendeur, jeton } = JSON.parse(dechiffrer(brut, cleCoffre)) as { vendeur: number; jeton: string };
  const choisies = ([] as string[]).concat(req.query.page ?? []).filter((p) => /^\d{1,20}$/.test(p));
  if (!choisies.length) {
    return reply.type('text/html').send(pageHtml('Aucune Page cochée', 'Aucune Page n\'a été reliée. Écrivez « bayam facebook » sur WhatsApp pour recommencer et cochez la Page de votre boutique.'));
  }
  try {
    return reply.type('text/html').send(await resultatConnexion(vendeur, jeton, choisies));
  } catch (x) {
    log.erreur('connexion Facebook impossible', { vendeur, erreur: texteErreur(x) });
    return reply.code(502).type('text/html').send(pageHtml('Connexion impossible', 'Facebook n\'a pas répondu comme prévu. Réessayez dans quelques minutes.'));
  }
});

/** Pages reliées, et la page de résultat pour le navigateur du vendeur. */
async function resultatConnexion(vendeur: number, jeton: string, choisies: string[] | null): Promise<string> {
  const { noms, manquent } = await connecterPages(vendeur, jeton, choisies);
  const reserve = manquent.length ? ` Attention : sans toutes les autorisations, BAYAM ne pourra pas ${manquent.map((a) => messenger.AUTORISATIONS[a]).join(', ')}.` : '';
  const premiere = (await donnees.pagesDuVendeur(vendeur)).find((x) => x.actif);
  return noms.length
    ? pageHtml('Page reliée à BAYAM ✅', `${noms.join(', ')} : vos clients ont maintenant votre catalogue dans Messenger.${reserve} Votre menu BAYAM (stock, ventes, publications) est aussi dans Messenger : touchez le bouton depuis votre compte Facebook.`,
      premiere ? { adresse: lienEspace(vendeur, premiere.page), libelle: '📱 Mon menu BAYAM dans Messenger' } : undefined)
    : pageHtml('Aucune Page choisie', 'Relancez la connexion et cochez la Page de votre boutique (il faut en être administrateur).');
}

app.get<{ Params: { id: string } }>('/interne/vendeurs/:id/pages', async (req) => donnees.pagesDuVendeur(Number(req.params.id)));
// Page retirée par le vendeur (« bayam facebook retirer … ») : désabonnée chez Meta, accueil effacé, jeton supprimé.
app.delete<{ Params: { id: string; page: string } }>('/interne/vendeurs/:id/pages/:page', async (req, reply) => {
  const p = await donnees.page(req.params.page);
  if (!p || p.vendeur !== Number(req.params.id)) {
    return reply.code(404).send({ erreur: 'Page inconnue pour cette boutique' });
  }
  // Meta injoignable ou jeton déjà révoqué : la Page est retirée de BAYAM quand même, l'écart est noté.
  await graph.retirer(p.page, p.jeton).catch((e: unknown) => log.alerte('retrait chez Meta incomplet', { page: p.page, erreur: texteErreur(e) }));
  await donnees.supprimerPage(p.page);
  log.info('Page retirée', { vendeur: p.vendeur, page: p.page });
  return { retiree: p.nom };
});
// Connexion par un jeton utilisateur collé à la main (essais, assistance) : même suite que la fenêtre Facebook.
app.post<{ Params: { id: string }; Body: { jeton?: string; pages?: string[] } }>('/interne/vendeurs/:id/pages', async (req, reply) => {
  if (!configure || !req.body?.jeton) {
    return reply.code(400).send({ erreur: configure ? 'jeton manquant' : 'Facebook non configuré' });
  }
  const { noms, manquent } = await connecterPages(Number(req.params.id), await graph.jetonLongue(req.body.jeton), Array.isArray(req.body.pages) ? req.body.pages.map(String) : null);
  return { pages: noms, manquent };
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
const declarer = () => void declarerAdresse().catch((e: unknown) => {
  log.alerte('adresse des événements non déclarée à Meta', { erreur: texteErreur(e) });
});
declarer();
// Chaque minute : Meta n'est interrogé que si l'adresse publique a changé, ou toutes les 10 minutes.
const veille = setInterval(declarer, 60_000);
let filesOccupees = false;
const files = setInterval(() => {
  if (filesOccupees || arret || !configure) {
    return;
  }
  filesOccupees = true;
  const echec = (nature: string) => (l: { id: string }, essais: number, definitif: boolean, e: unknown) =>
    log[definitif ? 'erreur' : 'alerte'](`${nature} en échec`, { id: l.id, essais, definitif, erreur: texteErreur(e) });
  void Promise.all([
    // Publications en maintenance : elles attendent dans la file et partent à la réouverture.
    fonctions.etat('facebook.publications').then((e) => (e.etat === 'actif' ? donnees.file('publications', 'vendeur, page, type, produits', publier, 6, echec('publication')) : 0)),
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
    clearInterval(veille);
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
