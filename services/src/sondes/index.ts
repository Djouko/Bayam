// Sondes de BAYAM : chaque minute, chaque composant est vérifié comme le ferait un utilisateur. Une panne confirmée
// (3 échecs de suite) ouvre un incident dans le service des retours (alerte de l'équipe), le rétablissement le ferme.
// GET /etat : état public de chaque composant, sans détail technique (page d'état, /api/etat par Traefik).

import Fastify from 'fastify';

import { lire, nombre } from '../commun/config.ts';
import { journal, texteErreur } from '../commun/journal.ts';
import { etatPublic, suivre, SUIVI_NEUF, type Suivi } from './regles.ts';

const log = journal('sondes');
const jeton = lire('BAYAM_SERVICE_JETON', '');
const retours = lire('BAYAM_RETOURS', 'http://retours:8093');
const site = lire('BAYAM_URL_PUBLIQUE', '');
const waha = { url: lire('WAHA_URL', 'http://waha:3000'), cle: lire('WAHA_API_KEY', '') };
const meta = { app: lire('FACEBOOK_APP_ID', ''), secret: lire('FACEBOOK_APP_SECRET', ''), graph: lire('FACEBOOK_GRAPH_URL', 'https://graph.facebook.com/v27.0') };
const r2 = lire('R2_PUBLIC_URL', '');

type Resultat = { ok: boolean; detail: string };
type Sonde = { nom: string; libelle: string; gravite: 3 | 4; toutesLes: number; controle: () => Promise<Resultat> };

async function http(url: string, attendu: (statut: number) => boolean, options: RequestInit = {}): Promise<Resultat> {
  try {
    const r = await fetch(url, { redirect: 'manual', signal: AbortSignal.timeout(10_000), ...options });
    return { ok: attendu(r.status), detail: `réponse ${r.status}` };
  } catch (e) {
    return { ok: false, detail: texteErreur(e).slice(0, 120) };
  }
}
const sain = (s: number) => s >= 200 && s < 400;

const sondes: Sonde[] = [
  { nom: 'boutique', libelle: 'Boutique (site et application)', gravite: 4, toutesLes: 1, controle: () => http('http://cache-web:8080/robots.txt', sain) },
  { nom: 'passerelle', libelle: 'Réception des messages WhatsApp et Facebook', gravite: 4, toutesLes: 1, controle: () => http('http://passerelle:8090/sante', (s) => s === 200) },
  { nom: 'canal-whatsapp', libelle: 'Réponses WhatsApp', gravite: 4, toutesLes: 1, controle: () => http('http://canal-whatsapp:8091/sante', (s) => s === 200) },
  { nom: 'canal-facebook', libelle: 'Réponses Messenger et Facebook', gravite: 3, toutesLes: 1, controle: () => http('http://canal-facebook:8092/sante', (s) => s === 200) },
  { nom: 'retours', libelle: 'Signalements des utilisateurs', gravite: 3, toutesLes: 1, controle: () => http(`${retours}/sante`, (s) => s === 200) },
  // Parcours complet par Internet, comme un téléphone : domaine, HTTPS, mandataire d'Easypanel, Traefik.
  ...(site ? [{ nom: 'site-public', libelle: 'Adresse publique BAYAM', gravite: 4 as const, toutesLes: 2, controle: () => http(`${site}/api/config`, (s) => s === 200) }] : []),
  // Stockage des photos et de l'application : joignable (une absence de fichier n'est pas une panne).
  ...(r2 ? [{ nom: 'stockage', libelle: 'Stockage des fichiers (R2)', gravite: 3 as const, toutesLes: 15, controle: () => http(`${r2}/bayam/application/derniere.json`, (s) => s < 500) }] : []),
  // Meta doit envoyer les messages Messenger à notre adresse : abonnement actif et adresse exacte.
  ...(meta.app && meta.secret && site ? [{
    nom: 'meta', libelle: 'Abonnement Meta (Messenger)', gravite: 4 as const, toutesLes: 10,
    controle: async (): Promise<Resultat> => {
      try {
        const r = await fetch(`${meta.graph}/${meta.app}/subscriptions?access_token=${encodeURIComponent(`${meta.app}|${meta.secret}`)}`, { signal: AbortSignal.timeout(15_000) });
        const page = ((await r.json()) as { data?: { object: string; active: boolean; callback_url: string }[] }).data?.find((a) => a.object === 'page');
        const attendue = `${site}/webhooks/facebook`;
        return { ok: Boolean(page?.active && page.callback_url === attendue), detail: page ? `actif ${page.active}, adresse ${page.callback_url === attendue ? 'exacte' : 'différente'}` : 'aucun abonnement' };
      } catch (e) {
        return { ok: false, detail: texteErreur(e).slice(0, 120) };
      }
    },
  }] : []),
];

