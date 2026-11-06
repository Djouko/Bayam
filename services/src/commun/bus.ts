// Bus d'événements NATS JetStream : chaque événement WhatsApp est écrit sur disque avant d'être acquitté à WAHA,
// puis lu par les services qui en ont besoin, à leur rythme, avec redistribution en cas d'échec. Un service peut
// redémarrer ou tomber : rien ne se perd, rien n'est traité deux fois grâce à l'identifiant de l'événement.

import { connect, nanos, type NatsConnection } from '@nats-io/transport-node';
import { DiscardPolicy, jetstreamManager, RetentionPolicy, StorageType, type StreamConfig } from '@nats-io/jetstream';

export const FLUX_WHATSAPP = 'WHATSAPP';
/** Sujet d'un événement : whatsapp.evenements.<session WAHA>. */
export const SUJET_WHATSAPP = 'whatsapp.evenements';

export function connecterBus(serveurs: string, nom: string): Promise<NatsConnection> {
  // Reconnexion sans fin : le bus redémarre, le service attend et reprend.
  return connect({ servers: serveurs.split(','), name: nom, maxReconnectAttempts: -1, reconnectTimeWait: 1000 });
}

/** Crée ou met à jour le flux des événements WhatsApp (au démarrage de chaque service, sans effet s'il existe). */
export async function assurerFluxWhatsapp(nc: NatsConnection, repliques = 1): Promise<void> {
  const jsm = await jetstreamManager(nc);
  const config: Partial<StreamConfig> & { name: string } = {
    name: FLUX_WHATSAPP,
    subjects: [`${SUJET_WHATSAPP}.>`],
    retention: RetentionPolicy.Limits,
    storage: StorageType.File,
    discard: DiscardPolicy.Old,
    // Une semaine d'historique pour rejouer après incident ; doublons de WAHA (nouvelles tentatives) écartés 10 min.
    max_age: nanos(7 * 24 * 3600 * 1000),
    max_bytes: 4 * 1024 ** 3,
    duplicate_window: nanos(10 * 60 * 1000),
    num_replicas: repliques,
  };
  try {
    await jsm.streams.info(FLUX_WHATSAPP);
    await jsm.streams.update(FLUX_WHATSAPP, config);
  } catch (e) {
    if (!/stream not found/i.test(e instanceof Error ? e.message : String(e))) {
      throw e;
    }
    await jsm.streams.add(config);
  }
}
