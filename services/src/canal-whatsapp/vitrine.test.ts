import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createCanvas } from '@napi-rs/canvas';
import { dessinerVitrine, photoLegere, type CaseVitrine } from './vitrine.ts';

/** Photo d'essai : dégradé de couleur, comme une vraie photo pour le poids du JPEG. */
async function photo(teinte: number): Promise<Buffer> {
  const canvas = createCanvas(600, 600);
  const ctx = canvas.getContext('2d');
  const degrade = ctx.createLinearGradient(0, 0, 600, 600);
  degrade.addColorStop(0, `hsl(${teinte}, 70%, 55%)`);
  degrade.addColorStop(1, `hsl(${teinte + 60}, 60%, 30%)`);
  ctx.fillStyle = degrade;
  ctx.fillRect(0, 0, 600, 600);
  return canvas.encode('jpeg', 85);
}

const cases = async (n: number): Promise<CaseVitrine[]> => Promise.all(Array.from({ length: n }, async (_, i) => ({
  numero: i + 1,
  nom: i === 0 ? 'Robe sirène en pagne wax avec un nom vraiment très long pour la case' : `Article ${i + 1}`,
  prix: '18 500 FCFA',
  ancienPrix: i === 1 ? '21 000 FCFA' : null,
  remise: i === 1 ? 12 : null,
  peuDeStock: i === 2 ? 2 : null,
  options: i === 3 ? 'Taille : S, M, L, XL, XXL, 3XL, 4XL, 5XL' : '',
  image: i === 3 ? null : await photo(i * 70),
})));

/** Hauteur d'une image JPEG lue dans son en-tête (marqueur SOF0 ou SOF2). */
function hauteurJpeg(jpeg: Buffer): number {
  for (let i = 2; i < jpeg.length - 8; i++) {
    if (jpeg[i] === 0xff && (jpeg[i + 1] === 0xc0 || jpeg[i + 1] === 0xc2)) {
      return jpeg.readUInt16BE(i + 5);
    }
  }
  return 0;
}

test('vitrine de 4 articles : JPEG léger (moins de 200 Ko), deux rangées', async () => {
  const jpeg = await dessinerVitrine('Robes', 'Mode Aïcha · articles 1 à 4 sur 6', await cases(4));
  assert.equal(jpeg[0], 0xff);
  assert.equal(jpeg[1], 0xd8);
  assert.ok(jpeg.length < 200_000, `${jpeg.length} octets`);
  assert.ok(hauteurJpeg(jpeg) > 1000);
});

test('vitrine de 1 ou 2 articles : une seule rangée, image moins haute', async () => {
  const une = await dessinerVitrine('Pagnes et tissus', 'Mode Aïcha · article 1 sur 1', await cases(1));
  const deux = await dessinerVitrine('Pagnes et tissus', 'Mode Aïcha · articles 1 à 2 sur 2', await cases(2));
  assert.equal(hauteurJpeg(une), hauteurJpeg(deux));
  assert.ok(hauteurJpeg(une) < 750);
});

test('nombre impair d\u2019articles : la case libre reçoit « Bon à savoir » sans changer la taille', async () => {
  const sans = await dessinerVitrine('Robes', 'Mode Aïcha · articles 1 à 3 sur 3', await cases(3));
  const avec = await dessinerVitrine('Robes', 'Mode Aïcha · articles 1 à 3 sur 3', await cases(3), ['Livraison en ville : 1 000 FCFA', 'Retrait en boutique : gratuit', 'Paiement à la livraison']);
  assert.equal(hauteurJpeg(avec), hauteurJpeg(sans));
  assert.ok(avec.length > sans.length);
});

test('article sans photo : case dessinée quand même, sans erreur', async () => {
  const [sansPhoto] = (await cases(4)).slice(3);
  const jpeg = await dessinerVitrine('Accessoires', 'Accessoires Akwa · article 1 sur 1', [sansPhoto]);
  assert.ok(jpeg.length > 5000);
});

test('photo d’article allégée : carré de 640 px, bien moins lourde que l’originale', async () => {
  const canvas = createCanvas(1600, 1200);
  const ctx = canvas.getContext('2d');
  for (let i = 0; i < 400; i++) {
    ctx.fillStyle = `hsl(${(i * 37) % 360}, 70%, 50%)`;
    ctx.fillRect((i * 53) % 1600, (i * 97) % 1200, 120, 90);
  }
  const originale = await canvas.encode('jpeg', 95);
  const legere = await photoLegere(originale);
  assert.equal(hauteurJpeg(legere), 640);
  assert.ok(legere.length < originale.length / 2, `${legere.length} contre ${originale.length}`);
});
