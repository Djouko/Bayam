// Classement des retours des utilisateurs, sans dépendance : catégorie, gravité, regroupement des doublons en sujets
// et score d'urgence. Les mots-clés couvrent le français courant du Cameroun ; l'équipe corrige au besoin la
// catégorie ou la gravité dans l'administration, le classement automatique ne fait que trier l'arrivée.

export const TYPES = ['souci', 'idee', 'question', 'incident'] as const;
export type TypeRetour = (typeof TYPES)[number];

export const CATEGORIES = [
  'securite', 'paiement', 'livraison', 'commande', 'connexion', 'whatsapp', 'facebook', 'application', 'photos', 'disponibilite', 'autre',
] as const;
export type Categorie = (typeof CATEGORIES)[number];

export const STATUTS = ['nouveau', 'confirme', 'en_cours', 'corrige', 'ferme', 'rejete', 'doublon'] as const;
export type Statut = (typeof STATUTS)[number];
export const OUVERTS: readonly Statut[] = ['nouveau', 'confirme', 'en_cours'];

/** 1 basse, 2 moyenne, 3 haute (bloquant), 4 critique (argent, fraude, données). */
export type Gravite = 1 | 2 | 3 | 4;

/** Texte comparable : minuscules, sans accents ni ponctuation, espaces simples. */
export function normaliser(texte: string): string {
  return texte
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, ' ')
    .trim();
}

// Racines cherchées en début de mot (« livr » couvre livraison, livreur, livré).
const MOTS: [Categorie, string[]][] = [
  ['securite', ['arnaq', 'escro', 'pirat', 'fraud', 'hack', 'usurp', 'faux compte', 'menac', 'vole mon', 'voleur']],
  ['paiement', ['paiement', 'payer', 'paye', 'momo', 'mobile money', 'orange money', 'cinetpay', 'debit', 'rembours', 'argent', 'transaction', 'facture', 'fcfa']],
  ['livraison', ['livr', 'colis', 'expedi', 'adresse de livraison']],
  ['connexion', ['connexion', 'connecter', 'mot de passe', 'code de', 'otp', 'inscri', 'identifiant', 'deconnect']],
  ['commande', ['commande', 'panier', 'stock', 'epuise', 'rupture', 'prix', 'taille']],
  ['whatsapp', ['whatsapp', 'groupe', 'statut', 'chaine']],
  ['facebook', ['facebook', 'messenger', 'commentaire', 'publication']],
  ['photos', ['photo', 'image', 'video']],
  ['application', ['appli', 'application', 'ecran', 'plante', 'crash', 'bug', 'lent', 'chargement', 'mise a jour', 'site']],
];

const BLOQUANT = [
  'impossible', 'bloqu', 'ne marche', 'marche plus', 'marche pas', 'fonctionne pas', 'fonctionne plus', 'plus rien', 'rien ne s affiche',
  'n affiche', 's affiche plus', 'erreur', 'plante', 'crash', 'ne s ouvre', 'page blanche', 'ecran blanc', 'ne repond', 'repond plus',
  'n arrive pas', 'arrive plus', 'aucune reponse',
];
const PERTE = ['debite', 'pas recu', 'jamais recu', 'perdu', 'vole', 'disparu', 'deux fois', 'double paiement', 'fuite', 'donnees personnelles'];

const contient = (texte: string, racine: string) => ` ${texte}`.includes(` ${racine}`);

export function categoriser(texte: string): Categorie {
  const t = normaliser(texte);
  return MOTS.find(([, racines]) => racines.some((r) => contient(t, r)))?.[0] ?? 'autre';
}

export function graviter(type: TypeRetour, categorie: Categorie, texte: string): Gravite {
  const t = normaliser(texte);
  if (categorie === 'securite' || (categorie === 'paiement' && PERTE.some((r) => contient(t, r))) || contient(t, 'fuite') || contient(t, 'donnees personnelles')) {
    return 4;
  }
  if (type === 'idee' || type === 'question') {
    return 1;
  }
  return BLOQUANT.some((r) => contient(t, r)) ? 3 : 2;
}

