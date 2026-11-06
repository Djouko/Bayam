// PostgreSQL : une base, un schéma et un rôle par service (moindre privilège). Les migrations SQL du service
// s'appliquent au démarrage, une seule fois, sous verrou : plusieurs instances peuvent démarrer ensemble.

import { readdir, readFile } from 'node:fs/promises';
import { join } from 'node:path';
import pg from 'pg';

export function creerPool(url: string, max = 10): pg.Pool {
  return new pg.Pool({ connectionString: url, max, idleTimeoutMillis: 30_000, connectionTimeoutMillis: 5_000 });
}

export async function migrer(pool: pg.Pool, schema: string, dossier: string): Promise<string[]> {
  if (!/^[a-z_]+$/.test(schema)) {
    throw new Error(`Nom de schéma invalide : ${schema}`);
  }
  const fichiers = (await readdir(dossier)).filter((f) => f.endsWith('.sql')).sort();
  const client = await pool.connect();
  try {
    await client.query('BEGIN');
    await client.query('SELECT pg_advisory_xact_lock(hashtext($1))', [schema]);
    await client.query(`CREATE TABLE IF NOT EXISTS ${schema}.migrations (nom text PRIMARY KEY, appliquee timestamptz NOT NULL DEFAULT now())`);
    const faites = new Set((await client.query<{ nom: string }>(`SELECT nom FROM ${schema}.migrations`)).rows.map((r) => r.nom));
    const appliquees: string[] = [];
    for (const fichier of fichiers.filter((f) => !faites.has(f))) {
      await client.query(await readFile(join(dossier, fichier), 'utf8'));
      await client.query(`INSERT INTO ${schema}.migrations (nom) VALUES ($1)`, [fichier]);
      appliquees.push(fichier);
    }
    await client.query('COMMIT');
    return appliquees;
  } catch (e) {
    await client.query('ROLLBACK');
    throw e;
  } finally {
    client.release();
  }
}
