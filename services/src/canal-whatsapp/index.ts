// Service canal WhatsApp : lit les événements du bus, répond lui-même à ce qu'il sait traiter (catalogue
// interactif) et transmet le reste à l'extension WordPress, inchangé. Sans état en mémoire (bases et Redis) :
// autant d'instances que nécessaire, les événements d'une même discussion restent traités dans l'ordre.

import { createHash, timingSafeEqual } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import type { JsMsg } from '@nats-io/jetstream';
import Fastify from 'fastify';
import { createClient, type RedisClientType } from 'redis';
import { adressePublique } from '../commun/adresse.ts';
import { creerPool, migrer } from '../commun/base.ts';
import { MARQUE } from '../passerelle/filtre.ts';
import { assurerFluxWhatsapp, connecterBus, consommer, FLUX_WHATSAPP, SUJET_WHATSAPP } from '../commun/bus.ts';
import { exiger, lire, nombre } from '../commun/config.ts';
import { Fonctions, messageFonction } from '../commun/fonctions.ts';
import { journal, texteErreur } from '../commun/journal.ts';
import { ordonnanceur } from '../commun/ordre.ts';
import { appel } from './appel.ts';
import * as catalogue from './catalogue.ts';
import { CommerceWordpress, type Boutique, type ReponseCopilote } from '../commun/commerce.ts';
import { Expediteur } from './expediteur.ts';
import { creerJeton, nettoyer, ouvrirJeton } from './jetons.ts';
import { LotWordpress } from './lot.ts';
import * as menuVendeur from './menu-vendeur.ts';
import { discussionDuVendeur, lireMessage, type Evenement, type MessageWhatsapp } from './message.ts';
import { deposer, envoyerDues, valide, type Notification } from './notifications.ts';
import { decider, FRAICHEUR, type Decision } from './routeur.ts';
import { COTE_PHOTO, dessinerVitrine, photoLegere } from './vitrine.ts';
import { Waha } from './waha.ts';

const log = journal('canal-whatsapp');
const jetonService = exiger('BAYAM_SERVICE_JETON');
const urlWordpress = lire('BAYAM_WP_INTERNE', 'http://wordpress');

const pool = creerPool(exiger('CANAL_WHATSAPP_BASE'));
const appliquees = await migrer(pool, 'canal_whatsapp', fileURLToPath(new URL('./migrations', import.meta.url)));
if (appliquees.length) {
  log.info('migrations appliquées', { appliquees });
}
const redis: RedisClientType = createClient({ url: lire('REDIS_URL', 'redis://redis:6379') });
redis.on('error', (e: unknown) => log.erreur('Redis', { erreur: texteErreur(e) }));
await redis.connect();

const fonctions = new Fonctions(redis);
const commerce = new CommerceWordpress(`${urlWordpress}/wp-json/bayam/v1/interne`, jetonService, redis);
const waha = new Waha(lire('WAHA_URL', 'http://waha:3000'), exiger('WAHA_API_KEY'));
const expediteur = new Expediteur(waha, redis, pool, log, {
  parDiscussion10Min: nombre('PLAFOND_DISCUSSION_10MIN', 40),
  parSessionJour: nombre('PLAFOND_SESSION_JOUR', 1500),
});

/** Adresse publique des liens envoyés : domaine ou tunnel (commun/adresse.ts), sinon celle connue de WordPress. */
async function basePublique(b: Boutique): Promise<string> {
  return (await adressePublique()) || b.base_publique;
}

