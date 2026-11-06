// Liens personnels de commande : un jeton aléatoire (aucune donnée lisible dans l'adresse), valable 24 h,
// que le site résout auprès de ce service pour préparer le panier et remplir le numéro WhatsApp du client.

import { randomBytes } from 'node:crypto';
import type pg from 'pg';

export type Jeton = { vendeur: number; produit: number; numero: string; chat: string; expire: string };

export async function creerJeton(pool: pg.Pool, j: Omit<Jeton, 'expire'>): Promise<string> {
  const jeton = randomBytes(18).toString('base64url');
  await pool.query(
    "INSERT INTO canal_whatsapp.jetons_commande (jeton, vendeur, produit, numero, chat, expire) VALUES ($1, $2, $3, $4, $5, now() + interval '24 hours')",
    [jeton, j.vendeur, j.produit, j.numero, j.chat],
  );
  return jeton;
}

export async function ouvrirJeton(pool: pg.Pool, jeton: string): Promise<Jeton | null> {
  if (!/^[A-Za-z0-9_-]{24}$/.test(jeton)) {
    return null;
  }
  const r = await pool.query<Jeton>(
    `UPDATE canal_whatsapp.jetons_commande SET ouvertures = ouvertures + 1
     WHERE jeton = $1 AND expire > now()
     RETURNING vendeur, produit, numero, chat, to_char(expire AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS expire`,
    [jeton],
  );
  return r.rows[0] ?? null;
}

/** Ménage : jetons expirés depuis une semaine, événements traités de plus de 8 jours (le bus n'en garde que 7). */
export async function nettoyer(pool: pg.Pool): Promise<void> {
  await pool.query("DELETE FROM canal_whatsapp.jetons_commande WHERE expire < now() - interval '7 days'");
  await pool.query("DELETE FROM canal_whatsapp.evenements_traites WHERE traite < now() - interval '8 days'");
}
