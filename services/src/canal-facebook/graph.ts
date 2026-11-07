// Client de l'API Graph de Meta (Pages, Messenger, connexion des vendeurs), version figée. fetch de Node garde ses
// connexions ouvertes : quelques connexions vers graph.facebook.com suffisent pour toutes les Pages. Chaque appel
// porte la preuve du secret de l'application (appsecret_proof) : un jeton de Page volé ne sert à rien sans lui.

import { createHmac } from 'node:crypto';

export const VERSION_GRAPH = 'v27.0';

/** Permissions demandées au vendeur : lire ses Pages, répondre dans Messenger et aux commentaires, publier. */
export const PERMISSIONS = ['pages_show_list', 'pages_messaging', 'pages_manage_metadata', 'pages_read_engagement', 'pages_manage_engagement', 'pages_manage_posts'];

/** Événements reçus pour chaque Page connectée. */
export const ABONNEMENTS = ['messages', 'messaging_postbacks', 'messaging_referrals', 'message_echoes', 'feed'];

// Limites de débit de Meta : il suffit de réessayer plus tard.
const CODES_PASSAGERS = new Set([1, 2, 4, 17, 32, 341, 368, 613, 80001, 80006]);

export class ErreurGraph extends Error {
  readonly statut: number;
  readonly code: number;

  constructor(statut: number, code: number, message: string) {
    super(`Graph ${statut} (code ${code}) : ${message}`);
    this.statut = statut;
    this.code = code;
  }

  /** Nouvel essai inutile : client hors de la fenêtre de 24 h, permission manquante, jeton révoqué… */
  get definitive(): boolean {
    return this.statut >= 400 && this.statut < 500 && !CODES_PASSAGERS.has(this.code);
  }

  /** Jeton de Page expiré ou révoqué (mot de passe changé, application retirée) : le vendeur doit reconnecter. */
  get jetonInvalide(): boolean {
    return this.code === 190;
  }
}

export type PageAutorisee = { id: string; name: string; access_token: string; tasks?: string[] };
export type Destinataire = { id: string } | { comment_id: string };

export class Graph {
  private readonly base: string;
  private readonly appId: string;
  private readonly secret: string;

  constructor(base: string, appId: string, secret: string) {
    this.base = base.replace(/\/$/, '');
    this.appId = appId;
    this.secret = secret;
  }

  /** Adresse de la fenêtre de connexion Facebook où le vendeur choisit ses Pages. */
  urlConnexion(redirection: string, etat: string): string {
    const q = new URLSearchParams({ client_id: this.appId, redirect_uri: redirection, state: etat, scope: PERMISSIONS.join(','), response_type: 'code' });
    return `https://www.facebook.com/${VERSION_GRAPH}/dialog/oauth?${q}`;
  }

  /** Code reçu au retour de la connexion -> jeton utilisateur longue durée (environ 60 jours). */
  async jetonUtilisateur(code: string, redirection: string): Promise<string> {
    const court = await this.appel<{ access_token: string }>('GET', '/oauth/access_token', null, undefined, { client_id: this.appId, client_secret: this.secret, redirect_uri: redirection, code });
    return this.jetonLongue(court.access_token);
  }

  async jetonLongue(jetonCourt: string): Promise<string> {
    const r = await this.appel<{ access_token: string }>('GET', '/oauth/access_token', null, undefined, { grant_type: 'fb_exchange_token', client_id: this.appId, client_secret: this.secret, fb_exchange_token: jetonCourt });
    return r.access_token;
  }

  /** Pages gérées par le vendeur ; obtenus depuis un jeton longue durée, leurs jetons n'expirent pas. */
  async pages(jetonUtilisateur: string): Promise<PageAutorisee[]> {
    const r = await this.appel<{ data: PageAutorisee[] }>('GET', '/me/accounts', jetonUtilisateur, undefined, { fields: 'id,name,access_token,tasks', limit: '100' });
    return r.data;
  }

