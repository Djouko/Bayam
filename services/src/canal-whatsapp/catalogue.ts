// Messages du catalogue interactif, comme une boutique en ligne mais dans WhatsApp et économe en données :
// rayons en liste, chaque page de rayon en une seule image vitrine (quatre articles), puis l'article choisi en une
// photo avec ses détails et son lien personnel de commande. Fonctions pures (aucun envoi ici).

import { MARQUE } from '../passerelle/filtre.ts';
import type { Boutique, Commande, PageRayon, Produit, Rayon } from '../commun/commerce.ts';
import { idAction } from './routeur.ts';

export type Ligne = { id: string; titre: string; description?: string };
export type Section = { titre: string; lignes: Ligne[] };
export type EnvoiPret =
  | { type: 'liste'; titre: string; texte: string; bouton: string; pied: string; sections: Section[] }
  /** JPEG léger en base64, préparé par le service (vitrine dessinée, photo recompressée). */
  | { type: 'image'; legende: string; donnees: string }
  | { type: 'texte'; texte: string }
  | { type: 'au_vendeur'; texte: string };
/** Vitrine d'un rayon, dessinée juste avant l'envoi (vitrine.ts). */
export type EnvoiVitrine = { type: 'vitrine'; titre: string; sousTitre: string; legende: string; premier: number; produits: Produit[]; infos: string[] };
/** Photo d'un article (chemin sur le site), recompressée juste avant l'envoi. */
export type EnvoiPhoto = { type: 'photo'; legende: string; chemin: string };
export type Envoi = EnvoiPret | EnvoiVitrine | EnvoiPhoto;

/** Limites des listes WhatsApp (API officielle) : respectées aussi en connexion non officielle. */
export const LIMITES = { lignes: 10, titreLigne: 24, descriptionLigne: 72, bouton: 20, titreSection: 24, entete: 60, pied: 60 };
export const PAR_PAGE = 4;
export const PROMOS = 'Promos du moment';

export function couper(texte: string, max: number): string {
  const lettres = [...texte];
  return lettres.length <= max ? texte : `${lettres.slice(0, max - 1).join('').trimEnd()}…`;
}

/** Même écriture que l'extension WordPress : « 12 000 FCFA » avec espaces insécables. */
export function fcfa(montant: number): string {
  return `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(montant).replace(/\s/g, ' ')} FCFA`;
}

export function numeroLisible(numero: string): string {
  const local = numero.startsWith('237') ? numero.slice(3) : numero;
  return local.length === 9 ? `${local[0]} ${local.slice(1).match(/../g)?.join(' ')}` : `+${numero}`;
}

function articles(nombre: number): string {
  return nombre === 1 ? '1 article' : `${nombre} articles`;
}

export function enPromo(p: Produit): boolean {
  return p.prix_regulier > p.prix;
}

export function prixTexte(p: Produit): string {
  const prix = p.variable ? `dès ${fcfa(p.prix)}` : fcfa(p.prix);
  return enPromo(p) ? `${prix} au lieu de ${fcfa(p.prix_regulier)}` : prix;
}

/** Reste peu de pièces : on le dit (« Plus que 2 »), c'est une information utile au client. */
export function peuDeStock(p: Produit): number | null {
  return p.stock !== null && p.stock > 0 && p.stock <= 3 ? p.stock : null;
}

function liste(titre: string, texte: string, bouton: string, pied: string, sections: Section[]): EnvoiPret {
  return {
    type: 'liste',
    // La marque en tête du titre : l'écho de la liste (premier mot du message) est reconnu comme venant de BAYAM.
    titre: MARQUE + couper(titre, LIMITES.entete - 1),
    texte: MARQUE + texte,
    bouton: couper(bouton, LIMITES.bouton),
    pied: couper(pied, LIMITES.pied),
    sections: sections.map((s) => ({
      titre: couper(s.titre, LIMITES.titreSection),
      lignes: s.lignes.map((l) => ({ id: l.id, titre: couper(l.titre, LIMITES.titreLigne), ...(l.description ? { description: couper(l.description, LIMITES.descriptionLigne) } : {}) })),
    })),
  };
}

export function menu(b: Boutique): Envoi[] {
  if (!b.rayons.length) {
    return [{ type: 'texte', texte: `${MARQUE}Bonjour 👋 *${b.nom}* met à jour son catalogue. Dites-nous ce que vous cherchez : la boutique vous répond ici.` }];
  }
  const boutique: Ligne[] = [
    { id: idAction({ type: 'commandes' }), titre: '📦 Mes commandes', description: 'Suivre une commande' },
    { id: idAction({ type: 'livraison' }), titre: '🚚 Livraison et paiement', description: 'Zones, frais, moyens de paiement' },
  ];
  const promos: Ligne[] = b.promos > 0
    ? [{ id: idAction({ type: 'rayon', rayon: 0, page: 1 }), titre: `🔥 ${PROMOS}`, description: `${articles(b.promos)} en promotion` }]
    : [];
  const rayons = b.rayons.slice(0, LIMITES.lignes - boutique.length - promos.length).map((r) => ({
    id: idAction({ type: 'rayon', rayon: r.id, page: 1 }),
    titre: r.nom,
    description: articles(r.nombre),
  }));
  return [liste(b.nom, 'Bonjour 👋 Voici nos rayons 🛍️ Choisissez celui qui vous intéresse 👇', 'Voir les rayons', b.nom,
    [{ titre: 'Rayons', lignes: [...promos, ...rayons] }, { titre: 'Boutique', lignes: boutique }])];
}

