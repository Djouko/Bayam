// Passerelle des événements WhatsApp et Facebook : WAHA ou Meta -> vérification de la signature -> filtre -> bus NATS
// JetStream. L'expéditeur n'est acquitté qu'une fois l'événement écrit dans le bus ; en cas de panne du bus il reçoit
// 503 et réessaie lui-même. Sans état : autant d'instances que nécessaire derrière le répartiteur de charge.

import { createHash, timingSafeEqual } from 'node:crypto';
import { createServer, type IncomingMessage, type ServerResponse } from 'node:http';
import { headers } from '@nats-io/transport-node';
import { jetstream } from '@nats-io/jetstream';
import { createClient, type RedisClientType } from 'redis';
import { assurerFluxFacebook, assurerFluxWhatsapp, connecterBus, SUJET_FACEBOOK, SUJET_WHATSAPP } from '../commun/bus.ts';
import { exiger, lire, nombre } from '../commun/config.ts';
import { etatDepuis, Fonctions, FONCTIONS } from '../commun/fonctions.ts';
import { journal, texteErreur } from '../commun/journal.ts';
import { evenementsFacebook, resumeFacebook, signatureMetaValide, type EvenementFacebook } from './facebook.ts';
import { signatureValide, utile } from './filtre.ts';

const log = journal('passerelle');
const secret = exiger('WAHA_WEBHOOK_SECRET');
// Facebook facultatif : sans application Meta configurée, l'adresse /facebook répond 503.
const secretFacebook = lire('FACEBOOK_APP_SECRET', '');
const jetonVerification = lire('FACEBOOK_JETON_VERIFICATION', '');
const nc = await connecterBus(lire('NATS_URL', 'nats://nats:4222'), 'passerelle');
await assurerFluxWhatsapp(nc, nombre('NATS_REPLIQUES', 1));
await assurerFluxFacebook(nc, nombre('NATS_REPLIQUES', 1));
const js = jetstream(nc);
// Interrupteurs des fonctions, publiés pour les applications mobile et web (GET /config, public via /api/config).
const redis: RedisClientType = createClient({ url: lire('REDIS_URL', 'redis://redis:6379') });
redis.on('error', (e: unknown) => log.erreur('Redis', { erreur: texteErreur(e) }));
await redis.connect();
const fonctions = new Fonctions(redis);
// Versions de l'application mobile publiées par le serveur : invitation discrète à mettre à jour (dernière version),
// blocage seulement sous la version minimale (faille grave). Réglées dans l'environnement, sans nouvelle application.
// Source : le fichier de version publié avec chaque application (bayam/outils/publier_apk.sh), relu toutes les
// 5 minutes ; à défaut, les variables BAYAM_APP_*. Publier une version ne demande donc aucun redéploiement.
let application = {
  derniere: lire('BAYAM_APP_DERNIERE', ''),
  minimale: lire('BAYAM_APP_MINIMALE', ''),
  notes: lire('BAYAM_APP_NOTES', ''),
  lien: lire('BAYAM_APP_LIEN', ''),
};
const manifeste = lire('BAYAM_APP_MANIFESTE', '');
async function lireManifeste(): Promise<void> {
  const r = await fetch(manifeste, { signal: AbortSignal.timeout(8000) });
  if (!r.ok) {
    return;
  }
  const m = (await r.json()) as { version?: string; minimale?: string; notes?: string };
  const site = lire('BAYAM_URL_PUBLIQUE', '');
  application = {
    derniere: String(m.version ?? application.derniere),
    minimale: String(m.minimale ?? application.minimale),
    notes: String(m.notes ?? application.notes).slice(0, 300),
    lien: site ? `${site}/telecharger` : application.lien,
  };
}
if (manifeste) {
  const relire = () => void lireManifeste().catch((e: unknown) => log.alerte('fichier de version illisible', { erreur: texteErreur(e) }));
  relire();
  setInterval(relire, 300_000).unref();
}
const compteurs = { depuis: new Date().toISOString(), recus: 0, publies: 0, doublons: 0, ecartes: 0, refuses: 0, echecs: 0 };
const compteursFacebook = { recus: 0, publies: 0, doublons: 0, refuses: 0, echecs: 0 };

