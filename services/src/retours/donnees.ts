// Accès PostgreSQL du service des retours (schéma « retours »). Chaque écriture se fait en transaction ; la création
// d'un signalement est sérialisée par catégorie pour que deux doublons simultanés rejoignent le même sujet.

import type pg from 'pg';

import {
  categoriser, graviter, niveauAlerte, OUVERTS, rattacher, score,
  type Categorie, type Gravite, type Statut, type TypeRetour,
} from './classement.ts';

export type Contact =
  | { canal: 'whatsapp'; session: string; chat: string }
  | { canal: 'messenger'; page: string; client: string };

export type Entree = {
  type: TypeRetour;
  texte: string;
  canal: string;
  personne: string;
  auteur?: string;
  vendeur?: number;
  fonction?: string;
  contact?: Contact;
  contexte?: Record<string, string>;
  gravite?: Gravite;
  categorie?: Categorie;
  cleIncident?: string;
};

export type Sujet = {
  id: number; titre: string; type: TypeRetour; categorie: Categorie; gravite: Gravite; statut: Statut; fonction: string | null;
  signaleurs: number; signalements: number; alerte: number; assigne: string | null; resolution: string | null;
  doublon_de: number | null; premier: Date; dernier: Date; modifie: Date; score: number;
};

export type Resultat = { numero: string; sujet: number; signaleurs: number; gravite: Gravite; nouveauSujet: boolean; alerte: 0 | 1 | 2; alerteEnvoyee: number };

export type Filtres = { statut?: Statut | 'ouverts'; categorie?: Categorie; type?: TypeRetour; limite: number };

export type Changements = { statut?: Statut; assigne?: string | null; gravite?: Gravite; categorie?: Categorie; resolution?: string; note?: string };

export type APrevenir = { id: number; contact: Contact };

export interface Depot {
  signaler(e: Entree, maintenant: number): Promise<Resultat>;
  sujets(f: Filtres, maintenant: number): Promise<Sujet[]>;
  sujet(id: number, maintenant: number): Promise<{ sujet: Sujet; signalements: unknown[]; historique: unknown[] } | null>;
  modifier(id: number, c: Changements, auteur: string, maintenant: number): Promise<Sujet | null>;
  fusionner(id: number, dans: number, auteur: string, maintenant: number): Promise<Sujet | null>;
  retablir(cle: string, auteur: string, maintenant: number): Promise<Sujet | null>;
  noterAlerte(id: number, niveau: number): Promise<void>;
  aPrevenir(id: number): Promise<APrevenir[]>;
  marquerPrevenus(ids: number[]): Promise<void>;
  statistiques(): Promise<Record<string, unknown>>;
  menage(): Promise<number>;
}

type Ligne = Omit<Sujet, 'score'>;
const COLONNES = 'id::int, titre, type, categorie, gravite, statut, fonction, signaleurs, signalements, alerte, assigne, resolution, doublon_de::int, premier, dernier, modifie';
const avecScore = (l: Ligne, maintenant: number): Sujet => ({ ...l, score: score(l, maintenant) });
const titreDe = (texte: string) => (texte.length > 90 ? `${texte.slice(0, 89).trimEnd()}…` : texte);

export class DepotPostgres implements Depot {
  private readonly pool: pg.Pool;

  constructor(pool: pg.Pool) {
    this.pool = pool;
  }

  private async transaction<T>(travail: (c: pg.PoolClient) => Promise<T>): Promise<T> {
    const c = await this.pool.connect();
    try {
      await c.query('BEGIN');
      const r = await travail(c);
      await c.query('COMMIT');
      return r;
    } catch (e) {
      await c.query('ROLLBACK');
      throw e;
    } finally {
      c.release();
    }
  }

