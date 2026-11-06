import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { MARQUE, signatureValide, utile } from './filtre.ts';

const message = (chat: string, extra: Record<string, unknown> = {}) =>
  ({ event: 'message.any', payload: { from: chat, _data: { Info: { Chat: chat } }, ...extra } });

test('discussion privée transmise', () => assert.ok(utile(message('237681010044@c.us'))));
test('identifiant LID privé transmis', () => assert.ok(utile(message('32779945431068@lid'))));
test('état de la session transmis', () => assert.ok(utile({ event: 'session.status', payload: {} })));
test('message ordinaire de groupe écarté', () => assert.ok(!utile(message('120363000000000001@g.us', { body: 'Bonsoir la famille' }))));
test('réponse de groupe à un message ordinaire écartée', () => assert.ok(!utile(message('120363000000000001@g.us', { replyTo: { body: 'Rdv demain' } }))));
test('commentaire sous une publication BAYAM transmis', () => assert.ok(utile(message('120363000000000001@g.us', { replyTo: { body: `${MARQUE}Polo : 12 000 FCFA` } }))));
test('le vendeur qui commente sa propre publication : écarté', () => assert.ok(!utile(message('120363000000000001@g.us', { fromMe: true, replyTo: { body: `${MARQUE}Polo` } }))));
test('chaînes, listes de diffusion et statuts écartés', () => {
  for (const chat of ['120363000000000009@newsletter', '1234@broadcast', 'status@broadcast']) {
    assert.ok(!utile(message(chat)), chat);
  }
});
test('autres événements écartés', () => assert.ok(!utile({ event: 'presence.update', payload: {} })));
test('événement sans contenu écarté sans erreur', () => assert.ok(!utile({})));

test('signature HMAC vérifiée, en temps constant', () => {
  const corps = Buffer.from('{"event":"message.any"}');
  const bonne = createHmac('sha512', 'secret-essai').update(corps).digest('hex');
  assert.ok(signatureValide(corps, { 'x-webhook-hmac': bonne, 'x-webhook-hmac-algorithm': 'sha512' }, 'secret-essai'));
  assert.ok(!signatureValide(corps, { 'x-webhook-hmac': bonne }, 'autre-secret'));
  assert.ok(!signatureValide(corps, { 'x-webhook-hmac': 'abcd' }, 'secret-essai'));
  assert.ok(!signatureValide(corps, {}, 'secret-essai'));
  assert.ok(!signatureValide(corps, { 'x-webhook-hmac': bonne }, ''));
  assert.ok(!signatureValide(corps, { 'x-webhook-hmac': bonne, 'x-webhook-hmac-algorithm': 'md5' }, 'secret-essai'));
});
