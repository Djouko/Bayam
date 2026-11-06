// Messages du catalogue interactif, comme dans une boutique en ligne mais dans WhatsApp : rayons en liste,
// articles en cartes avec photo, commande par lien personnel, suivi des commandes, livraison et paiement, vendeur.
// Fonctions pures (aucun envoi ici) : faciles à tester et identiques quel que soit le connecteur WhatsApp.

import { MARQUE } from '../passerelle/filtre.ts';
import type { Boutique, Commande, PageRayon, Produit, Rayon } from './commerce.ts';
import { idAction } from './routeur.ts';

export type Ligne = { id: string; titre: string; description?: string };
export type Section = { titre: string; lignes: Ligne[] };
export type Envoi =
  | { type: 'liste'; titre: string; texte: string; bouton: string; pied: string; sections: Section[] }
  | { type: 'carte'; texte: string; url: string; titre: string; description: string; image: string | null }
  | { type: 'texte'; texte: string }
  | { type: 'au_vendeur'; texte: string };

/** Limites des listes WhatsApp (API officielle) : respectées aussi en connexion non officielle. */
export const LIMITES = { lignes: 10, titreLigne: 24, descriptionLigne: 72, bouton: 20, titreSection: 24, entete: 60, pied: 60 };
export const PAR_PAGE = 3;

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

export function prixTexte(p: Produit): string {
  if (p.variable) {
    return `dès ${fcfa(p.prix)}`;
  }
  return p.prix_regulier > p.prix ? `${fcfa(p.prix)} au lieu de ${fcfa(p.prix_regulier)}` : fcfa(p.prix);
}

function liste(titre: string, texte: string, bouton: string, pied: string, sections: Section[]): Envoi {
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
  const rayons = b.rayons.slice(0, LIMITES.lignes - boutique.length).map((r) => ({
    id: idAction({ type: 'rayon', rayon: r.id, page: 1 }),
    titre: r.nom,
    description: articles(r.nombre),
  }));
  return [liste(b.nom, 'Bonjour 👋 Voici nos rayons 🛍️ Choisissez celui qui vous intéresse 👇', 'Voir les rayons', b.nom,
    [{ titre: 'Rayons', lignes: rayons }, { titre: 'Boutique', lignes: boutique }])];
}

function carte(p: Produit): Envoi {
  return {
    type: 'carte',
    texte: `${MARQUE}*${p.nom}* · ${prixTexte(p)}\n${p.en_stock ? '✅ En stock' : '❌ Épuisé pour le moment'}\n${p.lien}`,
    url: p.lien,
    titre: couper(`${p.nom} · ${p.variable ? `dès ${fcfa(p.prix)}` : fcfa(p.prix)}`, 80),
    description: couper(p.resume || 'Touchez pour voir les photos et commander.', 140),
    image: p.image,
  };
}

export function pageRayon(b: Boutique, rayon: Rayon, page: PageRayon, numero: number): Envoi[] {
  if (!page.produits.length) {
    return [{ type: 'texte', texte: `${MARQUE}Plus rien de disponible dans *${rayon.nom}* pour le moment. Tapez *bayam* pour revoir nos rayons.` }];
  }
  const debut = (numero - 1) * PAR_PAGE + 1;
  const fin = debut + page.produits.length - 1;
  const reste = Math.max(0, page.total - fin);
  const continuer: Ligne[] = [];
  if (reste > 0) {
    continuer.push({ id: idAction({ type: 'rayon', rayon: rayon.id, page: numero + 1 }), titre: 'Voir la suite', description: `${articles(reste)} de plus dans ${rayon.nom}` });
  }
  continuer.push(
    { id: idAction({ type: 'menu' }), titre: 'Autres rayons', description: 'Revoir tous les rayons' },
    { id: idAction({ type: 'vendeur' }), titre: 'Parler au vendeur', description: 'Une question, un prix, une taille' },
  );
  const position = debut === fin ? `article ${debut}` : `articles ${debut} à ${fin}`;
  return [
    ...page.produits.map(carte),
    liste(rayon.nom, `${rayon.nom} · ${position} sur ${page.total}. Que souhaitez-vous ? 👇`, 'Choisir', b.nom, [
      { titre: 'Commander', lignes: page.produits.map((p) => ({ id: idAction({ type: 'commander', produit: p.id }), titre: `Commander ${p.nom}`, description: prixTexte(p) })) },
      { titre: 'Continuer', lignes: continuer },
    ]),
  ];
}

export function commander(b: Boutique, p: Produit | null, lien: string | null): Envoi[] {
  if (!p || !p.en_stock || !lien) {
    return [{ type: 'texte', texte: `${MARQUE}Désolé, ${p ? `*${p.nom}* vient d'être épuisé` : 'cet article n\'est plus disponible'} chez *${b.nom}*. Tapez *bayam* pour voir les autres articles, ou écrivez-nous : la boutique vous répond ici.` }];
  }
  const choix = p.variable ? 'choisissez la taille ou la couleur, ' : '';
  return [{
    type: 'carte',
    texte: `${MARQUE}Pour commander *${p.nom}* (${prixTexte(p)}) : touchez le lien, ${choix}indiquez la quantité et la livraison, puis confirmez. La suite de votre commande arrive ici.\n\n🔒 Lien personnel, valable 24 h :\n${lien}`,
    url: lien,
    titre: couper(`Commander ${p.nom}`, 80),
    description: `${prixTexte(p)} · ${b.nom}`,
    image: p.image,
  }];
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
  return [{ type: 'texte', texte: `${MARQUE}🚚 *Livraison*\n${puces(b.livraison)}\n\n💳 *Paiement*\n${puces(b.paiements)}\n\nUne question ? Écrivez-nous ici, *${b.nom}* vous répond.` }];
}

export function vendeur(b: Boutique, nom: string, numero: string | null): Envoi[] {
  const qui = [nom, numero ? numeroLisible(numero) : ''].filter(Boolean).join(', ');
  return [
    { type: 'texte', texte: `${MARQUE}C'est noté 👍 *${b.nom}* vous répond ici très vite.` },
    { type: 'au_vendeur', texte: `${MARQUE}🙋 *Un client souhaite vous parler*${qui ? ` : ${qui}` : ''}${numero ? `\nhttps://wa.me/${numero}` : ''}\nIl attend votre réponse dans sa discussion.` },
  ];
}
