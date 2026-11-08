import { test } from 'node:test';
import assert from 'node:assert/strict';
import type { EvenementFacebook, Messagerie } from '../passerelle/facebook.ts';
import { actionDuLien, decider, FRAICHEUR, lireActionFacebook } from './routeur.ts';

const MAINTENANT = 1_762_500_000;
const message = (donnees: Messagerie, age = 5): EvenementFacebook => ({ id: 'm.1', page: '555', type: 'messagerie', horodatage: MAINTENANT - age, donnees: { sender: { id: '7001' }, recipient: { id: '555' }, ...donnees } });
const action = (e: EvenementFacebook) => {
  const d = decider(e, MAINTENANT);
  return d.route === 'client' ? [d.action, d.libre] : d.route;
};

test('boutons et réponses rapides : l’action demandée, jamais « libre »', () => {
  assert.deepEqual(action(message({ postback: { payload: 'bayam:rayon:60:2' } })), [{ type: 'rayon', rayon: 60, page: 2 }, false]);
  assert.deepEqual(action(message({ postback: { payload: 'bayam:photos:25' } })), [{ type: 'photos', produit: 25 }, false]);
  assert.deepEqual(action(message({ postback: { payload: 'bayam:publication:4' } })), [{ type: 'publication', publication: 4 }, false]);
  assert.deepEqual(action(message({ message: { mid: 'm', text: 'Robes', quick_reply: { payload: 'bayam:produit:9' } } })), [{ type: 'produit', produit: 9 }, false]);
  assert.equal(action(message({ postback: { payload: 'autre:chose' } })), 'ignore');
});

test('lien m.me partagé dans une publication : l’article directement, aussi au premier contact (bouton Démarrer)', () => {
  assert.deepEqual(action(message({ referral: { ref: 'produit-25', source: 'SHORTLINK' } })), [{ type: 'produit', produit: 25 }, false]);
  assert.deepEqual(action(message({ postback: { payload: 'bayam:menu', referral: { ref: 'rayon-60' } } })), [{ type: 'rayon', rayon: 60, page: 1 }, false]);
  assert.deepEqual(actionDuLien('menu'), { type: 'menu' });
  assert.deepEqual(actionDuLien('n-importe-quoi'), { type: 'menu' });
});

test('demande de la boutique tapée (« bayam », « menu », « catalogue ») : le menu ; numéro : réponse à la dernière liste ; le reste : message libre', () => {
  for (const t of ['bayam', 'Menu', 'catalogue ?', 'Vous avez quoi ?', 'BAYAM menu']) {
    assert.deepEqual(action(message({ message: { mid: 'm', text: t } })), [{ type: 'menu' }, true], t);
  }
  for (const [t, n] of [['2', 2], ['le 3', 3], ['N°4', 4], ['#1', 1], ['12.', 12]] as const) {
    assert.deepEqual(action(message({ message: { mid: 'm', text: t } })), [{ type: 'numero', numero: n }, true], t);
  }
  // Une salutation n'ouvre pas le menu à elle seule : le service le montre au premier message ou après plusieurs jours.
  assert.deepEqual(action(message({ message: { mid: 'm', text: 'Bonsoir' } })), [{ type: 'question', extrait: 'Bonsoir' }, true]);
  assert.deepEqual(action(message({ message: { mid: 'm', text: 'Vous livrez à Kribi ?' } })), [{ type: 'question', extrait: 'Vous livrez à Kribi ?' }, true]);
  assert.deepEqual(action(message({ message: { mid: 'm', attachments: [{ type: 'image' }] } })), [{ type: 'question', extrait: '📷 (photo ou fichier envoyé)' }, true]);
});

test('le vendeur répond lui-même (écho sans marque BAYAM) : reprise de la discussion de ce client', () => {
  const d = decider(message({ sender: { id: '555' }, recipient: { id: '7001' }, message: { mid: 'e', is_echo: true, text: 'Je vous appelle' } }), MAINTENANT);
  assert.deepEqual(d, { route: 'reprise', page: '555', client: '7001' });
});

test('plus de 24 h : Meta n’autorise plus la réponse, rien n’est tenté', () => {
  assert.equal(action(message({ message: { mid: 'm', text: 'Bonjour' } }, FRAICHEUR + 1)), 'ignore');
});

test('commentaires : intérêt d’achat -> réponse ; simple mention d’un ami -> rien', () => {
  const commentaire = (texte: string): EvenementFacebook => ({ id: 'c', page: '555', type: 'commentaire', horodatage: MAINTENANT, donnees: { comment_id: 'c1', post_id: '555_9', message: texte, from: { id: '7001', name: 'Awa' } } });
  assert.equal(decider(commentaire('Prix ?'), MAINTENANT).route, 'commentaire');
  assert.equal(decider(commentaire('Paul Nkoulou'), MAINTENANT).route, 'ignore');
});

test('identifiants d’actions : ceux de WhatsApp restent valables', () => {
  assert.deepEqual(lireActionFacebook('bayam:commander:5'), { type: 'produit', produit: 5 });
  assert.equal(lireActionFacebook('bayam:photos:x'), null);
});

test('référence d’article copiée d’une publication (« réf. P25 ») : l’article directement', () => {
  assert.deepEqual(action(message({ message: { mid: 'm', text: 'Bonjour ! Je veux commander : Polo (réf. P25)' } })), [{ type: 'produit', produit: 25 }, true]);
});
