// Messages de la boutique dans Messenger, avec ce que Messenger fait de mieux : carrousel d'articles qu'on fait
// défiler (photo, prix, boutons), réponses rapides au-dessus du clavier, bouton « Commander » qui ouvre le lien
// personnel. Mêmes règles qu'en WhatsApp : prix et stock du moment, peu de messages, rien d'inutile à télécharger.
// Fonctions pures (aucun envoi ici).

import type { Boutique, PageRayon, Produit, Rayon } from '../commun/commerce.ts';
import { sansAccents } from '../canal-whatsapp/appel.ts';
import { couper, enPromo, fcfa, peuDeStock, prixTexte, PROMOS } from '../canal-whatsapp/catalogue.ts';
import { idAction } from '../canal-whatsapp/routeur.ts';

/** Objet « message » de l'API d'envoi de Messenger. */
export type MessageMessenger = Record<string, unknown>;
export type Envoi = { type: 'message'; message: MessageMessenger } | { type: 'au_vendeur'; texte: string };

/** Limites de Messenger (documentation de la plateforme). */
export const LIMITES = { reponsesRapides: 13, titreReponse: 20, elements: 10, titreElement: 80, sousTitre: 80, boutons: 3, titreBouton: 20, texteBoutons: 640, texte: 2000 };
export const PAR_PAGE = 10;

type Bouton = { type: 'postback'; title: string; payload: string } | { type: 'web_url'; title: string; url: string; webview_height_ratio: 'full' };
type ReponseRapide = { content_type: 'text'; title: string; payload: string };

const rapide = (title: string, payload: string): ReponseRapide => ({ content_type: 'text', title: couper(title, LIMITES.titreReponse), payload });
const choix = (title: string, payload: string): Bouton => ({ type: 'postback', title: couper(title, LIMITES.titreBouton), payload });
const menuRapide = rapide('🛍️ Nos rayons', idAction({ type: 'menu' }));
const vendeurRapide = rapide('🙋 Le vendeur', idAction({ type: 'vendeur' }));

export const idPhotos = (produit: number) => `bayam:photos:${produit}`;
export const idPublication = (publication: number) => `bayam:publication:${publication}`;

function texte(t: string, rapides: ReponseRapide[] = []): Envoi {
  const message: MessageMessenger = { text: couper(t, LIMITES.texte) };
  if (rapides.length) {
    message.quick_replies = rapides.slice(0, LIMITES.reponsesRapides);
  }
  return { type: 'message', message };
}

function boutons(t: string, liste: Bouton[]): MessageMessenger {
  return { attachment: { type: 'template', payload: { template_type: 'button', text: couper(t, LIMITES.texteBoutons), buttons: liste.slice(0, LIMITES.boutons) } } };
}

function carrousel(elements: Record<string, unknown>[], rapides: ReponseRapide[] = []): Envoi {
  const message: MessageMessenger = { attachment: { type: 'template', payload: { template_type: 'generic', image_aspect_ratio: 'square', elements: elements.slice(0, LIMITES.elements) } } };
  if (rapides.length) {
    message.quick_replies = rapides;
  }
  return { type: 'message', message };
}

function dispo(p: Produit): string {
  const reste = peuDeStock(p);
  return reste ? (reste === 1 ? '⚡ Dernière pièce' : `⚡ Plus que ${reste}`) : '✅ En stock';
}

const image = (base: string, chemin: string | null) => (chemin ? { image_url: `${base}${chemin}` } : {});

/** Carte d'un article dans un carrousel : photo carrée, nom, prix et disponibilité, boutons. */
function carte(p: Produit, base: string): Record<string, unknown> {
  const liste = [choix('🛒 Commander', idAction({ type: 'produit', produit: p.id }))];
  if (p.galerie.length) {
    liste.push(choix('📷 Plus de photos', idPhotos(p.id)));
  }
  liste.push(choix('🙋 Une question', idAction({ type: 'vendeur' })));
  return {
    title: couper(p.nom, LIMITES.titreElement),
    subtitle: couper([prixTexte(p), dispo(p), p.options].filter(Boolean).join(' · '), LIMITES.sousTitre),
    ...image(base, p.photo),
    buttons: liste,
  };
}

/** Rayons en réponses rapides (promotions d'abord), puis livraison et vendeur : treize au plus. */
function rayonsRapides(b: Boutique): ReponseRapide[] {
  const fin = [rapide('🚚 Livraison', idAction({ type: 'livraison' })), vendeurRapide];
  const promos = b.promos > 0 ? [rapide(`🔥 ${PROMOS}`, idAction({ type: 'rayon', rayon: 0, page: 1 }))] : [];
  const rayons = b.rayons.slice(0, LIMITES.reponsesRapides - fin.length - promos.length).map((r) => rapide(r.nom, idAction({ type: 'rayon', rayon: r.id, page: 1 })));
  return [...promos, ...rayons, ...fin];
}

