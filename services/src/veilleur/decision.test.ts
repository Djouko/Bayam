import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ECHECS_AVANT_RELANCE, observer, PAUSE_ENTRE_RELANCES, type Etat } from './decision.ts';

const neuf: Etat = { echecs: 0, derniereRelance: 0 };

test('tunnel prêt : rien à faire, compteur remis à zéro', () => {
  const r = observer({ echecs: 2, derniereRelance: 0 }, true, 1_000_000);
  assert.deepEqual(r, { etat: { echecs: 0, derniereRelance: 0 }, relancer: false });
});

test('relance seulement après plusieurs échecs de suite (une coupure passagère ne suffit pas)', () => {
  let etat = neuf;
  const decisions: boolean[] = [];
  for (let i = 0; i < ECHECS_AVANT_RELANCE; i++) {
    const r = observer(etat, false, 1_000_000 + i);
    etat = r.etat;
    decisions.push(r.relancer);
  }
  assert.deepEqual(decisions, [false, false, true]);
  assert.equal(etat.echecs, 0);
});

test('jamais deux relances rapprochées, même si le tunnel reste mort', () => {
  const apres = observer({ echecs: ECHECS_AVANT_RELANCE, derniereRelance: 1_000_000 }, false, 1_000_000 + PAUSE_ENTRE_RELANCES - 1);
  assert.equal(apres.relancer, false);
  const plusTard = observer({ echecs: ECHECS_AVANT_RELANCE, derniereRelance: 1_000_000 }, false, 1_000_000 + PAUSE_ENTRE_RELANCES);
  assert.equal(plusTard.relancer, true);
});
