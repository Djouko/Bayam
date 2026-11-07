import { test } from 'node:test';
import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { chiffrer, cleDepuis, dechiffrer } from './coffre.ts';

const cle = randomBytes(32);

test('jeton de Page chiffré puis relu à l’identique ; jamais deux fois le même chiffré', () => {
  const jeton = 'EAAG' + 'x'.repeat(180);
  const a = chiffrer(jeton, cle);
  assert.ok(a.startsWith('v1.') && !a.includes(jeton));
  assert.notEqual(a, chiffrer(jeton, cle));
  assert.equal(dechiffrer(a, cle), jeton);
});

test('chiffré modifié ou mauvaise clé : refusé, rien de lisible', () => {
  const a = chiffrer('secret', cle);
  const [v, iv, etiquette, donnees] = a.split('.');
  const abime = [v, iv, etiquette, Buffer.from('zz').toString('base64url') + donnees.slice(2)].join('.');
  assert.throws(() => dechiffrer(abime, cle));
  assert.throws(() => dechiffrer(a, randomBytes(32)));
  assert.throws(() => dechiffrer('texte-en-clair', cle));
});

test('clé de configuration : 32 octets en base64 exigés', () => {
  assert.equal(cleDepuis(cle.toString('base64')).length, 32);
  assert.throws(() => cleDepuis('trop-courte'), /FACEBOOK_CLE/);
});
