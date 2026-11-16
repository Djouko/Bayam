import assert from 'node:assert/strict';
import { test } from 'node:test';

import { etatPublic, suivre, SUIVI_NEUF, type Suivi } from './regles.ts';

const serie = (resultats: boolean[]) => {
  let s: Suivi = SUIVI_NEUF;
  const actions: (string | null)[] = [];
  resultats.forEach((ok, i) => {
    const r = suivre(s, ok, ok ? '' : 'délai dépassé', i);
    s = r.suivi;
    actions.push(r.action);
  });
  return { s, actions };
};

test('un raté isolé ne déclenche rien ; trois échecs de suite ouvrent un incident, une seule fois', () => {
  assert.deepEqual(serie([false, true, false, false, true]).actions, [null, null, null, null, null]);
  const { s, actions } = serie([false, false, false, false, false]);
  assert.deepEqual(actions, [null, null, 'ouvrir', null, null]);
  assert.equal(s.incident, true);
  assert.equal(etatPublic(s), 'panne');
});

test('rétablissement confirmé par deux succès de suite ; un succès suivi d’un échec garde l’incident', () => {
  assert.deepEqual(serie([false, false, false, true, true]).actions, [null, null, 'ouvrir', null, 'fermer']);
  const { s, actions } = serie([false, false, false, true, false, true]);
  assert.deepEqual(actions, [null, null, 'ouvrir', null, null, null]);
  assert.equal(s.incident, true);
});

test('état public : perturbé dès un échec, opérationnel après succès', () => {
  assert.equal(etatPublic(serie([false]).s), 'perturbe');
  assert.equal(etatPublic(serie([false, true]).s), 'operationnel');
});
