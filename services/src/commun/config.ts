// Configuration par variables d'environnement : un service refuse de démarrer s'il lui manque une valeur
// indispensable, avec le nom de la variable dans l'erreur.

export function exiger(nom: string): string {
  const valeur = process.env[nom];
  if (!valeur) {
    throw new Error(`Variable d'environnement manquante : ${nom}`);
  }
  return valeur;
}

export function lire(nom: string, defaut: string): string {
  return process.env[nom] || defaut;
}

export function nombre(nom: string, defaut: number): number {
  const brut = process.env[nom];
  const valeur = Number(brut);
  return brut && Number.isFinite(valeur) ? valeur : defaut;
}
