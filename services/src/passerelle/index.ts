// Passerelle des événements WhatsApp : WAHA -> vérification de la signature -> filtre -> bus NATS JetStream.
// WAHA n'est acquitté qu'une fois l'événement écrit dans le bus ; en cas de panne du bus il reçoit 503 et réessaie
// lui-même. Sans état : autant d'instances que nécessaire derrière le répartiteur de charge.

import { createHash } from 'node:crypto';
import { createServer, type IncomingMessage } from 'node:http';
import { headers } from '@nats-io/transport-node';
import { jetstream } from '@nats-io/jetstream';
import { assurerFluxWhatsapp, connecterBus, SUJET_WHATSAPP } from '../commun/bus.ts';
import { exiger, lire, nombre } from '../commun/config.ts';
import { journal, texteErreur } from '../commun/journal.ts';
import { signatureValide, utile } from './filtre.ts';

const log = journal('passerelle');
const secret = exiger('WAHA_WEBHOOK_SECRET');
const nc = await connecterBus(lire('NATS_URL', 'nats://nats:4222'), 'passerelle');
await assurerFluxWhatsapp(nc, nombre('NATS_REPLIQUES', 1));
const js = jetstream(nc);
const compteurs = { depuis: new Date().toISOString(), recus: 0, publies: 0, doublons: 0, ecartes: 0, refuses: 0, echecs: 0 };

/** Corps brut de la requête, plafonné : aucun événement légitime n'approche 5 Mo (les médias passent par lien). */
function lireCorps(req: IncomingMessage): Promise<Buffer | null> {
  return new Promise((resoudre, rejeter) => {
    const morceaux: Buffer[] = [];
    let taille = 0;
    req.on('data', (m: Buffer) => {
      taille += m.length;
      if (taille > 5e6) {
        resoudre(null);
        req.destroy();
      } else {
        morceaux.push(m);
      }
    });
    req.on('end', () => resoudre(Buffer.concat(morceaux)));
    req.on('error', rejeter);
  });
}

const serveur = createServer(async (req, res) => {
  const repondre = (statut: number, corps: unknown = { ok: statut < 300 }) => {
    res.writeHead(statut, { 'content-type': 'application/json' });
    res.end(JSON.stringify(corps));
  };
  if (req.method === 'GET' && req.url === '/sante') {
    repondre(nc.isClosed() ? 503 : 200, { ...compteurs, bus: nc.isClosed() ? 'fermé' : 'connecté' });
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

const port = nombre('PORT', 8090);
serveur.listen(port, () => log.info('à l\'écoute', { port }));

// Arrêt propre : plus de nouvelles requêtes, puis vidage de la connexion au bus.
for (const signal of ['SIGTERM', 'SIGINT'] as const) {
  process.once(signal, () => {
    serveur.close(() => void nc.drain().finally(() => process.exit(0)));
  });
}
