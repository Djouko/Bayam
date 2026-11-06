// Veilleur du tunnel public de démonstration : Cloudflare supprime sans prévenir les adresses gratuites
// (« Tunnel not found »). Le veilleur interroge le tunnel toutes les 20 s et le relance s'il reste mort, pour que
// le site redevienne joignable sans intervention. Inutile avec un vrai domaine (tunnel nommé ou serveur public).

import { lire, nombre } from '../commun/config.ts';
import { journal, texteErreur } from '../commun/journal.ts';
import { observer, type Etat } from './decision.ts';

const log = journal('veilleur');
const pret = lire('TUNNEL_PRET', 'http://tunnel:2000/ready');
const docker = lire('DOCKER_URL', 'http://proxy-docker-veilleur:2375');
const conteneur = lire('TUNNEL_CONTENEUR', 'fluxstore-local-tunnel-1');
let etat: Etat = { echecs: 0, derniereRelance: 0 };

async function surveiller(): Promise<void> {
  const ok = await fetch(pret, { signal: AbortSignal.timeout(5000) }).then((r) => r.ok).catch(() => false);
  const r = observer(etat, ok, Date.now());
  etat = r.etat;
  if (!r.relancer) {
    return;
  }
  try {
    const reponse = await fetch(`${docker}/containers/${encodeURIComponent(conteneur)}/restart?t=5`, { method: 'POST', signal: AbortSignal.timeout(30_000) });
    log[reponse.ok ? 'alerte' : 'erreur']('tunnel mort, relancé', { statut: reponse.status });
  } catch (e) {
    log.erreur('relance du tunnel impossible', { erreur: texteErreur(e) });
  }
}

setInterval(() => void surveiller(), nombre('VEILLE_INTERVALLE_MS', 20_000));
log.info('veille du tunnel', { conteneur });
for (const signal of ['SIGTERM', 'SIGINT'] as const) {
  process.once(signal, () => process.exit(0));
}
