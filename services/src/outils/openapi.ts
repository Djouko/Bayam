// Écrit la documentation OpenAPI de chaque service construit avec des schémas (docs/api/<service>.json), sans base ni
// réseau : l'application est montée avec un dépôt vide. Lancer après tout changement de route : npm run openapi
// (le test « documentation à jour » échoue sinon).

import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

import { creerApp } from '../retours/app.ts';
import type { Depot } from '../retours/donnees.ts';

const vide = new Proxy({}, { get: () => async () => null }) as unknown as Depot;

export async function documentation(): Promise<Record<string, string>> {
  const retours = await creerApp({ depot: vide, jeton: 'documentation' });
  await retours.ready();
  return { retours: `${JSON.stringify(retours.swagger(), null, 2)}\n` };
}

if (import.meta.main) {
  const dossier = join(import.meta.dirname, '..', '..', 'docs', 'api');
  await mkdir(dossier, { recursive: true });
  for (const [nom, contenu] of Object.entries(await documentation())) {
    await writeFile(join(dossier, `${nom}.json`), contenu);
    console.log(`docs/api/${nom}.json`);
  }
}
