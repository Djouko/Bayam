// Contrat HTTP du service des retours, avec un dépôt en mémoire : authentification, validation des entrées, filtrage
// des sorties, alertes et réponse aux signaleurs. La base réelle est vérifiée par scripts/essai_retours.sh.

import assert from 'node:assert/strict';
import { test } from 'node:test';

import { creerApp, merci } from './app.ts';
import type { Depot, Entree, Sujet } from './donnees.ts';

const JETON = 'j'.repeat(40);
const H = { 'x-bayam-service': JETON };

function faux() {
  const entrees: Entree[] = [];
  const sujet: Sujet = {
    id: 7, titre: 'menu absent', type: 'souci', categorie: 'whatsapp', gravite: 3, statut: 'corrige', fonction: null, signaleurs: 2, signalements: 2,
    alerte: 0, assigne: null, resolution: null, doublon_de: null, premier: new Date(0), dernier: new Date(0), modifie: new Date(0), score: 0,
  };
  const prevenus: number[][] = [];
  let alerteNotee = 0;
  const depot: Depot = {
    async signaler(e) {
      entrees.push(e);
      const n = entrees.filter((x) => x.personne !== e.personne || x === e).length;
      return { numero: `R-${entrees.length}`, sujet: 7, signaleurs: n, gravite: 3, nouveauSujet: entrees.length === 1, alerte: n >= 3 ? 1 : 0, alerteEnvoyee: alerteNotee };
    },
    async sujets() { return [sujet]; },
    async sujet(id) { return id === 7 ? { sujet, signalements: [{ id: 1, texte: 'x', contact: { canal: 'whatsapp' }, personne: 'secret' }], historique: [] } : null; },
    async modifier(id) { return id === 7 ? sujet : null; },
    async fusionner() { return null; },
    async retablir() { return null; },
    async noterAlerte(_id, niveau) { alerteNotee = niveau; },
    async aPrevenir() { return [{ id: 1, contact: { canal: 'whatsapp', session: 's', chat: '237600000000@c.us' } }]; },
    async marquerPrevenus(ids) { prevenus.push(ids); },
    async statistiques() { return { ouverts: [] }; },
    async menage() { return 0; },
  };
  return { depot, entrees, prevenus, alertes: () => alerteNotee };
}

test('les routes internes exigent le jeton de service', async () => {
  const app = await creerApp({ depot: faux().depot, jeton: JETON });
  assert.equal((await app.inject({ method: 'GET', url: '/interne/sujets' })).statusCode, 401);
  assert.equal((await app.inject({ method: 'GET', url: '/interne/sujets', headers: { 'x-bayam-service': 'faux' } })).statusCode, 401);
  assert.equal((await app.inject({ method: 'GET', url: '/interne/sujets', headers: H })).statusCode, 200);
});

test('route publique : validation stricte, personne anonymisée, réponse prête à afficher', async () => {
  const f = faux();
  const app = await creerApp({ depot: f.depot, jeton: JETON });
  const mauvais = await app.inject({ method: 'POST', url: '/retours', payload: { type: 'incident', texte: 'panne' } });
  assert.equal(mauvais.statusCode, 400);
  assert.equal((await app.inject({ method: 'POST', url: '/retours', payload: { type: 'souci', texte: 'x'.repeat(2001) } })).statusCode, 400);
  const r = await app.inject({ method: 'POST', url: '/retours', headers: { 'user-agent': 'BAYAM/1.4 Android' },
    payload: { type: 'souci', texte: "  L'application plante  ", contexte: { version: '1.4.0', ecran: 'panier' }, inconnu: 'retiré' } });
  assert.equal(r.statusCode, 201);
  assert.equal(r.json().numero, 'R-1');
  assert.match(r.json().message, /R-1/);
  assert.equal(f.entrees[0].texte, "L'application plante");
  assert.equal(f.entrees[0].canal, 'app');
  assert.match(f.entrees[0].personne, /^[0-9a-f]{32}$/);
  assert.equal('inconnu' in (f.entrees[0] as object), false);
});

test('alerte envoyée une seule fois quand le seuil est franchi', async () => {
  const f = faux();
  let alertes = 0;
  const app = await creerApp({ depot: f.depot, jeton: JETON, alerter: async () => { alertes++; return true; } });
  for (const qui of ['a', 'b', 'c', 'd']) {
    await app.inject({ method: 'POST', url: '/interne/signalements', headers: H, payload: { type: 'souci', texte: 'menu absent', canal: 'whatsapp', identifiant: qui } });
  }
  assert.equal(alertes, 1);
  assert.equal(f.alertes(), 1);
});

