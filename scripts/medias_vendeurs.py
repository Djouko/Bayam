# Logos et bannières des boutiques de démonstration, images des catégories, dans le style de la charte BAYAM.
# Usage (depuis plateforme-locale) : python scripts/medias_vendeurs.py   puis installer.sh les importe (étape 4 quinquies).
# Émojis Noto et photos produits : bayam-project/bayam/charte/ (licences dans ce dossier).
import math
import pathlib

from PIL import Image, ImageDraw

ICI = pathlib.Path(__file__).resolve().parent
CHARTE = ICI.parents[1] / 'bayam-project' / 'bayam' / 'charte'
SORTIE = ICI / 'medias'
EMERAUDE, NUIT, SOLEIL, MENTHE = (14, 159, 110), (11, 37, 69), (255, 194, 51), (230, 247, 240)

# identifiant, nom, fond du logo, émoji du logo, visuels de la bannière (photo ou émoji)
BOUTIQUES = [
    ('aicha', 'Mode Aïcha', EMERAUDE, '1f457', ['hoodie-with-zipper-2.jpg', 'tshirt-2.jpg']),
    ('akwa', 'Accessoires Akwa', SOLEIL, '1f9e2', ['cap-2.jpg', 'sunglasses-2.jpg']),
    ('bonamoussadi', 'Musique Bonamoussadi', MENTHE, '1f3b5', ['emoji_u1f3b5.png', 'emoji_u1f5bc.png']),
]


def degrade(l, h):
    """Dégradé diagonal émeraude, vert profond puis nuit, comme les bannières de l'application."""
    etapes = [(0.0, EMERAUDE), (0.55, (11, 107, 87)), (1.0, NUIT)]
    img = Image.new('RGB', (l, h))
    px = img.load()
    for x in range(l):
        for y in range(h):
            t = (x / l + y / h) / 2
            for (t0, c0), (t1, c1) in zip(etapes, etapes[1:]):
                if t <= t1:
                    k = (t - t0) / (t1 - t0)
                    px[x, y] = tuple(round(a + (b - a) * k) for a, b in zip(c0, c1))
                    break
    return img


def emoji(code, taille):
    return Image.open(CHARTE / 'sources' / f'emoji_u{code}.png').convert('RGBA').resize((taille, taille), Image.LANCZOS)


