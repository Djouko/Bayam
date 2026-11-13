import assert from 'node:assert/strict';
import { test } from 'node:test';

import { categoriser, graviter, niveauAlerte, normaliser, rattacher, score, similarite, trigrammes } from './classement.ts';

test('normaliser : accents, ponctuation et casse retirés', () => {
  assert.equal(normaliser("  Rien NE s'affiche, plus de Menu !! "), 'rien ne s affiche plus de menu');
});

test('catégories : mots du quotidien', () => {
  assert.equal(categoriser("J'ai payé par MoMo mais la commande n'est pas validée"), 'paiement');
  assert.equal(categoriser('Le livreur n’est jamais venu'), 'livraison');
  assert.equal(categoriser("Quelqu'un utilise un faux compte avec mon nom, c'est une arnaque"), 'securite');
  assert.equal(categoriser("Je n'arrive pas à me connecter, mot de passe refusé"), 'connexion');
  assert.equal(categoriser("Quand j'écris bayam sur WhatsApp plus rien ne s'affiche"), 'whatsapp');
  assert.equal(categoriser("L'application plante à l'ouverture"), 'application');
  assert.equal(categoriser('Ajoutez un mode sombre'), 'autre');
});

test('gravité : argent perdu critique, blocage haut, idée basse', () => {
  assert.equal(graviter('souci', 'paiement', 'Mon compte MoMo a été débité deux fois'), 4);
  assert.equal(graviter('souci', 'securite', 'arnaque'), 4);
  assert.equal(graviter('souci', 'whatsapp', "plus rien ne s'affiche quand j'écris bayam"), 3);
  assert.equal(graviter('souci', 'commande', 'Le prix affiché est faux'), 2);
  assert.equal(graviter('idee', 'application', 'Ajoutez un mode sombre'), 1);
  assert.equal(graviter('idee', 'paiement', "j'ai été débité sans recevoir"), 4);
});

test('similarité : deux formulations du même souci se ressemblent, deux soucis différents non', () => {
  const a = trigrammes("Le menu ne s'affiche plus quand j'écris bayam");
  const b = trigrammes('quand on écrit bayam le menu ne s affiche pas');
  const c = trigrammes('Le livreur est arrivé en retard à Bonamoussadi');
  assert.ok(similarite(a, b) >= 0.3, `proche : ${similarite(a, b)}`);
  assert.ok(similarite(a, c) < 0.1, `éloigné : ${similarite(a, c)}`);
  assert.equal(similarite(new Set(), a), 0);
});

test('rattachement : doublon dans la même catégorie, rafale sur la même fonction, rien sinon', () => {
  const maintenant = Date.parse('2026-11-13T10:00:00Z');
  const candidats = [
    { id: 1, categorie: 'whatsapp' as const, fonction: 'whatsapp.catalogue', texte: "Le menu ne s'affiche plus quand j'écris bayam", dernier: new Date(maintenant - 600_000) },
    { id: 2, categorie: 'livraison' as const, fonction: null, texte: 'Livreur en retard', dernier: new Date(maintenant - 600_000) },
  ];
  assert.equal(rattacher({ categorie: 'whatsapp', fonction: null, texte: 'quand on écrit bayam le menu ne s affiche pas' }, candidats, maintenant), 1);
  assert.equal(rattacher({ categorie: 'whatsapp', fonction: 'whatsapp.catalogue', texte: 'whatsapp ne répond plus du tout depuis ce matin au menu' }, candidats, maintenant), 1);
  assert.equal(rattacher({ categorie: 'whatsapp', fonction: null, texte: 'Je voudrais publier dans plusieurs groupes' }, candidats, maintenant), null);
  assert.equal(rattacher({ categorie: 'paiement', fonction: null, texte: "Le menu ne s'affiche plus quand j'écris bayam" }, candidats, maintenant), null);
});

test('score : plus grave, plus de personnes, plus récent passe devant ; un sujet clos vaut 0', () => {
  const t = Date.parse('2026-11-13T10:00:00Z');
  const recent = new Date(t - 60_000);
  const critique = score({ gravite: 4, signaleurs: 1, dernier: recent, statut: 'nouveau' }, t);
  const bloquant5 = score({ gravite: 3, signaleurs: 5, dernier: recent, statut: 'nouveau' }, t);
  const bloquant1 = score({ gravite: 3, signaleurs: 1, dernier: recent, statut: 'nouveau' }, t);
  const ancien = score({ gravite: 3, signaleurs: 1, dernier: new Date(t - 10 * 86_400_000), statut: 'nouveau' }, t);
  assert.ok(bloquant5 > critique && critique > bloquant1 && bloquant1 > ancien);
  assert.equal(score({ gravite: 4, signaleurs: 9, dernier: recent, statut: 'corrige' }, t), 0);
});

test('alerte : critique et panne tout de suite, trois personnes ensuite', () => {
  assert.equal(niveauAlerte({ gravite: 4, signaleurs: 1, type: 'souci' }), 2);
  assert.equal(niveauAlerte({ gravite: 2, signaleurs: 1, type: 'incident' }), 2);
  assert.equal(niveauAlerte({ gravite: 3, signaleurs: 3, type: 'souci' }), 1);
  assert.equal(niveauAlerte({ gravite: 3, signaleurs: 2, type: 'souci' }), 0);
});