/** Réponse du catalogue ; 'heritage' quand finalement c'est à WordPress de traiter (son propre chat, boutique inconnue…). */
async function repondre(d: Extract<Decision, { route: 'catalogue' }>): Promise<'fait' | 'heritage'> {
  const m = d.message;
  const vendeur = Number(/^boutique-(\d+)$/.exec(m.session)?.[1] ?? 0);
  const b = vendeur ? await commerce.boutique(vendeur) : null;
  // Message tapé par le client (menu, référence d'article) : rien quand le vendeur a coupé les réponses automatiques.
  if (!b || (m.deMoi && discussionDuVendeur(m.chat, b.identifiants)) || (!m.deMoi && !m.choixListe && !b.actif)) {
    return 'heritage';
  }
  const a = d.action;
  // Catalogue en maintenance : un message clair (une fois par heure et par discussion), le reste de WhatsApp continue.
  const etat = await fonctions.etat('whatsapp.catalogue', b.id);
  if (etat.etat !== 'actif' && a.type !== 'signaler') {
    if (await redis.set(`canal:maintenance:${m.session}:${m.chat}`, '1', { condition: 'NX', expiration: { type: 'EX', value: 3600 } })) {
      await expediteur.envoyer(m.session, m.chat, b.chat_vendeur, [{ type: 'texte', texte: MARQUE + messageFonction(etat) }]);
    }
    return 'fait';
  }
  let envois: catalogue.Envoi[];
  switch (a.type) {
    case 'menu':
      envois = catalogue.menu(b);
      break;
    case 'aide':
      envois = catalogue.aideClient(b);
      break;
    case 'signaler': {
      const merci = await commerce.retour({ type: a.genre, texte: a.texte || '(sans détail)', canal: 'whatsapp', auteur: m.numero ?? m.nom, vendeur: b.id });
      envois = [{ type: 'texte', texte: MARQUE + merci }];
      break;
    }
    case 'rayon': {
      const rayon = a.rayon === 0
        ? { id: 0, nom: catalogue.PROMOS, nombre: b.promos }
        : (b.rayons.find((r) => r.id === a.rayon) ?? { id: a.rayon, nom: 'Ce rayon', nombre: 0 });
      envois = catalogue.pageRayon(b, rayon, await commerce.rayon(b.id, a.rayon, a.page, catalogue.PAR_PAGE), a.page);
      break;
    }
    case 'produit': {
      const p = await commerce.produit(b.id, a.produit);
      const jeton = p?.en_stock ? await creerJeton(pool, { vendeur: b.id, produit: p.id, numero: m.numero ?? '', chat: m.chat }) : null;
      envois = catalogue.produit(b, p, jeton ? `${await basePublique(b)}/commander/${jeton}` : null);
      break;
    }
    case 'commandes':
      envois = catalogue.commandes(b, m.numero ? await commerce.commandes(b.id, m.numero) : null);
      break;
    case 'livraison':
      envois = catalogue.livraison(b);
      break;
    case 'vendeur':
      envois = catalogue.vendeur(b, m.nom, m.numero);
      break;
  }
  const prets = await Promise.all(envois.map((e) => (e.type === 'vitrine' ? vitrine(e) : e.type === 'photo' ? photoArticle(e) : e)));
  await expediteur.envoyer(m.session, m.chat, b.chat_vendeur, prets);
  return 'fait';
}

/**
 * Vitrine d'un rayon dessinée une fois puis gardée 24 h : même contenu, même image, servie depuis Redis sans
 * redessiner. Tout changement de prix, de stock ou de photo change l'empreinte, donc l'image.
 */
async function vitrine(e: catalogue.EnvoiVitrine): Promise<catalogue.EnvoiPret> {
  const cases = e.produits.slice(0, catalogue.PAR_PAGE);
  const empreinte = createHash('sha1')
    .update(JSON.stringify([e.titre, e.sousTitre, e.premier, e.infos, cases.map((p) => [p.id, p.nom, p.prix, p.prix_regulier, p.stock, p.options, p.photo])]))
    .digest('hex');
  const cle = `canal:vitrine:${empreinte}`;
  let donnees = await redis.get(cle);
  if (!donnees) {
    const images = await Promise.all(cases.map((p) => commerce.image(p.photo)));
    const jpeg = await dessinerVitrine(e.titre, e.sousTitre, cases.map((p, i) => ({
      numero: e.premier + i,
      nom: p.nom,
      prix: p.variable ? `dès ${catalogue.fcfa(p.prix)}` : catalogue.fcfa(p.prix),
      ancienPrix: catalogue.enPromo(p) ? catalogue.fcfa(p.prix_regulier) : null,
      remise: catalogue.enPromo(p) ? Math.round((1 - p.prix / p.prix_regulier) * 100) : null,
      peuDeStock: catalogue.peuDeStock(p),
      options: p.options,
      image: images[i],
    })), e.infos);
    donnees = jpeg.toString('base64');
    await redis.set(cle, donnees, { expiration: { type: 'EX', value: 86_400 } });
  }
  return { type: 'image', legende: e.legende, donnees };
}

/** Photo d'un article recompressée une fois puis gardée 7 jours ; texte seul si elle manque. */
async function photoArticle(e: catalogue.EnvoiPhoto): Promise<catalogue.EnvoiPret> {
  // Taille dans la clé : un changement de réglage ne ressert jamais l'ancienne version.
  const cle = `canal:photo:${COTE_PHOTO}:${createHash('sha1').update(e.chemin).digest('hex')}`;
  let donnees = await redis.get(cle);
  if (!donnees) {
    const brute = await commerce.image(e.chemin);
    if (!brute) {
      return { type: 'texte', texte: e.legende };
    }
    donnees = (await photoLegere(brute)).toString('base64');
    await redis.set(cle, donnees, { expiration: { type: 'EX', value: 7 * 86_400 } });
  }
  return { type: 'image', legende: e.legende, donnees };
}

