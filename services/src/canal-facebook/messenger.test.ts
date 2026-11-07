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
type Message = { text?: string; quick_replies?: { title: string; payload: string }[]; attachment?: { type: string; payload: Record<string, unknown> & { elements?: Element[]; buttons?: Bouton[]; text?: string } } };
type Bouton = { type: string; title: string; payload?: string; url?: string };
type Element = { title: string; subtitle: string; image_url?: string; buttons: Bouton[] };
const messages = (envois: m.Envoi[]) => envois.filter((e) => e.type === 'message').map((e) => (e as { message: Message }).message);

/** Tout message respecte les limites de Messenger (sinon Meta le refuse entier). */
function verifier(envois: m.Envoi[]): void {
  for (const msg of messages(envois)) {
    assert.ok(!msg.quick_replies || msg.quick_replies.length <= m.LIMITES.reponsesRapides);
    for (const r of msg.quick_replies ?? []) {
      assert.ok([...r.title].length <= m.LIMITES.titreReponse, r.title);
    }
    for (const e of msg.attachment?.payload.elements ?? []) {
      assert.ok([...e.title].length <= m.LIMITES.titreElement && [...e.subtitle].length <= m.LIMITES.sousTitre, e.subtitle);
      assert.ok(e.buttons.length <= m.LIMITES.boutons && e.buttons.every((b) => [...b.title].length <= m.LIMITES.titreBouton));
    }
    assert.ok((msg.attachment?.payload.elements?.length ?? 0) <= m.LIMITES.elements);
    assert.ok(!msg.text || msg.text.length <= m.LIMITES.texte);
  }
}

test('menu : promos d’abord, rayons, puis livraison et vendeur ; treize réponses rapides au plus', () => {
  const envois = m.menu(boutique);
  verifier(envois);
  const [menu] = messages(envois);
  assert.equal(menu.quick_replies?.length, 13);
  assert.equal(menu.quick_replies?.[0].payload, 'bayam:rayon:0:1');
  assert.deepEqual(menu.quick_replies?.slice(-2).map((r) => r.payload), ['bayam:livraison', 'bayam:vendeur']);
  assert.ok(menu.quick_replies?.[1].title.endsWith('…'));
});

test('rayon : carrousel de 10 articles au plus (photo carrée en adresse publique, prix, boutons), puis la suite', () => {
  const page = { total: 23, produits: Array.from({ length: 10 }, (_, i) => article(i + 1, i === 0 ? { galerie: ['/a.jpg'], prix: 15000, prix_regulier: 18500, stock: 2 } : {})) };
  const envois = m.pageRayon({ id: 60, nom: 'Robes', nombre: 23 }, page, 2, BASE);
  verifier(envois);
  const [carrousel, suite] = messages(envois);
  const elements = carrousel.attachment?.payload.elements ?? [];
  assert.equal(carrousel.attachment?.payload.image_aspect_ratio, 'square');
  assert.equal(elements.length, 10);
  assert.equal(elements[0].image_url, `${BASE}/wp-content/uploads/robe-768x768.jpg`);
  assert.ok(elements[0].subtitle.startsWith(`${fcfa(15000)} au lieu de ${fcfa(18500)}`) && elements[0].subtitle.includes('Plus que 2'));
  assert.deepEqual(elements[0].buttons.map((b) => b.payload), ['bayam:produit:1', 'bayam:photos:1', 'bayam:vendeur']);
  assert.deepEqual(elements[1].buttons.map((b) => b.payload), ['bayam:produit:2', 'bayam:vendeur']);
  assert.ok(suite.text?.includes('articles 11 à 20 sur 23'));
  assert.equal(suite.quick_replies?.[0].payload, 'bayam:rayon:60:3');
});

