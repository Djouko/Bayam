// Envoi des réponses comme le ferait une personne : indicateur « écrit… », pauses entre deux messages, et plafonds
// par discussion et par jour pour protéger le numéro du vendeur (WhatsApp bannit les comptes qui envoient en rafale).

import type pg from 'pg';
import type { RedisClientType } from 'redis';
import type { Journal } from '../commun/journal.ts';
import type { EnvoiPret } from './catalogue.ts';
import type { Waha } from './waha.ts';

export type Plafonds = { parDiscussion10Min: number; parSessionJour: number };

const pause = (min: number, max: number) => new Promise((ok) => setTimeout(ok, min + Math.random() * (max - min)));

export class Expediteur {
  private readonly waha: Waha;
  private readonly redis: RedisClientType;
  private readonly pool: pg.Pool;
  private readonly log: Journal;
  private readonly plafonds: Plafonds;
  /** File par session : un même numéro n'envoie jamais deux messages au même instant. */
  private readonly files = new Map<string, Promise<unknown>>();

  constructor(waha: Waha, redis: RedisClientType, pool: pg.Pool, log: Journal, plafonds: Plafonds) {
    this.waha = waha;
    this.redis = redis;
    this.pool = pool;
    this.log = log;
    this.plafonds = plafonds;
  }

  /**
   * Envoie les messages dans l'ordre ; renvoie le nombre envoyé (0 si un plafond est atteint). soi : discussion du
   * vendeur avec lui-même (menu BAYAM), sans risque pour le numéro, donc sans plafond par discussion.
   */
  envoyer(session: string, chat: string, chatVendeur: string | null, envois: EnvoiPret[], soi = false): Promise<number> {
    const precedent = this.files.get(session) ?? Promise.resolve();
    const suivant = precedent.catch(() => undefined).then(() => this.envoyerMaintenant(session, chat, chatVendeur, envois, soi));
    this.files.set(session, suivant);
    void suivant.finally(() => this.files.get(session) === suivant && this.files.delete(session)).catch(() => undefined);
    return suivant;
  }

  private async envoyerMaintenant(session: string, chat: string, chatVendeur: string | null, envois: EnvoiPret[], soi: boolean): Promise<number> {
    const auClient = soi ? 0 : envois.filter((e) => e.type !== 'au_vendeur').length;
    const jour = new Date().toISOString().slice(0, 10);
    const [discussion, quotidien] = await Promise.all([
      this.compter(`canal:plafond:chat:${session}:${chat}`, auClient, 600),
      this.compter(`canal:plafond:jour:${session}:${jour}`, envois.length, 2 * 86_400),
    ]);
    if (discussion > this.plafonds.parDiscussion10Min || quotidien > this.plafonds.parSessionJour) {
      this.log.alerte('plafond d\'envoi atteint, réponse retenue', { session, discussion, quotidien });
      return 0;
    }
    let envoyes = 0;
    await this.waha.ecrit(session, chat, true);
    try {
      for (const e of envois) {
        const destinataire = e.type === 'au_vendeur' ? chatVendeur : chat;
        if (!destinataire) {
          continue;
        }
        // Le temps de « taper » : plus long avant une image ou une liste qu'avant une courte phrase.
        await pause(e.type === 'texte' || e.type === 'au_vendeur' ? 500 : 900, e.type === 'texte' || e.type === 'au_vendeur' ? 1100 : 1600);
        const id = await this.waha.envoyer(session, destinataire, e);
        envoyes++;
        await this.pool.query('INSERT INTO canal_whatsapp.messages_envoyes (session, chat, nature, message_id) VALUES ($1, $2, $3, $4)', [session, destinataire, e.type, id]);
      }
    } finally {
      await this.waha.ecrit(session, chat, false);
    }
    return envoyes;
  }

  private async compter(cle: string, nombre: number, duree: number): Promise<number> {
    const total = await this.redis.incrBy(cle, nombre);
    if (total === nombre) {
      await this.redis.expire(cle, duree);
    }
    return total;
  }
}
