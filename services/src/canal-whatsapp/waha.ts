// Connecteur WAHA (WhatsApp en appareil lié). Les autres connecteurs (API officielle Cloud API, coexistence)
// exposeront les mêmes méthodes : le reste du service ne dépend que de cette forme.

import { texteErreur } from '../commun/journal.ts';
import type { EnvoiPret } from './catalogue.ts';

type Reponse = { id?: string | { _serialized?: string } } | null;

export class Waha {
  private readonly url: string;
  private readonly cle: string;

  constructor(url: string, cle: string) {
    this.url = url.replace(/\/$/, '');
    this.cle = cle;
  }

  async envoyer(session: string, chatId: string, e: EnvoiPret): Promise<string | null> {
    switch (e.type) {
      case 'liste':
        return this.id(await this.appel('/api/sendList', {
          session, chatId,
          message: {
            title: e.titre, description: e.texte, footer: e.pied, button: e.bouton,
            sections: e.sections.map((s) => ({ title: s.titre, rows: s.lignes.map((l) => ({ title: l.titre, rowId: l.id, description: l.description ?? null })) })),
          },
        }));
      case 'image':
        // Légende sans aperçu de lien : aucune miniature de plus à télécharger pour le client.
        return this.id(await this.appel('/api/sendImage', {
          session, chatId, caption: e.legende, file: { mimetype: 'image/jpeg', filename: 'bayam.jpg', data: e.donnees },
        }));
      case 'texte':
      case 'au_vendeur':
        // Pas d'aperçu de lien automatique (actif par défaut chez WAHA) : une miniature de moins à télécharger.
        return this.id(await this.appel('/api/sendText', { session, chatId, text: e.texte, linkPreview: false }));
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
