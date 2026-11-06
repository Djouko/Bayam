// Un message s'adresse à BAYAM s'il commence par « bayam », écrit comme on l'écrit vraiment au téléphone :
// majuscules, accents, une faute de frappe (« bayan », « baym ») ou deux lettres inversées (« byaam »).
// Même règle que l'extension WordPress (Bayam_WA_Outils::appel), pour que les deux côtés décident pareil.

const APPEL = 'bayam';

export function sansAccents(texte: string): string {
  return texte.normalize('NFD').replace(/\p{M}/gu, '');
}

function levenshtein(a: string, b: string): number {
  let precedente = Array.from({ length: b.length + 1 }, (_, j) => j);
  for (let i = 1; i <= a.length; i++) {
    const courante = [i];
    for (let j = 1; j <= b.length; j++) {
      courante[j] = Math.min(precedente[j] + 1, courante[j - 1] + 1, precedente[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
    }
    precedente = courante;
  }
  return precedente[b.length];
}

function proche(mot: string, cible: string): boolean {
  if (levenshtein(mot, cible) <= 1) {
    return true;
  }
  if (mot.length !== cible.length) {
    return false;
  }
  const ecarts = [...mot].flatMap((lettre, i) => (lettre === cible[i] ? [] : [i]));
  return ecarts.length === 2 && ecarts[1] === ecarts[0] + 1
    && mot[ecarts[0]] === cible[ecarts[1]] && mot[ecarts[1]] === cible[ecarts[0]];
}

/** Reste du message après « bayam » (chaîne vide si le message n'est que « bayam »), ou null s'il ne s'adresse pas à BAYAM. */
export function appel(texte: string): string | null {
  const m = /^\s*[@/]?\s*(\p{L}{3,7})(?=$|[\s,:;.!?])[\s,:;.!?]*([\s\S]*)$/u.exec(texte);
  if (!m) {
    return null;
  }
  return proche(sansAccents(m[1].toLowerCase()), APPEL) ? m[2].trim() : null;
}

const MOTS_MENU = new Set(['', 'menu', 'catalogue', 'catalog', 'rayons', 'rayon', 'boutique', 'shop', 'produits', 'articles', 'categories', 'categorie']);

/** Le client demande-t-il à voir la boutique ? « bayam », « Bayam menu », « #catalogue », « bayan rayons »… */
export function demandeMenu(texte: string): boolean {
  const reste = appel(texte);
  if (reste !== null) {
    return MOTS_MENU.has(sansAccents(reste.toLowerCase()).replace(/[\s.!?]+$/, ''));
  }
  return /^\s*#\s*(menu|catalogue|rayons|boutique)?\s*$/iu.test(texte);
}