export function menu(b: Boutique): Envoi[] {
  if (!b.rayons.length) {
    return [texte(`Bonjour 👋 ${b.nom} met à jour son catalogue. Dites-nous ce que vous cherchez : la boutique vous répond ici.`)];
  }
  return [texte(`Bonjour 👋 Bienvenue chez ${b.nom} ! Choisissez un rayon ci-dessous 👇`, rayonsRapides(b))];
}

/** Page d'un rayon : dix articles au plus à faire défiler, puis la suite et les autres choix. */
export function pageRayon(rayon: Rayon, page: PageRayon, numero: number, base: string): Envoi[] {
  if (!page.produits.length) {
    return [texte(`Plus rien de disponible dans « ${rayon.nom} » pour le moment.`, [menuRapide, vendeurRapide])];
  }
  const debut = (numero - 1) * PAR_PAGE + 1;
  const fin = debut + page.produits.length - 1;
  const reste = Math.max(0, page.total - fin);
  const suite = reste > 0 ? [rapide(`➡️ Voir ${reste} de plus`, idAction({ type: 'rayon', rayon: rayon.id, page: numero + 1 }))] : [];
  const position = debut === fin ? `article ${debut} sur ${page.total}` : `articles ${debut} à ${fin} sur ${page.total}`;
  return [
    carrousel(page.produits.map((p) => carte(p, base))),
    texte(`${rayon.nom} · ${position}. Faites glisser pour tout voir, puis touchez « Commander ».`, [...suite, menuRapide, vendeurRapide]),
  ];
}