/**
 * « Message à moi-même » : le menu BAYAM et ses parcours guidés (« bayam », puis des choix). Renvoie faux quand le
 * message n'est pas pour le menu (commande tapée, note personnelle, écho d'un envoi) : WordPress le traite comme avant.
 */
async function pourLeVendeur(m: MessageWhatsapp, b: Boutique): Promise<boolean> {
  // Échos de nos propres envois (marque BAYAM), messages vides : jamais une réponse du vendeur.
  if (m.texte.includes(MARQUE) || (!m.texte.trim() && !m.photo && !m.choixListe)) {
    return false;
  }
  const cle = `canal:menu-vendeur:${b.id}`;
  const brut = await redis.get(cle);
  const etat = brut ? (JSON.parse(brut) as menuVendeur.Etat) : null;
  const demande = appel(m.texte);
  const outils = { articles: (recherche: string, page: number) => commerce.articles(b.id, recherche, page, 8), destinations: () => commerce.destinations(b.id) };
  const etatMenu = await fonctions.etat('whatsapp.menu', b.id);
  if (etatMenu.etat !== 'actif' && (m.choixListe?.startsWith('bayam:v:') || (demande !== null && /^(menu)?$/i.test(demande)) || etat)) {
    await redis.del(cle);
    await expediteur.envoyer(m.session, m.chat, b.chat_vendeur, [{ type: 'texte', texte: MARQUE + messageFonction(etatMenu) }], true);
    return true;
  }
  let pas: menuVendeur.Pas;
  if (m.choixListe?.startsWith('bayam:v:')) {
    pas = await menuVendeur.avancer(etat ?? { etape: 'menu', options: [] }, { type: 'choix', id: m.choixListe }, outils);
  } else if (demande !== null && /^(menu)?$/i.test(demande)) {
    pas = menuVendeur.menu();
  } else if (etat && demande === null) {
    pas = await menuVendeur.avancer(etat, m.photo ? { type: 'photo', url: m.photo } : { type: 'texte', texte: m.texte }, outils);
  } else {
    if (etat) {
      await redis.del(cle); // commande tapée en entier : le parcours s'arrête, WordPress la traite
    }
    return false;
  }
  if (pas.etat) {
    await redis.set(cle, JSON.stringify(pas.etat), { expiration: { type: 'EX', value: 900 } });
  } else {
    await redis.del(cle);
  }
  if (pas.envois.length) {
    await expediteur.envoyer(m.session, m.chat, b.chat_vendeur, pas.envois, true);
  }
  if (pas.commande) {
    // Jamais rejouée automatiquement (une vente ne doit pas compter deux fois) : en cas d'échec, le vendeur est prévenu.
    const c = pas.commande;
    const action: Promise<ReponseCopilote[]> = 'publication' in c ? commerce.publier(b.id, c.publication) : commerce.copilote(b.id, c.texte, { photo: c.photo });
    await action.catch(async (e: unknown) => {
      log.erreur('commande du menu non exécutée', { vendeur: b.id, erreur: texteErreur(e) });
      await expediteur.envoyer(m.session, m.chat, b.chat_vendeur, [{ type: 'texte', texte: `${MARQUE}⚠️ L'action n'a pas pu se faire. Réessayez dans un instant (tapez *bayam*).` }], true);
    });
  }
  return true;
}

/** Transmission à l'extension WordPress, par lots ; chaque événement garde la signature HMAC de WAHA, revérifiée. */
const lot = new LotWordpress(`${urlWordpress}/wp-json/bayam/v1/interne/whatsapp/lot`, jetonService);
function transmettre(msg: JsMsg): Promise<void> {
  return lot.ajouter({
    corps: new TextDecoder().decode(msg.data),
    hmac: msg.headers?.get('x-webhook-hmac') ?? '',
    algo: msg.headers?.get('x-webhook-hmac-algorithm') || 'sha512',
  });
}