def vignette(source, cote=230, angle=0):
    """Carte blanche arrondie portant une photo produit (ou un émoji sur fond menthe), légèrement inclinée."""
    carte = Image.new('RGBA', (cote, cote), (0, 0, 0, 0))
    masque = Image.new('L', (cote, cote), 0)
    ImageDraw.Draw(masque).rounded_rectangle((0, 0, cote - 1, cote - 1), radius=28, fill=255)
    if source.startswith('emoji_'):
        fond = Image.new('RGBA', (cote, cote), MENTHE + (255,))
        e = emoji(source[len('emoji_u'):-len('.png')], int(cote * 0.62))
        fond.alpha_composite(e, ((cote - e.width) // 2, (cote - e.height) // 2))
    else:
        fond = Image.open(CHARTE / 'sources' / source).convert('RGBA').resize((cote, cote), Image.LANCZOS)
    carte.paste(fond, (0, 0), masque)
    return carte.rotate(angle, resample=Image.BICUBIC, expand=True)


def logo(fond, code):
    img = Image.new('RGB', (400, 400), fond)
    e = emoji(code, 230)
    img.paste(e, (85, 85), e)
    return img


def banniere(visuels):
    """Bannière sans texte : l'application affiche déjà le nom et le logo de la boutique par-dessus et en dessous."""
    img = degrade(1200, 400).convert('RGBA')
    halo = Image.new('RGBA', img.size, (0, 0, 0, 0))
    ImageDraw.Draw(halo).ellipse((900, -160, 1300, 240), fill=SOLEIL + (46,))  # halo soleil, comme sur l'accueil
    img.alpha_composite(halo)
    for i, (source, angle, x, y) in enumerate(zip(visuels, (-6, 5), (640, 860), (70, 110))):
        v = vignette(source, angle=angle)
        img.alpha_composite(v, (x, y - 30 * i))
    return img.convert('RGB')


def images_categories():
    """Pictogrammes des catégories (ceux de l'accueil de l'application), posés sur un carré menthe pour le site et les vignettes."""
    for source in sorted((CHARTE / 'visuels' / 'app').glob('categorie_*.png')):
        fond = Image.new('RGBA', (400, 400), MENTHE + (255,))
        picto = Image.open(source).convert('RGBA').resize((240, 240), Image.LANCZOS)
        fond.alpha_composite(picto, (80, 80))
        fond.convert('RGB').save(SORTIE / source.name)


def fanion_cameroun(cote):
    """Fanion vert, rouge, jaune à l'étoile, sur sa baguette ; dessiné en quatre fois plus grand puis réduit (bords nets)."""
    c = cote * 4
    x0, x1, haut, bas = int(c * .2), int(c * .9), int(c * .27), int(c * .73)
    ombre = Image.new('L', (c, c), 0)
    ImageDraw.Draw(ombre).polygon([(x0 + 40, haut + 60), (x0 + 40, bas + 60), (x1 + 40, (haut + bas) // 2 + 60)], fill=30)
    img = Image.new('RGBA', (c, c), (0, 0, 0, 0))
    img.paste((11, 37, 69, 255), (0, 0), ombre)
    masque = Image.new('L', (c, c), 0)
    ImageDraw.Draw(masque).polygon([(x0, haut), (x0, bas), (x1, (haut + bas) // 2)], fill=255)
    bandes = Image.new('RGBA', (c, c), (0, 0, 0, 0))
    db = ImageDraw.Draw(bandes)
    tiers = (x1 - x0) / 3
    for i, couleur in enumerate([(0, 122, 94), (206, 17, 38), (252, 209, 22)]):
        db.rectangle((x0 + i * tiers, 0, x0 + (i + 1) * tiers, c), fill=couleur + (255,))
    img.paste(bandes, (0, 0), masque)
    # Étoile jaune au centre de la bande rouge, comme sur le drapeau.
    cx, cy, r = x0 + 1.5 * tiers, (haut + bas) / 2, tiers * .32
    points = [(cx + (r if j % 2 == 0 else r * .4) * math.sin(j * math.pi / 5), cy - (r if j % 2 == 0 else r * .4) * math.cos(j * math.pi / 5)) for j in range(10)]
    d = ImageDraw.Draw(img)
    d.polygon(points, fill=(252, 209, 22, 255))
    d.rounded_rectangle((x0 - 70, haut - 160, x0 - 10, bas + 260), radius=30, fill=(140, 94, 60, 255))  # baguette
    return img.resize((cote, cote), Image.LANCZOS)


def images_produits():
    """Visuels des articles de musique et du fanion, sur le même fond gris clair que les photos du catalogue."""
    fond_catalogue = (234, 239, 243)
    for nom, motif in [('produit_album', emoji('1f3a7', 470)), ('produit_single', emoji('1f3b6', 430)), ('produit_fanion', fanion_cameroun(800))]:
        img = Image.new('RGBA', (800, 800), fond_catalogue + (255,))
        img.alpha_composite(motif, ((800 - motif.width) // 2, (800 - motif.height) // 2))
        img.convert('RGB').save(SORTIE / f'{nom}.png')


if __name__ == '__main__':
    SORTIE.mkdir(exist_ok=True)
    images_categories()
    images_produits()
    for ident, nom, fond, code, visuels in BOUTIQUES:
        logo(fond, code).save(SORTIE / f'logo_{ident}.png')
        banniere(visuels).save(SORTIE / f'banniere_{ident}.png')
        print('boutique prête :', nom)
