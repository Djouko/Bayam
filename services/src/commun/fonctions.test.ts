import { test } from 'node:test';
import assert from 'node:assert/strict';
import { etatDepuis, messageFonction } from './fonctions.ts';

const MAINTENANT = Date.parse('2026-11-09T10:00:00Z');

test('aucune consigne : la fonction est active', () => {
  assert.equal(etatDepuis({}, 'facebook.messenger', 3, MAINTENANT).etat, 'actif');
});

test('maintenance d’une fonction : elle seule est touchée', () => {
  const r = { 'facebook.commentaires': { etat: 'maintenance', message: 'Réponses aux commentaires en pause jusqu’à 11 h.' } };
  assert.equal(etatDepuis(r, 'facebook.commentaires', 3, MAINTENANT).message, 'Réponses aux commentaires en pause jusqu’à 11 h.');
  assert.equal(etatDepuis(r, 'facebook.messenger', 3, MAINTENANT).etat, 'actif');
});

test('préfixe : « facebook » en maintenance coupe toutes les fonctions Facebook, pas WhatsApp', () => {
  const r = { facebook: { etat: 'maintenance' } };
  assert.equal(etatDepuis(r, 'facebook.publications', 3, MAINTENANT).etat, 'maintenance');
  assert.equal(etatDepuis(r, 'whatsapp.catalogue', 3, MAINTENANT).etat, 'actif');
});

test('l’arrêt l’emporte sur la maintenance ; heure de fin passée : rouverte toute seule', () => {
  const r = { facebook: { etat: 'maintenance' }, 'facebook.messenger': { etat: 'arret' }, 'whatsapp.menu': { etat: 'maintenance', jusqua: '2026-11-09T09:30:00Z' } };
  assert.equal(etatDepuis(r, 'facebook.messenger', 3, MAINTENANT).etat, 'arret');
  assert.equal(etatDepuis(r, 'whatsapp.menu', 3, MAINTENANT).etat, 'actif');
});

test('testeurs de l’équipe : gardent l’accès pendant la maintenance, pour vérifier la correction', () => {
  const r = { 'whatsapp.copilote': { etat: 'maintenance', testeurs: [4] } };
  assert.equal(etatDepuis(r, 'whatsapp.copilote', 4, MAINTENANT).etat, 'actif');
  assert.equal(etatDepuis(r, 'whatsapp.copilote', 3, MAINTENANT).etat, 'maintenance');
});

test('message à l’utilisateur : celui de l’équipe, sinon un message simple avec l’heure de retour (heure de Douala)', () => {
  assert.equal(messageFonction({ etat: 'maintenance', message: 'Pause', jusqua: null }), 'Pause');
  const m = messageFonction({ etat: 'maintenance', message: '', jusqua: '2026-11-09T14:30:00Z' });
  assert.ok(m.includes('maintenance') && m.includes('15:30') && m.includes('Le reste de la boutique marche normalement'), m);
});
