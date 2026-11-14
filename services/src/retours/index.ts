// Service des retours : point unique où arrivent les signalements, idées et pannes détectées, de tous les canaux.
// Sans état en mémoire (tout en base) : plusieurs instances peuvent tourner derrière Traefik.

import { join } from 'node:path';

import { creerPool, migrer } from '../commun/base.ts';
import { exiger, lire, nombre } from '../commun/config.ts';
import { journal, texteErreur } from '../commun/journal.ts';
import { creerApp } from './app.ts';
import type { Contact } from './donnees.ts';
import { DepotPostgres } from './donnees.ts';

const log = journal('retours');
const jeton = exiger('BAYAM_SERVICE_JETON');
const pool = creerPool(exiger('RETOURS_BASE'), nombre('RETOURS_CONNEXIONS', 5));
const appliquees = await migrer(pool, 'retours', join(import.meta.dirname, 'migrations'));
if (appliquees.length) {
  log.info('migrations appliquées', { appliquees });
}
const depot = new DepotPostgres(pool);
const wordpress = lire('BAYAM_WP_INTERNE', 'http://wordpress');
const canaux = { whatsapp: lire('BAYAM_CANAL_WHATSAPP', 'http://canal-whatsapp:8091'), messenger: lire('BAYAM_CANAL_FACEBOOK', 'http://canal-facebook:8092') };
// Alerte WhatsApp de l'équipe, facultative : « session:chat » d'un numéro relié à BAYAM.
const alerteWhatsapp = lire('RETOURS_ALERTE_WHATSAPP', '');
const GRAVITES = ['', 'basse', 'moyenne', 'haute', 'CRITIQUE'];

async function appeler(url: string, corps: unknown): Promise<boolean> {
  const r = await fetch(url, {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'x-bayam-service': jeton },
    body: JSON.stringify(corps),
    signal: AbortSignal.timeout(10_000),
  });
  return r.ok;
}

const app = await creerApp({
  depot,
  jeton,
  ipClient: lire('BAYAM_IP_CLIENT', 'cloudflare') === 'mandataire' ? 'mandataire' : 'cloudflare',
  sante: async () => (await pool.query('SELECT 1')).rowCount === 1,
  alerter: async (s) => {
    const texte = `🚨 BAYAM, sujet ${s.id} (gravité ${GRAVITES[s.gravite]}, ${s.signaleurs} personne${s.signaleurs > 1 ? 's' : ''}) : ${s.titre}`;
    const envois = [appeler(`${wordpress}/wp-json/bayam/v1/interne/alerte`, { sujet: s.id, texte, niveau: s.niveau })];
    const [session, chat] = alerteWhatsapp.split(':');
    if (session && chat) {
      envois.push(appeler(`${canaux.whatsapp}/interne/notifications`, { cle: `alerte-${s.id}-${s.niveau}`, session, chat, texte }));
    }
    const resultats = await Promise.allSettled(envois);
    const ok = resultats.some((r) => r.status === 'fulfilled' && r.value);
    log[ok ? 'alerte' : 'erreur'](ok ? 'équipe prévenue' : 'alerte non envoyée', { sujet: s.id, palier: s.niveau });
    return ok;
  },
  prevenir: async (sujet, texte) => {
    const prevenus: number[] = [];
    for (const { id, contact } of await depot.aPrevenir(sujet.id)) {
      const c = contact as Contact;
      const corps = c.canal === 'whatsapp'
        ? { cle: `retour-${id}-regle`, session: c.session, chat: c.chat, texte }
        : { cle: `retour-${id}-regle`, page: c.page, client: c.client, texte };
      const ok = await appeler(`${canaux[c.canal]}/interne/notifications`, corps).catch(() => false);
      if (ok) {
        prevenus.push(id);
      } else {
        log.alerte('signaleur non prévenu', { signalement: id, canal: c.canal });
      }
    }
    return prevenus;
  },
});

const port = nombre('PORT', 8093);
await app.listen({ host: '0.0.0.0', port });
log.info("à l'écoute", { port });

const menage = setInterval(() => {
  void depot.menage()
    .then((n) => n && log.info('contacts effacés', { nombre: n }))
    .catch((e: unknown) => log.erreur('ménage', { erreur: texteErreur(e) }));
}, 6 * 3_600_000);

for (const signal of ['SIGTERM', 'SIGINT'] as const) {
  process.once(signal, async () => {
    clearInterval(menage);
    await app.close();
    await pool.end();
    process.exit(0);
  });
}
