import { test } from 'node:test';
import assert from 'node:assert/strict';
import { appel, demandeMenu } from './appel.ts';

test('« bayam » reconnu malgré la casse, les accents et les fautes de frappe', () => {
  for (const texte of ['bayam', 'Bayam robe', 'BAYAM, robe', '@bayam robe', '/bayam robe', 'Bayám robe', 'bayan robe', 'baym robe', 'byaam robe', 'bayamm robe']) {
    assert.notEqual(appel(texte), null, texte);
  }
  assert.equal(appel('Bayam,   robe rouge  '), 'robe rouge');
  assert.equal(appel('bayam'), '');
});

test('mots ordinaires et « bayam » au milieu d\'une phrase : pas un appel', () => {
  for (const texte of ['Bonjour', 'bayam-sellam du marché', 'les bayams sellams', 'je cherche bayam', 'banane', 'robe', '']) {
    assert.equal(appel(texte), null, texte);
  }
});

test('demande de menu : « bayam » seul, « bayam menu », « #catalogue »', () => {
  for (const texte of ['bayam', 'Bayam menu', 'bayam catalogue', 'BAYAM Rayons', 'bayan boutique', 'bayam catégories', '#', '# menu', '#Catalogue', 'bayam menu !']) {
    assert.ok(demandeMenu(texte), texte);
  }
});

test('recherche précise ou conversation : pas une demande de menu', () => {
  for (const texte of ['bayam robe rouge', '#robe', 'Bonjour, vous avez le menu ?', 'menu', 'bayam stock polo']) {
    assert.ok(!demandeMenu(texte), texte);
  }
});
