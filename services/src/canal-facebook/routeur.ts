// Aiguillage des événements Facebook : boutons et réponses rapides touchés, liens m.me ouverts depuis une
// publication (profil, groupe, Marketplace, statut WhatsApp…), messages tapés, commentaires sous les publications,
// réponses tapées par le vendeur lui-même. Fonctions pures.

import { demandeMenu, sansAccents } from '../canal-whatsapp/appel.ts';
import { lireAction, type Action } from '../canal-whatsapp/routeur.ts';
import type { Commentaire, EvenementFacebook } from '../passerelle/facebook.ts';
import { interesse } from './messenger.ts';

/** Meta n'autorise une réponse que dans les 24 h qui suivent le message du client. */
export const FRAICHEUR = 24 * 3600;

export type ActionFacebook =
  | Action
  | { type: 'photos'; produit: number }
  | { type: 'publication'; publication: number }
  /** Message libre (ou photo envoyée par le client) que le service ne sait pas traiter : le vendeur est prévenu. */
  | { type: 'question'; extrait: string };

export type Decision =
  /** libre : déclenchée par un message tapé (et non un bouton) ; ignorée quand le vendeur a repris la main. */
  | { route: 'client'; page: string; client: string; action: ActionFacebook; libre: boolean }
  | { route: 'commentaire'; page: string; commentaire: Commentaire }
  /** Le vendeur a répondu lui-même dans Messenger : le service se tait un moment dans cette discussion. */
  | { route: 'reprise'; page: string; client: string }
  | { route: 'ignore' };

export function lireActionFacebook(id: string): ActionFacebook | null {
  const [prefixe, type, valeur] = id.split(':');
  if (prefixe === 'bayam' && /^\d{1,10}$/.test(valeur ?? '')) {
    if (type === 'photos') {
      return { type, produit: Number(valeur) };
    }
    if (type === 'publication') {
      return { type, publication: Number(valeur) };
    }
  }
  return lireAction(id);
}

/** Paramètre ref d'un lien m.me (lettres, chiffres, - et _) : « produit-25 », « rayon-3 », sinon le menu. */
export function actionDuLien(ref: string): ActionFacebook {
  const m = /^(produit|rayon)-(\d{1,10})$/.exec(ref);
  if (m?.[1] === 'produit') {
    return { type: 'produit', produit: Number(m[2]) };
  }
  if (m?.[1] === 'rayon') {
    return { type: 'rayon', rayon: Number(m[2]), page: 1 };
  }
  return { type: 'menu' };
}

// Premiers mots d'un client sur Messenger : il ne tape pas « bayam », il salue ou demande ce qu'il y a.
const SALUTATION = /^(bonjour|bonsoir|bjr|bsr|slt|salut|hello|hi|hey|cc|coucou|yo|menu|catalogue|rayons?|boutique|articles?|produits?|prix|dispo|disponible|vous avez quoi|qu'?est[- ]ce que vous (avez|vendez))\b/;

export function decider(e: EvenementFacebook, maintenant: number): Decision {
  if (maintenant - e.horodatage > FRAICHEUR) {
    return { route: 'ignore' };
  }
  if (e.type === 'commentaire') {
    return interesse(e.donnees.message ?? '') ? { route: 'commentaire', page: e.page, commentaire: e.donnees } : { route: 'ignore' };
  }
  const m = e.donnees;
  if (m.message?.is_echo) {
    return { route: 'reprise', page: e.page, client: String(m.recipient?.id ?? '') };
  }
  const client = String(m.sender?.id ?? '');
  const choisi = (action: ActionFacebook | null, libre = false): Decision => (action ? { route: 'client', page: e.page, client, action, libre } : { route: 'ignore' });
  if (m.postback) {
    return choisi(m.postback.referral?.ref ? actionDuLien(m.postback.referral.ref) : lireActionFacebook(m.postback.payload ?? ''));
  }
  if (m.referral) {
    return choisi(actionDuLien(m.referral.ref ?? ''));
  }
  if (m.message?.quick_reply?.payload) {
    return choisi(lireActionFacebook(m.message.quick_reply.payload));
  }
  const texte = (m.message?.text ?? '').trim();
  if (!texte && !m.message?.attachments?.length) {
    return { route: 'ignore' };
  }
  // Référence d'article copiée d'une publication (« réf. P25 ») : l'article directement.
  const reference = /\br[ée]f(?:[ée]rence)?\.?\s*:?\s*P(\d{1,10})\b/i.exec(texte);
  if (reference) {
    return choisi({ type: 'produit', produit: Number(reference[1]) }, true);
  }
  if (texte && (demandeMenu(texte) || SALUTATION.test(sansAccents(texte.toLowerCase())))) {
    return choisi({ type: 'menu' }, true);
  }
  return choisi({ type: 'question', extrait: texte || '📷 (photo ou fichier envoyé)' }, true);
}