test('contact invalide refusé ; identifiant jamais conservé en clair', async () => {
  const f = faux();
  const app = await creerApp({ depot: f.depot, jeton: JETON });
  const mauvais = await app.inject({ method: 'POST', url: '/interne/signalements', headers: H,
    payload: { type: 'souci', texte: 'menu absent', canal: 'whatsapp', contact: { canal: 'whatsapp', session: 's', chat: 'pas-un-chat' } } });
  assert.equal(mauvais.statusCode, 400);
  await app.inject({ method: 'POST', url: '/interne/signalements', headers: H,
    payload: { type: 'souci', texte: 'menu absent', canal: 'whatsapp', identifiant: '237699999999', contact: { canal: 'whatsapp', session: 's', chat: '237699999999@c.us' } } });
  assert.notEqual(f.entrees[0].personne, '237699999999');
  assert.equal(JSON.stringify(f.entrees[0]).includes('"identifiant"'), false);
});

test('détail : les champs internes (empreinte, contact) ne sortent jamais', async () => {
  const app = await creerApp({ depot: faux().depot, jeton: JETON });
  const r = await app.inject({ method: 'GET', url: '/interne/sujets/7', headers: H });
  assert.equal(r.statusCode, 200);
  assert.equal(r.body.includes('secret'), true, 'propriétés libres conservées pour les signalements');
  assert.equal((await app.inject({ method: 'GET', url: '/interne/sujets/8', headers: H })).statusCode, 404);
});

test('corrigé avec « prévenir » : chaque signaleur joignable reçoit le message', async () => {
  const f = faux();
  const textes: string[] = [];
  const app = await creerApp({ depot: f.depot, jeton: JETON, prevenir: async (_s, t) => { textes.push(t); return [1]; } });
  const r = await app.inject({ method: 'PATCH', url: '/interne/sujets/7', headers: H, payload: { auteur: 'admin', statut: 'corrige', prevenir: true } });
  assert.equal(r.statusCode, 200);
  assert.equal(r.json().prevenus, 1);
  assert.deepEqual(f.prevenus, [[1]]);
  assert.match(textes[0], /réglé/);
});

test('messages aux personnes : seul, ou avec d’autres signaleurs', () => {
  assert.match(merci('R-3', 'souci', 1), /Nous vous prévenons/);
  assert.match(merci('R-3', 'souci', 4), /3 autres personnes/);
  assert.match(merci('R-3', 'idee', 2), /1 autre personne la propose/);
});

test('corps vide accepté avec un type JSON ; objet piégé refusé', async () => {
  const app = await creerApp({ depot: faux().depot, jeton: JETON });
  const vide = await app.inject({ method: 'POST', url: '/interne/incidents/essai-1/retabli', headers: { ...H, 'content-type': 'application/json' }, payload: '' });
  assert.equal(vide.statusCode, 204);
  const piege = await app.inject({ method: 'POST', url: '/retours', headers: { 'content-type': 'application/json' }, payload: '{"type":"souci","texte":"abc def","__proto__":{"x":1}}' });
  assert.equal(piege.statusCode, 400);
});

test('deux personnes distinctes derrière Cloudflare : deux empreintes', async () => {
  const f = faux();
  const app = await creerApp({ depot: f.depot, jeton: JETON });
  for (const ip of ['203.0.113.1', '198.51.100.2']) {
    await app.inject({ method: 'POST', url: '/retours', headers: { 'cf-connecting-ip': ip }, payload: { type: 'souci', texte: 'panier vide' } });
  }
  assert.notEqual(f.entrees[0].personne, f.entrees[1].personne);
});

test('documentation OpenAPI générée depuis les routes', async () => {
  const app = await creerApp({ depot: faux().depot, jeton: JETON });
  await app.ready();
  const doc = app.swagger() as { openapi: string; paths: Record<string, unknown> };
  assert.match(doc.openapi, /^3\./);
  for (const chemin of ['/retours', '/interne/signalements', '/interne/sujets', '/interne/sujets/{id}', '/interne/incidents']) {
    assert.ok(doc.paths[chemin], chemin);
  }
  assert.equal(doc.paths['/sante'], undefined);
});
