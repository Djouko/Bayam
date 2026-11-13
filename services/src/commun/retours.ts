// Client du service des retours pour les canaux : un signalement envoyé depuis WhatsApp ou Messenger arrive
// directement au service (sans passer par WordPress, qui peut être justement en panne), avec le moyen de prévenir la
// personne quand ce sera réglé. Si le service ne répond pas, la personne reçoit un message honnête, jamais un faux numéro.

export type DemandeRetour = {
  type: 'souci' | 'idee';
  texte: string;
  canal: 'whatsapp' | 'messenger';
  auteur: string;
  identifiant: string;
  vendeur?: number;
  contact: { canal: 'whatsapp'; session: string; chat: string } | { canal: 'messenger'; page: string; client: string };
};

export const RETOUR_INDISPONIBLE = "Votre message n'a pas pu être enregistré pour l'instant. Réessayez dans quelques minutes 🙏";

export class ClientRetours {
  private readonly base: string;
  private readonly jeton: string;

  constructor(base: string, jeton: string) {
    this.base = base.replace(/\/$/, '');
    this.jeton = jeton;
  }

  /** Enregistre le retour et renvoie la réponse à donner à la personne (avec son numéro R-…). */
  async signaler(d: DemandeRetour): Promise<string> {
    const r = await fetch(`${this.base}/interne/signalements`, {
      method: 'POST',
      headers: { 'content-type': 'application/json', 'x-bayam-service': this.jeton },
      body: JSON.stringify({ ...d, auteur: d.auteur.slice(0, 120), texte: d.texte.slice(0, 2000) }),
      signal: AbortSignal.timeout(8000),
    }).catch(() => null);
    if (!r?.ok) {
      return RETOUR_INDISPONIBLE;
    }
    return ((await r.json()) as { message: string }).message;
  }
}
