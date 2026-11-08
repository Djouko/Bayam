// Messages de la boutique dans Messenger et dans la messagerie de Facebook (application et site). Messenger affiche
// les cartes (carrousel d'articles avec photo, prix, boutons) ; la messagerie de Facebook n'affiche ni cartes ni
// boutons, seulement le texte et les images. Chaque envoi a donc son texte numéroté, lisible partout : le client
// touche une carte ou répond par le numéro. Mêmes règles qu'en WhatsApp : prix et stock du moment, peu de messages.
// Fonctions pures (aucun envoi ici).

import type { Boutique, PageRayon, Produit, Rayon } from '../commun/commerce.ts';
import { sansAccents } from '../canal-whatsapp/appel.ts';
import { couper, enPromo, fcfa, peuDeStock, prixTexte, PROMOS } from '../canal-whatsapp/catalogue.ts';
import { idAction } from '../canal-whatsapp/routeur.ts';

/** Objet « message » de l'API d'envoi de Messenger. */
export type MessageMessenger = Record<string, unknown>;
/** choix : identifiants des lignes numérotées envoyées, gardés pour comprendre la réponse « 2 » du client. */
export type Envoi = { type: 'message'; message: MessageMessenger } | { type: 'au_vendeur'; texte: string } | { type: 'choix'; ids: string[] };

/** Limites de Messenger (documentation de la plateforme). */
export const LIMITES = { elements: 10, titreElement: 80, sousTitre: 80, boutons: 3, titreBouton: 20, texte: 2000 };
export const PAR_PAGE = 10;

type Bouton = { type: 'postback'; title: string; payload: string } | { type: 'web_url'; title: string; url: string; webview_height_ratio: 'full' };
/** Ligne d'une liste numérotée : libellé et identifiant de l'action. */
type Ligne = [string, string];

const choix = (title: string, payload: string): Bouton => ({ type: 'postback', title: couper(title, LIMITES.titreBouton), payload });
const ARTICLES: Ligne = ['🛍️ Voir nos articles', idAction({ type: 'menu' })];
const VENDEUR: Ligne = ['🙋 Parler au vendeur', idAction({ type: 'vendeur' })];
const LIVRAISON: Ligne = ['🚚 Livraison et paiement', idAction({ type: 'livraison' })];

export const idPhotos = (produit: number) => `bayam:photos:${produit}`;
export const idPublication = (publication: number) => `bayam:publication:${publication}`;

const nombreArticles = (n: number) => (n === 1 ? '1 article' : `${n} articles`);

/** Texte simple : seul format affiché partout (Messenger, Facebook sur téléphone et sur ordinateur). */
function texte(t: string): Envoi {
  return { type: 'message', message: { text: couper(t, LIMITES.texte) } };
}

/** Texte suivi de choix numérotés ; le client répond par le numéro. */
function liste(intro: string, lignes: Ligne[]): Envoi[] {
  const numeros = lignes.map(([libelle], i) => `${i + 1}. ${libelle}`).join('\n');
  return [texte(`${intro}\n${numeros}`), { type: 'choix', ids: lignes.map(([, id]) => id) }];
}

function carrousel(elements: Record<string, unknown>[]): Envoi {
  return { type: 'message', message: { attachment: { type: 'template', payload: { template_type: 'generic', image_aspect_ratio: 'square', elements: elements.slice(0, LIMITES.elements) } } } };
}

function dispo(p: Produit): string {
  const reste = peuDeStock(p);
  return reste ? (reste === 1 ? '⚡ Dernière pièce' : `⚡ Plus que ${reste}`) : '✅ En stock';
}

const image = (base: string, chemin: string | null | undefined) => (chemin ? { image_url: `${base}${chemin}` } : {});

/** Carte d'un article dans un carrousel : photo carrée, nom, prix et disponibilité, boutons. */
function carte(p: Produit, base: string): Record<string, unknown> {
  const boutons = [choix('🛒 Commander', idAction({ type: 'produit', produit: p.id }))];
  if (p.galerie.length) {
    boutons.push(choix('📷 Plus de photos', idPhotos(p.id)));
  }
  boutons.push(choix('🙋 Une question', idAction({ type: 'vendeur' })));
  return {
    title: couper(p.nom, LIMITES.titreElement),
    subtitle: couper([prixTexte(p), dispo(p), p.options].filter(Boolean).join(' · '), LIMITES.sousTitre),
    ...image(base, p.photo),
    buttons: boutons,
  };
}

