// Adresse publique du site pour les liens envoyés aux clients : celle du domaine (BAYAM_URL_PUBLIQUE) en production ;
// pendant les démonstrations, celle du tunnel, lue en direct (gardée 30 s), car elle change à chaque relance du tunnel.

import { lire } from './config.ts';

let adresse = { valeur: '', lue: 0 };

/** Adresse sans « / » final, ou chaîne vide si aucune n'est connue. */
export async function adressePublique(): Promise<string> {
  const fixe = lire('BAYAM_URL_PUBLIQUE', '');
  if (fixe) {
    return fixe.replace(/\/$/, '');
  }
  if (Date.now() - adresse.lue > 30_000) {
    const hote = await fetch(`${lire('BAYAM_TUNNEL_METRICS', 'http://tunnel:2000')}/quicktunnel`, { signal: AbortSignal.timeout(3000) })
      .then((r): Promise<{ hostname?: string }> | { hostname?: string } => (r.ok ? r.json() : {}))
      .then((d) => (typeof d.hostname === 'string' && d.hostname ? `https://${d.hostname}` : ''))
      .catch(() => '');
    adresse = { valeur: hote, lue: Date.now() };
  }
  return adresse.valeur;
}
