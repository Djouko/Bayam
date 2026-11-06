// Journal JSON sur une ligne, lisible par n'importe quel collecteur (Loki, Datadog, CloudWatch).
// Règle : jamais de secret ni de contenu de conversation privée, seulement des identifiants et des compteurs.

type Champs = Record<string, unknown>;

export type Journal = {
  info(message: string, champs?: Champs): void;
  alerte(message: string, champs?: Champs): void;
  erreur(message: string, champs?: Champs): void;
};

export function journal(service: string): Journal {
  const ecrire = (niveau: string, message: string, champs: Champs = {}) => {
    const ligne = JSON.stringify({ t: new Date().toISOString(), niveau, service, message, ...champs });
    (niveau === 'erreur' ? process.stderr : process.stdout).write(ligne + '\n');
  };
  return {
    info: (message, champs) => ecrire('info', message, champs),
    alerte: (message, champs) => ecrire('alerte', message, champs),
    erreur: (message, champs) => ecrire('erreur', message, champs),
  };
}

/** Message d'une erreur quelconque, pour le journal. */
export function texteErreur(e: unknown): string {
  return e instanceof Error ? e.message : String(e);
}