/**
 * Corps brut de la requête, plafonné avant toute vérification de signature : aucun événement légitime n'approche
 * 5 Mo pour WAHA (les médias passent par lien) ni 1 Mo pour Meta. Au-delà, la lecture s'arrête et la réponse est un
 * 413 propre, connexion fermée ensuite (pas de mémoire ni de calcul dépensés pour un envoi hostile).
 */
function lireCorps(req: IncomingMessage, plafond = 5e6): Promise<Buffer | null> {
  return new Promise((resoudre, rejeter) => {
    const morceaux: Buffer[] = [];
    let taille = Number(req.headers['content-length'] ?? 0);
    if (taille > plafond) {
      resoudre(null);
      return;
    }
    taille = 0;
    const lire = (m: Buffer) => {
      taille += m.length;
      if (taille > plafond) {
        req.off('data', lire);
        req.pause();
        resoudre(null);
      } else {
        morceaux.push(m);
      }
    };
    req.on('data', lire);
    req.on('end', () => resoudre(Buffer.concat(morceaux)));
    req.on('error', rejeter);
  });
}

/** Comparaison en temps constant (jetons, secrets) : la durée ne révèle rien du texte attendu. */
function memeTexte(recu: string, attendu: string): boolean {
  const a = createHash('sha256').update(recu).digest();
  const b = createHash('sha256').update(attendu).digest();
  return timingSafeEqual(a, b) && recu.length === attendu.length;
}

const serveur = createServer(async (req, res) => {
  const repondre = (statut: number, corps: unknown = { ok: statut < 300 }) => {
    // Corps refusé (trop gros) : réponse envoyée puis connexion fermée, le reste de l'envoi n'est jamais lu.
    res.writeHead(statut, { 'content-type': 'application/json', ...(statut === 413 ? { connection: 'close' } : {}) });
    res.end(JSON.stringify(corps), () => statut === 413 && req.destroy());
  };
  if (req.method === 'GET' && req.url === '/config') {
    // État de chaque fonction pour les applications : elles affichent la maintenance d'une partie sans mise à jour.
    const reglages = await fonctions.tous();
    const etats = Object.fromEntries(Object.keys(FONCTIONS).map((nom) => [nom, etatDepuis(reglages, nom, 0, Date.now())]));
    res.writeHead(200, { 'content-type': 'application/json', 'cache-control': 'public, max-age=30' });
    res.end(JSON.stringify({ genere: new Date().toISOString(), rafraichir_s: 60, fonctions: etats, application }));
    return;
  }
  if (req.method === 'GET' && req.url === '/sante') {
    repondre(nc.isClosed() ? 503 : 200, { ...compteurs, facebook: compteursFacebook, bus: nc.isClosed() ? 'fermé' : 'connecté' });
    return;
  }
  const adresse = new URL(req.url ?? '/', 'http://passerelle');
  if (adresse.pathname === '/facebook') {
    await facebook(req, res, adresse, repondre);
    return;
  }
  if (req.method !== 'POST' || req.url !== '/whatsapp') {
    repondre(404);
    return;
  }
  const corps = await lireCorps(req).catch(() => null);
  compteurs.recus++;
  if (!corps) {
    repondre(413);
    return;
  }
  if (!signatureValide(corps, req.headers, secret)) {
    compteurs.refuses++;
    repondre(401);
    return;
  }
  let evt: { id?: string; session?: string; event?: string };
  try {
    evt = JSON.parse(corps.toString('utf8'));
  } catch {
    repondre(400);
    return;
  }
  if (!utile(evt)) {
    compteurs.ecartes++;
    repondre(200, { ok: true, ecarte: true });
    return;
  }
  // La signature d'origine voyage avec l'événement : WordPress la revérifie tant qu'il traite une partie des messages.
  const h = headers();
  h.set('x-webhook-hmac', String(req.headers['x-webhook-hmac']));
  h.set('x-webhook-hmac-algorithm', String(req.headers['x-webhook-hmac-algorithm'] ?? 'sha512'));
  const session = String(evt.session ?? 'inconnue').replace(/[^A-Za-z0-9_-]/g, '_');
  const id = evt.id ? String(evt.id) : createHash('sha256').update(corps).digest('hex');
  try {
    const ack = await js.publish(`${SUJET_WHATSAPP}.${session}`, corps, { msgID: id, headers: h, timeout: 5000 });
    compteurs[ack.duplicate ? 'doublons' : 'publies']++;
    repondre(200);
  } catch (e) {
    compteurs.echecs++;
    log.erreur('écriture dans le bus impossible', { session, evenement: evt.event, erreur: texteErreur(e) });
    repondre(503);
  }
});

