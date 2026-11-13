// API du service des retours, construite à part du démarrage (index.ts) pour être testée sans base ni réseau et pour
// produire sa documentation OpenAPI (scripts/openapi.ts). Les schémas valident chaque entrée et filtrent chaque sortie.

import { createHmac, timingSafeEqual } from 'node:crypto';

import swagger from '@fastify/swagger';
import Fastify, { type FastifyInstance } from 'fastify';

import { CATEGORIES, STATUTS, TYPES, type Gravite, type Statut, type TypeRetour } from './classement.ts';
import type { Changements, Contact, Depot, Entree, Filtres, Resultat, Sujet } from './donnees.ts';

export type Options = {
  depot: Depot;
  jeton: string;
  /** Prévient l'équipe d'un sujet urgent (courriel, WhatsApp) ; renvoie true si au moins un envoi a réussi. */
  alerter?: (sujet: { id: number; titre: string; gravite: Gravite; signaleurs: number; niveau: number }) => Promise<boolean>;
  /** Prévient chaque signaleur joignable ; renvoie les identifiants de signalements prévenus. */
  prevenir?: (sujet: Sujet, texte: string) => Promise<number[]>;
  sante?: () => Promise<boolean>;
  maintenant?: () => number;
  journaliser?: boolean;
};

const TEXTE = { type: 'string', minLength: 3, maxLength: 2000 } as const;
const CONTEXTE = { type: 'object', maxProperties: 12, additionalProperties: { type: 'string', maxLength: 200 } } as const;
const CONTACT = {
  oneOf: [
    { type: 'object', required: ['canal', 'session', 'chat'], additionalProperties: false, properties: {
      canal: { const: 'whatsapp' }, session: { type: 'string', pattern: '^[\\w-]{1,64}$' }, chat: { type: 'string', pattern: '^[\\w.-]{3,64}@(c\\.us|lid|s\\.whatsapp\\.net)$' } } },
    { type: 'object', required: ['canal', 'page', 'client'], additionalProperties: false, properties: {
      canal: { const: 'messenger' }, page: { type: 'string', pattern: '^\\d{1,20}$' }, client: { type: 'string', pattern: '^\\d{1,30}$' } } },
  ],
} as const;
const SUJET = {
  type: 'object',
  properties: {
    id: { type: 'integer' }, titre: { type: 'string' }, type: { type: 'string', enum: TYPES }, categorie: { type: 'string', enum: CATEGORIES },
    gravite: { type: 'integer', minimum: 1, maximum: 4, description: '1 basse, 2 moyenne, 3 haute (bloquant), 4 critique' },
    statut: { type: 'string', enum: STATUTS }, fonction: { type: ['string', 'null'] }, signaleurs: { type: 'integer', description: 'personnes distinctes' },
    signalements: { type: 'integer' }, alerte: { type: 'integer' }, assigne: { type: ['string', 'null'] }, resolution: { type: ['string', 'null'] },
    doublon_de: { type: ['integer', 'null'] }, premier: { type: 'string', format: 'date-time' }, dernier: { type: 'string', format: 'date-time' },
    modifie: { type: 'string', format: 'date-time' }, score: { type: 'number', description: "urgence : gravité, personnes touchées, fraîcheur" },
  },
} as const;
const ACCUSE = {
  type: 'object',
  properties: {
    numero: { type: 'string', description: 'numéro à donner à la personne (R-123)' }, sujet: { type: 'integer' },
    signaleurs: { type: 'integer' }, message: { type: 'string', description: 'réponse prête à envoyer à la personne' },
  },
} as const;
const ERREUR = { type: 'object', properties: { erreur: { type: 'string' } } } as const;
const SERVICE = [{ service: [] }];

/** Réponse à la personne : son numéro, et le fait qu'elle n'est pas seule quand d'autres ont signalé la même chose. */
export function merci(numero: string, type: TypeRetour, signaleurs: number): string {
  const autres = signaleurs - 1;
  if (type === 'idee') {
    return `🙏 Merci pour votre idée (${numero}) ! L'équipe BAYAM l'étudie.${autres > 0 ? ` ${autres} autre${autres > 1 ? 's personnes la proposent' : ' personne la propose'} aussi.` : ''}`;
  }
  if (type === 'question') {
    return `🙏 Merci, votre question (${numero}) est bien arrivée à l'équipe BAYAM. Nous vous répondons ici.`;
  }
  return autres > 0
    ? `🙏 Merci ! Votre signalement (${numero}) est bien arrivé. ${autres} autre${autres > 1 ? 's personnes nous ont' : ' personne nous a'} signalé la même chose : l'équipe BAYAM s'en occupe déjà. Nous vous prévenons ici dès que c'est réglé.`
    : `🙏 Merci ! Votre signalement (${numero}) est bien arrivé à l'équipe BAYAM. Nous vous prévenons ici dès que c'est réglé.`;
}

