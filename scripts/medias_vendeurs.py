# Logos et bannières des boutiques de démonstration, images des catégories, dans le style de la charte BAYAM.
# Usage (depuis plateforme-locale) : python scripts/medias_vendeurs.py   puis installer.sh les importe (étape 4 quinquies).
# Émojis Noto et photos produits : bayam-project/bayam/charte/ (licences dans ce dossier).
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


if __name__ == '__main__':
    SORTIE.mkdir(exist_ok=True)
    images_categories()
    for ident, nom, fond, code, visuels in BOUTIQUES:
        logo(fond, code).save(SORTIE / f'logo_{ident}.png')
        banniere(visuels).save(SORTIE / f'banniere_{ident}.png')
        print('boutique prête :', nom)