const VIDES = new Set(['le', 'la', 'les', 'un', 'une', 'des', 'de', 'du', 'et', 'ou', 'a', 'au', 'aux', 'je', 'j', 'tu', 'il', 'on', 'nous', 'vous', 'ils',
  'me', 'ma', 'mon', 'mes', 'ce', 'cet', 'cette', 'est', 'sont', 'pas', 'ne', 'n', 'plus', 'que', 'qui', 'quand', 'pour', 'dans', 'sur', 'avec', 'svp', 'bonjour',
  'merci', 'bayam', 'signaler', 'idee', 's', 'l', 'd', 'c', 'y', 'en', 'ca', 'cela']);

/** Trigrammes des mots porteurs de sens, base de la comparaison entre deux retours. */
export function trigrammes(texte: string): Set<string> {
  const ensemble = new Set<string>();
  for (const mot of normaliser(texte).split(' ').filter((m) => m.length > 1 && !VIDES.has(m))) {
    const m = ` ${mot} `;
    for (let i = 0; i + 3 <= m.length; i++) {
      ensemble.add(m.slice(i, i + 3));
    }
  }
  return ensemble;
}

/** Similarité de Jaccard entre deux ensembles de trigrammes (0 à 1). */
export function similarite(a: Set<string>, b: Set<string>): number {
  if (!a.size || !b.size) {
    return 0;
  }
  let communs = 0;
  for (const t of a) {
    if (b.has(t)) {
      communs++;
    }
  }
  return communs / (a.size + b.size - communs);
}

export type Candidat = { id: number; categorie: Categorie; fonction: string | null; texte: string; dernier: Date };

export const SEUIL_DOUBLON = 0.3;
const SEUIL_RAFALE = 0.08;
const RAFALE_MS = 2 * 3_600_000;

/**
 * Sujet ouvert auquel rattacher un nouveau retour : même catégorie et texte proche ; ou même fonction touchée dans les
 * deux dernières heures avec un texte même vaguement proche (rafale de signalements pendant une panne).
 */
export function rattacher(
  nouveau: { categorie: Categorie; fonction: string | null; texte: string },
  candidats: Candidat[],
  maintenant: number,
): number | null {
  const t = trigrammes(nouveau.texte);
  let meilleur: { id: number; s: number } | null = null;
  for (const c of candidats.filter((x) => x.categorie === nouveau.categorie)) {
    const s = similarite(t, trigrammes(c.texte));
    const rafale = nouveau.fonction !== null && c.fonction === nouveau.fonction && maintenant - c.dernier.getTime() < RAFALE_MS;
    if ((s >= SEUIL_DOUBLON || (rafale && s >= SEUIL_RAFALE)) && (!meilleur || s > meilleur.s)) {
      meilleur = { id: c.id, s };
    }
  }
  return meilleur?.id ?? null;
}

const POIDS: Record<Gravite, number> = { 1: 2, 2: 8, 3: 20, 4: 40 };

/** Score d'urgence d'un sujet ouvert : gravité, nombre de personnes touchées, fraîcheur. Un sujet clos vaut 0. */
export function score(s: { gravite: Gravite; signaleurs: number; dernier: Date; statut: Statut }, maintenant: number): number {
  if (!OUVERTS.includes(s.statut)) {
    return 0;
  }
  const age = maintenant - s.dernier.getTime();
  const fraicheur = age < 3_600_000 ? 1.5 : age < 86_400_000 ? 1 : age < 7 * 86_400_000 ? 0.6 : 0.3;
  return Math.round(POIDS[s.gravite] * (1 + Math.log2(Math.max(1, s.signaleurs))) * fraicheur * 10) / 10;
}

/** Faut-il prévenir l'équipe tout de suite ? Critique dès le premier, ou trois personnes touchées, ou panne détectée. */
export function niveauAlerte(s: { gravite: Gravite; signaleurs: number; type: TypeRetour }): 0 | 1 | 2 {
  if (s.gravite === 4 || s.type === 'incident') {
    return 2;
  }
  return s.signaleurs >= 3 ? 1 : 0;
}