test('article choisi : bouton « Commander » vers le lien personnel, puis description et consigne', () => {
  const envois = m.produit(boutique, article(25, { variable: true, galerie: ['/b.jpg'] }), `${BASE}/commander/abcdefghijklmnopqrstuvwx`, BASE);
  verifier(envois);
  const [carte, consigne] = messages(envois);
  const [bouton, photos] = carte.attachment?.payload.elements?.[0].buttons ?? [];
  assert.deepEqual([bouton.type, bouton.url], ['web_url', `${BASE}/commander/abcdefghijklmnopqrstuvwx`]);
  assert.equal(photos.payload, 'bayam:photos:25');
  assert.ok(consigne.text?.startsWith('Coupée et cousue à Douala.') && consigne.text.includes('choisissez la taille'));
});

test('article épuisé ou sans lien : on le dit, et on propose le reste', () => {
  const [msg] = messages(m.produit(boutique, article(25, { en_stock: false }), null, BASE));
  assert.ok(msg.text?.includes('vient d\'être épuisé'));
  assert.equal(msg.quick_replies?.[0].payload, 'bayam:menu');
});

test('plus de photos : chaque photo de la galerie en image, puis de quoi commander', () => {
  const envois = m.photos(article(25, { galerie: ['/p1.jpg', '/p2.jpg'] }), BASE);
  const msgs = messages(envois);
  assert.deepEqual(msgs.slice(0, 2).map((x) => x.attachment?.payload.url), [`${BASE}/p1.jpg`, `${BASE}/p2.jpg`]);
  assert.equal(msgs[2].quick_replies?.[0].payload, 'bayam:produit:25');
});

test('message libre : accusé au client avec les rayons, alerte au vendeur sur WhatsApp', () => {
  const envois = m.question(boutique, 'Mode Aïcha Douala', 'Vous livrez à Bafoussam ?');
  assert.equal(envois.length, 2);
  assert.ok(messages(envois)[0].quick_replies?.length);
  assert.ok(envois[1].type === 'au_vendeur' && envois[1].texte.includes('« Vous livrez à Bafoussam ? »'));
});

test('commentaire intéressé reconnu, comme on l’écrit vraiment ; le reste ignoré', () => {
  for (const c of ['Prix ?', 'cb', 'Dispo en 42 ?', 'IB svp', 'Mp', 'je prends', 'Interested', 'How much', '2', 'Vous livrez à Yaoundé', 'Taille L']) {
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

test('réponse privée : article et prix avec boutons ; liste pour plusieurs ; boutique quand la publication est inconnue', () => {
  const robe = article(7, { nom: 'Robe wax courte', options: 'Taille : M, L' });
  const seul = m.reponsePrivee(boutique, 'Awa Ngono', [robe], robe, 4) as Message;
  assert.equal(seul.attachment?.payload.template_type, 'button');
  assert.ok(seul.attachment?.payload.text?.startsWith('Bonjour Awa 👋'));
  assert.ok(seul.attachment?.payload.text?.includes(`Robe wax courte : ${fcfa(18500)}, en stock (Taille : M, L)`));
  assert.equal(seul.attachment?.payload.buttons?.[0].payload, 'bayam:produit:7');
  const liste = m.reponsePrivee(boutique, 'Awa', [robe, article(8, { en_stock: false })], null, 4) as Message;
  assert.ok(liste.attachment?.payload.text?.includes('2. Robe sirène 8 : épuisé'));
  assert.equal(liste.attachment?.payload.buttons?.[0].payload, 'bayam:publication:4');
  const inconnue = m.reponsePrivee(boutique, '', [], null, null) as Message;
  assert.ok(inconnue.attachment?.payload.text?.startsWith('Bonjour 👋 Merci'));
  assert.equal(inconnue.attachment?.payload.buttons?.[0].payload, 'bayam:menu');
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

test('accueil Messenger : prénom du client placé par Messenger, 160 caractères au plus', () => {
  const s = m.salutation({ ...boutique, nom: 'B'.repeat(200) });
  assert.ok(s.includes('{{user_first_name}}') && s.length <= 160, String(s.length));
});
