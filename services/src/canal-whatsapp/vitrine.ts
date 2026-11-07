// Vitrine d'un rayon en une seule image : quatre articles numérotés (photo, nom, prix, promo, stock, tailles),
// aux couleurs de BAYAM. Une image d'environ 100 Ko au lieu de plusieurs messages : moins de données pour le
// client, moins d'envois pour le numéro du vendeur, et tout le rayon visible d'un coup d'œil, sans faire défiler.

import { fileURLToPath } from 'node:url';
import { createCanvas, GlobalFonts, loadImage, type Image, type SKRSContext2D } from '@napi-rs/canvas';

for (const police of ['Inter-Medium', 'Inter-SemiBold', 'Inter-Bold', 'Poppins-Bold']) {
  GlobalFonts.registerFromPath(fileURLToPath(new URL(`../../polices/${police}.ttf`, import.meta.url)));
}

const C = { em: '#0E9F6E', emD: '#0A7A55', nuit: '#0B2545', ardoise: '#475569', ligne: '#E2E8F0', nuage: '#F7FAF9', menthe: '#E6F7F0', corail: '#FF6B57', soleil: '#FFC233', gris: '#94A3B8' };

export type CaseVitrine = {
  numero: number;
  nom: string;
  prix: string;
  /** Ancien prix barré (promotion), sinon null. */
  ancienPrix: string | null;
  remise: number | null;
  /** Pièces restantes quand il en reste peu (alerte « Plus que 2 »), sinon null. */
  peuDeStock: number | null;
  options: string;
  image: Buffer | null;
};

const L = 960;
const MARGE = 36;
const ECART = 24;
const ENTETE = 150;
const PIED = 70;
const LARGEUR_CASE = (L - 2 * MARGE - ECART) / 2;
const HAUTEUR_PHOTO = 300;
const HAUTEUR_CASE = HAUTEUR_PHOTO + 158;

/** Texte coupé à la largeur, sur une ou deux lignes, avec points de suspension. */
function lignes(ctx: SKRSContext2D, texte: string, largeur: number, max: number): string[] {
  const mots = texte.split(/\s+/);
  const resultat: string[] = [];
  let courante = '';
  for (const mot of mots) {
    const essai = courante ? `${courante} ${mot}` : mot;
    if (ctx.measureText(essai).width <= largeur) {
      courante = essai;
      continue;
    }
    resultat.push(courante || mot);
    courante = courante ? mot : '';
    if (resultat.length === max) {
      break;
    }
  }
  if (resultat.length < max && courante) {
    resultat.push(courante);
  }
  if (resultat.length === max && resultat.join(' ').length < texte.length) {
    let derniere = resultat[max - 1];
    while (derniere && ctx.measureText(`${derniere}…`).width > largeur) {
      derniere = derniere.slice(0, -1);
    }
    resultat[max - 1] = `${derniere.trimEnd()}…`;
  }
  return resultat;
}

/** Pastille arrondie autour d'un texte ; sa hauteur suit la taille du texte (36 px pour la vitrine). */
function pastille(ctx: SKRSContext2D, x: number, y: number, texte: string, fond: string, encre: string, police: string, hauteur = 36): number {
  ctx.font = police;
  const marge = Math.round(hauteur / 3);
  const largeur = ctx.measureText(texte).width + 2 * marge;
  ctx.fillStyle = fond;
  ctx.beginPath();
  ctx.roundRect(x, y, largeur, hauteur, hauteur / 2);
  ctx.fill();
  ctx.fillStyle = encre;
  ctx.textBaseline = 'middle';
  ctx.fillText(texte, x + marge, y + hauteur / 2 + 1);
  ctx.textBaseline = 'alphabetic';
  return largeur;
}

/** Photo recadrée pour remplir le cadre (comme « object-fit: cover »). */
function photo(ctx: SKRSContext2D, image: Image, x: number, y: number, l: number, h: number): void {
  const echelle = Math.max(l / image.width, h / image.height);
  const sl = l / echelle;
  const sh = h / echelle;
  ctx.drawImage(image, (image.width - sl) / 2, (image.height - sh) / 2, sl, sh, x, y, l, h);
}

