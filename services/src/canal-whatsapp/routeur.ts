// Aiguillage de chaque événement : ce que ce service sait déjà faire (catalogue interactif), il le fait ; tout le
// reste continue d'aller à l'extension WordPress, inchangé. Les fonctions migrent ainsi une par une, sans coupure.

import { MARQUE } from '../passerelle/filtre.ts';
import { appel, demandeMenu, sansAccents } from './appel.ts';
import type { MessageWhatsapp } from './message.ts';

/** Référence d'article écrite dans un message (« réf. P25 ») : liens wa.me des publications partagées par le vendeur. */
const REFERENCE = /\br[ée]f(?:[ée]rence)?\.?\s*:?\s*P(\d{1,10})\b/i;

/** Au-delà, un message est un rattrapage d'historique (reconnexion) : on ne répond pas des heures plus tard. */
export const FRAICHEUR = 900;

export type Action =
  | { type: 'menu' }
  | { type: 'rayon'; rayon: number; page: number }
  | { type: 'produit'; produit: number }
  | { type: 'commandes' }
  | { type: 'livraison' }
  | { type: 'vendeur' }
  /** « bayam aide » : comment se servir de la boutique sur WhatsApp. */
  | { type: 'aide' }
  /** « bayam signaler … », « bayam idée … » : souci ou idée transmis à l'équipe BAYAM. */
  | { type: 'signaler'; genre: 'souci' | 'idee'; texte: string };

/** « aide », « signaler … », « idée … » (sans accents ni majuscules) ; null si ce n'est ni l'un ni l'autre. */
export function aideOuRetour(reste: string): Action | null {
  const t = sansAccents(reste.toLowerCase()).trim();
  if (/^(aide|help|comment (ca|ça) marche)\b/.test(t)) {
    return { type: 'aide' };
  }
  const m = /^(signaler|signale|souci|probleme|bug|idee|suggestion|amelioration)\b\s*:?\s*([\s\S]*)$/.exec(t);
  return m ? { type: 'signaler', genre: /^(idee|suggestion|amelioration)/.test(m[1]) ? 'idee' : 'souci', texte: reste.trim().slice(reste.trim().length - m[2].length) } : null;
}

export type Decision = { route: 'catalogue'; action: Action; message: MessageWhatsapp } | { route: 'heritage' };

/** Identifiant de ligne de liste : « bayam:rayon:15:2 », « bayam:produit:25 »… */
export function idAction(action: Action): string {
  switch (action.type) {
    case 'rayon':
      return `bayam:rayon:${action.rayon}:${action.page}`;
    case 'produit':
      return `bayam:produit:${action.produit}`;
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
      // Rayon 0 : les promotions de la boutique.
      const rayon = entier(a);
      const page = entier(b) ?? 1;
      return rayon !== null && page >= 1 ? { type, rayon, page } : null;
    }
    case 'produit':
    case 'commander': { // « commander » : lignes des listes envoyées avant la vitrine, toujours valables
      const produit = entier(a);
      return produit ? { type: 'produit', produit } : null;
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
  // Le client arrive d'une publication (profil ou groupe Facebook, statut) avec la référence de l'article.
  const reference = REFERENCE.exec(m.texte);
  if (reference && !m.deMoi) {
    return { route: 'catalogue', action: { type: 'produit', produit: Number(reference[1]) }, message: m };
  }
  // « bayam aide », « bayam signaler … » d'un client : l'aide de la boutique, ou un retour pour l'équipe BAYAM.
  const reste = m.deMoi ? null : appel(m.texte);
  const aide = reste !== null ? aideOuRetour(reste) : null;
  if (aide) {
    return { route: 'catalogue', action: aide, message: m };
  }
  // Le client demande la boutique ; ou le vendeur tape « bayam menu » dans la discussion d'un client pour la lui
  // montrer (dans sa propre discussion, c'est le menu du Copilote : le service le renvoie à WordPress).
  if (demandeMenu(m.texte) && (!m.deMoi || !m.texte.trim().startsWith('#'))) {
    return { route: 'catalogue', action: { type: 'menu' }, message: m };
  }
  return { route: 'heritage' };
}