  async signaler(e: Entree, maintenant: number): Promise<Resultat> {
    const categorie = e.categorie ?? categoriser(e.texte);
    const gravite = e.gravite ?? graviter(e.type, categorie, e.texte);
    const fonction = e.fonction ?? null;
    return this.transaction(async (c) => {
      await c.query('SELECT pg_advisory_xact_lock(hashtext($1))', [`retours:${categorie}`]);
      let sujet: number | null = null;
      if (e.cleIncident) {
        sujet = (await c.query<{ id: number }>('SELECT id::int FROM retours.sujets WHERE cle_incident = $1', [e.cleIncident])).rows[0]?.id ?? null;
      } else {
        const candidats = await c.query<{ id: number; categorie: Categorie; fonction: string | null; titre: string; dernier: Date }>(
          `SELECT id::int, categorie, fonction, titre, dernier FROM retours.sujets
            WHERE statut = ANY($1) AND categorie = $2 AND type <> 'incident' AND dernier > now() - interval '30 days'
            ORDER BY dernier DESC LIMIT 300`,
          [[...OUVERTS, 'corrige'], categorie],
        );
        sujet = rattacher({ categorie, fonction, texte: e.texte }, candidats.rows.map((r) => ({ ...r, texte: r.titre })), maintenant);
      }
      const nouveauSujet = sujet === null;
      if (sujet === null) {
        sujet = (await c.query<{ id: number }>(
          `INSERT INTO retours.sujets (titre, type, categorie, gravite, fonction, cle_incident) VALUES ($1, $2, $3, $4, $5, $6) RETURNING id::int`,
          [titreDe(e.texte), e.type, categorie, gravite, fonction, e.cleIncident ?? null],
        )).rows[0].id;
        await c.query(`INSERT INTO retours.historique (sujet, action, auteur, details) VALUES ($1, 'cree', 'systeme', $2)`, [sujet, { categorie, gravite }]);
      }
      const avant = (await c.query<{ statut: Statut }>('SELECT statut FROM retours.sujets WHERE id = $1 FOR UPDATE', [sujet])).rows[0].statut;
      if (avant === 'corrige' || avant === 'ferme') {
        await c.query(`INSERT INTO retours.historique (sujet, action, auteur, details) VALUES ($1, 'rouvert', 'systeme', $2)`, [sujet, { avant }]);
      }
      const deja = (await c.query('SELECT 1 FROM retours.signalements WHERE sujet = $1 AND personne = $2 LIMIT 1', [sujet, e.personne])).rowCount;
      const id = (await c.query<{ id: number }>(
        `INSERT INTO retours.signalements (sujet, type, texte, canal, auteur, personne, vendeur, contact, contexte)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9) RETURNING id::int`,
        [sujet, e.type, e.texte, e.canal, e.auteur ?? '', e.personne, e.vendeur ?? null, e.contact ?? null, e.contexte ?? {}],
      )).rows[0].id;
      // Un incident qui revient après avoir été clos est rouvert ; un sujet corrigé qui revient aussi (régression).
      const s = (await c.query<{ signaleurs: number; gravite: Gravite; type: TypeRetour; alerte: number }>(
        `UPDATE retours.sujets SET signalements = signalements + 1, signaleurs = signaleurs + $2, dernier = now(), modifie = now(),
                gravite = GREATEST(gravite, $3),
                statut = CASE WHEN statut IN ('corrige', 'ferme') THEN 'nouveau' ELSE statut END
          WHERE id = $1 RETURNING signaleurs, gravite, type, alerte`,
        [sujet, deja ? 0 : 1, gravite],
      )).rows[0];
      return { numero: `R-${id}`, sujet, signaleurs: s.signaleurs, gravite: s.gravite, nouveauSujet, alerte: niveauAlerte(s), alerteEnvoyee: s.alerte };
    });
  }