async function dessinerCase(ctx: SKRSContext2D, c: CaseVitrine, x: number, y: number): Promise<void> {
  // Carte blanche à coins arrondis et ombre douce.
  ctx.save();
  ctx.shadowColor = 'rgba(11, 37, 69, 0.10)';
  ctx.shadowBlur = 24;
  ctx.shadowOffsetY = 6;
  ctx.fillStyle = '#FFFFFF';
  ctx.beginPath();
  ctx.roundRect(x, y, LARGEUR_CASE, HAUTEUR_CASE, 26);
  ctx.fill();
  ctx.restore();

  // Photo, arrondie en haut.
  ctx.save();
  ctx.beginPath();
  ctx.roundRect(x, y, LARGEUR_CASE, HAUTEUR_PHOTO, [26, 26, 0, 0]);
  ctx.clip();
  ctx.fillStyle = C.menthe;
  ctx.fillRect(x, y, LARGEUR_CASE, HAUTEUR_PHOTO);
  if (c.image) {
    photo(ctx, await loadImage(c.image), x, y, LARGEUR_CASE, HAUTEUR_PHOTO);
  }
  ctx.restore();

  // Numéro à choisir dans la liste, promo, alerte de stock.
  ctx.fillStyle = '#FFFFFF';
  ctx.beginPath();
  ctx.arc(x + 40, y + 40, 28, 0, Math.PI * 2);
  ctx.fill();
  ctx.fillStyle = C.em;
  ctx.beginPath();
  ctx.arc(x + 40, y + 40, 24, 0, Math.PI * 2);
  ctx.fill();
  ctx.fillStyle = '#FFFFFF';
  ctx.font = '700 28px Poppins';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText(String(c.numero), x + 40, y + 42);
  ctx.textAlign = 'left';
  ctx.textBaseline = 'alphabetic';
  if (c.remise) {
    ctx.font = '700 22px Inter';
    const texte = `-${c.remise} %`;
    pastille(ctx, x + LARGEUR_CASE - ctx.measureText(texte).width - 40, y + 20, texte, C.corail, '#FFFFFF', '700 22px Inter');
  }
  if (c.peuDeStock) {
    pastille(ctx, x + 16, y + HAUTEUR_PHOTO - 52, c.peuDeStock === 1 ? 'Dernière pièce' : `Plus que ${c.peuDeStock}`, C.soleil, C.nuit, '600 20px Inter');
  }

  // Nom (deux lignes au plus), puis prix juste dessous, ancien prix barré, tailles disponibles.
  const gauche = x + 20;
  const largeurTexte = LARGEUR_CASE - 40;
  ctx.fillStyle = C.nuit;
  ctx.font = '600 26px Inter';
  const nom = lignes(ctx, c.nom, largeurTexte, 2);
  nom.forEach((l, i) => ctx.fillText(l, gauche, y + HAUTEUR_PHOTO + 40 + i * 32));
  const lignePrix = y + HAUTEUR_PHOTO + 40 + (nom.length - 1) * 32 + 44;
  ctx.font = '700 30px Poppins';
  ctx.fillStyle = C.emD;
  ctx.fillText(c.prix, gauche, lignePrix);
  if (c.ancienPrix) {
    const decalage = ctx.measureText(c.prix).width + 12;
    ctx.font = '500 20px Inter';
    ctx.fillStyle = C.gris;
    ctx.fillText(c.ancienPrix, gauche + decalage, lignePrix - 2);
    const l = ctx.measureText(c.ancienPrix).width;
    ctx.fillRect(gauche + decalage, lignePrix - 9, l, 2);
  }
  if (c.options) {
    ctx.font = '500 19px Inter';
    ctx.fillStyle = C.ardoise;
    ctx.fillText(lignes(ctx, c.options, largeurTexte, 1)[0], gauche, lignePrix + 30);
  }
}

