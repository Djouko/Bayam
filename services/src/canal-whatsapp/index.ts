// Service canal WhatsApp : lit les événements du bus, répond lui-même à ce qu'il sait traiter (catalogue
// interactif) et transmet le reste à l'extension WordPress, inchangé. Sans état en mémoire (bases et Redis) :
// autant d'instances que nécessaire, les événements d'une même discussion restent traités dans l'ordre.

import { createHash, timingSafeEqual } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { AckPolicy, DeliverPolicy, jetstream, jetstreamManager, type JsMsg } from '@nats-io/jetstream';
import { nanos } from '@nats-io/transport-node';
import Fastify from 'fastify';
import { createClient, type RedisClientType } from 'redis';
import { creerPool, migrer } from '../commun/base.ts';
import { assurerFluxWhatsapp, connecterBus, FLUX_WHATSAPP, SUJET_WHATSAPP } from '../commun/bus.ts';
import { exiger, lire, nombre } from '../commun/config.ts';
import { journal, texteErreur } from '../commun/journal.ts';
import * as catalogue from './catalogue.ts';
import { CommerceWordpress, type Boutique } from './commerce.ts';
import { Expediteur } from './expediteur.ts';
import { creerJeton, nettoyer, ouvrirJeton } from './jetons.ts';
import { discussionDuVendeur, lireMessage, type Evenement } from './message.ts';
import { deposer, envoyerDues, valide, type Notification } from './notifications.ts';
import { decider, type Decision } from './routeur.ts';
import { COTE_PHOTO, dessinerVitrine, photoLegere } from './vitrine.ts';
import { Waha } from './waha.ts';

const log = journal('canal-whatsapp');
const jetonService = exiger('BAYAM_SERVICE_JETON');
const urlWordpress = lire('BAYAM_WP_INTERNE', 'http://wordpress');
const webhookWordpress = lire('BAYAM_WP_WEBHOOK', `${urlWordpress}/wp-json/bayam/v1/whatsapp/webhook`);

const pool = creerPool(exiger('CANAL_WHATSAPP_BASE'));
const appliquees = await migrer(pool, 'canal_whatsapp', fileURLToPath(new URL('./migrations', import.meta.url)));
if (appliquees.length) {
  log.info('migrations appliquées', { appliquees });
}
const redis: RedisClientType = createClient({ url: lire('REDIS_URL', 'redis://redis:6379') });
redis.on('error', (e: unknown) => log.erreur('Redis', { erreur: texteErreur(e) }));
await redis.connect();

const commerce = new CommerceWordpress(`${urlWordpress}/wp-json/bayam/v1/interne`, jetonService, redis);
const waha = new Waha(lire('WAHA_URL', 'http://waha:3000'), exiger('WAHA_API_KEY'));
const expediteur = new Expediteur(waha, redis, pool, log, {
  parDiscussion10Min: nombre('PLAFOND_DISCUSSION_10MIN', 40),
  parSessionJour: nombre('PLAFOND_SESSION_JOUR', 1500),
});

/**
 * Adresse publique du site pour les liens envoyés : celle du domaine (BAYAM_URL_PUBLIQUE) en production ; pendant les
 * démonstrations, celle du tunnel, lue en direct (gardée 30 s), car elle change à chaque relance du tunnel.
 */
let adresse = { valeur: '', lue: 0 };
async function basePublique(b: Boutique): Promise<string> {
  const fixe = lire('BAYAM_URL_PUBLIQUE', '');
  if (fixe) {
    return fixe.replace(/\/$/, '');
  }
  if (Date.now() - adresse.lue > 30_000) {
    const hote = await fetch(`${lire('BAYAM_TUNNEL_METRICS', 'http://tunnel:2000')}/quicktunnel`, { signal: AbortSignal.timeout(3000) })
      .then((r): Promise<{ hostname?: string }> | { hostname?: string } => (r.ok ? r.json() : {}))
      .then((d) => (typeof d.hostname === 'string' && d.hostname ? `https://${d.hostname}` : ''))
      .catch(() => '');
    adresse = { valeur: hote, lue: Date.now() };
  }
  return adresse.valeur || b.base_publique;
}