  async sujets(f: Filtres, maintenant: number): Promise<Sujet[]> {
    const conditions: string[] = [];
    const valeurs: unknown[] = [];
    if (f.statut === 'ouverts' || !f.statut) {
      valeurs.push(OUVERTS);
      conditions.push(`statut = ANY($${valeurs.length})`);
    } else {
      valeurs.push(f.statut);
      conditions.push(`statut = $${valeurs.length}`);
    }
    for (const [colonne, valeur] of [['categorie', f.categorie], ['type', f.type]] as const) {
      if (valeur) {
        valeurs.push(valeur);
        conditions.push(`${colonne} = $${valeurs.length}`);
      }
    }
    valeurs.push(Math.min(Math.max(f.limite, 1), 500));
    const r = await this.pool.query<Ligne>(
      `SELECT ${COLONNES} FROM retours.sujets WHERE ${conditions.join(' AND ')} ORDER BY dernier DESC LIMIT $${valeurs.length}`,
      valeurs,
    );
    return r.rows.map((l) => avecScore(l, maintenant)).sort((a, b) => b.score - a.score || b.dernier.getTime() - a.dernier.getTime());
  }

  async sujet(id: number, maintenant: number) {
    const s = (await this.pool.query<Ligne>(`SELECT ${COLONNES} FROM retours.sujets WHERE id = $1`, [id])).rows[0];
    if (!s) {
      return null;
    }
    const signalements = (await this.pool.query(
      `SELECT id::int, type, texte, canal, auteur, vendeur, contexte, (contact IS NOT NULL) AS joignable, prevenu, cree
         FROM retours.signalements WHERE sujet = $1 ORDER BY cree DESC LIMIT 200`, [id],
    )).rows;
    const historique = (await this.pool.query(
      'SELECT action, auteur, details, quand FROM retours.historique WHERE sujet = $1 ORDER BY quand DESC LIMIT 200', [id],
    )).rows;
    return { sujet: avecScore(s, maintenant), signalements, historique };
  }

  async modifier(id: number, ch: Changements, auteur: string, maintenant: number): Promise<Sujet | null> {
    return this.transaction(async (c) => {
      const avant = (await c.query<Ligne>(`SELECT ${COLONNES} FROM retours.sujets WHERE id = $1 FOR UPDATE`, [id])).rows[0];
      if (!avant) {
        return null;
      }
      const l = (await c.query<Ligne>(
        `UPDATE retours.sujets SET statut = COALESCE($2, statut), assigne = CASE WHEN $3::boolean THEN $4 ELSE assigne END,
                gravite = COALESCE($5, gravite), categorie = COALESCE($6, categorie), resolution = COALESCE($7, resolution), modifie = now()
          WHERE id = $1 RETURNING ${COLONNES}`,
        [id, ch.statut ?? null, ch.assigne !== undefined, ch.assigne ?? null, ch.gravite ?? null, ch.categorie ?? null, ch.resolution ?? null],
      )).rows[0];
      const details: Record<string, unknown> = {};
      for (const cle of ['statut', 'assigne', 'gravite', 'categorie', 'resolution'] as const) {
        if (ch[cle] !== undefined && ch[cle] !== avant[cle]) {
          details[cle] = { avant: avant[cle], apres: ch[cle] };
        }
      }
      if (ch.note) {
        details.note = ch.note;
      }
      if (Object.keys(details).length) {
        await c.query(`INSERT INTO retours.historique (sujet, action, auteur, details) VALUES ($1, 'modifie', $2, $3)`, [id, auteur, details]);
      }
      return avecScore(l, maintenant);
    });
  }

  async fusionner(id: number, dans: number, auteur: string, maintenant: number): Promise<Sujet | null> {
    if (id === dans) {
      return null;
    }
    return this.transaction(async (c) => {
      const deux = (await c.query('SELECT id FROM retours.sujets WHERE id = ANY($1) FOR UPDATE', [[id, dans]])).rowCount;
      if (deux !== 2) {
        return null;
      }
      await c.query('UPDATE retours.signalements SET sujet = $2 WHERE sujet = $1', [id, dans]);
      await c.query(`UPDATE retours.sujets SET statut = 'doublon', doublon_de = $2, signalements = 0, signaleurs = 0, modifie = now() WHERE id = $1`, [id, dans]);
      const l = (await c.query<Ligne>(
        `UPDATE retours.sujets s SET signalements = n.total, signaleurs = n.personnes, modifie = now(),
                gravite = GREATEST(s.gravite, (SELECT gravite FROM retours.sujets WHERE id = $2)), dernier = GREATEST(s.dernier, n.dernier)
           FROM (SELECT count(*)::int total, count(DISTINCT personne)::int personnes, max(cree) dernier FROM retours.signalements WHERE sujet = $1) n
          WHERE s.id = $1 RETURNING ${COLONNES.split(', ').map((x) => `s.${x}`).join(', ')}`,
        [dans, id],
      )).rows[0];
      await c.query(`INSERT INTO retours.historique (sujet, action, auteur, details) VALUES ($1, 'fusionne', $2, $3), ($4, 'fusionne', $2, $5)`,
        [dans, auteur, { recu: id }, id, { vers: dans }]);
      return avecScore(l, maintenant);
    });
  }

