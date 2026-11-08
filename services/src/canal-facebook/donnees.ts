// Données du canal Facebook (schéma canal_facebook) : Pages connectées, liens personnels de commande, file des
// publications et des confirmations. Les files se partagent entre instances sans doublon (FOR UPDATE SKIP LOCKED).

import { randomBytes } from 'node:crypto';
import type pg from 'pg';
import { chiffrer, dechiffrer } from './coffre.ts';

export type Page = { page: string; vendeur: number; nom: string; jeton: string; actif: boolean; permissions: string[] };

export class Donnees {
  private readonly pool: pg.Pool;
  private readonly cle: Buffer;
  private readonly memoire = new Map<string, { page: Page | null; lue: number }>();

  constructor(pool: pg.Pool, cle: Buffer) {
    this.pool = pool;
    this.cle = cle;
  }

  // --- Pages ---

  async enregistrerPage(p: Omit<Page, 'actif'>): Promise<void> {
    await this.pool.query(
      `INSERT INTO canal_facebook.pages (page, vendeur, nom, jeton, permissions) VALUES ($1, $2, $3, $4, $5)
       ON CONFLICT (page) DO UPDATE SET vendeur = EXCLUDED.vendeur, nom = EXCLUDED.nom, jeton = EXCLUDED.jeton, permissions = EXCLUDED.permissions, actif = true, connectee = now()`,
      [p.page, p.vendeur, p.nom, chiffrer(p.jeton, this.cle), p.permissions],
    );
    this.memoire.delete(p.page);
  }

  /** Page connectée et active, gardée 60 s en mémoire : un événement ne coûte pas une requête en base. */
  async page(id: string): Promise<Page | null> {
    const connue = this.memoire.get(id);
    if (connue && Date.now() - connue.lue < 60_000) {
      return connue.page;
    }
    const r = await this.pool.query<Page>('SELECT page, vendeur, nom, jeton, actif, permissions FROM canal_facebook.pages WHERE page = $1 AND actif', [id]);
    const page = r.rows[0] ? { ...r.rows[0], jeton: dechiffrer(r.rows[0].jeton, this.cle) } : null;
    this.memoire.set(id, { page, lue: Date.now() });
    return page;
  }

  async pagesDuVendeur(vendeur: number): Promise<Omit<Page, 'jeton'>[]> {
    const r = await this.pool.query<Omit<Page, 'jeton'>>('SELECT page, vendeur, nom, actif, permissions FROM canal_facebook.pages WHERE vendeur = $1 ORDER BY connectee', [vendeur]);
    return r.rows;
  }

  /** Page retirée par le vendeur : la ligne et son jeton sont effacés (les publications passées restent). */
  async supprimerPage(id: string): Promise<void> {
    await this.pool.query('DELETE FROM canal_facebook.pages WHERE page = $1', [id]);
    this.memoire.delete(id);
  }

  /** Jeton refusé par Meta (mot de passe changé, application retirée) : la Page attend une nouvelle connexion. */
  async desactiverPage(id: string): Promise<void> {
    await this.pool.query('UPDATE canal_facebook.pages SET actif = false WHERE page = $1', [id]);
    this.memoire.delete(id);
  }

  // --- Liens personnels de commande ---

  async creerJeton(j: { vendeur: number; produit: number; page: string; client: string }): Promise<string> {
    const jeton = randomBytes(18).toString('base64url');
    await this.pool.query(
      "INSERT INTO canal_facebook.jetons_commande (jeton, vendeur, produit, page, client, expire) VALUES ($1, $2, $3, $4, $5, now() + interval '24 hours')",
      [jeton, j.vendeur, j.produit, j.page, j.client],
    );
    return jeton;
  }

  /** Même réponse que le canal WhatsApp (numero vide : Messenger ne donne pas le numéro), plus le canal et la Page. */
  async ouvrirJeton(jeton: string): Promise<Record<string, unknown> | null> {
    if (!/^[A-Za-z0-9_-]{24}$/.test(jeton)) {
      return null;
    }
    const r = await this.pool.query(
      `UPDATE canal_facebook.jetons_commande SET ouvertures = ouvertures + 1 WHERE jeton = $1 AND expire > now()
       RETURNING vendeur, produit, page, client AS chat, '' AS numero, 'facebook' AS canal,
         to_char(expire AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS expire`,
      [jeton],
    );
    return r.rows[0] ?? null;
  }

