// Ordre garanti par discussion : chaîne de traitements en mémoire, verrou Redis entre instances. Deux événements
// d'une même discussion ne sont jamais traités en même temps ni dans le désordre ; les autres avancent en parallèle.

import type { JsMsg } from '@nats-io/jetstream';
import type { RedisClientType } from 'redis';
import { texteErreur, type Journal } from './journal.ts';

export function ordonnanceur(redis: RedisClientType, log: Journal, prefixe: string) {
  const chaines = new Map<string, Promise<void>>();
  return async function dansLOrdre(cle: string, msg: JsMsg, traiter: (msg: JsMsg) => Promise<void>): Promise<void> {
    const verrou = `${prefixe}:verrou:${cle}`;
    const precedent = chaines.get(cle) ?? Promise.resolve();
    const courant = precedent.then(async () => {
      const pris = await redis.set(verrou, '1', { condition: 'NX', expiration: { type: 'PX', value: 60_000 } });
      if (!pris) {
        msg.nak(500); // une autre instance traite cette discussion : on repasse juste après
        return;
      }
      try {
        await traiter(msg);
      } catch (e) {
        const essai = msg.info.deliveryCount;
        log.erreur('traitement en échec, nouvelle tentative', { sujet: msg.subject, essai, erreur: texteErreur(e) });
        msg.nak(Math.min(60_000, 1000 * 2 ** essai));
      } finally {
        await redis.del(verrou);
      }
    });
    chaines.set(cle, courant);
    await courant;
    if (chaines.get(cle) === courant) {
      chaines.delete(cle);
    }
  };
}