/** Article choisi : sa carte avec le bouton qui ouvre le lien personnel de commande, puis sa description. */
export function produit(b: Boutique, p: Produit | null, lien: string | null, base: string): Envoi[] {
  if (!p || !p.en_stock || !lien) {
    return [texte(`Désolé, ${p ? `« ${p.nom} » vient d'être épuisé` : 'cet article n\'est plus disponible'} chez ${b.nom}. Voyez nos autres articles 👇`, [menuRapide, vendeurRapide])];
  }
  const liste: Bouton[] = [{ type: 'web_url', title: '🛒 Commander', url: lien, webview_height_ratio: 'full' }];
  if (p.galerie.length) {
    liste.push(choix('📷 Plus de photos', idPhotos(p.id)));
  }
  liste.push(choix('🛍️ Autres articles', idAction({ type: 'menu' })));
  const element = { ...carte(p, base), buttons: liste };
  const consigne = `🛒 Touchez « Commander » : ${p.variable ? 'choisissez la taille, ' : ''}indiquez la quantité et la livraison. La confirmation arrive ici. Lien personnel, valable 24 h.`;
  return [carrousel([element]), texte([p.resume, consigne].filter(Boolean).join('\n\n'), [vendeurRapide])];
}

/** Les autres photos de l'article, puis de quoi commander. */
export function photos(p: Produit | null, base: string): Envoi[] {
  if (!p) {
    return [texte('Cet article n\'est plus disponible.', [menuRapide])];
  }
  const commander = rapide('🛒 Commander', idAction({ type: 'produit', produit: p.id }));
  if (!p.galerie.length) {
    return [texte(`Pas d'autre photo pour « ${p.nom} ». Une question ? La boutique vous répond ici.`, [commander, menuRapide, vendeurRapide])];
  }
  return [
    ...p.galerie.map((chemin): Envoi => ({ type: 'message', message: { attachment: { type: 'image', payload: { url: `${base}${chemin}`, is_reusable: true } } } })),
    texte(`${p.nom} : ${prixTexte(p)}`, [commander, menuRapide, vendeurRapide]),
  ];
}

/** Articles d'une publication de la Page (le client a touché « Voir ces articles » sous un commentaire). */
export function articlesPublication(produits: Produit[], base: string): Envoi[] {
  const dispos = produits.filter((p) => p.en_stock);
  if (!dispos.length) {
    return [texte('Les articles de cette publication sont épuisés 😔 Voyez ce qui reste en boutique 👇', [menuRapide, vendeurRapide])];
  }
  return [carrousel(dispos.map((p) => carte(p, base)), [menuRapide, vendeurRapide])];
}

export function livraison(b: Boutique): Envoi[] {
  const puces = (l: string[]) => (l.length ? l.map((x) => `• ${x}`).join('\n') : '• À convenir avec la boutique');
  return [texte(`🚚 Livraison\n${puces(b.livraison)}\n\n💳 Paiement\n${puces(b.paiements)}\n${b.conditions}`, [menuRapide, vendeurRapide])];
}

/** Le client veut parler au vendeur : on le rassure ici, et le vendeur est prévenu sur son WhatsApp. */
export function vendeur(b: Boutique, nomPage: string): Envoi[] {
  return [
    texte(`C'est noté 👍 ${b.nom} vous répond ici très vite. En attendant, vous pouvez voir nos articles 👇`, [menuRapide]),
    { type: 'au_vendeur', texte: `🙋 *Un client veut vous parler sur Messenger* (Page ${nomPage}).\nRépondez-lui depuis la messagerie de votre Page : application Facebook, Messenger ou Meta Business Suite.` },
  ];
}

/** Message libre que le service ne sait pas traiter : accusé de réception, rayons, et le vendeur prévenu. */
export function question(b: Boutique, nomPage: string, extrait: string): Envoi[] {
  return [
    texte(`Merci 🙏 ${b.nom} a bien reçu votre message et vous répond ici. En attendant, voici nos rayons 👇`, b.rayons.length ? rayonsRapides(b) : [vendeurRapide]),
    { type: 'au_vendeur', texte: `💬 *Nouveau message sur Messenger* (Page ${nomPage}) :\n« ${couper(extrait, 300)} »\nRépondez depuis la messagerie de votre Page.` },
  ];
}

// --- Commentaires sous les publications ------------------------------------------------------------------------

// Ce qu'écrit un acheteur intéressé sous une publication de vente, au Cameroun et ailleurs en Afrique : « Prix ? »,
// « cb », « dispo ? », « ib », « mp », « je prends », « taille 42 ? », « livrez à Yaoundé ? », « how much ».
const INTERET = /\b(prix|px|combien|cb|cmb|cbien|dispo|dispos|disponible|disponibles|inbox|ib|mp|pv|prive|interesse|interessee|interested|prends|veux|jveux|commande|commander|taille|tailles|pointure|couleur|couleurs|livraison|livrez|livrer|livre|stock|reste|how much|price|available|details?|infos?|numero|contact|whatsapp|wa|tel|localisation|situe|situee)\b/;

/** Le commentaire montre-t-il un intérêt d'achat ? (les simples mentions d'amis, « Amen », émojis : non) */
export function interesse(commentaire: string): boolean {
  const t = sansAccents(commentaire.toLowerCase());
  return t.includes('?') || INTERET.test(t) || /^\s*(n°|no|numero)?\s*\d{1,2}\s*$/.test(t);
}

/** Article désigné dans un commentaire sous une publication de plusieurs articles : « le 2 », « la robe wax ». */
export function articleDesigne(commentaire: string, produits: Produit[]): Produit | null {
  if (produits.length === 1) {
    return produits[0];
  }
  const t = sansAccents(commentaire.toLowerCase());
  const numero = /(?:^|\D)(\d{1,2})(?:\D|$)/.exec(t.replace(/\d{3,}/g, ' '));
  if (numero && Number(numero[1]) >= 1 && Number(numero[1]) <= produits.length) {
    return produits[Number(numero[1]) - 1];
  }
  const trouves = produits.filter((p) => sansAccents(p.nom.toLowerCase()).split(/[^a-z0-9]+/).some((mot) => mot.length >= 4 && t.includes(mot)));
  return trouves.length === 1 ? trouves[0] : null;
}

const prenom = (nom: string) => nom.trim().split(/\s+/)[0] ?? '';

/**
 * Réponse privée au commentaire (un seul message permis par Meta, dans les 7 jours) : l'article, son prix, et des
 * boutons. Le client qui touche un bouton ouvre la discussion : la suite (photo, lien de commande) part tout de suite.
 */
export function reponsePrivee(b: Boutique, nom: string, produits: Produit[], designe: Produit | null, publication: number | null): MessageMessenger {
  const qui = prenom(nom);
  const bonjour = `Bonjour${qui ? ` ${qui}` : ''} 👋 Merci pour votre commentaire !`;
  const catalogue = choix('🛍️ Tout le catalogue', idAction({ type: 'menu' }));
  const questionBouton = choix('🙋 Une question', idAction({ type: 'vendeur' }));
  if (designe && designe.en_stock) {
    const etat = dispo(designe).replace(/^\S+ /, '').toLowerCase();
    return boutons(`${bonjour}\n${designe.nom} : ${prixTexte(designe)}, ${etat}${designe.options ? ` (${designe.options})` : ''}.\nTouchez « Voir et commander » pour la photo et votre lien de commande 👇`,
      [choix('🛒 Voir et commander', idAction({ type: 'produit', produit: designe.id })), catalogue, questionBouton]);
  }
  if (designe) {
    return boutons(`${bonjour}\n${designe.nom} vient d'être épuisé 😔 Voyez ce qui reste en boutique 👇`, [catalogue, questionBouton]);
  }
  if (produits.length && publication !== null) {
    const liste = produits.map((p, i) => `${i + 1}. ${p.nom} : ${p.en_stock ? prixTexte(p) : 'épuisé'}`).join('\n');
    return boutons(`${bonjour}\n${liste}\nTouchez « Voir ces articles » 👇`, [choix('🛍️ Voir ces articles', idPublication(publication)), catalogue, questionBouton]);
  }
  return boutons(`${bonjour}\nVoici la boutique ${b.nom} : prix, tailles et commande en un geste 👇`, [catalogue, questionBouton]);
}

const PUBLIQUES = [
  (p: string) => `${p ? `${p}, j` : 'J'}e vous ai écrit en privé 📩`,
  (p: string) => `Merci${p ? ` ${p}` : ''} ! Regardez vos messages 📩`,
  (p: string) => `${p ? `${p}, t` : 'T'}ous les détails sont dans votre messagerie 📩`,
];

/** Réponse publique sous le commentaire : courte et variée, pour ne pas répéter la même phrase sous une publication. */
export function reponsePublique(nom: string, commentaire: string): string {
  const variante = [...commentaire].reduce((somme, c) => somme + c.charCodeAt(0), 0) % PUBLIQUES.length;
  return PUBLIQUES[variante](prenom(nom));
}

// --- Publications de la Page -----------------------------------------------------------------------------------

/** Texte d'une publication : prix clairs, et comment commander sans attendre (« inbox » inutile). */
export function textePublication(b: Boutique, produits: Produit[], page: string): string {
  if (produits.length === 1) {
    const p = produits[0];
    return [
      p.nom,
      `💰 ${prixTexte(p)}`,
      [dispo(p), p.options].filter(Boolean).join(' · '),
      p.resume ? `\n${p.resume}` : '',
      `\n💬 Commentez « prix » : réponse immédiate en privé.`,
      `🛒 Commander sur Messenger : https://m.me/${page}?ref=produit-${p.id}`,
      `🌐 ${p.lien}`,
    ].filter(Boolean).join('\n');
  }
  const lignes = produits.map((p, i) => `${i + 1}. ${p.nom} : ${prixTexte(p)}${enPromo(p) ? ' 🔥' : ''}`);
  return [`🛍️ Chez ${b.nom}`, '', ...lignes, '', '💬 Commentez le numéro de l\'article (« 2 ») : réponse immédiate en privé.', `🛒 Toute la boutique sur Messenger : https://m.me/${page}?ref=menu`].join('\n');
}

/** Phrase d'appel en bas du visuel de story. */
export const APPEL_STORY = 'Écrivez « prix » à la Page sur Messenger : réponse immédiate';

/** Prix, promotion et alerte de stock pour le visuel de story. */
export const detailsStory = (p: Produit) => ({
  prix: p.variable ? `dès ${fcfa(p.prix)}` : fcfa(p.prix),
  ancienPrix: enPromo(p) ? fcfa(p.prix_regulier) : null,
  remise: enPromo(p) ? Math.round((1 - p.prix / p.prix_regulier) * 100) : null,
  peuDeStock: peuDeStock(p),
});

/** Menu permanent de la discussion (bouton menu de Messenger) : la boutique toujours à portée, rien à retenir. */
export function menuPermanent(): Record<string, unknown>[] {
  return [{
    locale: 'default',
    composer_input_disabled: false,
    call_to_actions: [
      { type: 'postback', title: '🛍️ Voir les articles', payload: idAction({ type: 'menu' }) },
      { type: 'postback', title: '🚚 Livraison et paiement', payload: idAction({ type: 'livraison' }) },
      { type: 'postback', title: '🙋 Parler au vendeur', payload: idAction({ type: 'vendeur' }) },
    ],
  }];
}

/** Autorisations utiles et ce qu'elles permettent, dit au vendeur quand il en a refusé une. */
export const AUTORISATIONS: Record<string, string> = {
  pages_messaging: 'répondre à vos clients dans Messenger',
  pages_read_engagement: 'voir les commentaires sous vos publications',
  pages_read_user_content: 'lire ce qu\'écrivent vos clients en commentaire',
  pages_manage_engagement: 'répondre en public sous les commentaires',
  pages_manage_posts: 'publier sur votre Page (publications et stories)',
};

export function autorisationsManquantes(accordees: string[]): string[] {
  return Object.keys(AUTORISATIONS).filter((a) => !accordees.includes(a));
}