  /** Abonne la Page à l'application (messages, choix, liens m.me, échos, commentaires). */
  async abonner(page: string, jeton: string): Promise<void> {
    await this.appel('POST', `/${page}/subscribed_apps`, jeton, { subscribed_fields: ABONNEMENTS.join(',') });
  }

  /** Bouton « Démarrer » et message d'accueil de la Page dans Messenger. */
  async accueil(jeton: string, salutation: string): Promise<void> {
    await this.appel('POST', '/me/messenger_profile', jeton, { get_started: { payload: 'bayam:menu' }, greeting: [{ locale: 'default', text: salutation }] });
  }

  /** Message Messenger (réponse au client, ou réponse privée à un commentaire) ; renvoie son identifiant. */
  async envoyer(page: string, jeton: string, destinataire: Destinataire, message: Record<string, unknown>): Promise<string> {
    const r = await this.appel<{ message_id?: string }>('POST', `/${page}/messages`, jeton, { recipient: destinataire, messaging_type: 'RESPONSE', message });
    return r.message_id ?? '';
  }

  /** Réponse publique sous un commentaire. */
  async repondreCommentaire(commentaire: string, jeton: string, texte: string): Promise<void> {
    await this.appel('POST', `/${commentaire}/comments`, jeton, { message: texte });
  }

  /** Photo envoyée en fichier (aucune adresse publique nécessaire) ; publiée tout de suite, ou gardée pour un album ou une story. */
  async photo(page: string, jeton: string, image: Buffer, legende: string, publiee: boolean): Promise<{ id: string; post_id?: string }> {
    const formulaire = new FormData();
    formulaire.append('source', new Blob([new Uint8Array(image)], { type: 'image/jpeg' }), 'photo.jpg');
    formulaire.append('published', String(publiee));
    if (legende) {
      formulaire.append('caption', legende);
    }
    return this.appel('POST', `/${page}/photos`, jeton, formulaire);
  }

  /** Publication de plusieurs photos déjà envoyées (non publiées), avec un texte ; renvoie l'identifiant de la publication. */
  async album(page: string, jeton: string, texte: string, photos: string[]): Promise<string> {
    const r = await this.appel<{ id: string }>('POST', `/${page}/feed`, jeton, { message: texte, attached_media: photos.map((media_fbid) => ({ media_fbid })) });
    return r.id;
  }

  async story(page: string, jeton: string, photo: string): Promise<string> {
    const r = await this.appel<{ post_id: string | number }>('POST', `/${page}/photo_stories`, jeton, { photo_id: photo });
    return String(r.post_id);
  }

  private async appel<T>(methode: 'GET' | 'POST', chemin: string, jeton: string | null, corps?: Record<string, unknown> | FormData, requete: Record<string, string> = {}): Promise<T> {
    const q = new URLSearchParams(requete);
    if (jeton) {
      q.set('access_token', jeton);
      q.set('appsecret_proof', createHmac('sha256', this.secret).update(jeton).digest('hex'));
    }
    const json = corps && !(corps instanceof FormData);
    const r = await fetch(`${this.base}${chemin}?${q}`, {
      method: methode,
      headers: json ? { 'content-type': 'application/json' } : {},
      body: corps instanceof FormData ? corps : json ? JSON.stringify(corps) : undefined,
      signal: AbortSignal.timeout(20_000),
    });
    const texte = await r.text();
    let donnees: { error?: { code?: number; message?: string } } & Record<string, unknown> = {};
    try {
      donnees = texte ? JSON.parse(texte) : {};
    } catch {
      // réponse non JSON (panne en amont) : traitée comme une erreur passagère ci-dessous
    }
    if (!r.ok || donnees.error) {
      throw new ErreurGraph(r.status, Number(donnees.error?.code ?? 0), String(donnees.error?.message ?? texte.slice(0, 200)));
    }
    return donnees as T;
  }
}
