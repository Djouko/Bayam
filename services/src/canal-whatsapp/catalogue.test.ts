import { test } from 'node:test';
import assert from 'node:assert/strict';
import { MARQUE } from '../passerelle/filtre.ts';
import { commander, commandes, couper, fcfa, LIMITES, livraison, menu, numeroLisible, pageRayon, prixTexte, vendeur, type Envoi } from './catalogue.ts';
import type { Boutique, Produit } from './commerce.ts';

const boutique: Boutique = {
  id: 3, nom: 'Mode Aïcha', actif: true, chat_vendeur: '237654770063@c.us', identifiants: ['237654770063@c.us'], base_publique: 'https://exemple.trycloudflare.com',
  rayons: [{ id: 49, nom: 'T-shirts', nombre: 5 }, { id: 50, nom: 'Sweats à capuche', nombre: 3 }, { id: 52, nom: 'Accessoires', nombre: 1 }],
  livraison: ['Livraison en ville : 1 000 FCFA', 'Retrait en boutique : gratuit'], paiements: ['Paiement à la livraison'],
};
const produit = (id: number, extra: Partial<Produit> = {}): Produit => ({
  id, nom: `Polo ${id}`, prix: 12000, prix_regulier: 12000, en_stock: true, variable: false, image: '/wp-content/uploads/polo.jpg', lien: `https://exemple.trycloudflare.com/b/abc${id}`, resume: '', ...extra,
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
      assert.ok(l.id.length <= 200);
    }
  }
}

test('prix écrits comme dans le reste de BAYAM', () => {
  assert.equal(fcfa(12000), '12 000 FCFA');
  assert.equal(fcfa(500), '500 FCFA');
  assert.equal(prixTexte(produit(1, { prix: 10800, prix_regulier: 12000 })), `${fcfa(10800)} au lieu de ${fcfa(12000)}`);
  assert.equal(prixTexte(produit(1, { variable: true, prix: 9000 })), `dès ${fcfa(9000)}`);
  assert.equal(numeroLisible('237681010044'), '6 81 01 00 44');
});

test('coupe proprement, sans casser un emoji', () => {
  assert.equal(couper('Sweats à capuche brodés main', 24), 'Sweats à capuche brodés…');
  assert.equal([...couper('👗'.repeat(30), 24)].length, 24);
  assert.equal(couper('Court', 24), 'Court');
});

test('menu : rayons avec le nombre d\'articles, puis Mes commandes et Livraison', () => {
  const [liste] = menu(boutique);
  verifierListes([liste]);
  assert.equal(liste.type, 'liste');
  assert.deepEqual(liste.type === 'liste' && liste.sections.map((s) => s.lignes.length), [3, 2]);
  assert.equal(liste.type === 'liste' && liste.sections[0].lignes[2].description, '1 article');
});

test('menu : au plus 10 lignes même avec beaucoup de rayons', () => {
  const grande = { ...boutique, rayons: Array.from({ length: 25 }, (_, i) => ({ id: i + 1, nom: `Rayon numéro ${i + 1} très long nom`, nombre: i + 1 })) };
  verifierListes(menu(grande));
});

test('boutique sans article : message simple, pas de liste vide', () => {
  const [m] = menu({ ...boutique, rayons: [] });
  assert.equal(m.type, 'texte');
});

test('page de rayon : une carte avec photo par article, puis le choix Commander / Voir la suite', () => {
  const envois = pageRayon(boutique, boutique.rayons[0], { total: 5, produits: [produit(1), produit(2), produit(3)] }, 1);
  verifierListes(envois);
  assert.deepEqual(envois.map((e) => e.type), ['carte', 'carte', 'carte', 'liste']);
  const liste = envois[3];
  assert.ok(liste.type === 'liste' && liste.texte.includes('articles 1 à 3 sur 5'));
  assert.ok(liste.type === 'liste' && liste.sections[1].lignes[0].id === 'bayam:rayon:49:2');
  const carte = envois[0];
  assert.ok(carte.type === 'carte' && carte.texte.startsWith(MARQUE) && carte.texte.includes(carte.url) && carte.image);
});

test('dernière page : pas de « Voir la suite »', () => {
  const envois = pageRayon(boutique, boutique.rayons[0], { total: 5, produits: [produit(4), produit(5)] }, 2);
  const liste = envois.at(-1);
  assert.ok(liste?.type === 'liste' && liste.texte.includes('articles 4 à 5 sur 5'));
  assert.ok(liste?.type === 'liste' && !liste.sections[1].lignes.some((l) => l.titre === 'Voir la suite'));
});

test('commander : lien personnel ; article épuisé ou disparu : message clair', () => {
  const [ok] = commander(boutique, produit(7), 'https://exemple.trycloudflare.com/commander/jeton');
  assert.ok(ok.type === 'carte' && ok.url.endsWith('/commander/jeton') && ok.texte.includes('valable 24 h'));
  const [variable] = commander(boutique, produit(8, { variable: true }), 'https://x/commander/j');
  assert.ok(variable.type === 'carte' && variable.texte.includes('taille ou la couleur'));
  assert.equal(commander(boutique, produit(9, { en_stock: false }), 'https://x')[0].type, 'texte');
  assert.equal(commander(boutique, null, null)[0].type, 'texte');
});

test('mes commandes : liste, aucune commande, numéro illisible', () => {
  const [liste] = commandes(boutique, [{ numero: '412', total: 13000, statut: 'En cours', date: '05/11/2026' }]);
  assert.ok(liste.type === 'texte' && liste.texte.includes('N° 412') && liste.texte.includes('En cours'));
  assert.ok(commandes(boutique, [])[0].type === 'texte');
  assert.ok(commandes(boutique, null)[0].type === 'texte');
});

test('livraison et paiement : ce qui est réellement réglé, sinon « à convenir »', () => {
  const [m] = livraison(boutique);
  assert.ok(m.type === 'texte' && m.texte.includes('Livraison en ville') && m.texte.includes('Paiement à la livraison'));
  const [vide] = livraison({ ...boutique, livraison: [], paiements: [] });
  assert.ok(vide.type === 'texte' && vide.texte.includes('À convenir'));
});

test('parler au vendeur : réponse au client et alerte au vendeur avec le lien de la discussion', () => {
  const [client, alerte] = vendeur(boutique, 'Kekottech', '237681010044');
  assert.equal(client.type, 'texte');
  assert.ok(alerte.type === 'au_vendeur' && alerte.texte.startsWith(MARQUE) && alerte.texte.includes('https://wa.me/237681010044') && alerte.texte.includes('6 81 01 00 44'));
});