/** Réponse du catalogue ; 'heritage' quand finalement c'est à WordPress de traiter (son propre chat, boutique inconnue…). */
async function repondre(d: Extract<Decision, { route: 'catalogue' }>): Promise<'fait' | 'heritage'> {
  const m = d.message;
  const vendeur = Number(/^boutique-(\d+)$/.exec(m.session)?.[1] ?? 0);
  const b = vendeur ? await commerce.boutique(vendeur) : null;
  if (!b || (m.deMoi && discussionDuVendeur(m.chat, b.identifiants)) || (!m.deMoi && d.action.type === 'menu' && !b.actif)) {
    return 'heritage';
  }
  const a = d.action;
  let envois: catalogue.Envoi[];
  switch (a.type) {
    case 'menu':
      envois = catalogue.menu(b);
      break;
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

/** Image servie par le site (réseau interne), ou null si indisponible. */
async function imageDuSite(chemin: string | null): Promise<Buffer | null> {
  if (!chemin) {
    return null;
  }
  const r = await fetch(urlWordpress + chemin, { signal: AbortSignal.timeout(5000) }).catch(() => null);
  return r?.ok ? Buffer.from(await r.arrayBuffer()) : null;
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
    const images = await Promise.all(cases.map((p) => imageDuSite(p.photo)));
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
    const brute = await imageDuSite(e.chemin);
    if (!brute) {
      return { type: 'texte', texte: e.legende };
    }
    donnees = (await photoLegere(brute)).toString('base64');
    await redis.set(cle, donnees, { expiration: { type: 'EX', value: 7 * 86_400 } });
  }
  return { type: 'image', legende: e.legende, donnees };
}

/** Transmission à l'extension WordPress : même corps, même signature HMAC que WAHA (elle la vérifie toujours). */
async function transmettre(msg: JsMsg): Promise<void> {
  const r = await fetch(webhookWordpress, {
    method: 'POST',
    body: Buffer.from(msg.data),
    headers: {
      'content-type': 'application/json',
      'x-webhook-hmac': msg.headers?.get('x-webhook-hmac') ?? '',
      'x-webhook-hmac-algorithm': msg.headers?.get('x-webhook-hmac-algorithm') || 'sha512',
    },
    signal: AbortSignal.timeout(15_000),
  });
  if (!r.ok) {
    throw new Error(`WordPress a répondu ${r.status}`);
  }
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
  const d = decider(lireMessage(evt), Math.floor(recu / 1000));
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

// Ordre garanti par discussion : chaîne de traitements en mémoire, verrou Redis entre instances.
const chaines = new Map<string, Promise<void>>();
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

async function traiterDansLOrdre(msg: JsMsg): Promise<void> {
  const cle = cleDiscussion(msg);
  const verrou = `canal:verrou:${cle}`;
  const precedent = chaines.get(cle) ?? Promise.resolve();
  const courant = precedent.then(async () => {
    const pris = await redis.set(verrou, '1', { condition: 'NX', expiration: { type: 'PX', value: 60_000 } });
    if (!pris) {
      msg.nak(500); // une autre instance traite cette discussion : on repasse juste après
      return;
    }
    try {
      await traiter(msg);
    } catch (e) {
      const essai = msg.info.deliveryCount;
      log.erreur('traitement en échec, nouvelle tentative', { sujet: msg.subject, essai, erreur: texteErreur(e) });
      msg.nak(Math.min(60_000, 1000 * 2 ** essai));
    } finally {
      await redis.del(verrou);
    }
  });
  chaines.set(cle, courant);
  await courant;
  if (chaines.get(cle) === courant) {
    chaines.delete(cle);
  }
}

const nc = await connecterBus(lire('NATS_URL', 'nats://nats:4222'), 'canal-whatsapp');
await assurerFluxWhatsapp(nc, nombre('NATS_REPLIQUES', 1));
const jsm = await jetstreamManager(nc);
const CONSOMMATEUR = 'canal-whatsapp';
const configConsommateur = {
  ack_policy: AckPolicy.Explicit,
  filter_subject: `${SUJET_WHATSAPP}.>`,
  ack_wait: nanos(90_000),
  max_deliver: 20,
  max_ack_pending: 1000,
};
try {
  await jsm.consumers.info(FLUX_WHATSAPP, CONSOMMATEUR);
  await jsm.consumers.update(FLUX_WHATSAPP, CONSOMMATEUR, configConsommateur);
} catch {
  await jsm.consumers.add(FLUX_WHATSAPP, { durable_name: CONSOMMATEUR, deliver_policy: DeliverPolicy.All, ...configConsommateur });
}
const flot = await (await jetstream(nc).consumers.get(FLUX_WHATSAPP, CONSOMMATEUR)).consume({ max_messages: 64 });

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
  void traiterDansLOrdre(msg).finally(() => enCours--);
}
