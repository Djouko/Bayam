// Lecture d'un événement WAHA (moteur GOWS) : qui écrit, dans quelle discussion, quoi, et s'il répond à une liste
// envoyée par BAYAM. Tout le reste du service travaille sur ce format simple, quel que soit le connecteur.

import { MARQUE } from '../passerelle/filtre.ts';

type Liste = { title?: string; description?: string };
type ReponseListe = {
  singleSelectReply?: { selectedRowID?: string; selectedRowId?: string };
  contextInfo?: { quotedMessage?: { listMessage?: Liste } };
};
export type Evenement = {
  id?: string;
  event?: string;
  session?: string;
  payload?: {
    id?: string;
    timestamp?: number;
    from?: string;
    fromMe?: boolean;
    body?: string;
    replyTo?: { body?: string } | null;
    _data?: {
      Info?: { Chat?: string; IsGroup?: boolean; SenderAlt?: string; PushName?: string };
      Message?: { listResponseMessage?: ReponseListe };
    };
  };
};

export type MessageWhatsapp = {
  evt: string;
  session: string;
  horodatage: number;
  chat: string;
  deMoi: boolean;
  groupe: boolean;
  texte: string;
  /** Numéro international du client (237…), quand WhatsApp le donne (identifiants @lid). */
  numero: string | null;
  nom: string;
  idMessage: string;
  /** Ligne choisie dans une liste envoyée par BAYAM, sinon null. */
  choixListe: string | null;
};

function numeroDe(adresse: string | undefined): string | null {
  const m = /^(\d{8,15})@(s\.whatsapp\.net|c\.us)$/.exec(adresse ?? '');
  return m ? m[1] : null;
}

/** Partie identifiante d'une adresse WhatsApp, sans suffixe ni appareil : « 237654770063@s.whatsapp.net » donne « 237654770063 ». */
export function compte(adresse: string): string {
  return adresse.replace(/[:@].*$/, '');
}

/** La discussion est-elle celle du vendeur avec lui-même ? (« Message à moi-même », sous n'importe quelle forme d'adresse) */
export function discussionDuVendeur(chat: string, identifiants: string[]): boolean {
  const lui = compte(chat);
  return lui !== '' && identifiants.some((id) => compte(id) === lui);
}

export function lireMessage(evt: Evenement): MessageWhatsapp | null {
  const p = evt.payload;
  if ((evt.event !== 'message' && evt.event !== 'message.any') || !p) {
    return null;
  }
  const info = p._data?.Info ?? {};
  const chat = String(info.Chat || p.from || '');
  if (!chat) {
    return null;
  }
  const reponse = p._data?.Message?.listResponseMessage;
  const liste = reponse?.contextInfo?.quotedMessage?.listMessage;
  const deBayam = (liste ? `${liste.title ?? ''}${liste.description ?? ''}` : String(p.replyTo?.body ?? '')).includes(MARQUE);
  const choix = reponse?.singleSelectReply?.selectedRowID ?? reponse?.singleSelectReply?.selectedRowId ?? null;
  return {
    evt: String(evt.id ?? p.id ?? ''),
    session: String(evt.session ?? ''),
    horodatage: Number(p.timestamp ?? 0),
    chat,
    deMoi: Boolean(p.fromMe),
    groupe: Boolean(info.IsGroup) || chat.endsWith('@g.us'),
    texte: String(p.body ?? ''),
    numero: numeroDe(info.SenderAlt) ?? numeroDe(chat),
    nom: String(info.PushName ?? ''),
    idMessage: String(p.id ?? ''),
    choixListe: reponse && deBayam && choix ? choix : null,
  };
}