/**
 * Facebook : GET = vérification de l'adresse par Meta lors de l'abonnement (jeton choisi par nous, défi renvoyé tel
 * quel) ; POST = événements signés, découpés, publiés un par un sur facebook.evenements.<Page>.
 */
async function facebook(req: IncomingMessage, res: ServerResponse, adresse: URL, repondre: (statut: number, corps?: unknown) => void): Promise<void> {
  if (!secretFacebook || !jetonVerification) {
    repondre(503, { erreur: 'Facebook non configuré' });
    return;
  }
  if (req.method === 'GET') {
    const p = adresse.searchParams;
    const defi = p.get('hub.challenge') ?? '';
    if (p.get('hub.mode') !== 'subscribe' || !memeTexte(p.get('hub.verify_token') ?? '', jetonVerification) || !/^[\w-]{1,128}$/.test(defi)) {
      repondre(403);
      return;
    }
    // Meta attend le défi seul, en texte brut.
    res.writeHead(200, { 'content-type': 'text/plain' });
    res.end(defi);
    return;
  }
  if (req.method !== 'POST') {
    repondre(405);
    return;
  }
  const corps = await lireCorps(req, 1e6).catch(() => null);
  compteursFacebook.recus++;
  if (!corps) {
    repondre(413);
    return;
  }
  if (!signatureMetaValide(corps, req.headers['x-hub-signature-256'] as string | undefined, secretFacebook)) {
    compteursFacebook.refuses++;
    repondre(401);
    return;
  }
  let evenements: EvenementFacebook[];
  try {
    const recu = JSON.parse(corps.toString('utf8'));
    evenements = evenementsFacebook(recu);
    log.info('facebook', { natures: resumeFacebook(recu), gardes: evenements.length });
  } catch {
    repondre(400);
    return;
  }
  try {
    for (const e of evenements) {
      const ack = await js.publish(`${SUJET_FACEBOOK}.${e.page}`, JSON.stringify(e), { msgID: e.id, timeout: 5000 });
      compteursFacebook[ack.duplicate ? 'doublons' : 'publies']++;
    }
    repondre(200);
  } catch (e) {
    // Meta renvoie tout l'appel plus tard : ce qui est déjà publié est reconnu comme doublon.
    compteursFacebook.echecs++;
    log.erreur('écriture dans le bus impossible (Facebook)', { erreur: texteErreur(e) });
    repondre(503);
  }
}

const port = nombre('PORT', 8090);
serveur.listen(port, () => log.info('à l\'écoute', { port }));

// Arrêt propre : plus de nouvelles requêtes, puis vidage de la connexion au bus.
for (const signal of ['SIGTERM', 'SIGINT'] as const) {
  process.once(signal, () => {
    serveur.close(() => void nc.drain().finally(() => process.exit(0)));
  });
}
