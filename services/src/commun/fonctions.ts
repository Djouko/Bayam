// Interrupteurs des fonctions (maintenance sans couper le reste) : l'équipe met une fonction en maintenance ou à
// l'arrêt (scripts/fonctions.sh), tous les services, WordPress et les applications le voient en quelques secondes,
// sans redéploiement ni mise à jour d'application. Le reste de BAYAM continue de marcher ; l'utilisateur reçoit un
// message clair au lieu d'une erreur, et la fonction se rouvre seule à l'heure annoncée.
// Rangement : table de hachage Redis « bayam:fonctions », un champ par fonction mise de côté (absente : active).

import type { RedisClientType } from 'redis';

export const CLE_FONCTIONS = 'bayam:fonctions';

/** Fonctions connues (un préfixe vaut pour toutes ses fonctions : « facebook » coupe tout Facebook). */
export const FONCTIONS: Record<string, string> = {
  'whatsapp.catalogue': 'Catalogue WhatsApp des clients',
  'whatsapp.menu': 'Menu du vendeur (WhatsApp et Messenger)',
  'whatsapp.copilote': 'Commandes du vendeur (Copilote)',
  'whatsapp.publication': 'Publication dans les groupes, chaînes et statut',
  'whatsapp.assistant': 'Assistant qui répond aux clients',
  'facebook.messenger': 'Catalogue Messenger des clients',
  'facebook.commentaires': 'Réponses aux commentaires',
  'facebook.publications': 'Publications et stories sur les Pages',
  'facebook.connexion': 'Connexion des Pages',
  'paiement': 'Paiements en ligne',
  'mobile': 'Application mobile',
  'web': 'Site web',
};

export type EtatFonction = { etat: 'actif' | 'maintenance' | 'arret'; message: string; jusqua: string | null };
type Reglage = { etat?: string; message?: string; jusqua?: string; testeurs?: number[] };

const ACTIF: EtatFonction = { etat: 'actif', message: '', jusqua: null };

/** Message montré à l'utilisateur : celui de l'équipe, sinon un message simple avec l'heure de retour si connue. */
export function messageFonction(e: EtatFonction): string {
  if (e.message) {
    return e.message;
  }
  const retour = e.jusqua ? ` Elle revient vers ${new Date(e.jusqua).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', timeZone: 'Africa/Douala' })}.` : '';
  return e.etat === 'arret'
    ? 'Cette fonction est momentanément indisponible. Le reste de la boutique marche normalement 🙏'
    : `Cette fonction est en maintenance pour quelques minutes.${retour} Le reste de la boutique marche normalement 🙏`;
}

/** Lecture des interrupteurs, gardés 5 s en mémoire : une lecture Redis toutes les 5 s au plus par service. */
export class Fonctions {
  private readonly redis: RedisClientType;
  private lus: { quand: number; reglages: Record<string, Reglage> } = { quand: 0, reglages: {} };

  constructor(redis: RedisClientType) {
    this.redis = redis;
  }

  async tous(): Promise<Record<string, Reglage>> {
    if (Date.now() - this.lus.quand > 5000) {
      const brut = await this.redis.hGetAll(CLE_FONCTIONS).catch(() => ({} as Record<string, string>));
      const reglages: Record<string, Reglage> = {};
      for (const [nom, valeur] of Object.entries(brut)) {
        try {
          reglages[nom] = JSON.parse(valeur) as Reglage;
        } catch {
          // valeur abîmée : ignorée, la fonction reste active
        }
      }
      this.lus = { quand: Date.now(), reglages };
    }
    return this.lus.reglages;
  }

  /**
   * État d'une fonction pour ce vendeur : la plus restrictive entre elle et ses préfixes (« facebook » puis
   * « facebook.messenger »). Les testeurs désignés (vendeurs d'essai de l'équipe) gardent l'accès pendant la maintenance ;
   * une maintenance dont l'heure de fin est passée est terminée.
   */
  async etat(nom: string, vendeur = 0): Promise<EtatFonction> {
    return etatDepuis(await this.tous(), nom, vendeur, Date.now());
  }
}

export function etatDepuis(reglages: Record<string, Reglage>, nom: string, vendeur: number, maintenant: number): EtatFonction {
  const parties = nom.split('.');
  let pire: EtatFonction = ACTIF;
  for (let i = 1; i <= parties.length; i++) {
    const r = reglages[parties.slice(0, i).join('.')];
    if (!r || (r.etat !== 'maintenance' && r.etat !== 'arret')) {
      continue;
    }
    if ((r.jusqua && Date.parse(r.jusqua) <= maintenant) || (vendeur && r.testeurs?.includes(vendeur))) {
      continue;
    }
    if (pire.etat === 'actif' || r.etat === 'arret') {
      pire = { etat: r.etat, message: r.message ?? '', jusqua: r.jusqua ?? null };
    }
  }
  return pire;
}
