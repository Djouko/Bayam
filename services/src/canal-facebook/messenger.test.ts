import { test } from 'node:test';
import assert from 'node:assert/strict';
import type { Boutique, Produit } from '../commun/commerce.ts';
import { fcfa } from '../canal-whatsapp/catalogue.ts';
import * as m from './messenger.ts';

const boutique: Boutique = {
  id: 3, nom: 'Mode Aïcha', actif: true, chat_vendeur: '237654770063@c.us', identifiants: [], base_publique: 'https://exemple.trycloudflare.com',
  rayons: Array.from({ length: 14 }, (_, i) => ({ id: 60 + i, nom: i === 0 ? 'Robes de soirée et de cérémonie' : `Rayon ${i}`, nombre: 3 })),
  promos: 2, livraison: ['Livraison en ville : 1 000 FCFA'], paiements: ['Paiement à la livraison'], conditions: 'Paiement à la livraison, en espèces ou MoMo.',
};
const article = (id: number, extra: Partial<Produit> = {}): Produit => ({
  id, nom: `Robe sirène ${id}`, prix: 18500, prix_regulier: 18500, en_stock: true, variable: false, stock: 6, options: '',
  photo: '/wp-content/uploads/robe-768x768.jpg', galerie: [], lien: `https://exemple.trycloudflare.com/b/abc${id}`, resume: 'Coupée et cousue à Douala.', ...extra,
});
const BASE = 'https://exemple.trycloudflare.com';
type Message = { text?: string; quick_replies?: unknown[]; attachment?: { type: string; payload: Record<string, unknown> & { elements?: Element[]; buttons?: Bouton[]; text?: string } } };
type Bouton = { type: string; title: string; payload?: string; url?: string };
type Element = { title: string; subtitle: string; image_url?: string; buttons: Bouton[] };
const messages = (envois: m.Envoi[]) => envois.filter((e) => e.type === 'message').map((e) => (e as { message: Message }).message);
/** Le texte numéroté (lisible partout) et les choix gardés pour comprendre la réponse « 2 ». */
const lisible = (envois: m.Envoi[]) => ({
  texte: messages(envois).filter((x) => x.text).map((x) => x.text).join('\n'),
  choix: envois.flatMap((e) => (e.type === 'choix' ? e.ids : [])),
});

/**
 * Tout envoi respecte les limites de Messenger, n'utilise ni réponses rapides ni boutons seuls (invisibles dans la
 * messagerie de Facebook) et porte un texte numéroté dont chaque ligne correspond à un choix gardé.
 */
function verifier(envois: m.Envoi[]): void {
  for (const msg of messages(envois)) {
    assert.equal(msg.quick_replies, undefined, 'réponses rapides : invisibles dans la messagerie de Facebook');
    assert.notEqual(msg.attachment?.payload.template_type, 'button', 'boutons seuls : invisibles dans la messagerie de Facebook');
    for (const e of msg.attachment?.payload.elements ?? []) {
      assert.ok([...e.title].length <= m.LIMITES.titreElement && [...e.subtitle].length <= m.LIMITES.sousTitre, e.subtitle);
      assert.ok(e.buttons.length <= m.LIMITES.boutons && e.buttons.every((b) => [...b.title].length <= m.LIMITES.titreBouton));
    }
    assert.ok((msg.attachment?.payload.elements?.length ?? 0) <= m.LIMITES.elements);
    assert.ok(!msg.text || msg.text.length <= m.LIMITES.texte);
  }
  const { texte, choix } = lisible(envois);
  assert.ok(choix.length > 0 && texte.includes(`${choix.length}. `) && !texte.includes(`${choix.length + 1}. `), texte);
}

test('menu : carrousel des rayons (Messenger) et la même chose numérotée en texte (messagerie de Facebook)', () => {
  const avecPhotos = { ...boutique, rayons: boutique.rayons.map((r, i) => (i === 0 ? { ...r, photo: '/wp-content/uploads/robe-768x768.jpg' } : r)) };
  const envois = m.menu(avecPhotos, BASE);
  verifier(envois);
  const [carrousel] = messages(envois);
  const cartes = carrousel.attachment?.payload.elements ?? [];
  assert.equal(cartes.length, 10);
  assert.equal(cartes[0].buttons[0].payload, 'bayam:rayon:0:1');
  assert.equal(cartes[1].image_url, `${BASE}/wp-content/uploads/robe-768x768.jpg`);
  assert.deepEqual(cartes.at(-1)?.buttons.map((b) => b.payload), ['bayam:livraison', 'bayam:vendeur']);
  const { texte, choix } = lisible(envois);
  assert.ok(texte.startsWith('Bonjour 👋 Bienvenue chez Mode Aïcha') && texte.includes('1. 🔥 Promos du moment (2 articles)') && texte.includes('2. Robes de soirée et de cérémonie (3 articles)'));
  assert.deepEqual([choix[0], choix[1], ...choix.slice(-2)], ['bayam:rayon:0:1', 'bayam:rayon:60:1', 'bayam:livraison', 'bayam:vendeur']);
});

