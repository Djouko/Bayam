import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import { LotWordpress } from './lot.ts';

/** Faux WordPress : compte les appels et la taille de chaque lot ; répond le statut demandé. */
async function fauxWordpress(statut: number): Promise<{ url: string; lots: number[]; jetons: string[]; serveur: Server }> {
  const lots: number[] = [];
  const jetons: string[] = [];
  const serveur = createServer((req, res) => {
    let corps = '';
    req.on('data', (m) => (corps += m));
    req.on('end', () => {
      lots.push(JSON.parse(corps).evenements.length);
      jetons.push(String(req.headers['x-bayam-service']));
      res.writeHead(statut).end('{}');
    });
  });
  await new Promise<void>((ok) => serveur.listen(0, '127.0.0.1', ok));
  return { url: `http://127.0.0.1:${(serveur.address() as AddressInfo).port}/lot`, lots, jetons, serveur };
}

const evenement = (i: number) => ({ corps: `{"id":"${i}"}`, hmac: 'abc', algo: 'sha512' });

test('120 événements : 3 appels seulement (50, 50, 20), tous confirmés, jeton de service envoyé', async () => {
  const w = await fauxWordpress(200);
  const lot = new LotWordpress(w.url, 'jeton-essai', 50, 50);
  await Promise.all(Array.from({ length: 120 }, (_, i) => lot.ajouter(evenement(i))));
  assert.deepEqual(w.lots, [50, 50, 20]);
  assert.ok(w.jetons.every((j) => j === 'jeton-essai'));
  w.serveur.close();
});

test('un événement seul part après le délai, sans attendre d’en avoir 50', async () => {
  const w = await fauxWordpress(200);
  const lot = new LotWordpress(w.url, 'j', 50, 30);
  const debut = Date.now();
  await lot.ajouter(evenement(1));
  assert.deepEqual(w.lots, [1]);
  assert.ok(Date.now() - debut < 1000);
  w.serveur.close();
});

test('WordPress en panne : tout le lot est rejeté, pour être retenté par le bus', async () => {
  const w = await fauxWordpress(503);
  const lot = new LotWordpress(w.url, 'j', 50, 20);
  const resultats = await Promise.allSettled([lot.ajouter(evenement(1)), lot.ajouter(evenement(2))]);
  assert.ok(resultats.every((r) => r.status === 'rejected'));
  w.serveur.close();
});
