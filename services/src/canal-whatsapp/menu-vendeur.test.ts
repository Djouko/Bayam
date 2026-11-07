import { test } from 'node:test';
import assert from 'node:assert/strict';
import type { ArticleVendeur, PageArticles } from '../commun/commerce.ts';
import { MARQUE } from '../passerelle/filtre.ts';
import { fcfa, LIMITES, type EnvoiPret } from './catalogue.ts';
import { avancer, commandeVente, menu, montant, type Etat, type Pas } from './menu-vendeur.ts';

const polo: ArticleVendeur = { id: 25, nom: 'Polo', prix: 12000, stock: 6, en_stock: true, variable: false, variantes: [] };
const sweat: ArticleVendeur = { id: 15, nom: 'Sweat zippé', prix: 18000, stock: null, en_stock: true, variable: true,
  variantes: [{ id: 151, nom: 'M', stock: 2 }, { id: 152, nom: 'L', stock: 0 }] };
const epuise: ArticleVendeur = { id: 30, nom: 'Casquette brodée', prix: 6500, stock: 0, en_stock: false, variable: false, variantes: [] };
const tous = [polo, sweat, epuise, ...Array.from({ length: 9 }, (_, i): ArticleVendeur => ({ ...polo, id: 100 + i, nom: `Article ${i + 1}` }))];

/** Faux commerce : recherche par « #id » ou par mot, huit articles par page. */
const appels: [string, number][] = [];
async function source(recherche: string, page: number): Promise<PageArticles> {
  appels.push([recherche, page]);
  const id = /^#(\d+)$/.exec(recherche)?.[1];
  const trouves = id ? tous.filter((a) => a.id === Number(id)) : tous.filter((a) => !recherche || a.nom.toLowerCase().includes(recherche.toLowerCase()));
  return { total: trouves.length, articles: trouves.slice((page - 1) * 8, page * 8) };
}

const lignes = (p: Pas) => p.envois.flatMap((e) => (e.type === 'liste' ? e.sections.flatMap((s) => s.lignes) : []));
const texte = (p: Pas) => p.envois.map((e: EnvoiPret) => (e.type === 'texte' ? e.texte : e.type === 'liste' ? e.texte : '')).join('\n');
/** Enchaîne des entrées (« 2 », « choix:… », « photo:… », texte) à partir du menu ; rend le dernier pas. */
async function parcours(...entrees: string[]): Promise<Pas> {
  let pas = menu();
  for (const e of entrees) {
    assert.ok(pas.etat, `parcours terminé avant « ${e} »`);
    pas = await avancer(pas.etat, e.startsWith('choix:') ? { type: 'choix', id: e.slice(6) } : e.startsWith('photo:') ? { type: 'photo', url: e.slice(6) } : { type: 'texte', texte: e }, source);
  }
  return pas;
}

test('menu : dix actions numérotées, titres dans les limites de WhatsApp, marque BAYAM', () => {
  const m = menu();
  const l = lignes(m);
  assert.equal(l.length, 10);
  assert.ok(l.every((x) => [...x.titre].length <= LIMITES.titreLigne && !x.titre.endsWith('…')), l.map((x) => x.titre).join(' | '));
  assert.equal(l[0].titre, '1. 📦 Ajouter du stock');
  assert.deepEqual(m.etat?.options.slice(0, 3), ['bayam:v:stock', 'bayam:v:vente', 'bayam:v:nouveau']);
  const liste = m.envois[0];
  assert.ok(liste.type === 'liste' && liste.titre.startsWith(MARQUE) && liste.texte.includes('répondez par le numéro'));
});

test('ajouter du stock : article (par numéro), quantité, commande « +5 #25 »', async () => {
  const articles = await parcours('1');
  assert.equal(lignes(articles)[0].titre, '1. Polo');
  assert.ok(lignes(articles).some((l) => l.id === 'bayam:v:page:2') && lignes(articles).length === 10);
  assert.equal(lignes(articles).find((l) => l.id === 'bayam:v:art:30')?.description, `Épuisé · ${fcfa(6500)}`);
  const fin = await parcours('1', '1', 'choix:bayam:v:q:5');
  assert.deepEqual(fin.commande, { texte: '+5 #25' });
  assert.equal(fin.etat, null);
});

test('stock d’un article à tailles : la taille d’abord ; quantité libre tapée', async () => {
  const tailles = await parcours('1', 'choix:bayam:v:art:15');
  assert.deepEqual(lignes(tailles).map((l) => [l.titre, l.description]), [['1. M', 'Stock : 2'], ['2. L', 'Stock : 0']]);
  const fin = await parcours('1', 'choix:bayam:v:art:15', '2', 'choix:bayam:v:q:autre', '12');
  assert.deepEqual(fin.commande, { texte: '+12 #15 L' });
});

test('vente au prix normal payée par MoMo', async () => {
  const fin = await parcours('2', '1', '2', 'choix:bayam:v:prix:catalogue', '2');
  assert.deepEqual(fin.commande, { texte: 'vendu #25 x2 momo' });
});