test('rayon : carrousel de 10 articles et liste numérotée (prix, stock), puis la suite et les autres choix', () => {
  const page = { total: 23, produits: Array.from({ length: 10 }, (_, i) => article(i + 1, i === 0 ? { galerie: ['/a.jpg'], prix: 15000, prix_regulier: 18500, stock: 2 } : {})) };
  const envois = m.pageRayon({ id: 60, nom: 'Robes', nombre: 23 }, page, 2, BASE);
  verifier(envois);
  const [carrousel] = messages(envois);
  const elements = carrousel.attachment?.payload.elements ?? [];
  assert.equal(carrousel.attachment?.payload.image_aspect_ratio, 'square');
  assert.equal(elements.length, 10);
  assert.equal(elements[0].image_url, `${BASE}/wp-content/uploads/robe-768x768.jpg`);
  assert.deepEqual(elements[0].buttons.map((b) => b.payload), ['bayam:produit:1', 'bayam:photos:1', 'bayam:vendeur']);
  const { texte, choix } = lisible(envois);
  assert.ok(texte.includes('articles 11 à 20 sur 23') && texte.includes(`1. Robe sirène 1 : ${fcfa(15000)} au lieu de ${fcfa(18500)} (plus que 2)`));
  assert.deepEqual(choix.slice(-3), ['bayam:rayon:60:3', 'bayam:menu', 'bayam:vendeur']);
  assert.equal(choix[0], 'bayam:produit:1');
});

test('article choisi : carte « Commander » (Messenger) et, pour tous, détails, lien personnel en texte, choix suivants', () => {
  const lien = `${BASE}/commander/abcdefghijklmnopqrstuvwx`;
  const envois = m.produit(boutique, article(25, { variable: true, galerie: ['/b.jpg'] }), lien, BASE);
  verifier(envois);
  const [carte] = messages(envois);
  const [bouton, photos] = carte.attachment?.payload.elements?.[0].buttons ?? [];
  assert.deepEqual([bouton.type, bouton.url, photos.payload], ['web_url', lien, 'bayam:photos:25']);
  const { texte, choix } = lisible(envois);
  assert.ok(texte.startsWith('Robe sirène 25\n💰') && texte.includes('Coupée et cousue à Douala.') && texte.includes(`choisissez la taille`) && texte.includes(`\n${lien}\n`));
  assert.deepEqual(choix, ['bayam:photos:25', 'bayam:menu', 'bayam:vendeur']);
});

test('article épuisé ou sans lien : on le dit, et on propose le reste', () => {
  const envois = m.produit(boutique, article(25, { en_stock: false }), null, BASE);
  verifier(envois);
  assert.ok(lisible(envois).texte.includes('vient d\'être épuisé'));
  assert.deepEqual(lisible(envois).choix, ['bayam:menu', 'bayam:vendeur']);
});

test('plus de photos : chaque photo en image (affichée partout), puis de quoi commander', () => {
  const envois = m.photos(article(25, { galerie: ['/p1.jpg', '/p2.jpg'] }), BASE);
  verifier(envois);
  assert.deepEqual(messages(envois).slice(0, 2).map((x) => x.attachment?.payload.url), [`${BASE}/p1.jpg`, `${BASE}/p2.jpg`]);
  assert.equal(lisible(envois).choix[0], 'bayam:produit:25');
});

test('livraison, vendeur : texte et choix numérotés ; le vendeur est prévenu sur WhatsApp', () => {
  verifier(m.livraison(boutique));
  const envois = m.vendeur(boutique, 'Mode Aïcha Douala');
  verifier(envois);
  assert.ok(envois.some((e) => e.type === 'au_vendeur'));
});

test('message en cours de conversation : accusé qui rappelle « menu », alerte au vendeur ; « merci », « ok », émojis : rien', () => {
  assert.ok(messages([m.accuse(boutique)])[0].text?.includes('écrivez « menu »'));
  const alerte = m.alerteQuestion('Mode Aïcha Douala', 'Vous livrez à Bafoussam ?');
  assert.ok(alerte.type === 'au_vendeur' && alerte.texte.includes('« Vous livrez à Bafoussam ? »'));
  for (const t of ['Merci', 'ok', 'D\'accord !', '👍', 'Bonne nuit', 'Bonjour']) {
    assert.ok(m.banal(t), t);
  }
  for (const t of ['Vous livrez à Bafoussam ?', 'Je veux la taille L', 'merci, et pour la taille XL ?']) {
    assert.ok(!m.banal(t), t);
  }
});