/** Ligne d'un article dans une liste numérotée : nom, prix, alerte de stock. */
const ligneArticle = (p: Produit): Ligne => [`${p.nom} : ${prixTexte(p)}${peuDeStock(p) ? ` (${dispo(p).replace(/^\S+ /, '').toLowerCase()})` : ''}`, idAction({ type: 'produit', produit: p.id })];

/**
 * La boutique : un carrousel de rayons à faire glisser (une photo par rayon, promotions d'abord) pour Messenger, et
 * la même chose en liste numérotée pour la messagerie de Facebook. Le client touche ou répond par le numéro.
 */
export function menu(b: Boutique, base: string): Envoi[] {
  if (!b.rayons.length) {
    return liste(`Bonjour 👋 ${b.nom} met à jour son catalogue. Dites-nous ce que vous cherchez : la boutique vous répond ici.`, [VENDEUR]);
  }
  const lignes: Ligne[] = [];
  const cartes: Record<string, unknown>[] = [];
  if (b.promos > 0) {
    const id = idAction({ type: 'rayon', rayon: 0, page: 1 });
    lignes.push([`🔥 ${PROMOS} (${nombreArticles(b.promos)})`, id]);
    cartes.push({ title: `🔥 ${PROMOS}`, subtitle: `${nombreArticles(b.promos)} en promotion`, buttons: [choix('Voir les promos', id)] });
  }
  for (const r of b.rayons.slice(0, LIMITES.elements - cartes.length - 1)) {
    const id = idAction({ type: 'rayon', rayon: r.id, page: 1 });
    lignes.push([`${r.nom} (${nombreArticles(r.nombre)})`, id]);
    cartes.push({ title: couper(r.nom, LIMITES.titreElement), subtitle: nombreArticles(r.nombre), ...image(base, r.photo), buttons: [choix('Voir le rayon', id)] });
  }
  cartes.push({ title: couper(b.nom, LIMITES.titreElement), subtitle: 'Livraison, paiement, une question', buttons: [choix('🚚 Livraison', LIVRAISON[1]), choix('🙋 Le vendeur', VENDEUR[1])] });
  return [carrousel(cartes), ...liste(`Bonjour 👋 Bienvenue chez ${b.nom} ! Touchez un rayon, ou répondez par son numéro :`, [...lignes, LIVRAISON, VENDEUR])];
}

/** Page d'un rayon : dix articles au plus, en cartes et en liste numérotée, puis la suite et les autres choix. */
export function pageRayon(rayon: Rayon, page: PageRayon, numero: number, base: string): Envoi[] {
  if (!page.produits.length) {
    return liste(`Plus rien de disponible dans « ${rayon.nom} » pour le moment.`, [ARTICLES, VENDEUR]);
  }
  const debut = (numero - 1) * PAR_PAGE + 1;
  const fin = debut + page.produits.length - 1;
  const reste = Math.max(0, page.total - fin);
  const suite: Ligne[] = reste > 0 ? [[`➡️ Voir ${nombreArticles(reste)} de plus`, idAction({ type: 'rayon', rayon: rayon.id, page: numero + 1 })]] : [];
  const position = debut === fin ? `article ${debut} sur ${page.total}` : `articles ${debut} à ${fin} sur ${page.total}`;
  return [
    carrousel(page.produits.map((p) => carte(p, base))),
    ...liste(`${rayon.nom} · ${position}. Touchez « Commander », ou répondez par le numéro de l'article :`, [...page.produits.map(ligneArticle), ...suite, ['🛍️ Autres rayons', ARTICLES[1]], VENDEUR]),
  ];
}

/**
 * Article choisi : sa carte (bouton « Commander » vers le lien personnel) pour Messenger ; pour tous, sa photo puis
 * ses détails et le lien de commande en texte, et les choix suivants.
 */
