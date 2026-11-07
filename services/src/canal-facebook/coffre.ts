// Jetons des Pages chiffrés en base (AES-256-GCM) : une copie volée de la base ne permet pas d'écrire au nom des
// vendeurs. La clé ne vit que dans l'environnement du service (FACEBOOK_CLE, 32 octets en base64).

import { createCipheriv, createDecipheriv, randomBytes } from 'node:crypto';

export function cleDepuis(texte: string): Buffer {
  const cle = Buffer.from(texte, 'base64');
  if (cle.length !== 32) {
    throw new Error('FACEBOOK_CLE doit contenir 32 octets encodés en base64 (openssl rand -base64 32)');
  }
  return cle;
}

/** « v1.<iv>.<étiquette>.<chiffré> » en base64url : la version permet de changer d'algorithme plus tard. */
export function chiffrer(texte: string, cle: Buffer): string {
  const iv = randomBytes(12);
  const c = createCipheriv('aes-256-gcm', cle, iv);
  const chiffre = Buffer.concat([c.update(texte, 'utf8'), c.final()]);
  return ['v1', iv, c.getAuthTag(), chiffre].map((x) => (typeof x === 'string' ? x : x.toString('base64url'))).join('.');
}

export function dechiffrer(valeur: string, cle: Buffer): string {
  const [version, iv, etiquette, chiffre] = valeur.split('.');
  if (version !== 'v1' || !iv || !etiquette || chiffre === undefined) {
    throw new Error('jeton chiffré illisible');
  }
  const d = createDecipheriv('aes-256-gcm', cle, Buffer.from(iv, 'base64url'));
  d.setAuthTag(Buffer.from(etiquette, 'base64url'));
  return Buffer.concat([d.update(Buffer.from(chiffre, 'base64url')), d.final()]).toString('utf8');
}
