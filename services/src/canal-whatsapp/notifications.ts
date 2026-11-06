// Boîte d'envoi durable : un service dépose une notification (confirmation de commande…), le canal l'envoie
// quand le numéro du vendeur est disponible, avec nouvelles tentatives espacées. Plusieurs instances se partagent
// la file sans doublon (verrouillage ligne par ligne, SKIP LOCKED).

import type pg from 'pg';
import { MARQUE } from '../passerelle/filtre.ts';
import type { Journal } from '../commun/journal.ts';
import { texteErreur } from '../commun/journal.ts';
import type { Expediteur } from './expediteur.ts';

export type Notification = { cle: string; session: string; chat: string; texte: string };

const ESSAIS_MAX = 8;

export function valide(n: Partial<Notification>): n is Notification {
  return typeof n.cle === 'string' && /^[\w:-]{1,100}$/.test(n.cle)
    && typeof n.session === 'string' && /^[\w-]{1,64}$/.test(n.session)
    && typeof n.chat === 'string' && /^[\w.-]{3,64}@(c\.us|lid|s\.whatsapp\.net)$/.test(n.chat)
    && typeof n.texte === 'string' && n.texte.length > 0 && n.texte.length <= 4000;
}

/** Dépôt idempotent : la même clé (« commande-412 ») n'est envoyée qu'une fois, même déposée deux fois. */
export async function deposer(pool: pg.Pool, n: Notification): Promise<void> {
  await pool.query('INSERT INTO canal_whatsapp.notifications (cle, session, chat, texte) VALUES ($1, $2, $3, $4) ON CONFLICT (cle) DO NOTHING', [n.cle, n.session, n.chat, n.texte]);
}

/** Envoie les notifications dues ; renvoie le nombre traité. */
export async function envoyerDues(pool: pg.Pool, expediteur: Expediteur, log: Journal): Promise<number> {
  const client = await pool.connect();
  try {
    await client.query('BEGIN');
    const { rows } = await client.query<Notification & { id: string; essais: number }>(
      "SELECT id, cle, session, chat, texte, essais FROM canal_whatsapp.notifications WHERE statut = 'attente' AND prochain <= now() ORDER BY prochain LIMIT 10 FOR UPDATE SKIP LOCKED",
    );
    for (const n of rows) {
      try {
        const envoyes = await expediteur.envoyer(n.session, n.chat, null, [{ type: 'texte', texte: MARQUE + n.texte }]);
        if (envoyes === 0) {
          throw new Error('plafond d\'envoi atteint');
        }
        // Texte effacé une fois envoyé : il contient l'adresse et la commande du client.
        await client.query("UPDATE canal_whatsapp.notifications SET statut = 'envoye', envoye = now(), texte = '' WHERE id = $1", [n.id]);
      } catch (e) {
        const essais = n.essais + 1;
        const statut = essais >= ESSAIS_MAX ? 'echec' : 'attente';
        await client.query(
          "UPDATE canal_whatsapp.notifications SET essais = $2, statut = $3, prochain = now() + make_interval(secs => $4) WHERE id = $1",
          [n.id, essais, statut, Math.min(3600, 15 * 2 ** essais)],
        );
        log[statut === 'echec' ? 'erreur' : 'alerte']('notification non envoyée', { cle: n.cle, essais, erreur: texteErreur(e) });
      }
    }
    await client.query('COMMIT');
    return rows.length;
  } catch (e) {
    await client.query('ROLLBACK');
    throw e;
  } finally {
    client.release();
  }
}