export function produit(b: Boutique, p: Produit | null, lien: string | null, base: string): Envoi[] {
  if (!p || !p.en_stock || !lien) {
    return liste(`Désolé, ${p ? `« ${p.nom} » vient d'être épuisé` : 'cet article n\'est plus disponible'} chez ${b.nom}.`, [ARTICLES, VENDEUR]);
  }
  const boutons: Bouton[] = [{ type: 'web_url', title: '🛒 Commander', url: lien, webview_height_ratio: 'full' }];
  if (p.galerie.length) {
    boutons.push(choix('📷 Plus de photos', idPhotos(p.id)));
  }
  boutons.push(choix('🛍️ Autres articles', idAction({ type: 'menu' })));
  const details = [
    p.nom,
    `💰 ${prixTexte(p)}`,
    [dispo(p), p.options].filter(Boolean).join(' · '),
    ...(p.resume ? ['', p.resume] : []),
    '',
    `🛒 Pour commander, ouvrez votre lien personnel (valable 24 h) : ${p.variable ? 'choisissez la taille, ' : ''}indiquez la quantité et la livraison, la confirmation arrive ici.`,
    lien,
    '',
    'Ensuite :',
  ].join('\n');
  const suite: Ligne[] = [...(p.galerie.length ? [['📷 Plus de photos', idPhotos(p.id)] as Ligne] : []), ['🛍️ Autres articles', ARTICLES[1]], VENDEUR];
  return [carrousel([{ ...carte(p, base), buttons: boutons }]), ...liste(details, suite)];
}

/** Les autres photos de l'article (images : affichées partout), puis de quoi commander. */
export function photos(p: Produit | null, base: string): Envoi[] {
  if (!p) {
    return liste('Cet article n\'est plus disponible.', [ARTICLES]);
  }
  const lignes: Ligne[] = [['🛒 Commander', idAction({ type: 'produit', produit: p.id })], ['🛍️ Autres articles', ARTICLES[1]], VENDEUR];
  if (!p.galerie.length) {
    return liste(`Pas d'autre photo pour « ${p.nom} ». Une question ? La boutique vous répond ici.`, lignes);
  }
  return [
    ...p.galerie.map((chemin): Envoi => ({ type: 'message', message: { attachment: { type: 'image', payload: { url: `${base}${chemin}`, is_reusable: true } } } })),
    ...liste(`${p.nom} : ${prixTexte(p)}`, lignes),
  ];
}

/** Articles d'une publication de la Page (choisis depuis une réponse à un commentaire). */
export function articlesPublication(produits: Produit[], base: string): Envoi[] {
  const dispos = produits.filter((p) => p.en_stock);
  if (!dispos.length) {
    return liste('Les articles de cette publication sont épuisés 😔', [ARTICLES, VENDEUR]);
  }
  return [carrousel(dispos.map((p) => carte(p, base))), ...liste('Répondez par le numéro de l\'article (ou touchez « Commander ») :', [...dispos.map(ligneArticle), ['🛍️ Autres rayons', ARTICLES[1]], VENDEUR])];
}

export function livraison(b: Boutique): Envoi[] {
  const puces = (l: string[]) => (l.length ? l.map((x) => `• ${x}`).join('\n') : '• À convenir avec la boutique');
  return liste(`🚚 Livraison\n${puces(b.livraison)}\n\n💳 Paiement\n${puces(b.paiements)}\n${b.conditions}\n`, [ARTICLES, VENDEUR]);
}

/** Le client veut parler au vendeur : on le rassure ici, et le vendeur est prévenu sur son WhatsApp. */
export function vendeur(b: Boutique, nomPage: string): Envoi[] {
  return [
    ...liste(`C'est noté 👍 ${b.nom} vous répond ici très vite. En attendant :`, [ARTICLES]),
    { type: 'au_vendeur', texte: `🙋 *Un client veut vous parler sur Messenger* (Page ${nomPage}).\nRépondez-lui depuis la messagerie de votre Page : application Facebook, Messenger ou Meta Business Suite.` },
  ];
}

/** Alerte au vendeur sur WhatsApp pour un message que BAYAM ne sait pas traiter. */
export function alerteQuestion(nomPage: string, extrait: string): Envoi {
  return { type: 'au_vendeur', texte: `💬 *Nouveau message sur Messenger* (Page ${nomPage}) :\n« ${couper(extrait, 300)} »\nRépondez depuis la messagerie de votre Page.` };
}

/** Accusé de réception d'un message en cours de conversation : rassure, et rappelle le mot pour revoir la boutique. */
export function accuse(b: Boutique): Envoi {
  return texte(`Merci 🙏 ${b.nom} a bien reçu votre message et vous répond ici. Pour revoir nos articles à tout moment, écrivez « menu ».`);
}