test('vente à prix négocié, à crédit, avec le nom du client', async () => {
  const prix = await parcours('2', '1', '2');
  assert.ok(lignes(prix)[0].description?.startsWith(fcfa(24000)), lignes(prix)[0].description);
  const fin = await parcours('2', '1', '2', 'choix:bayam:v:prix:autre', '22 000', 'choix:bayam:v:pay:credit', 'Paul Mbarga');
  assert.deepEqual(fin.commande, { texte: 'vendu #25 x2 22000 credit Paul Mbarga' });
});

test('vente avec acompte par OM : la commande se lit comme le Copilote l’attend', async () => {
  const fin = await parcours('2', 'choix:bayam:v:art:15', '1', '1', 'choix:bayam:v:prix:catalogue', 'choix:bayam:v:pay:acompte_om', '5k', 'Awa');
  assert.deepEqual(fin.commande, { texte: 'vendu #15 M x1 om acompte 5000 Awa' });
  assert.equal(commandeVente({ etape: 'client', options: [], article: { id: 7, nom: 'x', prix: 1, variable: true }, variante: 'Rouge, M', quantite: 1, mode: 'cash' }), 'vendu #7 Rouge M x1 cash');
});

test('vente d’un article épuisé : refusée avec la marche à suivre', async () => {
  const fin = await parcours('2', 'choix:bayam:v:art:30');
  assert.equal(fin.etat, null);
  assert.ok(texte(fin).includes('ajoutez d\'abord du stock'));
});

test('nouvel article pas à pas : photo, nom, prix, quantités ; la photo part avec la commande', async () => {
  const fin = await parcours('3', 'photo:http://waha:3000/api/files/boutique-3/abc.jpeg', 'Robe wax courte', '15 000', 'M : 3 L:2');
  assert.deepEqual(fin.commande, { texte: 'Robe wax courte 15000 M:3 L:2', photo: 'http://waha:3000/api/files/boutique-3/abc.jpeg' });
  const sansPhoto = await parcours('3', 'bonjour');
  assert.ok(texte(sansPhoto).includes('J\'attends la photo'));
  assert.ok(texte(await parcours('3', 'photo:u', 'Robe', 'quinze mille')).includes('Je n\'ai pas compris le prix'));
});

test('consultations directes : bilan d’hier, lien de la boutique, stock, commandes', async () => {
  assert.deepEqual((await parcours('5', '2')).commande, { texte: 'bilan hier' });
  assert.deepEqual((await parcours('9', '1')).commande, { texte: 'boutique' });
  assert.deepEqual((await parcours('6')).commande, { texte: 'stock' });
  assert.deepEqual((await parcours('4')).commande, { texte: 'commandes' });
});

test('publier : nouveautés partout ; un article choisi dans la story Facebook ; partage pour le profil', async () => {
  assert.deepEqual((await parcours('7', '1', 'choix:bayam:v:ou:partout')).commande, { texte: 'publier nouveautes partout' });
  assert.deepEqual((await parcours('7', '3', '1', 'choix:bayam:v:ou:story')).commande, { texte: 'publier #25 story' });
  assert.deepEqual((await parcours('7', '2', 'choix:bayam:v:ou:partager')).commande, { texte: 'partager promos' });
});

test('recherche d’un article par son nom, puis pages suivantes', async () => {
  const trouves = await parcours('1', 'choix:bayam:v:chercher', 'sweat');
  assert.deepEqual(lignes(trouves).map((l) => l.id), ['bayam:v:art:15', 'bayam:v:chercher']);
  const page2 = await parcours('1', 'choix:bayam:v:page:2');
  assert.ok(lignes(page2).some((l) => l.titre.endsWith('Article 9')));
});

test('annuler, mauvais numéro, texte au lieu d’un choix : on le dit, sans rien exécuter', async () => {
  const annule = await parcours('2', 'annuler');
  assert.equal(annule.etat, null);
  assert.ok(!annule.commande);
  const horsListe = await parcours('15');
  assert.ok(texte(horsListe).includes('entre 1 et 10') && horsListe.etat?.etape === 'menu');
  const autre = await parcours('ma note perso');
  assert.ok(texte(autre).includes('Touchez *Choisir*') && !autre.commande);
});

test('choix d’une ancienne liste (parcours expiré) : le menu revient, rien n’est vendu ni publié au hasard', async () => {
  const vide: Etat = { etape: 'menu', options: [] };
  for (const id of ['bayam:v:q:5', 'bayam:v:pay:momo', 'bayam:v:ou:partout', 'bayam:v:art:25']) {
    const p = await avancer(vide, { type: 'choix', id }, source);
    assert.ok(!p.commande && texte(p).includes('ancienne liste'), id);
  }
});

test('montants écrits comme au Cameroun', () => {
  assert.deepEqual(['15000', '15 000', '15.000 F', '15k', '15 mille', '1 500 000', 'abc', '0', '15 000 000 000'].map(montant), [15000, 15000, 15000, 15000, 15000, 1_500_000, null, null, null]);
});
