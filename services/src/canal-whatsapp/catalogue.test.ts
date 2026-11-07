import { test } from 'node:test';
import assert from 'node:assert/strict';
import { MARQUE } from '../passerelle/filtre.ts';
import { commandes, couper, fcfa, LIMITES, livraison, menu, numeroLisible, pageRayon, peuDeStock, prixTexte, produit, vendeur, type Envoi } from './catalogue.ts';
import type { Boutique, Produit } from '../commun/commerce.ts';

const boutique: Boutique = {
  id: 3, nom: 'Mode Aïcha', actif: true, chat_vendeur: '237654770063@c.us', identifiants: ['237654770063@c.us'], base_publique: 'https://exemple.trycloudflare.com',
  rayons: [{ id: 60, nom: 'Robes', nombre: 4 }, { id: 61, nom: 'Pagnes et tissus', nombre: 2 }, { id: 49, nom: 'T-shirts et polos', nombre: 3 }],
  promos: 1, livraison: ['Livraison en ville : 1 000 FCFA', 'Retrait en boutique : gratuit'], paiements: ['Paiement à la livraison'],
  conditions: 'Acompte de 50 % par MTN MoMo au 6 54 77 00 63 (Aïcha Mbarga) avant l\'envoi, le reste à la livraison.',
};
const article = (id: number, extra: Partial<Produit> = {}): Produit => ({
  id, nom: `Robe sirène ${id}`, prix: 18500, prix_regulier: 18500, en_stock: true, variable: false, stock: 6, options: '',
  photo: '/wp-content/uploads/robe-768x768.jpg', galerie: [], lien: `https://exemple.trycloudflare.com/b/abc${id}`, resume: 'Coupée et cousue à Douala.', ...extra,
});

/** Toute liste respecte les limites de WhatsApp et porte la marque BAYAM en tête. */
function verifierListes(envois: Envoi[]): void {
  for (const e of envois) {
    if (e.type !== 'liste') continue;
    assert.ok(e.titre.startsWith(MARQUE) && e.texte.startsWith(MARQUE));
    assert.ok([...e.bouton].length <= LIMITES.bouton);
    const lignes = e.sections.flatMap((s) => s.lignes);
    assert.ok(lignes.length <= LIMITES.lignes, `${lignes.length} lignes`);
    for (const l of lignes) {
      assert.ok([...l.titre].length <= LIMITES.titreLigne, l.titre);
      assert.ok(!l.description || [...l.description].length <= LIMITES.descriptionLigne, l.description);
    }
  }
}

test('prix écrits comme dans le reste de BAYAM', () => {
  assert.equal(fcfa(12000), '12 000 FCFA');
  assert.equal(fcfa(500), '500 FCFA');
  assert.equal(prixTexte(article(1, { prix: 12500, prix_regulier: 14000 })), `${fcfa(12500)} au lieu de ${fcfa(14000)}`);
  assert.equal(prixTexte(article(1, { variable: true, prix: 9000, prix_regulier: 9000 })), `dès ${fcfa(9000)}`);
  assert.equal(prixTexte(article(1, { variable: true, prix: 12500, prix_regulier: 14000 })), `dès ${fcfa(12500)} au lieu de ${fcfa(14000)}`);
  assert.equal(numeroLisible('237681010044'), '6 81 01 00 44');
});

test('peu de stock signalé de 1 à 3 pièces seulement', () => {
  assert.equal(peuDeStock(article(1, { stock: 2 })), 2);
  assert.equal(peuDeStock(article(1, { stock: 6 })), null);
  assert.equal(peuDeStock(article(1, { stock: null })), null);
  assert.equal(peuDeStock(article(1, { stock: 0 })), null);
});

test('coupe proprement, sans casser un emoji', () => {
  assert.equal(couper('Sweats à capuche brodés main', 24), 'Sweats à capuche brodés…');
  assert.equal([...couper('👗'.repeat(30), 24)].length, 24);
});

test('menu : promos en tête, rayons avec leur nombre d\'articles, puis Mes commandes et Livraison', () => {
  const [liste] = menu(boutique);
  verifierListes([liste]);
  assert.ok(liste.type === 'liste');
  assert.deepEqual(liste.sections.map((s) => s.lignes.length), [4, 2]);
  assert.equal(liste.sections[0].lignes[0].id, 'bayam:rayon:0:1');
  assert.equal(menu({ ...boutique, promos: 0 })[0].type === 'liste' && (menu({ ...boutique, promos: 0 })[0] as { sections: { lignes: unknown[] }[] }).sections[0].lignes.length, 3);
});

test('menu : au plus 10 lignes même avec beaucoup de rayons', () => {
  verifierListes(menu({ ...boutique, rayons: Array.from({ length: 25 }, (_, i) => ({ id: i + 1, nom: `Rayon numéro ${i + 1} au nom très long`, nombre: i + 1 })) }));
});

test('boutique sans article : message simple, pas de liste vide', () => {
  assert.equal(menu({ ...boutique, rayons: [] })[0].type, 'texte');
});

