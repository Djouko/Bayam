import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { createServer, type IncomingHttpHeaders, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import { ErreurGraph, Graph } from './graph.ts';

type Appel = { methode: string; chemin: string; requete: URLSearchParams; entetes: IncomingHttpHeaders; corps: string };

/** Faux Graph : garde les appels reçus, répond ce que la fonction donnée décide. */
async function fauxGraph(reponse: (a: Appel) => [number, unknown]): Promise<{ graph: Graph; appels: Appel[]; serveur: Server }> {
  const appels: Appel[] = [];
  const serveur = createServer((req, res) => {
    let corps = '';
    req.setEncoding('latin1');
    req.on('data', (m) => (corps += m));
    req.on('end', () => {
      const url = new URL(req.url ?? '/', 'http://graph');
      const a = { methode: req.method ?? '', chemin: url.pathname, requete: url.searchParams, entetes: req.headers, corps };
      appels.push(a);
      const [statut, donnees] = reponse(a);
      res.writeHead(statut, { 'content-type': 'application/json' }).end(JSON.stringify(donnees));
    });
  });
  await new Promise<void>((ok) => serveur.listen(0, '127.0.0.1', ok));
  return { graph: new Graph(`http://127.0.0.1:${(serveur.address() as AddressInfo).port}`, '1234', 'secret-app'), appels, serveur };
}

test('envoi Messenger : jeton de Page et preuve du secret joints, réponse au client (RESPONSE)', async () => {
  const f = await fauxGraph(() => [200, { recipient_id: '7001', message_id: 'm.99' }]);
  const id = await f.graph.envoyer('555', 'JETON-PAGE', { id: '7001' }, { text: 'Bonjour', metadata: 'bayam' });
  assert.equal(id, 'm.99');
  const [a] = f.appels;
  assert.equal(a.chemin, '/555/messages');
  assert.equal(a.requete.get('access_token'), 'JETON-PAGE');
  assert.equal(a.requete.get('appsecret_proof'), createHmac('sha256', 'secret-app').update('JETON-PAGE').digest('hex'));
  assert.deepEqual(JSON.parse(a.corps), { recipient: { id: '7001' }, messaging_type: 'RESPONSE', message: { text: 'Bonjour', metadata: 'bayam' } });
  f.serveur.close();
});

test('erreurs de Meta : limite de débit passagère, fenêtre de 24 h dépassée définitive, jeton révoqué reconnu', async () => {
  const codes = [[400, 613], [400, 10], [400, 190], [500, 2]];
  let i = 0;
  const f = await fauxGraph(() => {
    const [statut, code] = codes[i++];
    return [statut, { error: { code, message: 'refus' } }];
  });
  const erreurs: ErreurGraph[] = [];
  for (let n = 0; n < codes.length; n++) {
    await f.graph.envoyer('555', 'J', { id: '7001' }, { text: 'x' }).catch((e: ErreurGraph) => erreurs.push(e));
  }
  assert.deepEqual(erreurs.map((e) => [e.code, e.definitive, e.jetonInvalide]), [[613, false, false], [10, true, false], [190, true, true], [2, false, false]]);
  f.serveur.close();
});

test('photo envoyée en fichier (multipart), sans adresse publique ; album avec les photos déjà envoyées', async () => {
  const f = await fauxGraph((a) => [200, a.chemin.endsWith('/photos') ? { id: 'ph1', post_id: '555_77' } : { id: '555_88' }]);
  const r = await f.graph.photo('555', 'J', Buffer.from([0xff, 0xd8, 0xff, 0xd9]), 'Robe wax : 15 000 FCFA', true);
  assert.deepEqual(r, { id: 'ph1', post_id: '555_77' });
  assert.match(String(f.appels[0].entetes['content-type']), /^multipart\/form-data; boundary=/);
  assert.ok(f.appels[0].corps.includes('name="source"; filename="photo.jpg"'));
  assert.ok(f.appels[0].corps.includes('name="published"\r\n\r\ntrue'));
  assert.equal(await f.graph.album('555', 'J', 'Nouveautés', ['ph1', 'ph2']), '555_88');
  assert.deepEqual(JSON.parse(Buffer.from(f.appels[1].corps, 'latin1').toString('utf8')), { message: 'Nouveautés', attached_media: [{ media_fbid: 'ph1' }, { media_fbid: 'ph2' }] });
  f.serveur.close();
});

test('connexion du vendeur : code échangé puis jeton longue durée ; abonnement de la Page aux événements utiles', async () => {
  const f = await fauxGraph((a) => [200, a.chemin === '/oauth/access_token' ? { access_token: a.requete.get('code') ? 'COURT' : 'LONG' } : { success: true }]);
  assert.equal(await f.graph.jetonUtilisateur('CODE', 'https://bayam.cm/facebook/retour'), 'LONG');
  assert.equal(f.appels[0].requete.get('redirect_uri'), 'https://bayam.cm/facebook/retour');
  assert.equal(f.appels[1].requete.get('fb_exchange_token'), 'COURT');
  await f.graph.abonner('555', 'J');
  assert.deepEqual(JSON.parse(f.appels[2].corps), { subscribed_fields: 'messages,messaging_postbacks,messaging_referrals,message_echoes,feed' });
  const url = new URL(f.graph.urlConnexion('https://bayam.cm/facebook/retour', '3.99.abc'));
  assert.equal(url.searchParams.get('state'), '3.99.abc');
  assert.ok(url.searchParams.get('scope')?.includes('pages_messaging'));
  f.serveur.close();
});
