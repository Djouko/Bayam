import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { evenementsFacebook, METADONNEE_BAYAM, signatureMetaValide } from './facebook.ts';

const PAGE = '1029384756';
const page = (champs: Record<string, unknown>) => ({ object: 'page', entry: [{ id: PAGE, time: 1, ...champs }] });
const message = (extra: Record<string, unknown>) => ({ sender: { id: '7001' }, recipient: { id: PAGE }, timestamp: 1_762_500_000_000, ...extra });

test('signature de Meta : bonne acceptée, fausse ou absente refusée', () => {
  const corps = Buffer.from('{"object":"page"}');
  const bonne = `sha256=${createHmac('sha256', 'secret-app').update(corps).digest('hex')}`;
  assert.ok(signatureMetaValide(corps, bonne, 'secret-app'));
  assert.ok(!signatureMetaValide(corps, bonne, 'autre-secret'));
  assert.ok(!signatureMetaValide(corps, undefined, 'secret-app'));
  assert.ok(!signatureMetaValide(corps, bonne.replace('sha256=', 'sha1='), 'secret-app'));
  assert.ok(!signatureMetaValide(corps, bonne, ''));
});

test('un appel groupé de Meta donne un événement par message, avec son identifiant', () => {
  const e = evenementsFacebook(page({ messaging: [message({ message: { mid: 'm.1', text: 'Bonjour' } }), message({ message: { mid: 'm.2', text: 'Prix ?' } })] }));
  assert.deepEqual(e.map((x) => [x.id, x.page, x.type, x.horodatage]), [['m.1', PAGE, 'messagerie', 1_762_500_000], ['m.2', PAGE, 'messagerie', 1_762_500_000]]);
});

test('bouton touché et lien m.me : gardés, identifiant stable sans mid', () => {
  const [choix] = evenementsFacebook(page({ messaging: [message({ postback: { title: 'Commander', payload: 'bayam:produit:25' } })] }));
  assert.equal(choix.id, `${PAGE}-7001-1762500000000-choix`);
  const [lien] = evenementsFacebook(page({ messaging: [message({ referral: { ref: 'produit-25', source: 'SHORTLINK' } })] }));
  assert.equal(lien.id, `${PAGE}-7001-1762500000000-lien`);
});

test('écho : celui de BAYAM écarté, la réponse tapée par le vendeur gardée (il reprend la main)', () => {
  const echo = (metadata?: string) => ({ sender: { id: PAGE }, recipient: { id: '7001' }, timestamp: 1, message: { mid: 'e.1', is_echo: true, text: 'ok', ...(metadata ? { metadata } : {}) } });
  assert.equal(evenementsFacebook(page({ messaging: [echo(METADONNEE_BAYAM)] })).length, 0);
  assert.equal(evenementsFacebook(page({ messaging: [echo()] })).length, 1);
});

test('accusés de lecture, réceptions et réactions écartés', () => {
  const bruit = [message({ read: { watermark: 1 } }), message({ delivery: { mids: ['m.1'] } }), message({ reaction: { mid: 'm.1', action: 'react' } })];
  assert.equal(evenementsFacebook(page({ messaging: bruit })).length, 0);
});

test('nouveau commentaire d’un client gardé ; commentaire de la Page, modification et réaction écartés', () => {
  const changement = (value: Record<string, unknown>, field = 'feed') => ({ field, value: { item: 'comment', verb: 'add', comment_id: 'c1', post_id: `${PAGE}_9`, from: { id: '7001', name: 'Awa' }, message: 'Prix ?', created_time: 1_762_500_000, ...value } });
  const [c] = evenementsFacebook(page({ changes: [changement({})] }));
  assert.deepEqual([c.id, c.type, c.horodatage], ['commentaire-c1', 'commentaire', 1_762_500_000]);
  const ecartes = [changement({ from: { id: PAGE } }), changement({ verb: 'edited' }), changement({ item: 'reaction' }), changement({}, 'mention')];
  assert.equal(evenementsFacebook(page({ changes: ecartes })).length, 0);
});

test('autre objet que Page, ou Page sans identifiant valable : rien', () => {
  assert.equal(evenementsFacebook({ object: 'instagram', entry: [] }).length, 0);
  assert.equal(evenementsFacebook({ object: 'page', entry: [{ id: '../x', messaging: [message({ message: { mid: 'm' } })] }] }).length, 0);
});