export const MESSAGE_CORRIGE = "✅ Bonne nouvelle : le souci que vous nous aviez signalé est réglé. Merci de nous aider à améliorer BAYAM 🙏";

export async function creerApp(o: Options): Promise<FastifyInstance> {
  const maintenant = o.maintenant ?? Date.now;
  const app = Fastify({ bodyLimit: 20_000, logger: o.journaliser ?? false, trustProxy: true });
  await app.register(swagger, {
    openapi: {
      info: {
        title: 'BAYAM, service des retours',
        version: '1.0.0',
        description: "Signalements, idées et pannes détectées : regroupement des doublons en sujets, urgence, suivi jusqu'à la résolution, réponse aux signaleurs.",
      },
      components: { securitySchemes: { service: { type: 'apiKey', in: 'header', name: 'x-bayam-service', description: 'jeton de service partagé (réseau interne)' } } },
      tags: [{ name: 'public', description: 'application mobile et site (via /api/retours)' }, { name: 'interne', description: 'services et administration' }],
    },
  });

  // Corps vide accepté (routes sans contenu appelées avec un type JSON) ; sinon l'analyseur de Fastify, qui refuse
  // les objets piégés (__proto__, constructor).
  const json = app.getDefaultJsonParser('error', 'error');
  app.removeContentTypeParser('application/json');
  app.addContentTypeParser('application/json', { parseAs: 'string' }, (req, corps, fini) => (corps === '' ? fini(null, undefined) : json(req, corps as string, fini)));

  const autorise = (valeur: unknown) => {
    const a = Buffer.from(String(valeur ?? ''));
    const b = Buffer.from(o.jeton);
    return a.length === b.length && timingSafeEqual(a, b);
  };
  const empreinte = (cle: string) => createHmac('sha256', o.jeton).update(`retours:${cle}`).digest('hex').slice(0, 32);

  app.addHook('onRequest', async (req, reply) => {
    if (req.url.startsWith('/interne/') && !autorise(req.headers['x-bayam-service'])) {
      return reply.code(401).send({ erreur: 'jeton de service invalide' });
    }
  });
  app.setErrorHandler((e: Error & { validation?: unknown; statusCode?: number }, req, reply) => {
    if (e.validation) {
      return reply.code(400).send({ erreur: e.message });
    }
    req.log.error(e);
    return reply.code(e.statusCode && e.statusCode < 500 ? e.statusCode : 500).send({ erreur: e.statusCode && e.statusCode < 500 ? e.message : 'erreur interne' });
  });

  /** Enregistre, puis prévient l'équipe si le sujet vient de franchir un seuil d'alerte. */
  const enregistrer = async (e: Entree): Promise<Resultat> => {
    const r = await o.depot.signaler(e, maintenant());
    if (r.alerte > r.alerteEnvoyee && o.alerter) {
      const envoye = await o.alerter({ id: r.sujet, titre: e.texte.slice(0, 140), gravite: r.gravite, signaleurs: r.signaleurs, niveau: r.alerte })
        .catch(() => false);
      if (envoye) {
        await o.depot.noterAlerte(r.sujet, r.alerte);
      }
    }
    return r;
  };

  app.get('/sante', { schema: { hide: true } }, async (_req, reply) => {
    const ok = o.sante ? await o.sante().catch(() => false) : true;
    return reply.code(ok ? 200 : 503).send({ ok });
  });

  app.post<{ Body: { type: 'souci' | 'idee' | 'question'; texte: string; canal?: 'app' | 'web'; contexte?: Record<string, string> } }>('/retours', {
    schema: {
      tags: ['public'], summary: "Signaler un souci ou proposer une idée depuis l'application ou le site",
      body: { type: 'object', required: ['type', 'texte'], additionalProperties: false, properties: {
        type: { type: 'string', enum: ['souci', 'idee', 'question'] }, texte: TEXTE, canal: { type: 'string', enum: ['app', 'web'], default: 'app' },
        contexte: { ...CONTEXTE, description: 'version, plateforme, écran, appareil' } } },
      response: { 201: ACCUSE, 400: ERREUR },
    },
  }, async (req, reply) => {
    // Même critère que la limite de débit de Traefik : l'adresse réelle transmise par Cloudflare.
    const adresse = String(req.headers['cf-connecting-ip'] ?? req.ip).slice(0, 64);
    const personne = empreinte(`public:${adresse}:${String(req.headers['user-agent'] ?? '').slice(0, 200)}`);
    const r = await enregistrer({ type: req.body.type, texte: req.body.texte.trim(), canal: req.body.canal ?? 'app', personne, contexte: req.body.contexte });
    return reply.code(201).send({ numero: r.numero, sujet: r.sujet, signaleurs: r.signaleurs, message: merci(r.numero, req.body.type, r.signaleurs) });
  });

  app.post<{ Body: { type: TypeRetour; texte: string; canal: string; auteur?: string; vendeur?: number; fonction?: string; contact?: Contact; contexte?: Record<string, string>; identifiant?: string } }>('/interne/signalements', {
    schema: {
      tags: ['interne'], summary: 'Enregistrer un retour reçu sur un canal (WhatsApp, Messenger, copilote, administration)', security: SERVICE,
      body: { type: 'object', required: ['type', 'texte', 'canal'], additionalProperties: false, properties: {
        type: { type: 'string', enum: ['souci', 'idee', 'question'] }, texte: TEXTE,
        canal: { type: 'string', enum: ['whatsapp', 'messenger', 'copilote', 'app', 'web', 'admin'] },
        auteur: { type: 'string', maxLength: 120, description: "nom lisible par l'équipe (boutique, prénom)" },
        vendeur: { type: 'integer', minimum: 1 }, fonction: { type: 'string', pattern: '^[a-z][a-z.]{1,59}$', description: 'fonction touchée (whatsapp.menu, paiement…)' },
        contact: { ...CONTACT, description: 'où prévenir la personne quand ce sera réglé' }, contexte: CONTEXTE,
        identifiant: { type: 'string', maxLength: 120, description: 'identifiant stable de la personne, jamais stocké en clair' } } },
      response: { 201: ACCUSE, 400: ERREUR, 401: ERREUR },
    },
  }, async (req, reply) => {
    const { identifiant, ...b } = req.body;
    const brut = identifiant ?? (b.contact ? JSON.stringify(b.contact) : `${b.canal}:${b.vendeur ?? ''}:${b.auteur ?? ''}`);
    const r = await enregistrer({ ...b, texte: b.texte.trim(), personne: empreinte(`${b.canal}:${brut}`) });
    return reply.code(201).send({ numero: r.numero, sujet: r.sujet, signaleurs: r.signaleurs, message: merci(r.numero, b.type, r.signaleurs) });
  });

  app.post<{ Body: { cle: string; texte: string; fonction?: string; gravite?: Gravite } }>('/interne/incidents', {
    schema: {
      tags: ['interne'], summary: 'Panne détectée par une sonde (ouvre ou rouvre le sujet de cette panne)', security: SERVICE,
      body: { type: 'object', required: ['cle', 'texte'], additionalProperties: false, properties: {
        cle: { type: 'string', pattern: '^[\\w.:-]{3,100}$' }, texte: TEXTE, fonction: { type: 'string', pattern: '^[a-z][a-z.]{1,59}$' },
        gravite: { type: 'integer', minimum: 2, maximum: 4, default: 3 } } },
      response: { 201: ACCUSE, 400: ERREUR, 401: ERREUR },
    },
  }, async (req, reply) => {
    const b = req.body;
    const r = await enregistrer({ type: 'incident', texte: b.texte, canal: 'sonde', personne: empreinte(`sonde:${b.cle}`), fonction: b.fonction,
      gravite: b.gravite ?? 3, categorie: 'disponibilite', cleIncident: b.cle });
    return reply.code(201).send({ numero: r.numero, sujet: r.sujet, signaleurs: r.signaleurs, message: '' });
  });

  app.post<{ Params: { cle: string } }>('/interne/incidents/:cle/retabli', {
    schema: {
      tags: ['interne'], summary: 'La sonde voit la fonction rétablie : le sujet de la panne passe à corrigé', security: SERVICE,
      params: { type: 'object', properties: { cle: { type: 'string', pattern: '^[\\w.:-]{3,100}$' } } },
      response: { 200: SUJET, 401: ERREUR },
    },
  }, async (req, reply) => {
    const s = await o.depot.retablir(req.params.cle, 'sonde', maintenant());
    return s ? reply.send(s) : reply.code(204).send();
  });

  app.get<{ Querystring: Omit<Filtres, 'limite'> & { limite?: number } }>('/interne/sujets', {
    schema: {
      tags: ['interne'], summary: "Sujets à traiter, du plus urgent au moins urgent", security: SERVICE,
      querystring: { type: 'object', additionalProperties: false, properties: {
        statut: { type: 'string', enum: [...STATUTS, 'ouverts'], default: 'ouverts' }, categorie: { type: 'string', enum: CATEGORIES },
        type: { type: 'string', enum: TYPES }, limite: { type: 'integer', minimum: 1, maximum: 500, default: 100 } } },
      response: { 200: { type: 'array', items: SUJET }, 401: ERREUR },
    },
  }, async (req) => o.depot.sujets({ ...req.query, limite: req.query.limite ?? 100 }, maintenant()));

  app.get<{ Params: { id: number } }>('/interne/sujets/:id', {
    schema: {
      tags: ['interne'], summary: "Un sujet, ses signalements et l'historique des décisions", security: SERVICE,
      params: { type: 'object', properties: { id: { type: 'integer', minimum: 1 } } },
      response: {
        200: { type: 'object', properties: {
          sujet: SUJET,
          signalements: { type: 'array', items: { type: 'object', additionalProperties: true, properties: {
            id: { type: 'integer' }, type: { type: 'string' }, texte: { type: 'string' }, canal: { type: 'string' }, auteur: { type: 'string' },
            vendeur: { type: ['integer', 'null'] }, contexte: { type: 'object', additionalProperties: true }, joignable: { type: 'boolean' },
            prevenu: { type: ['string', 'null'], format: 'date-time' }, cree: { type: 'string', format: 'date-time' } } } },
          historique: { type: 'array', items: { type: 'object', properties: {
            action: { type: 'string' }, auteur: { type: 'string' }, details: { type: 'object', additionalProperties: true }, quand: { type: 'string', format: 'date-time' } } } },
        } },
        401: ERREUR, 404: ERREUR,
      },
    },
  }, async (req, reply) => {
    const d = await o.depot.sujet(req.params.id, maintenant());
    return d ? reply.send(d) : reply.code(404).send({ erreur: 'sujet inconnu' });
  });

  app.patch<{ Params: { id: number }; Body: Changements & { auteur: string; prevenir?: boolean; message?: string } }>('/interne/sujets/:id', {
    schema: {
      tags: ['interne'], summary: 'Décision sur un sujet (statut, responsable, gravité, résolution) et réponse aux signaleurs', security: SERVICE,
      params: { type: 'object', properties: { id: { type: 'integer', minimum: 1 } } },
      body: { type: 'object', required: ['auteur'], additionalProperties: false, properties: {
        auteur: { type: 'string', minLength: 1, maxLength: 80 }, statut: { type: 'string', enum: STATUTS.filter((s) => s !== 'doublon') },
        assigne: { type: ['string', 'null'], maxLength: 80 }, gravite: { type: 'integer', minimum: 1, maximum: 4 },
        categorie: { type: 'string', enum: CATEGORIES }, resolution: { type: 'string', maxLength: 2000 }, note: { type: 'string', maxLength: 2000 },
        prevenir: { type: 'boolean', description: 'prévenir les signaleurs joignables (avec un statut corrigé)' },
        message: { type: 'string', minLength: 3, maxLength: 1000, description: 'message aux signaleurs, sinon le message standard' } } },
      response: { 200: { type: 'object', properties: { sujet: SUJET, prevenus: { type: 'integer' } } }, 400: ERREUR, 401: ERREUR, 404: ERREUR },
    },
  }, async (req, reply) => {
    const { auteur, prevenir, message, ...changements } = req.body;
    const s = await o.depot.modifier(req.params.id, changements, auteur, maintenant());
    if (!s) {
      return reply.code(404).send({ erreur: 'sujet inconnu' });
    }
    let prevenus = 0;
    if (prevenir && (s.statut as Statut) === 'corrige' && o.prevenir) {
      const ids = await o.prevenir(s, message ?? MESSAGE_CORRIGE);
      await o.depot.marquerPrevenus(ids);
      prevenus = ids.length;
    }
    return reply.send({ sujet: s, prevenus });
  });

  app.post<{ Params: { id: number }; Body: { dans: number; auteur: string } }>('/interne/sujets/:id/fusionner', {
    schema: {
      tags: ['interne'], summary: "Déclarer ce sujet doublon d'un autre : ses signalements et ses personnes passent dans l'autre", security: SERVICE,
      params: { type: 'object', properties: { id: { type: 'integer', minimum: 1 } } },
      body: { type: 'object', required: ['dans', 'auteur'], additionalProperties: false, properties: {
        dans: { type: 'integer', minimum: 1 }, auteur: { type: 'string', minLength: 1, maxLength: 80 } } },
      response: { 200: SUJET, 401: ERREUR, 404: ERREUR },
    },
  }, async (req, reply) => {
    const s = await o.depot.fusionner(req.params.id, req.body.dans, req.body.auteur, maintenant());
    return s ? reply.send(s) : reply.code(404).send({ erreur: 'sujets introuvables ou identiques' });
  });

  app.get('/interne/statistiques', {
    schema: {
      tags: ['interne'], summary: 'Tableau de bord : sujets par statut et catégorie, signalements par jour, délai médian de correction', security: SERVICE,
      response: { 200: { type: 'object', additionalProperties: true }, 401: ERREUR },
    },
  }, async () => o.depot.statistiques());

  return app;
}
