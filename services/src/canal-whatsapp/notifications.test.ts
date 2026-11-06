import { test } from 'node:test';
import assert from 'node:assert/strict';
import { valide } from './notifications.ts';

const bonne = { cle: 'commande-412', session: 'boutique-3', chat: '32779945431068@lid', texte: '✅ Commande n° 412 reçue' };

test('notification bien formée acceptée (discussion @lid, @c.us)', () => {
  assert.ok(valide(bonne));
  assert.ok(valide({ ...bonne, chat: '237681010044@c.us' }));
});

test('dépôt refusé : champ manquant, groupe, texte vide ou trop long, clé ou session douteuses', () => {
  assert.ok(!valide({ ...bonne, cle: undefined }));
  assert.ok(!valide({ ...bonne, chat: '120363000000000001@g.us' }));
  assert.ok(!valide({ ...bonne, texte: '' }));
  assert.ok(!valide({ ...bonne, texte: 'x'.repeat(4001) }));
  assert.ok(!valide({ ...bonne, cle: 'commande 412; DROP TABLE' }));
  assert.ok(!valide({ ...bonne, session: '../boutique' }));
  assert.ok(!valide({}));
});