/** Page d'un rayon : la vitrine (une image, quatre articles numérotés), puis la liste pour choisir. */
export function pageRayon(b: Boutique, rayon: Rayon, page: PageRayon, numero: number): Envoi[] {
  if (!page.produits.length) {
    return [{ type: 'texte', texte: `${MARQUE}Plus rien de disponible dans *${rayon.nom}* pour le moment. Tapez *bayam* pour revoir nos rayons.` }];
  }
  const debut = (numero - 1) * PAR_PAGE + 1;
  const fin = debut + page.produits.length - 1;
  const reste = Math.max(0, page.total - fin);
  const position = debut === fin ? `article ${debut} sur ${page.total}` : `articles ${debut} à ${fin} sur ${page.total}`;
  const continuer: Ligne[] = [];
  if (reste > 0) {
    continuer.push({ id: idAction({ type: 'rayon', rayon: rayon.id, page: numero + 1 }), titre: 'Voir la suite', description: `${articles(reste)} de plus dans ${rayon.nom}` });
  }
  continuer.push(
    { id: idAction({ type: 'menu' }), titre: 'Autres rayons', description: 'Revoir tous les rayons' },
    { id: idAction({ type: 'vendeur' }), titre: 'Parler au vendeur', description: 'Une question, un prix, une taille' },
  );
  return [
    { type: 'vitrine', titre: rayon.nom, sousTitre: `${b.nom} · ${position}`, legende: `${MARQUE}*${rayon.nom}* · ${position}`, premier: debut, produits: page.produits, infos: [...b.livraison, b.conditions] },
    liste(rayon.nom, `Quel article vous intéresse ? Touchez *Choisir* : photo, détails et commande en un message 👇`, 'Choisir', b.nom, [
      { titre: 'Articles', lignes: page.produits.map((p, i) => ({ id: idAction({ type: 'produit', produit: p.id }), titre: `${debut + i}. ${p.nom}`, description: [prixTexte(p), p.options].filter(Boolean).join(' · ') })) },
      { titre: 'Continuer', lignes: continuer },
    ]),
  ];
}

/** Article choisi : sa photo, ses détails et le lien personnel de commande, en un seul message. */
export function produit(b: Boutique, p: Produit | null, lien: string | null): Envoi[] {
  if (!p || !p.en_stock || !lien) {
    return [{ type: 'texte', texte: `${MARQUE}Désolé, ${p ? `*${p.nom}* vient d'être épuisé` : 'cet article n\'est plus disponible'} chez *${b.nom}*. Tapez *bayam* pour voir les autres articles, ou écrivez-nous : la boutique vous répond ici.` }];
  }
  const reste = peuDeStock(p);
  const dispo = ['✅ En stock', reste ? (reste === 1 ? 'dernière pièce' : `plus que ${reste}`) : '', p.options].filter(Boolean).join(' · ');
  const legende = [
    `${MARQUE}*${p.nom}*`,
    `💰 *${prixTexte(p)}*`,
    dispo,
    p.resume ? `\n${p.resume}` : '',
    `\n🛒 *Commander* : touchez le lien, ${p.variable ? 'choisissez la taille, ' : ''}indiquez la quantité et la livraison. La suite arrive ici.`,
    `🔒 Lien personnel, valable 24 h :\n${lien}`,
  ].filter(Boolean).join('\n');
  if (!p.photo) {
    return [{ type: 'texte', texte: legende }];
  }
  // La première photo porte tous les détails ; les autres suivent sans texte visible (WhatsApp les regroupe en
  // album). Leur légende est la seule marque invisible : leur écho est reconnu comme venant de BAYAM, pas du vendeur.
  return [{ type: 'photo', legende, chemin: p.photo }, ...p.galerie.map((chemin): Envoi => ({ type: 'photo', legende: MARQUE, chemin }))];
}

export function commandes(b: Boutique, liste: Commande[] | null): Envoi[] {
  if (liste === null) {
    return [{ type: 'texte', texte: `${MARQUE}Nous n'arrivons pas à lire votre numéro. Écrivez-nous votre numéro de commande : *${b.nom}* vous répond ici.` }];
  }
  if (!liste.length) {
    return [{ type: 'texte', texte: `${MARQUE}Aucune commande à votre numéro chez *${b.nom}* pour le moment. Tapez *bayam* pour voir nos rayons.` }];
  }
  const lignes = liste.slice(0, 5).map((c) => `• N° ${c.numero} du ${c.date} : ${fcfa(c.total)}, *${c.statut}*`);
  return [{ type: 'texte', texte: `${MARQUE}📦 Vos dernières commandes chez *${b.nom}* :\n\n${lignes.join('\n')}\n\nUne question sur une commande ? Écrivez-nous ici.` }];
}

export function livraison(b: Boutique): Envoi[] {
  const puces = (l: string[]) => (l.length ? l.map((x) => `• ${x}`).join('\n') : '• À convenir avec la boutique');
  return [{ type: 'texte', texte: `${MARQUE}🚚 *Livraison*\n${puces(b.livraison)}\n\n💳 *Paiement*\n${puces(b.paiements)}\n${b.conditions}\n\nUne question ? Écrivez-nous ici, *${b.nom}* vous répond.` }];
}

export function vendeur(b: Boutique, nom: string, numero: string | null): Envoi[] {
  const qui = [nom, numero ? numeroLisible(numero) : ''].filter(Boolean).join(', ');
  return [
    { type: 'texte', texte: `${MARQUE}C'est noté 👍 *${b.nom}* vous répond ici très vite.` },
    { type: 'au_vendeur', texte: `${MARQUE}🙋 *Un client souhaite vous parler*${qui ? ` : ${qui}` : ''}${numero ? `\nhttps://wa.me/${numero}` : ''}\nIl attend votre réponse dans sa discussion.` },
  ];
}
