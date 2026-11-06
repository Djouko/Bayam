// Ce que la passerelle laisse entrer : un commerçant actif est dans des centaines de groupes, des centaines de
// messages par minute presque tous sans rapport avec sa boutique. Seul ce qui concerne BAYAM entre dans le bus.

import { createHmac, timingSafeEqual } from 'node:crypto';
import type { IncomingHttpHeaders } from 'node:http';

/** Marque invisible (U+2063) en tête des messages et légendes envoyés par BAYAM. */
export const MARQUE = '⁣';

/** Signature HMAC de WAHA sur le corps brut (en-têtes X-Webhook-Hmac et X-Webhook-Hmac-Algorithm). */
export function signatureValide(corps: Buffer, entetes: IncomingHttpHeaders, secret: string): boolean {
  const recue = String(entetes['x-webhook-hmac'] ?? '');
  const algo = String(entetes['x-webhook-hmac-algorithm'] ?? 'sha512').toLowerCase();
  if (!secret || !recue || !['sha512', 'sha256'].includes(algo)) {
    return false;
  }
  const attendue = createHmac(algo, secret).update(corps).digest('hex');
  return attendue.length === recue.length && timingSafeEqual(Buffer.from(attendue), Buffer.from(recue));
}

type Evenement = {
  event?: string;
  payload?: {
    from?: string;
    fromMe?: boolean;
    replyTo?: { body?: string } | null;
    _data?: { Info?: { Chat?: string } };
  };
};

/** L'événement concerne-t-il BAYAM ? Discussions privées, état des sessions, commentaires sous une publication BAYAM. */
export function utile(evt: Evenement): boolean {
  if (evt.event === 'session.status') {
    return true;
  }
  if (evt.event !== 'message' && evt.event !== 'message.any') {
    return false;
  }
  const p = evt.payload ?? {};
  const chat = String(p._data?.Info?.Chat || p.from || '');
  if (/@newsletter|@broadcast|^status@/.test(chat)) {
    return false;
  }
  if (chat.endsWith('@g.us')) {
    // Groupe : seulement un commentaire qui répond à une publication de BAYAM (le message cité porte la marque).
    return !p.fromMe && String(p.replyTo?.body ?? '').startsWith(MARQUE);
  }
  return true;
}