const suivis = new Map<string, Suivi>();
const libelles = new Map<string, string>(sondes.map((s) => [s.nom, s.libelle]));
// Sessions WhatsApp vues reliées au moins une fois : seules elles sont surveillées (une session en cours
// d'association par un nouveau vendeur n'est pas une panne).
const sessionsConnues = new Set<string>();

async function incident(action: 'ouvrir' | 'fermer', nom: string, gravite: number, detail: string): Promise<void> {
  const cle = `sonde:${nom}`;
  const r = action === 'ouvrir'
    ? await fetch(`${retours}/interne/incidents`, {
      method: 'POST',
      headers: { 'content-type': 'application/json', 'x-bayam-service': jeton },
      body: JSON.stringify({ cle, gravite, texte: `Panne détectée par les sondes : ${libelles.get(nom) ?? nom} (${detail})` }),
      signal: AbortSignal.timeout(10_000),
    })
    : await fetch(`${retours}/interne/incidents/${encodeURIComponent(cle)}/retabli`, { method: 'POST', headers: { 'x-bayam-service': jeton }, signal: AbortSignal.timeout(10_000) });
  log[action === 'ouvrir' ? 'alerte' : 'info'](action === 'ouvrir' ? 'incident ouvert' : 'incident fermé', { sonde: nom, statut: r.status });
}

async function noter(nom: string, gravite: number, res: Resultat): Promise<void> {
  const { suivi, action } = suivre(suivis.get(nom) ?? SUIVI_NEUF, res.ok, res.detail, Date.now());
  suivis.set(nom, suivi);
  if (action) {
    await incident(action, nom, gravite, res.detail).catch((e: unknown) => log.erreur('incident non transmis', { sonde: nom, erreur: texteErreur(e) }));
  }
}

async function sessionsWhatsapp(): Promise<void> {
  if (!waha.cle) {
    return;
  }
  const r = await fetch(`${waha.url}/api/sessions?all=true`, { headers: { 'X-Api-Key': waha.cle }, signal: AbortSignal.timeout(10_000) }).catch(() => null);
  if (!r?.ok) {
    await noter('whatsapp', 4, { ok: false, detail: r ? `réponse ${r.status}` : 'connecteur injoignable' });
    return;
  }
  await noter('whatsapp', 4, { ok: true, detail: '' });
  libelles.set('whatsapp', 'Connecteur WhatsApp');
  for (const s of (await r.json()) as { name: string; status: string }[]) {
    if (s.status === 'WORKING') {
      sessionsConnues.add(s.name);
    }
    if (sessionsConnues.has(s.name)) {
      libelles.set(`whatsapp:${s.name}`, `WhatsApp de la boutique ${s.name.replace('boutique-', 'n° ')}`);
      await noter(`whatsapp:${s.name}`, 3, { ok: s.status === 'WORKING', detail: `état ${s.status}` });
    }
  }
}

let tour = 0;
async function controler(): Promise<void> {
  tour++;
  await Promise.all(sondes.filter((s) => tour % s.toutesLes === 1 % s.toutesLes).map(async (s) => noter(s.nom, s.gravite, await s.controle())));
  await sessionsWhatsapp().catch((e: unknown) => log.erreur('sessions WhatsApp', { erreur: texteErreur(e) }));
}

const app = Fastify();
app.get('/sante', async () => ({ ok: true, sondes: suivis.size }));
app.get('/etat', async (_req, reply) => {
  const composants = Object.fromEntries([...suivis].map(([nom, s]) => [nom, { libelle: libelles.get(nom) ?? nom, etat: etatPublic(s) }]));
  const global = Object.values(composants).some((c) => c.etat === 'panne') ? 'panne' : Object.values(composants).some((c) => c.etat === 'perturbe') ? 'perturbe' : 'operationnel';
  return reply.header('cache-control', 'public, max-age=30').send({ genere: new Date().toISOString(), global, composants });
});
await app.listen({ host: '0.0.0.0', port: nombre('PORT', 8094) });
log.info("à l'écoute", { port: nombre('PORT', 8094), sondes: sondes.map((s) => s.nom) });

const minuteur = setInterval(() => void controler().catch((e: unknown) => log.erreur('tour de sondes', { erreur: texteErreur(e) })), 60_000);
void controler();
for (const signal of ['SIGTERM', 'SIGINT'] as const) {
  process.once(signal, async () => {
    clearInterval(minuteur);
    await app.close();
    process.exit(0);
  });
}
