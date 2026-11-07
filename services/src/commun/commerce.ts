// Accès au commerce (boutiques, rayons, articles, commandes) derrière une interface : aujourd'hui l'extension
// WordPress/WooCommerce, demain le service catalogue. Les canaux (WhatsApp, Facebook) ne changent pas quand la source change.

import type { RedisClientType } from 'redis';
import { texteErreur } from '../commun/journal.ts';

export type Rayon = { id: number; nom: string; nombre: number };
export type Produit = {
  id: number;
  nom: string;
  prix: number;
  prix_regulier: number;
  en_stock: boolean;
  variable: boolean;
  /** Pièces en stock quand le stock est suivi, sinon null. */
  stock: number | null;
  /** Déclinaisons encore disponibles (« Taille : S, M, XL »), vide pour un article simple. */
  options: string;
  /** Chemin de la photo (/wp-content/…, 768 px) servie par l'origine interne, pour la vitrine et la fiche. */
  photo: string | null;
  /** Autres photos de l'article (trois au plus), envoyées à la suite de la première. */
  galerie: string[];
  /** Lien court public vers la fiche (clics comptés par canal). */
  lien: string;
  resume: string;
};
export type Boutique = {
  id: number;
  nom: string;
  /** Réponses automatiques aux clients activées par le vendeur. */
  actif: boolean;
  /** Discussion du vendeur avec lui-même (alertes), et ses identifiants pour la reconnaître. */
  chat_vendeur: string | null;
  identifiants: string[];
  base_publique: string;
  rayons: Rayon[];
  /** Nombre d'articles en promotion (rayon « Promos du moment », numéro 0). */
  promos: number;
  livraison: string[];
  paiements: string[];
  /** Conditions de paiement de la boutique : à la livraison, acompte ou tout avant l'envoi, numéros Mobile Money. */
  conditions: string;
};
export type PageRayon = { total: number; produits: Produit[] };
/** Article vu par son vendeur (menus de stock, de vente, de publication) : épuisés compris, stock par variante. */
export type ArticleVendeur = {
  id: number;
  nom: string;
  prix: number;
  /** Pièces en stock (article simple suivi), sinon null. */
  stock: number | null;
  en_stock: boolean;
  variable: boolean;
  variantes: { id: number; nom: string; stock: number | null }[];
};
export type PageArticles = { total: number; articles: ArticleVendeur[] };
export type Commande = { numero: string; total: number; statut: string; date: string };

export interface Commerce {
  boutique(id: number): Promise<Boutique | null>;
  rayon(id: number, rayon: number, page: number, parPage: number): Promise<PageRayon>;
  produit(id: number, produit: number): Promise<Produit | null>;
  commandes(id: number, numero: string): Promise<Commande[]>;
  /** Photo d'un article (chemin donné par le catalogue), ou null si indisponible. */
  image(chemin: string | null): Promise<Buffer | null>;
  /** Articles du vendeur, par recherche (« robe », « #25 ») ou par page alphabétique. */
  articles(id: number, recherche: string, page: number, parPage: number): Promise<PageArticles>;
  /** Commande du Copilote exécutée pour le vendeur ; les réponses arrivent dans sa discussion « Message à moi-même ». */
  copilote(id: number, texte: string, photo?: string): Promise<void>;
}

/** Source WooCommerce : API interne de l'extension BAYAM, mise en cache dans Redis jusqu'à la prochaine modification. */
export class CommerceWordpress implements Commerce {
  private readonly url: string;
  private readonly jeton: string;
  private readonly redis: RedisClientType;

  constructor(url: string, jeton: string, redis: RedisClientType) {
    this.url = url.replace(/\/$/, '');
    this.jeton = jeton;
    this.redis = redis;
  }

  boutique(id: number): Promise<Boutique | null> {
    return this.enCache(id, 'boutique', `/boutiques/${id}`);
  }

  async rayon(id: number, rayon: number, page: number, parPage: number): Promise<PageRayon> {
    return (await this.enCache<PageRayon>(id, `rayon:${rayon}:${page}:${parPage}`, `/boutiques/${id}/rayons/${rayon}?page=${page}&par=${parPage}`)) ?? { total: 0, produits: [] };
  }

  produit(id: number, produit: number): Promise<Produit | null> {
    // Jamais en cache : le stock et le prix doivent être ceux du moment où le client commande.
    return this.lire(`/boutiques/${id}/produits/${produit}`);
  }

  async commandes(id: number, numero: string): Promise<Commande[]> {
    return (await this.lire<Commande[]>(`/boutiques/${id}/commandes?numero=${encodeURIComponent(numero)}`)) ?? [];
  }

  async image(chemin: string | null): Promise<Buffer | null> {
    if (!chemin) {
      return null;
    }
    const r = await fetch(new URL(chemin, this.url).toString(), { signal: AbortSignal.timeout(5000) }).catch(() => null);
    return r?.ok ? Buffer.from(await r.arrayBuffer()) : null;
  }

  async articles(id: number, recherche: string, page: number, parPage: number): Promise<PageArticles> {
    // Jamais en cache : le vendeur voit le stock exact du moment.
    const q = new URLSearchParams({ q: recherche, page: String(page), par: String(parPage) });
    return (await this.lire<PageArticles>(`/boutiques/${id}/articles?${q}`)) ?? { total: 0, articles: [] };
  }

  async copilote(id: number, texte: string, photo?: string): Promise<void> {
    const r = await fetch(`${this.url}/boutiques/${id}/copilote`, {
      method: 'POST',
      headers: { 'x-bayam-service': this.jeton, 'content-type': 'application/json' },
      body: JSON.stringify(photo ? { texte, photo } : { texte }),
      // Publier ou interroger le modèle de langage peut prendre du temps.
      signal: AbortSignal.timeout(60_000),
    });
    if (!r.ok) {
      throw new Error(`Copilote : réponse ${r.status}`);
    }
  }

  /** Clé de cache liée à la version du catalogue, que WordPress incrémente à chaque modification d'article. */
  private async enCache<T>(id: number, nom: string, chemin: string): Promise<T | null> {
    const version = (await this.redis.get(`canal:catalogue:version:${id}`)) ?? '0';
    const cle = `canal:catalogue:${id}:${version}:${nom}`;
    const brut = await this.redis.get(cle);
    if (brut) {
      return JSON.parse(brut) as T;
    }
    const valeur = await this.lire<T>(chemin);
    if (valeur !== null) {
      await this.redis.set(cle, JSON.stringify(valeur), { expiration: { type: 'EX', value: 300 } });
    }
    return valeur;
  }

  private async lire<T>(chemin: string): Promise<T | null> {
    const reponse = await fetch(`${this.url}${chemin}`, {
      headers: { 'x-bayam-service': this.jeton, accept: 'application/json' },
      signal: AbortSignal.timeout(8000),
    }).catch((e: unknown) => {
      throw new Error(`commerce injoignable (${chemin}) : ${texteErreur(e)}`);
    });
    if (reponse.status === 404) {
      return null;
    }
    if (!reponse.ok) {
      throw new Error(`commerce : réponse ${reponse.status} pour ${chemin}`);
    }
    return (await reponse.json()) as T;
  }
}
