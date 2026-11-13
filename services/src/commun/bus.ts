// Bus d'événements NATS JetStream : chaque événement WhatsApp ou Facebook est écrit sur disque avant d'être acquitté
// à son expéditeur (WAHA, Meta), puis lu par les services qui en ont besoin, à leur rythme, avec redistribution en cas d'échec. Un service peut
// redémarrer ou tomber : rien ne se perd, rien n'est traité deux fois grâce à l'identifiant de l'événement.

import { connect, nanos, type NatsConnection } from '@nats-io/transport-node';
import { AckPolicy, DeliverPolicy, DiscardPolicy, jetstream, jetstreamManager, RetentionPolicy, StorageType, type StreamConfig } from '@nats-io/jetstream';

export const FLUX_WHATSAPP = 'WHATSAPP';
/** Sujet d'un événement : whatsapp.evenements.<session WAHA>. */
export const SUJET_WHATSAPP = 'whatsapp.evenements';
export const FLUX_FACEBOOK = 'FACEBOOK';
/** Sujet d'un événement : facebook.evenements.<identifiant de la Page>. */
export const SUJET_FACEBOOK = 'facebook.evenements';

export function connecterBus(serveurs: string, nom: string): Promise<NatsConnection> {
  // Reconnexion sans fin : le bus redémarre, le service attend et reprend.
  // Compte du service sur le bus (infra/nats/nats.conf) : sans lui, NATS refuse la connexion.
  const user = process.env.NATS_UTILISATEUR;
  const pass = process.env.NATS_MOT_DE_PASSE;
  return connect({ servers: serveurs.split(','), name: nom, maxReconnectAttempts: -1, reconnectTimeWait: 1000, ...(user && pass ? { user, pass } : {}) });
}

/** Crée ou met à jour le flux des événements WhatsApp (au démarrage de chaque service, sans effet s'il existe). */
export function assurerFluxWhatsapp(nc: NatsConnection, repliques = 1): Promise<void> {
  return assurerFlux(nc, FLUX_WHATSAPP, SUJET_WHATSAPP, 4 * 1024 ** 3, repliques);
}

/** Même chose pour les événements Facebook (Pages et Messenger), bien moins volumineux. */
export function assurerFluxFacebook(nc: NatsConnection, repliques = 1): Promise<void> {
  return assurerFlux(nc, FLUX_FACEBOOK, SUJET_FACEBOOK, 1024 ** 3, repliques);
}

async function assurerFlux(nc: NatsConnection, nom: string, sujet: string, octets: number, repliques: number): Promise<void> {
  const jsm = await jetstreamManager(nc);
  const config: Partial<StreamConfig> & { name: string } = {
    name: nom,
    subjects: [`${sujet}.>`],
    retention: RetentionPolicy.Limits,
    storage: StorageType.File,
    discard: DiscardPolicy.Old,
    // Une semaine d'historique pour rejouer après incident ; doublons (nouvelles tentatives de l'expéditeur) écartés 10 min.
    max_age: nanos(7 * 24 * 3600 * 1000),
    max_bytes: octets,
    duplicate_window: nanos(10 * 60 * 1000),
    num_replicas: repliques,
  };
  try {
    await jsm.streams.info(nom);
    await jsm.streams.update(nom, config);
  } catch (e) {
    if (!/stream not found/i.test(e instanceof Error ? e.message : String(e))) {
      throw e;
    }
    await jsm.streams.add(config);
  }
}

/**
 * Consommateur durable d'un flux (créé ou mis à jour) : acquittement explicite, redistribution après 90 s sans
 * réponse, 20 tentatives au plus. Plusieurs instances du même service se partagent les événements.
 */
export async function consommer(nc: NatsConnection, flux: string, nom: string, sujet: string) {
  const jsm = await jetstreamManager(nc);
  const config = { ack_policy: AckPolicy.Explicit, filter_subject: `${sujet}.>`, ack_wait: nanos(90_000), max_deliver: 20, max_ack_pending: 1000 };
  try {
    await jsm.consumers.info(flux, nom);
    await jsm.consumers.update(flux, nom, config);
  } catch {
    await jsm.consumers.add(flux, { durable_name: nom, deliver_policy: DeliverPolicy.All, ...config });
  }
  return (await jetstream(nc).consumers.get(flux, nom)).consume({ max_messages: 64 });
}
