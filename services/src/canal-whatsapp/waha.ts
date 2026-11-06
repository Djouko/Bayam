// Connecteur WAHA (WhatsApp en appareil lié). Les autres connecteurs (API officielle Cloud API, coexistence)
// exposeront les mêmes méthodes : le reste du service ne dépend que de cette forme.

import { texteErreur } from '../commun/journal.ts';
import type { Envoi } from './catalogue.ts';

type Reponse = { id?: string | { _serialized?: string } } | null;

export class Waha {
  private readonly url: string;
  private readonly cle: string;
  /** Origine depuis laquelle WAHA télécharge les images (réseau interne). */
  private readonly origineImages: string;

  constructor(url: string, cle: string, origineImages: string) {
    this.url = url.replace(/\/$/, '');
    this.cle = cle;
    this.origineImages = origineImages.replace(/\/$/, '');
  }

  async envoyer(session: string, chatId: string, e: Envoi): Promise<string | null> {
    switch (e.type) {
      case 'liste':
        return this.id(await this.appel('/api/sendList', {
          session, chatId,
          message: {
            title: e.titre, description: e.texte, footer: e.pied, button: e.bouton,
            sections: e.sections.map((s) => ({ title: s.titre, rows: s.lignes.map((l) => ({ title: l.titre, rowId: l.id, description: l.description ?? null })) })),
          },
        }));
      case 'carte':
        return this.id(await this.appel('/api/send/link-custom-preview', {
          session, chatId, text: e.texte, linkPreviewHighQuality: true,
          preview: { url: e.url, title: e.titre, description: e.description, ...(e.image ? { image: { url: this.origineImages + e.image } } : {}) },
        }));
      case 'texte':
      case 'au_vendeur':
        return this.id(await this.appel('/api/sendText', { session, chatId, text: e.texte }));
    }
  }

  /** Indicateur « en train d'écrire… » : le client voit une personne qui répond, pas une machine. */
  async ecrit(session: string, chatId: string, actif: boolean): Promise<void> {
    await this.appel(actif ? '/api/startTyping' : '/api/stopTyping', { session, chatId }, 1).catch(() => undefined);
  }

  private id(r: Reponse): string | null {
    const id = r?.id;
    return typeof id === 'string' ? id : (id?._serialized ?? null);
  }

  /** Nouvelles tentatives sur les pannes passagères (session qui se reconnecte, erreur 5xx), jamais sur un refus. */
  private async appel(chemin: string, corps: unknown, essais = 3): Promise<Reponse> {
    let derniere = '';
    for (let essai = 1; essai <= essais; essai++) {
      try {
        const r = await fetch(this.url + chemin, {
          method: 'POST',
          headers: { 'content-type': 'application/json', 'x-api-key': this.cle },
          body: JSON.stringify(corps),
          signal: AbortSignal.timeout(30_000),
        });
        const texte = await r.text();
        if (r.ok) {
          return texte ? (JSON.parse(texte) as Reponse) : null;
        }
        derniere = `${r.status} ${texte.slice(0, 200)}`;
        if (r.status < 500 && !texte.includes('Session status is not as expected')) {
          break;
        }
      } catch (e) {
        derniere = texteErreur(e);
      }
      if (essai < essais) {
        await new Promise((ok) => setTimeout(ok, essai * 2000));
      }
    }
    throw new Error(`WAHA ${chemin} : ${derniere}`);
  }
}
