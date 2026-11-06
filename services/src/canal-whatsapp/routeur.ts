// Aiguillage de chaque événement : ce que ce service sait déjà faire (catalogue interactif), il le fait ; tout le
// reste continue d'aller à l'extension WordPress, inchangé. Les fonctions migrent ainsi une par une, sans coupure.

import { MARQUE } from '../passerelle/filtre.ts';
import { demandeMenu } from './appel.ts';
import type { MessageWhatsapp } from './message.ts';

/** Au-delà, un message est un rattrapage d'historique (reconnexion) : on ne répond pas des heures plus tard. */
export const FRAICHEUR = 900;

export type Action =
  | { type: 'menu' }
  | { type: 'rayon'; rayon: number; page: number }
  | { type: 'commander'; produit: number }
  | { type: 'commandes' }
  | { type: 'livraison' }
  | { type: 'vendeur' };

export type Decision = { route: 'catalogue'; action: Action; message: MessageWhatsapp } | { route: 'heritage' };

/** Identifiant de ligne de liste : « bayam:rayon:15:2 », « bayam:commander:25 »… */
export function idAction(action: Action): string {
  switch (action.type) {
    case 'rayon':
      return `bayam:rayon:${action.rayon}:${action.page}`;
    case 'commander':
      return `bayam:commander:${action.produit}`;
    default:
      return `bayam:${action.type}`;
  }
}

export function lireAction(id: string): Action | null {
  const [prefixe, type, a, b] = id.split(':');
  if (prefixe !== 'bayam') {
    return null;
  }
  const entier = (v: string | undefined) => (v && /^\d{1,10}$/.test(v) ? Number(v) : null);
  switch (type) {
    case 'menu':
    case 'commandes':
    case 'livraison':
    case 'vendeur':
      return { type };
    case 'rayon': {
      const rayon = entier(a);
      const page = entier(b) ?? 1;
      return rayon && page >= 1 ? { type, rayon, page } : null;
    }
    case 'commander': {
      const produit = entier(a);
      return produit ? { type, produit } : null;
    }
    default:
      return null;
  }
}

export function decider(m: MessageWhatsapp | null, maintenant: number): Decision {
  // Écho de nos propres envois (marque en tête) : WordPress l'enregistre et l'ignore, comme avant.
  if (!m || m.groupe || maintenant - m.horodatage > FRAICHEUR || m.texte.startsWith(MARQUE)) {
    return { route: 'heritage' };
  }
  if (m.choixListe && !m.deMoi) {
    const action = lireAction(m.choixListe);
    return action ? { route: 'catalogue', action, message: m } : { route: 'heritage' };
  }
  // Le client demande la boutique ; ou le vendeur tape « bayam menu » dans la discussion d'un client pour la lui
  // montrer (dans sa propre discussion, c'est le menu du Copilote : le service le renvoie à WordPress).
  if (demandeMenu(m.texte) && (!m.deMoi || !m.texte.trim().startsWith('#'))) {
    return { route: 'catalogue', action: { type: 'menu' }, message: m };
  }
  return { route: 'heritage' };
}