  async retablir(cle: string, auteur: string, maintenant: number): Promise<Sujet | null> {
    return this.transaction(async (c) => {
      const l = (await c.query<Ligne>(
        `UPDATE retours.sujets SET statut = 'corrige', resolution = 'Rétabli automatiquement', modifie = now()
          WHERE cle_incident = $1 AND statut = ANY($2) RETURNING ${COLONNES}`,
        [cle, OUVERTS],
      )).rows[0];
      if (!l) {
        return null;
      }
      await c.query(`INSERT INTO retours.historique (sujet, action, auteur, details) VALUES ($1, 'retabli', $2, '{}')`, [l.id, auteur]);
      return avecScore(l, maintenant);
    });
  }

  async noterAlerte(id: number, niveau: number): Promise<void> {
    await this.pool.query('UPDATE retours.sujets SET alerte = GREATEST(alerte, $2) WHERE id = $1', [id, niveau]);
  }

  async aPrevenir(id: number): Promise<APrevenir[]> {
    return (await this.pool.query<APrevenir>(
      'SELECT id::int, contact FROM retours.signalements WHERE sujet = $1 AND contact IS NOT NULL AND prevenu IS NULL ORDER BY cree LIMIT 500', [id],
    )).rows;
  }

  async marquerPrevenus(ids: number[]): Promise<void> {
    if (ids.length) {
      await this.pool.query('UPDATE retours.signalements SET prevenu = now() WHERE id = ANY($1)', [ids]);
    }
  }

  async statistiques(): Promise<Record<string, unknown>> {
    const parStatut = (await this.pool.query('SELECT statut, count(*)::int n FROM retours.sujets GROUP BY statut')).rows;
    const ouverts = (await this.pool.query(
      `SELECT categorie, count(*)::int sujets, sum(signaleurs)::int personnes, max(gravite)::int gravite_max
         FROM retours.sujets WHERE statut = ANY($1) GROUP BY categorie ORDER BY gravite_max DESC, personnes DESC`, [OUVERTS],
    )).rows;
    const jours = (await this.pool.query(
      `SELECT to_char(date_trunc('day', cree), 'YYYY-MM-DD') jour, count(*)::int n FROM retours.signalements
        WHERE cree > now() - interval '14 days' GROUP BY 1 ORDER BY 1`,
    )).rows;
    const delai = (await this.pool.query(
      `SELECT round(extract(epoch FROM percentile_cont(0.5) WITHIN GROUP (ORDER BY h.quand - s.premier)) / 3600, 1)::float heures
         FROM retours.sujets s JOIN retours.historique h ON h.sujet = s.id AND h.details ? 'statut' AND h.details->'statut'->>'apres' = 'corrige'
        WHERE s.premier > now() - interval '30 days'`,
    )).rows[0]?.heures ?? null;
    return { parStatut, ouverts, signalementsParJour: jours, delaiMedianCorrectionHeures: delai };
  }

  /** Données personnelles minimales : le moyen de contact s'efface 30 jours après la clôture du sujet. */
  async menage(): Promise<number> {
    return (await this.pool.query(
      `UPDATE retours.signalements g SET contact = NULL FROM retours.sujets s
        WHERE g.sujet = s.id AND g.contact IS NOT NULL AND s.statut NOT IN ('nouveau', 'confirme', 'en_cours') AND s.modifie < now() - interval '30 days'`,
    )).rowCount ?? 0;
  }
}
