// Règles des sondes, sans dépendance : quand ouvrir un incident (panne confirmée, pas un simple raté de réseau) et
// quand le fermer (rétablissement confirmé). Une sonde « tremblante » ne réveille personne.

export const ECHECS_AVANT_INCIDENT = 3;
export const SUCCES_AVANT_RETABLI = 2;

export type Suivi = { echecs: number; succes: number; incident: boolean; depuis: number; detail: string };
export type Action = 'ouvrir' | 'fermer' | null;

export const SUIVI_NEUF: Suivi = { echecs: 0, succes: 0, incident: false, depuis: 0, detail: '' };

/** Nouvel état d'une sonde après un contrôle, et l'action à mener (ouvrir ou fermer l'incident). */
export function suivre(s: Suivi, ok: boolean, detail: string, maintenant: number): { suivi: Suivi; action: Action } {
  if (ok) {
    const succes = s.succes + 1;
    if (s.incident && succes >= SUCCES_AVANT_RETABLI) {
      return { suivi: { ...SUIVI_NEUF, depuis: maintenant }, action: 'fermer' };
    }
    return { suivi: { ...s, echecs: 0, succes, detail: s.incident ? s.detail : '' }, action: null };
  }
  const echecs = s.echecs + 1;
  if (!s.incident && echecs >= ECHECS_AVANT_INCIDENT) {
    return { suivi: { echecs, succes: 0, incident: true, depuis: maintenant, detail }, action: 'ouvrir' };
  }
  return { suivi: { ...s, echecs, succes: 0, detail }, action: null };
}

/** État public d'un composant pour la page d'état : opérationnel, perturbé (échecs isolés) ou en panne. */
export function etatPublic(s: Suivi): 'operationnel' | 'perturbe' | 'panne' {
  return s.incident ? 'panne' : s.echecs > 0 ? 'perturbe' : 'operationnel';
}
