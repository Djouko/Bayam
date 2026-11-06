// Décision du veilleur, sans effet de bord : relancer le tunnel après plusieurs échecs de suite, jamais en rafale.

export const ECHECS_AVANT_RELANCE = 3;
export const PAUSE_ENTRE_RELANCES = 120_000;

export type Etat = { echecs: number; derniereRelance: number };

export function observer(etat: Etat, pret: boolean, maintenant: number): { etat: Etat; relancer: boolean } {
  const echecs = pret ? 0 : etat.echecs + 1;
  const relancer = echecs >= ECHECS_AVANT_RELANCE && maintenant - etat.derniereRelance >= PAUSE_ENTRE_RELANCES;
  return { etat: { echecs: relancer ? 0 : echecs, derniereRelance: relancer ? maintenant : etat.derniereRelance }, relancer };
}
