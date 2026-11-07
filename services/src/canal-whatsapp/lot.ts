// Envoi groupé à WordPress : charger WordPress coûte environ 0,7 s de processeur, ranger un événement presque rien.
// Les événements à transmettre sont donc regroupés (50 au plus, 100 ms d'attente au plus) et partent en un seul
// appel. Chacun garde la signature d'origine de WAHA, que WordPress revérifie.

export type EvenementSigne = { corps: string; hmac: string; algo: string };

type EnAttente = EvenementSigne & { fait: () => void; echec: (e: Error) => void };

export class LotWordpress {
  private readonly url: string;
  private readonly jeton: string;
  private readonly taille: number;
  private readonly delai: number;
  private attente: EnAttente[] = [];
  private minuteur: ReturnType<typeof setTimeout> | null = null;

  constructor(url: string, jeton: string, taille = 50, delai = 100) {
    this.url = url;
    this.jeton = jeton;
    this.taille = taille;
    this.delai = delai;
  }

  /** Résolue quand WordPress a rangé le lot qui contient cet événement ; rejetée si le lot a échoué (à retenter). */
  ajouter(e: EvenementSigne): Promise<void> {
    return new Promise((fait, echec) => {
      this.attente.push({ ...e, fait, echec });
      if (this.attente.length >= this.taille) {
        void this.vider();
      } else {
        this.minuteur ??= setTimeout(() => void this.vider(), this.delai);
      }
    });
  }

  private async vider(): Promise<void> {
    if (this.minuteur) {
      clearTimeout(this.minuteur);
      this.minuteur = null;
    }
    const lot = this.attente.splice(0, this.taille);
    if (this.attente.length) {
      this.minuteur = setTimeout(() => void this.vider(), this.delai);
    }
    if (!lot.length) {
      return;
    }
    try {
      const r = await fetch(this.url, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'x-bayam-service': this.jeton },
        body: JSON.stringify({ evenements: lot.map(({ corps, hmac, algo }) => ({ corps, hmac, algo })) }),
        signal: AbortSignal.timeout(20_000),
      });
      if (!r.ok) {
        throw new Error(`WordPress a répondu ${r.status}`);
      }
      lot.forEach((e) => e.fait());
    } catch (e) {
      const erreur = e instanceof Error ? e : new Error(String(e));
      lot.forEach((x) => x.echec(erreur));
    }
  }
}