// Messages qui n'appellent pas de réponse en cours de conversation : salutations, remerciements, accords, émojis.
const BANAL = /^(bonjour|bonsoir|bjr|bsr|slt|salut|hello|hi|hey|cc|coucou|merci|mrc|thanks|ok|okay|oki|d'?accord|dac|daccord|super|cool|top|parfait|bien recu|recu|vu|amen|bonne (nuit|journee|soiree))\b[\s!.,]*$/;

/** Le message ne demande rien (« merci », « ok », « 👍 ») : pas d'alerte au vendeur, pas d'accusé de réception. */
export function banal(message: string): boolean {
  const t = sansAccents(message.toLowerCase()).trim();
  return !t || BANAL.test(t) || !/\p{L}|\d/u.test(t);
}

// --- Commentaires sous les publications ------------------------------------------------------------------------

// Ce qu'écrit un acheteur intéressé sous une publication de vente, au Cameroun et ailleurs en Afrique : « Prix ? »,
// « cb », « dispo ? », « ib », « mp », « je prends », « taille 42 ? », « livrez à Yaoundé ? », « how much ». Une
// salutation (« Salut », « Bsr ») sous une publication de vente, c'est un client qui engage la conversation.
const INTERET = /\b(prix|px|combien|cb|cmb|cbien|dispo|dispos|disponible|disponibles|inbox|ib|mp|pv|prive|interesse|interessee|interested|prends|veux|jveux|commande|commander|taille|tailles|pointure|couleur|couleurs|livraison|livrez|livrer|livre|stock|reste|how much|price|available|details?|infos?|numero|contact|whatsapp|wa|tel|localisation|situe|situee|bonjour|bonsoir|bjr|bsr|salut|slt|cc|coucou|hello|hi)\b/;

/** Le commentaire montre-t-il un intérêt d'achat ? (les simples mentions d'amis, « Amen », émojis : non) */
export function interesse(commentaire: string): boolean {
  const t = sansAccents(commentaire.toLowerCase());
  // « 2 », « le 2 », « la 3 svp », « n°2 », « #4 » : l'article désigné par son numéro sous un album.
  return t.includes('?') || INTERET.test(t) || /^\s*(le|la|les|n°|no|numero|#)?\s*\d{1,2}\b/.test(t);
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
 * choix numérotés en texte simple, lisibles partout. La réponse du client (« 1 ») ouvre la suite : photo et lien.
 */
export function reponsePrivee(b: Boutique, nom: string, produits: Produit[], designe: Produit | null): { message: MessageMessenger; choix: string[] } {
  const qui = prenom(nom);
  const bonjour = `Bonjour${qui ? ` ${qui}` : ''} 👋 Merci pour votre commentaire !`;
  let envois: Envoi[];
  if (designe && designe.en_stock) {
    const etat = dispo(designe).replace(/^\S+ /, '').toLowerCase();
    envois = liste(`${bonjour}\n${designe.nom} : ${prixTexte(designe)}, ${etat}${designe.options ? ` (${designe.options})` : ''}.\nRépondez par un numéro :`,
      [['🛒 La photo et votre lien de commande', idAction({ type: 'produit', produit: designe.id })], ['🛍️ Tout le catalogue', ARTICLES[1]], VENDEUR]);
  } else if (designe) {
    envois = liste(`${bonjour}\n${designe.nom} vient d'être épuisé 😔 Répondez par un numéro :`, [['🛍️ Voir ce qui reste en boutique', ARTICLES[1]], VENDEUR]);
  } else if (produits.length) {
    envois = liste(`${bonjour}\nRépondez par le numéro de l'article pour sa photo et votre lien de commande :`, [...produits.map((p) => (p.en_stock ? ligneArticle(p) : [`${p.nom} : épuisé`, ARTICLES[1]] as Ligne)), ['🛍️ Tout le catalogue', ARTICLES[1]], VENDEUR]);
  } else {
    envois = liste(`${bonjour}\nBienvenue chez ${b.nom}. Répondez par un numéro :`, [['🛍️ Voir nos articles, prix et tailles', ARTICLES[1]], VENDEUR]);
  }
  const [message, liens] = envois as [{ type: 'message'; message: MessageMessenger }, { type: 'choix'; ids: string[] }];
  return { message: message.message, choix: liens.ids };
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
