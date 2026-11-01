// Passerelle des événements WhatsApp : WAHA -> (filtre) -> WordPress.
// Un commerçant actif est dans des centaines de groupes : des centaines de messages par minute, presque tous sans
// rapport avec sa boutique. La passerelle vérifie la signature de WAHA et ne transmet à WordPress que ce qui
// concerne BAYAM : discussions privées, état de la session, et commentaires sous une publication de BAYAM.
// Sans dépendance ; Node répond à des milliers d'événements par seconde, WordPress ne voit que l'utile.
'use strict';

const http = require('node:http');
const crypto = require('node:crypto');

const MARQUE = '⁣'; // marque invisible en tête des messages et légendes envoyés par BAYAM

/** Signature HMAC de WAHA sur le corps brut (en-têtes X-Webhook-Hmac et X-Webhook-Hmac-Algorithm). */
function signatureValide(corps, entetes, secret) {
  const recue = String(entetes['x-webhook-hmac'] || '');
  const algo = String(entetes['x-webhook-hmac-algorithm'] || 'sha512').toLowerCase();
  if (!secret || !recue || !['sha512', 'sha256'].includes(algo)) {
    return false;
  }
  const attendue = crypto.createHmac(algo, secret).update(corps).digest('hex');
  return attendue.length === recue.length && crypto.timingSafeEqual(Buffer.from(attendue), Buffer.from(recue));
}

/** L'événement concerne-t-il BAYAM ? */
function utile(evt) {
  if (evt.event === 'session.status') {
    return true;
  }
  if (evt.event !== 'message' && evt.event !== 'message.any') {
    return false;
  }
  const p = evt.payload || {};
  const chat = String((p._data && p._data.Info && p._data.Info.Chat) || p.from || '');
  if (/@newsletter|@broadcast|^status@/.test(chat)) {
    return false;
  }
  if (chat.endsWith('@g.us')) {
    // Groupe : seulement un commentaire qui répond à une publication de BAYAM (le message cité porte la marque).
    return !p.fromMe && String((p.replyTo && p.replyTo.body) || '').startsWith(MARQUE);
  }
  return true;
}

function demarrer() {
  const port = Number(process.env.PORT || 8090);
  const secret = process.env.WAHA_WEBHOOK_SECRET || '';
  const cible = process.env.BAYAM_WP_WEBHOOK || 'http://wordpress/wp-json/bayam/v1/whatsapp/webhook';
  const compteurs = { depuis: new Date().toISOString(), recus: 0, transmis: 0, ecartes: 0, refuses: 0, echecs: 0 };

  http.createServer((req, res) => {
    if (req.method === 'GET' && req.url === '/sante') {
      res.writeHead(200, { 'content-type': 'application/json' });
      res.end(JSON.stringify(compteurs));
      return;
    }
    if (req.method !== 'POST' || req.url !== '/whatsapp') {
      res.writeHead(404);
      res.end();
      return;
    }
    const morceaux = [];
    let taille = 0;
    req.on('data', (m) => {
      taille += m.length;
      if (taille > 5e6) {
        req.destroy(); // aucun événement légitime n'est aussi gros (les médias passent par lien)
      } else {
        morceaux.push(m);
      }
    });
    req.on('end', async () => {
      compteurs.recus++;
      const corps = Buffer.concat(morceaux);
      if (!signatureValide(corps, req.headers, secret)) {
        compteurs.refuses++;
        res.writeHead(401);
        res.end();
        return;
      }
      let evt;
      try {
        evt = JSON.parse(corps.toString('utf8'));
      } catch {
        res.writeHead(400);
        res.end();
        return;
      }
      if (!utile(evt)) {
        compteurs.ecartes++;
        res.writeHead(200, { 'content-type': 'application/json' });
        res.end('{"ok":true,"ecarte":true}');
        return;
      }
      // Transmis tel quel (même corps, même signature) ; la réponse de WordPress revient à WAHA, qui réessaie
      // lui-même en cas d'échec : aucun événement utile ne se perd si WordPress redémarre.
      try {
        const r = await fetch(cible, {
          method: 'POST',
          body: corps,
          headers: {
            'content-type': 'application/json',
            'x-webhook-hmac': req.headers['x-webhook-hmac'],
            'x-webhook-hmac-algorithm': req.headers['x-webhook-hmac-algorithm'] || 'sha512',
          },
          signal: AbortSignal.timeout(15000),
        });
        compteurs[r.ok ? 'transmis' : 'echecs']++;
        res.writeHead(r.status, { 'content-type': 'application/json' });
        res.end(await r.text());
      } catch (e) {
        compteurs.echecs++;
        console.error(new Date().toISOString(), 'WordPress injoignable :', e.message);
        res.writeHead(502);
        res.end();
      }
    });
  }).listen(port, () => console.log(`Passerelle BAYAM WhatsApp à l'écoute sur le port ${port}, vers ${cible}`));
}

if (require.main === module) {
  demarrer();
}

module.exports = { utile, signatureValide, MARQUE };