/** Case « Bon à savoir » (livraison et paiement de la boutique) à la place d'une case vide. */
function bonASavoir(ctx: SKRSContext2D, infos: string[], x: number, y: number): void {
  ctx.fillStyle = C.menthe;
  ctx.beginPath();
  ctx.roundRect(x, y, LARGEUR_CASE, HAUTEUR_CASE, 26);
  ctx.fill();
  ctx.fillStyle = C.emD;
  ctx.font = '700 30px Poppins';
  ctx.fillText('Bon à savoir', x + 28, y + 64);
  let ligne = y + 116;
  for (const info of infos.slice(0, 5)) {
    ctx.font = '500 22px Inter';
    for (const [rang, morceau] of lignes(ctx, info, LARGEUR_CASE - 76, 2).entries()) {
      if (ligne > y + HAUTEUR_CASE - 30) {
        return;
      }
      if (rang === 0) {
        ctx.fillStyle = C.em;
        ctx.beginPath();
        ctx.arc(x + 36, ligne - 8, 5, 0, Math.PI * 2);
        ctx.fill();
      }
      ctx.fillStyle = C.nuit;
      ctx.fillText(morceau, x + 52, ligne);
      ligne += 32;
    }
    ligne += 14;
  }
}

/**
 * Photo d'article allégée pour WhatsApp : carré de 640 px, JPEG qualité 70, de 40 à 80 Ko selon la photo, quel
 * que soit le format d'origine (WebP, PNG, grande photo). Nette sur un téléphone, légère pour un forfait mobile.
 */
export const COTE_PHOTO = 640;

export async function photoLegere(image: Buffer, cote = COTE_PHOTO): Promise<Buffer> {
  const canvas = createCanvas(cote, cote);
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#FFFFFF';
  ctx.fillRect(0, 0, cote, cote);
  photo(ctx, await loadImage(image), 0, 0, cote, cote);
  return canvas.encode('jpeg', 70);
}

/**
 * Image JPEG de la vitrine (une ou deux rangées selon le nombre d'articles, quatre au plus). Avec un nombre
 * impair d'articles, la case libre donne les informations utiles de la boutique (livraison, paiement).
 */
export async function dessinerVitrine(titre: string, sousTitre: string, cases: CaseVitrine[], infos: string[] = []): Promise<Buffer> {
  const rangees = Math.max(1, Math.ceil(Math.min(cases.length, 4) / 2));
  const hauteur = ENTETE + rangees * HAUTEUR_CASE + (rangees - 1) * ECART + PIED;
  const canvas = createCanvas(L, hauteur);
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = C.nuage;
  ctx.fillRect(0, 0, L, hauteur);

  // En-tête : nom du rayon, boutique et position, marque BAYAM.
  ctx.fillStyle = C.nuit;
  ctx.font = '700 44px Poppins';
  ctx.fillText(lignes(ctx, titre, L - 2 * MARGE - 170, 1)[0], MARGE, 74);
  ctx.font = '500 24px Inter';
  ctx.fillStyle = C.ardoise;
  ctx.fillText(lignes(ctx, sousTitre, L - 2 * MARGE, 1)[0], MARGE, 114);
  pastille(ctx, L - MARGE - 128, 44, 'bayam', C.em, '#FFFFFF', '700 24px Poppins');

  const affichees = cases.slice(0, 4);
  for (const [i, c] of affichees.entries()) {
    await dessinerCase(ctx, c, MARGE + (i % 2) * (LARGEUR_CASE + ECART), ENTETE + Math.floor(i / 2) * (HAUTEUR_CASE + ECART));
  }
  if (affichees.length % 2 === 1 && infos.length) {
    const i = affichees.length;
    bonASavoir(ctx, infos, MARGE + (i % 2) * (LARGEUR_CASE + ECART), ENTETE + Math.floor(i / 2) * (HAUTEUR_CASE + ECART));
  }

  ctx.font = '500 22px Inter';
  ctx.fillStyle = C.ardoise;
  ctx.textAlign = 'center';
  ctx.fillText('Touchez « Choisir » sous l’image pour voir un article et le commander', L / 2, hauteur - 28);
  // Qualité 70 : sur un écran de téléphone, aucune différence visible avec 80, pour un tiers de poids en moins.
  return canvas.encode('jpeg', 70);
}

export type FicheStory = { boutique: string; nom: string; prix: string; ancienPrix: string | null; remise: number | null; peuDeStock: number | null; options: string; appel: string; image: Buffer | null };