  // --- Publications ---

  async demanderPublications(vendeur: number, pages: string[], type: 'publication' | 'story', produits: number[]): Promise<void> {
    for (const page of pages) {
      await this.pool.query('INSERT INTO canal_facebook.publications (vendeur, page, type, produits) VALUES ($1, $2, $3, $4)', [vendeur, page, type, produits]);
    }
  }

  async publication(id: number): Promise<{ page: string; produits: number[] } | null> {
    const r = await this.pool.query<{ page: string; produits: number[] }>('SELECT page, produits FROM canal_facebook.publications WHERE id = $1', [id]);
    return r.rows[0] ?? null;
  }

  /** Publication de la Page à laquelle répond un commentaire (identifiant « <Page>_<publication> »). */
  async publicationDuPost(post: string): Promise<{ id: number; produits: number[] } | null> {
    const r = await this.pool.query<{ id: string; produits: number[] }>("SELECT id, produits FROM canal_facebook.publications WHERE publication = $1 AND statut = 'publie'", [post]);
    return r.rows[0] ? { id: Number(r.rows[0].id), produits: r.rows[0].produits } : null;
  }

  /** Traite les éléments dus d'une file, sous verrou ligne par ligne ; renvoie le nombre traité. */
  async file<T extends { id: string }>(table: 'publications' | 'notifications', colonnes: string, traiter: (ligne: T) => Promise<Record<string, unknown>>, essaisMax: number, journaliser: (ligne: T, essais: number, definitif: boolean, e: unknown) => void): Promise<number> {
    const client = await this.pool.connect();
    try {
      await client.query('BEGIN');
      const { rows } = await client.query<T & { essais: number }>(`SELECT id, essais, ${colonnes} FROM canal_facebook.${table} WHERE statut = 'attente' AND prochain <= now() ORDER BY prochain LIMIT 10 FOR UPDATE SKIP LOCKED`);
      for (const ligne of rows) {
        try {
          const fait = await traiter(ligne);
          const champs = Object.keys(fait);
          await client.query(`UPDATE canal_facebook.${table} SET ${champs.map((c, i) => `${c} = $${i + 2}`).join(', ')} WHERE id = $1`, [ligne.id, ...Object.values(fait)]);
        } catch (e) {
          const definitif = (e as { definitive?: boolean }).definitive === true;
          const essais = ligne.essais + 1;
          const statut = definitif || essais >= essaisMax ? 'echec' : 'attente';
          await client.query(`UPDATE canal_facebook.${table} SET essais = $2, statut = $3, prochain = now() + make_interval(secs => $4) WHERE id = $1`, [ligne.id, essais, statut, Math.min(3600, 15 * 2 ** essais)]);
          journaliser(ligne, essais, statut === 'echec', e);
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

  // --- Confirmations de commande ---

  async deposerNotification(n: { cle: string; page: string; client: string; texte: string }): Promise<void> {
    await this.pool.query('INSERT INTO canal_facebook.notifications (cle, page, client, texte) VALUES ($1, $2, $3, $4) ON CONFLICT (cle) DO NOTHING', [n.cle, n.page, n.client, n.texte]);
  }

  // --- Événements déjà traités ---

  async dejaTraite(id: string): Promise<boolean> {
    return Boolean((await this.pool.query('SELECT 1 FROM canal_facebook.evenements_traites WHERE evt_id = $1', [id])).rowCount);
  }

  async marquerTraite(id: string): Promise<void> {
    await this.pool.query('INSERT INTO canal_facebook.evenements_traites (evt_id) VALUES ($1) ON CONFLICT DO NOTHING', [id]);
  }

  /** Ménage : jetons expirés depuis une semaine, événements de plus de 8 jours (le bus n'en garde que 7). */
  async nettoyer(): Promise<void> {
    await this.pool.query("DELETE FROM canal_facebook.jetons_commande WHERE expire < now() - interval '7 days'");
    await this.pool.query("DELETE FROM canal_facebook.evenements_traites WHERE traite < now() - interval '8 days'");
  }
}