async function traiter(msg: JsMsg): Promise<void> {
  let evt: Evenement;
  try {
    evt = JSON.parse(new TextDecoder().decode(msg.data)) as Evenement;
  } catch {
    log.alerte('événement illisible écarté', { sujet: msg.subject, seq: msg.seq });
    msg.term();
    return;
  }
  const recu = Date.now();
  const message = lireMessage(evt);
  const vendeur = Number(/^boutique-(\d+)$/.exec(message?.session ?? '')?.[1] ?? 0);
  if (message && vendeur && message.deMoi && !message.groupe && recu / 1000 - message.horodatage <= FRAICHEUR) {
    const b = await commerce.boutique(vendeur);
    if (b && discussionDuVendeur(message.chat, b.identifiants)) {
      if (await pool.query('SELECT 1 FROM canal_whatsapp.evenements_traites WHERE evt_id = $1', [message.evt]).then((r) => r.rowCount)) {
        msg.ack();
        return;
      }
      if (await pourLeVendeur(message, b)) {
        await pool.query('INSERT INTO canal_whatsapp.evenements_traites (evt_id) VALUES ($1) ON CONFLICT DO NOTHING', [message.evt]);
        log.info('menu du vendeur', { session: message.session, duree_ms: Date.now() - recu });
        msg.ack();
        return;
      }
    }
  }
  const d = decider(message, Math.floor(recu / 1000));
  if (d.route === 'catalogue') {
    const deja = await pool.query('SELECT 1 FROM canal_whatsapp.evenements_traites WHERE evt_id = $1', [d.message.evt]);
    if (deja.rowCount) {
      msg.ack();
      return;
    }
    if ((await repondre(d)) === 'fait') {
      await pool.query('INSERT INTO canal_whatsapp.evenements_traites (evt_id) VALUES ($1) ON CONFLICT DO NOTHING', [d.message.evt]);
      // Délais : du message du client à sa lecture dans le bus, puis de la lecture à la fin des envois.
      log.info('catalogue', { session: d.message.session, action: d.action.type, attente_ms: recu - d.message.horodatage * 1000, duree_ms: Date.now() - recu });
      msg.ack();
      return;
    }
  }
  await transmettre(msg);
  msg.ack();
}

// Ordre garanti par discussion (verrou Redis entre instances).
const dansLOrdre = ordonnanceur(redis, log, 'canal');
let enCours = 0;
const MAX_EN_COURS = nombre('CANAL_PARALLELISME', 32);

function cleDiscussion(msg: JsMsg): string {
  try {
    const evt = JSON.parse(new TextDecoder().decode(msg.data)) as Evenement;
    return `${evt.session}|${evt.payload?._data?.Info?.Chat ?? evt.payload?.from ?? ''}`;
  } catch {
    return `seq|${msg.seq}`;
  }
}

const nc = await connecterBus(lire('NATS_URL', 'nats://nats:4222'), 'canal-whatsapp');
await assurerFluxWhatsapp(nc, nombre('NATS_REPLIQUES', 1));
const flot = await consommer(nc, FLUX_WHATSAPP, 'canal-whatsapp', SUJET_WHATSAPP);

// Petit serveur HTTP interne : santé, et résolution des liens personnels de commande pour le site.
const app = Fastify({ bodyLimit: 10_000 });
const autorise = (valeur: unknown) => {
  const a = Buffer.from(String(valeur ?? ''));
  const b = Buffer.from(jetonService);
  return a.length === b.length && timingSafeEqual(a, b);
};
app.get('/sante', async (_req, reply) => {
  const bus = !nc.isClosed();
  const etat = { bus, redis: redis.isReady, enCours };
  return reply.code(bus && redis.isReady ? 200 : 503).send(etat);
});
app.get<{ Params: { jeton: string } }>('/interne/jetons/:jeton', async (req, reply) => {
  if (!autorise(req.headers['x-bayam-service'])) {
    return reply.code(401).send({ erreur: 'jeton de service invalide' });
  }
  const j = await ouvrirJeton(pool, req.params.jeton);
  return j ? reply.send(j) : reply.code(404).send({ erreur: 'lien expiré ou inconnu' });
});
app.post<{ Body: Partial<Notification> }>('/interne/notifications', async (req, reply) => {
  if (!autorise(req.headers['x-bayam-service'])) {
    return reply.code(401).send({ erreur: 'jeton de service invalide' });
  }
  if (!valide(req.body ?? {})) {
    return reply.code(400).send({ erreur: 'notification invalide' });
  }
  await deposer(pool, req.body as Notification);
  return reply.code(202).send({ ok: true });
});
await app.listen({ host: '0.0.0.0', port: nombre('PORT', 8091) });
log.info('à l\'écoute', { port: nombre('PORT', 8091), parallelisme: MAX_EN_COURS });

const menage = setInterval(() => void nettoyer(pool).catch((e: unknown) => log.erreur('ménage', { erreur: texteErreur(e) })), 3_600_000);
let boiteOccupee = false;
const boite = setInterval(() => {
  if (boiteOccupee || arret) {
    return;
  }
  boiteOccupee = true;
  void envoyerDues(pool, expediteur, log)
    .catch((e: unknown) => log.erreur('boîte d\'envoi', { erreur: texteErreur(e) }))
    .finally(() => {
      boiteOccupee = false;
    });
}, 2000);

let arret = false;
for (const signal of ['SIGTERM', 'SIGINT'] as const) {
  process.once(signal, async () => {
    arret = true;
    clearInterval(menage);
    clearInterval(boite);
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
