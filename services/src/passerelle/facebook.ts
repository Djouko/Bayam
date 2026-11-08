// Événements Facebook (Pages et Messenger, API officielle de Meta) : Meta signe le corps brut avec le secret de
// l'application (en-tête X-Hub-Signature-256) et regroupe plusieurs événements par appel. Chacun part seul dans le
// bus, avec un identifiant stable : une nouvelle tentative de Meta ne crée pas de doublon.

import { createHmac, timingSafeEqual } from 'node:crypto';

/** Métadonnée jointe à chaque message envoyé par BAYAM : son écho est reconnu et écarté dès l'entrée. */
export const METADONNEE_BAYAM = 'bayam';

export function signatureMetaValide(corps: Buffer, entete: string | undefined, secret: string): boolean {
  const recue = String(entete ?? '');
  if (!secret || !recue.startsWith('sha256=')) {
    return false;
  }
  const attendue = `sha256=${createHmac('sha256', secret).update(corps).digest('hex')}`;
  return attendue.length === recue.length && timingSafeEqual(Buffer.from(attendue), Buffer.from(recue));
}

export type Messagerie = {
  sender?: { id?: string };
  recipient?: { id?: string };
  timestamp?: number;
  message?: { mid?: string; text?: string; is_echo?: boolean; metadata?: string; quick_reply?: { payload?: string }; attachments?: unknown[] };
  postback?: { mid?: string; title?: string; payload?: string; referral?: { ref?: string } };
  referral?: { ref?: string; source?: string };
};
export type Commentaire = {
  item?: string;
  verb?: string;
  comment_id?: string;
  post_id?: string;
  parent_id?: string;
  message?: string;
  created_time?: number;
  from?: { id?: string; name?: string };
};
type Corps = { object?: string; entry?: { id?: string; messaging?: Messagerie[]; changes?: { field?: string; value?: Commentaire }[] }[] };

/**
 * Résumé d'un appel de Meta pour le journal, sans aucun contenu : la nature de chaque événement (message, écho,
 * choix, lecture, commentaire ajouté…) et si l'auteur est connu. Sert à voir ce que Meta envoie vraiment.
 */
export function resumeFacebook(corps: Corps): string[] {
  const natures: string[] = [];
  for (const entree of corps.entry ?? []) {
    for (const m of entree.messaging ?? []) {
      const nature = m.message?.is_echo ? 'echo' : (['message', 'postback', 'referral', 'read', 'delivery', 'reaction'].find((k) => k in m) ?? 'autre');
      natures.push(`messagerie:${nature}`);
    }
    for (const c of entree.changes ?? []) {
      natures.push(`${c.field}:${c.value?.item ?? '?'}:${c.value?.verb ?? '?'}${c.value?.from?.id ? (c.value.from.id === entree.id ? ':page' : ':auteur') : ':sans-auteur'}`);
    }
  }
  return natures;
}

/** Ce qui entre dans le bus : un événement d'une Page, sous sa forme d'origine (donnees). */
export type EvenementFacebook =
  | { id: string; page: string; type: 'messagerie'; horodatage: number; donnees: Messagerie }
  | { id: string; page: string; type: 'commentaire'; horodatage: number; donnees: Commentaire };

/**
 * Découpe un appel de Meta et ne garde que ce qui concerne BAYAM : messages et choix des clients (boutons, réponses
 * rapides, liens m.me), réponses tapées par le vendeur lui-même (il reprend la main), nouveaux commentaires sous
 * les publications de la Page. Écartés : accusés de lecture et de réception, réactions, échos des envois de BAYAM,
 * commentaires de la Page elle-même.
 */
export function evenementsFacebook(corps: Corps): EvenementFacebook[] {
  if (corps.object !== 'page' || !Array.isArray(corps.entry)) {
    return [];
  }
  const sortie: EvenementFacebook[] = [];
  for (const entree of corps.entry) {
    const page = String(entree.id ?? '');
    if (!/^\d{1,20}$/.test(page)) {
      continue;
    }
    for (const m of entree.messaging ?? []) {
      const horodatage = Math.floor(Number(m.timestamp ?? 0) / 1000);
      const client = String((m.message?.is_echo ? m.recipient?.id : m.sender?.id) ?? '');
      if (!client || (m.message?.is_echo && m.message.metadata === METADONNEE_BAYAM)) {
        continue;
      }
      const id = m.message?.mid ?? m.postback?.mid ?? (m.referral || m.postback ? `${page}-${client}-${m.timestamp}-${m.postback ? 'choix' : 'lien'}` : null);
      if (id && (m.message || m.postback || m.referral)) {
        sortie.push({ id, page, type: 'messagerie', horodatage, donnees: m });
      }
    }
    for (const c of entree.changes ?? []) {
      const v = c.value ?? {};
      // Meta ne donne pas toujours l'auteur d'un commentaire (« from ») : la réponse privée n'en a pas besoin.
      if (c.field === 'feed' && v.item === 'comment' && v.verb === 'add' && v.comment_id && v.post_id && v.from?.id !== page) {
        sortie.push({ id: `commentaire-${v.comment_id}`, page, type: 'commentaire', horodatage: Number(v.created_time ?? 0), donnees: v });
      }
    }
  }
  return sortie;
}