/**
 * Visuel vertical d'un article pour les stories (Facebook, statut WhatsApp) : 1080 × 1920, la photo en grand, le nom,
 * le prix lisible d'un coup d'œil, puis comment commander. JPEG qualité 80 : affiché en plein écran.
 */
export async function dessinerStory(f: FicheStory): Promise<Buffer> {
  const l = 1080;
  const h = 1920;
  const canvas = createCanvas(l, h);
  const ctx = canvas.getContext('2d');
  const fond = ctx.createLinearGradient(0, 0, 0, h);
  fond.addColorStop(0, C.nuit);
  fond.addColorStop(1, C.emD);
  ctx.fillStyle = fond;
  ctx.fillRect(0, 0, l, h);

  ctx.fillStyle = '#FFFFFF';
  ctx.font = '700 52px Poppins';
  ctx.fillText(lignes(ctx, f.boutique, l - 330, 1)[0], 60, 140);
  ctx.font = '700 30px Poppins';
  pastille(ctx, l - 60 - ctx.measureText('bayam').width - 30, 86, 'bayam', C.em, '#FFFFFF', '700 30px Poppins', 46);

  // Photo carrée arrondie, pastilles promo et stock par-dessus.
  const cote = l - 120;
  const haut = 220;
  ctx.save();
  ctx.beginPath();
  ctx.roundRect(60, haut, cote, cote, 40);
  ctx.clip();
  ctx.fillStyle = C.menthe;
  ctx.fillRect(60, haut, cote, cote);
  if (f.image) {
    photo(ctx, await loadImage(f.image), 60, haut, cote, cote);
  }
  ctx.restore();
  if (f.remise) {
    ctx.font = '700 40px Inter';
    const texte = `-${f.remise} %`;
    pastille(ctx, 60 + cote - ctx.measureText(texte).width - 70, haut + 32, texte, C.corail, '#FFFFFF', '700 40px Inter', 60);
  }
  if (f.peuDeStock) {
    pastille(ctx, 92, haut + cote - 84, f.peuDeStock === 1 ? 'Dernière pièce' : `Plus que ${f.peuDeStock}`, C.soleil, C.nuit, '600 34px Inter', 54);
  }

  // Nom, prix et tailles centrés entre la photo et le bandeau du bas, quelle que soit leur hauteur.
  ctx.fillStyle = '#FFFFFF';
  ctx.font = '700 60px Poppins';
  const nom = lignes(ctx, f.nom, l - 120, 2);
  const bloc = nom.length * 74 + 30 + 84 + (f.options ? 70 : 0);
  let y = haut + cote + Math.max(60, (h - 250 - haut - cote - bloc) / 2) + 56;
  for (const ligne of nom) {
    ctx.fillText(ligne, 60, y);
    y += 74;
  }
  y += 30;
  ctx.font = '700 84px Poppins';
  ctx.fillStyle = C.soleil;
  ctx.fillText(f.prix, 60, y);
  if (f.ancienPrix) {
    const decalage = ctx.measureText(f.prix).width + 24;
    ctx.font = '500 40px Inter';
    ctx.fillStyle = 'rgba(255, 255, 255, 0.7)';
    ctx.fillText(f.ancienPrix, 60 + decalage, y - 6);
    ctx.fillRect(60 + decalage, y - 20, ctx.measureText(f.ancienPrix).width, 3);
  }
  if (f.options) {
    y += 70;
    ctx.font = '500 38px Inter';
    ctx.fillStyle = '#FFFFFF';
    ctx.fillText(lignes(ctx, f.options, l - 120, 1)[0], 60, y);
  }

  // Comment commander, en bas, dans un bandeau clair.
  ctx.fillStyle = '#FFFFFF';
  ctx.beginPath();
  ctx.roundRect(60, h - 250, l - 120, 150, 75);
  ctx.fill();
  ctx.fillStyle = C.nuit;
  ctx.font = '600 40px Inter';
  ctx.textAlign = 'center';
  lignes(ctx, f.appel, l - 220, 2).forEach((ligne, i, tout) => ctx.fillText(ligne, l / 2, h - 175 + (i - (tout.length - 1) / 2) * 50 + 14));
  ctx.textAlign = 'left';
  return canvas.encode('jpeg', 80);
}