test('commentaire intéressé reconnu, comme on l’écrit vraiment ; le reste ignoré', () => {
  for (const c of ['Prix ?', 'cb', 'Dispo en 42 ?', 'IB svp', 'Mp', 'je prends', 'Interested', 'How much', '2', 'le 2', 'La 3 svp', '#4', 'Vous livrez à Yaoundé', 'Taille L', 'Salut', 'Bsr', 'Bonjour la boutique']) {
    assert.ok(m.interesse(c), c);
  }
  for (const c of ['Amen', 'Paul Nkoulou', '😍😍😍', 'Très jolie ma sœur', 'Félicitations']) {
    assert.ok(!m.interesse(c), c);
  }
});

test('article désigné sous une publication de plusieurs articles : par numéro ou par nom', () => {
  const produits = [article(1, { nom: 'Robe wax courte' }), article(2, { nom: 'Sandales en cuir' }), article(3, { nom: 'Sac à main tressé' })];
  assert.equal(m.articleDesigne('Le 2 svp', produits)?.id, 2);
  assert.equal(m.articleDesigne('prix des sandales ?', produits)?.id, 2);
  assert.equal(m.articleDesigne('Je veux le sac', produits), null); // « sac » : trop court pour être sûr
  assert.equal(m.articleDesigne('Prix ?', produits), null);
  assert.equal(m.articleDesigne('Prix du 9 ?', produits), null);
  assert.equal(m.articleDesigne('Je paie 15000 pour le 1', produits)?.id, 1);
  assert.equal(m.articleDesigne('Prix ?', [produits[0]])?.id, 1);
});

test('réponse privée : texte numéroté lisible partout ; l’article, ou la liste de la publication, ou la boutique', () => {
  const robe = article(7, { nom: 'Robe wax courte', options: 'Taille : M, L' });
  const seul = m.reponsePrivee(boutique, 'Awa Ngono', [robe], robe);
  assert.equal(seul.message.attachment, undefined);
  assert.ok(String(seul.message.text).startsWith('Bonjour Awa 👋'));
  assert.ok(String(seul.message.text).includes(`Robe wax courte : ${fcfa(18500)}, en stock (Taille : M, L)`) && String(seul.message.text).includes('1. 🛒 La photo et votre lien de commande'));
  assert.deepEqual(seul.choix, ['bayam:produit:7', 'bayam:menu', 'bayam:vendeur']);
  const liste = m.reponsePrivee(boutique, 'Awa', [robe, article(8, { en_stock: false })], null);
  assert.ok(String(liste.message.text).includes(`1. Robe wax courte : ${fcfa(18500)}`) && String(liste.message.text).includes('2. Robe sirène 8 : épuisé'));
  assert.deepEqual(liste.choix.slice(0, 2), ['bayam:produit:7', 'bayam:menu']);
  const inconnue = m.reponsePrivee(boutique, '', [], null);
  assert.ok(String(inconnue.message.text).startsWith('Bonjour 👋 Merci'));
  assert.deepEqual(inconnue.choix, ['bayam:menu', 'bayam:vendeur']);
});

test('réponse publique : courte, avec le prénom, variée selon le commentaire', () => {
  const phrases = new Set(['c1', 'c2', 'c3', 'c4', 'c5', 'c6'].map((id) => m.reponsePublique('Awa Ngono', id)));
  assert.ok(phrases.size > 1);
  assert.ok([...phrases].every((p) => p.includes('Awa') && p.endsWith('📩')));
  assert.ok(!m.reponsePublique('', 'c1').includes('undefined'));
});

test('texte de publication : prix clairs, lien m.me direct vers l’article, numéros pour plusieurs articles', () => {
  const un = m.textePublication(boutique, [article(25)], '555');
  assert.ok(un.includes(`💰 ${fcfa(18500)}`) && un.includes('https://m.me/555?ref=produit-25'));
  const plusieurs = m.textePublication(boutique, [article(1), article(2, { prix: 9000, prix_regulier: 12000 })], '555');
  assert.ok(plusieurs.includes(`2. Robe sirène 2 : ${fcfa(9000)} au lieu de ${fcfa(12000)} 🔥`) && plusieurs.includes('ref=menu'));
});

test('menu permanent de Messenger : la boutique toujours à portée, titres de 30 caractères au plus', () => {
  const [menu] = m.menuPermanent() as { locale: string; call_to_actions: { title: string; payload: string }[] }[];
  assert.equal(menu.locale, 'default');
  assert.deepEqual(menu.call_to_actions.map((c) => c.payload), ['bayam:menu', 'bayam:livraison', 'bayam:vendeur']);
  assert.ok(menu.call_to_actions.every((c) => [...c.title].length <= 30));
});

test('autorisations refusées : expliquées au vendeur en mots simples', () => {
  assert.deepEqual(m.autorisationsManquantes(['pages_messaging', 'pages_read_engagement']), ['pages_read_user_content', 'pages_manage_engagement', 'pages_manage_posts']);
  assert.ok(m.AUTORISATIONS.pages_manage_posts.includes('publier'));
});