test('page de rayon : une seule image vitrine puis la liste pour choisir (2 messages, pas 5)', () => {
  const envois = pageRayon(boutique, boutique.rayons[0], { total: 6, produits: [article(1), article(2), article(3), article(4)] }, 1);
  verifierListes(envois);
  assert.deepEqual(envois.map((e) => e.type), ['vitrine', 'liste']);
  const [vitrine, liste] = envois;
  assert.ok(vitrine.type === 'vitrine' && vitrine.produits.length === 4 && vitrine.premier === 1 && vitrine.legende.startsWith(MARQUE));
  assert.ok(liste.type === 'liste');
  assert.deepEqual(liste.sections[0].lignes.map((l) => l.id), ['bayam:produit:1', 'bayam:produit:2', 'bayam:produit:3', 'bayam:produit:4']);
  assert.ok(liste.sections[0].lignes[0].titre.startsWith('1. '));
  assert.equal(liste.sections[1].lignes[0].id, 'bayam:rayon:60:2');
});

test('deuxième page : numérotation qui continue, pas de « Voir la suite » à la fin', () => {
  const envois = pageRayon(boutique, boutique.rayons[0], { total: 6, produits: [article(5), article(6)] }, 2);
  const [vitrine, liste] = envois;
  assert.ok(vitrine.type === 'vitrine' && vitrine.premier === 5 && vitrine.legende.includes('articles 5 à 6 sur 6'));
  assert.ok(liste.type === 'liste' && liste.sections[0].lignes[0].titre.startsWith('5. '));
  assert.ok(liste.type === 'liste' && !liste.sections[1].lignes.some((l) => l.titre === 'Voir la suite'));
});

test('rayon vide : message simple', () => {
  assert.equal(pageRayon(boutique, boutique.rayons[0], { total: 0, produits: [] }, 1)[0].type, 'texte');
});

test('article choisi : photo, prix, stock, tailles et lien personnel dans un seul message', () => {
  const [m] = produit(boutique, article(7, { stock: 2, options: 'Taille : S, M, XL', variable: true }), 'https://exemple.trycloudflare.com/commander/jeton');
  assert.ok(m.type === 'photo' && m.chemin.endsWith('768x768.jpg'));
  assert.ok(m.type === 'photo' && m.legende.startsWith(MARQUE));
  for (const attendu of ['*Robe sirène 7*', 'dès', 'plus que 2', 'Taille : S, M, XL', 'choisissez la taille', 'valable 24 h', '/commander/jeton']) {
    assert.ok(m.type === 'photo' && m.legende.includes(attendu), attendu);
  }
});

test('article avec plusieurs photos : la première avec les détails, les autres à la suite sans texte', () => {
  const envois = produit(boutique, article(10, { galerie: ['/wp-content/uploads/robe-2.jpg', '/wp-content/uploads/robe-3.jpg'] }), 'https://x/commander/j');
  assert.deepEqual(envois.map((e) => e.type), ['photo', 'photo', 'photo']);
  assert.ok(envois[0].type === 'photo' && envois[0].legende.includes('/commander/j'));
  assert.ok(envois[1].type === 'photo' && envois[1].legende === MARQUE && envois[2].type === 'photo' && envois[2].legende === MARQUE);
});

test('article sans photo : même texte ; épuisé ou disparu : message clair', () => {
  assert.equal(produit(boutique, article(8, { photo: null }), 'https://x/commander/j')[0].type, 'texte');
  assert.equal(produit(boutique, article(9, { en_stock: false }), 'https://x')[0].type, 'texte');
  assert.equal(produit(boutique, null, null)[0].type, 'texte');
});

test('mes commandes : liste, aucune commande, numéro illisible', () => {
  const [liste] = commandes(boutique, [{ numero: '412', total: 13000, statut: 'En cours', date: '05/11/2026' }]);
  assert.ok(liste.type === 'texte' && liste.texte.includes('N° 412') && liste.texte.includes('En cours'));
  assert.equal(commandes(boutique, [])[0].type, 'texte');
  assert.equal(commandes(boutique, null)[0].type, 'texte');
});

test('livraison et paiement : ce qui est réellement réglé, sinon « à convenir »', () => {
  const [m] = livraison(boutique);
  assert.ok(m.type === 'texte' && m.texte.includes('Livraison en ville') && m.texte.includes('Paiement à la livraison') && m.texte.includes('Acompte de 50 %'));
  const [vide] = livraison({ ...boutique, livraison: [], paiements: [] });
  assert.ok(vide.type === 'texte' && vide.texte.includes('À convenir'));
});

test('parler au vendeur : réponse au client et alerte au vendeur avec le lien de la discussion', () => {
  const [client, alerte] = vendeur(boutique, 'Kekottech', '237681010044');
  assert.equal(client.type, 'texte');
  assert.ok(alerte.type === 'au_vendeur' && alerte.texte.startsWith(MARQUE) && alerte.texte.includes('https://wa.me/237681010044') && alerte.texte.includes('6 81 01 00 44'));
});
