"""Photos libres des articles du catalogue : Wikimedia Commons puis Openverse, licences libres utilisables commercialement.

Étape 1, candidats : python photos.py candidats   (miniatures + planches de choix dans candidats/)
Étape 2, choix     : renseigner choix.json  {"cle-article": numéro du candidat, ou ["autre-cle", numéro]}
Étape 3, photos    : python photos.py telecharger  (photo choisie, recadrée en carré 1200 px, JPEG léger, dans images/)
Auteur, licence et source de chaque photo sont gardés dans credits.json (mention exigée par les licences CC BY).
"""
import io
import json
import pathlib
import re
import ssl
import sys
import time
import urllib.parse
import urllib.request

import certifi
from PIL import Image, ImageDraw, ImageOps

ICI = pathlib.Path(__file__).parent
# Magasin de certificats à jour (celui du système a un certificat racine expiré pour Wikimedia).
TLS = ssl.create_default_context(cafile=certifi.where())
API = 'https://api.openverse.org/v1/images/'
COMMONS = 'https://commons.wikimedia.org/w/api.php'
# Recherches précises dans Commons (photos réelles, souvent de Wiki Loves Africa pour le pagne et les tenues).
RECHERCHES_COMMONS = {
    'robe-sirene-wax': ['robe pagne', 'Ankara dress'], 'kaba-ngondo': ['kaba ngondo', 'kaba Cameroun'],
    'robe-courte-wax': ['robe en pagne', 'African print dress'], 'ensemble-pagne': ['tenue en pagne', 'pagne Cameroun femme'],
    'pagne-wax-6y': ['pagne wax', 'wax print fabric'], 'super-wax-6y': ['tissu wax', 'African wax print'],
    'chemise-pagne-homme': ['chemise pagne', 'African print shirt'], 'chemise-blanche': ['white shirt clothing', 'chemise blanche'],
    'polo': ['polo shirt', 'polo shirt blue'], 't-shirt': ['white t-shirt', 'plain t-shirt'], 't-shirt-col-v': ['v-neck t-shirt', 'v-neck shirt'],
    'sweat-capuche': ['red hoodie', 'hoodie'], 'casquette-brodee': ['embroidered baseball cap', 'baseball cap'],
    'sac-main-cuir': ['leather handbag', 'sac à main cuir'], 'sac-dos-cuir': ['leather backpack', 'backpack'],
    'pochette-soiree': ['clutch bag', 'evening bag'], 'baskets-blanches': ['white sneakers', 'sneakers'],
    'sandales-cuir': ['leather sandals', 'sandales cuir'], 'escarpins-noirs': ['black pumps shoes', 'high heels black'],
    'montre-homme': ['stainless steel wristwatch', 'wristwatch'], 'montre-femme': ['gold watch women', 'ladies watch'],
    'collier-perles': ['African bead necklace', 'collier perles'], 'boucles-dorees': ['gold hoop earrings', 'earrings gold'],
    'ceinture-cuir': ['leather belt', 'belt buckle leather'], 'casquette': ['baseball cap', 'cap headwear'],
    'lunettes-soleil': ['sunglasses', 'sunglasses black'], 'enceinte-bluetooth': ['bluetooth speaker', 'portable speaker'],
    'ecouteurs-sans-fil': ['wireless earbuds', 'true wireless earphones'], 'casque-audio': ['headphones', 'over-ear headphones'],
    'micro-cravate': ['lavalier microphone', 'lapel microphone'], 'chargeur-rapide': ['USB charger', 'phone charger'],
    'power-bank': ['power bank', 'powerbank'], 'guitare-acoustique': ['acoustic guitar', 'guitare folk'], 'djembe': ['djembe', 'djembé'],
}
AGENT = {'User-Agent': 'BAYAM-catalogue-essai/1.0 (https://bayam.cm; boutique de demonstration)'}  # en-tete HTTP : ASCII seulement
ARTICLES = json.loads((ICI / 'catalogue.json').read_text(encoding='utf8'))['articles']


def lire(url, essais=4):
    for essai in range(essais):
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers=AGENT), timeout=40, context=TLS) as r:
                return r.read()
        except urllib.error.HTTPError as e:
            if e.code in (429, 502, 503) and essai < essais - 1:
                time.sleep(20 * (essai + 1))  # limite de débit ou serveur saturé : on patiente
                continue
            raise
        except (TimeoutError, ConnectionError, urllib.error.URLError):
            if essai == essais - 1:
                raise
            time.sleep(5 * (essai + 1))  # réseau lent : nouvelle tentative


def chercher(requete):
    parametres = urllib.parse.urlencode({'q': requete, 'license_type': 'commercial', 'category': 'photograph', 'mature': 'false', 'page_size': 8})
    return json.loads(lire(f'{API}?{parametres}'))['results']


def chercher_commons(requete):
    """Photos de Commons (au moins 700 px) avec auteur et licence ; seulement les licences libres commerciales."""
    parametres = urllib.parse.urlencode({
        'action': 'query', 'format': 'json', 'generator': 'search', 'gsrnamespace': 6, 'gsrlimit': 8,
        'gsrsearch': f'{requete} filetype:bitmap filew:>700', 'prop': 'imageinfo', 'iiprop': 'url|size|extmetadata', 'iiurlwidth': 320,
    })
    pages = json.loads(lire(f'{COMMONS}?{parametres}')).get('query', {}).get('pages', {})
    resultats = []
    for page in sorted(pages.values(), key=lambda p: p.get('index', 0)):
        info = page['imageinfo'][0]
        meta = info.get('extmetadata', {})
        licence = re.sub('<[^>]+>', '', meta.get('LicenseShortName', {}).get('value', '')).strip()
        if not licence.lower().startswith(('cc0', 'cc by', 'public domain', 'pd')) or 'nc' in licence.lower():
            continue
        resultats.append({
            'id': f"commons-{page['pageid']}", 'url': info['url'], 'thumbnail': info.get('thumburl'), 'width': info.get('width'), 'height': info.get('height'),
            'creator': re.sub('<[^>]+>', '', meta.get('Artist', {}).get('value', '')).strip()[:120], 'creator_url': None,
            'license': licence, 'license_version': '', 'license_url': meta.get('LicenseUrl', {}).get('value'),
            'source': 'wikimedia commons', 'foreign_landing_url': info.get('descriptionurl'), 'title': page['title'].removeprefix('File:'),
        })
    return resultats


def candidats():
    dossier = ICI / 'candidats'
    dossier.mkdir(exist_ok=True)
    fichier = dossier / 'candidats.json'
    # Reprise : les articles déjà cherchés ne sont pas recherchés de nouveau.
    tous = json.loads(fichier.read_text(encoding='utf8')) if fichier.exists() else {}
    for a in ARTICLES:
        if a['cle'] in tous:
            continue
        vus = []
        for requete in RECHERCHES_COMMONS.get(a['cle'], []):
            try:
                trouves = chercher_commons(requete)
            except Exception as e:
                print('recherche Commons en échec', a['cle'], requete, e, flush=True)
                continue
            for r in trouves:
                l, h = r['width'] or 0, r['height'] or 0
                if r['id'] not in [v['id'] for v in vus] and 0.55 <= l / max(h, 1) <= 1.8:
                    vus.append(r)
            time.sleep(0.5)
        for requete in a['recherche']:
            try:
                trouves = chercher(requete)
            except Exception as e:
                print('recherche Openverse en échec', a['cle'], requete, e, flush=True)
                continue
            for r in trouves:
                l, h = r.get('width') or 0, r.get('height') or 0
                if r['id'] not in [v['id'] for v in vus] and l >= 500 and h >= 500 and 0.55 <= l / h <= 1.8:
                    vus.append({k: r.get(k) for k in ('id', 'url', 'thumbnail', 'creator', 'creator_url', 'license', 'license_version', 'license_url', 'source', 'foreign_landing_url', 'title')})
            if len(vus) >= 10:
                break
            time.sleep(1.5)
        tous[a['cle']] = vus[:10]
        fichier.write_text(json.dumps(tous, ensure_ascii=False, indent=1), encoding='utf8')
        print(a['cle'], len(tous[a['cle']]), flush=True)
    # Planches : 6 articles par planche, 6 candidats numérotés par article.
    cote = 150
    cles = list(tous)
    for n in range(0, len(cles), 6):
        planche = Image.new('RGB', (cote * 10 + 150, cote * 6), 'white')
        dessin = ImageDraw.Draw(planche)
        for ligne, cle in enumerate(cles[n:n + 6]):
            dessin.text((4, ligne * cote + 70), cle[:22], fill='black')
            for col, c in enumerate(tous[cle]):
                try:
                    im = Image.open(io.BytesIO(lire(c['thumbnail']))).convert('RGB')
                    im = ImageOps.fit(im, (cote - 6, cote - 6))
                    planche.paste(im, (150 + col * cote, ligne * cote))
                except Exception as e:  # miniature indisponible : case laissée blanche
                    print('miniature manquante', cle, col, e)
                dessin.rectangle((150 + col * cote, ligne * cote, 150 + col * cote + 22, ligne * cote + 18), fill='black')
                dessin.text((150 + col * cote + 6, ligne * cote + 3), str(col), fill='white')
                time.sleep(0.3)
        planche.save(dossier / f'planche_{n // 6 + 1}.jpg', quality=80)


def telecharger():
    choix = json.loads((ICI / 'choix.json').read_text(encoding='utf8'))
    tous = json.loads((ICI / 'candidats' / 'candidats.json').read_text(encoding='utf8'))
    (ICI / 'images').mkdir(exist_ok=True)
    fichier_credits = ICI / 'credits.json'
    credits = json.loads(fichier_credits.read_text(encoding='utf8')) if fichier_credits.exists() else {}
    # Photo principale (choix.json) puis galerie de chaque article (catalogue.json) : <clé>.jpg, <clé>-2.jpg, <clé>-3.jpg…
    a_telecharger = dict(choix)
    for article in ARTICLES:
        for rang, source in enumerate(article.get('galerie', []), start=2):
            a_telecharger[f"{article['cle']}-{rang}"] = source
    for cle, numero in a_telecharger.items():
        source, numero = numero if isinstance(numero, list) else (cle, numero)
        c = tous[source][numero]
        image = ICI / 'images' / f'{cle}.jpg'
        # Reprise : une photo déjà téléchargée pour ce choix n'est pas téléchargée une seconde fois.
        if not image.exists() or credits.get(cle, {}).get('foreign_landing_url') != c['foreign_landing_url']:
            im = Image.open(io.BytesIO(lire(c['url']))).convert('RGB')
            # Carré 1200 px centré : la photo se lit aussi bien en vignette qu'en plein écran, en ~120 Ko.
            im = ImageOps.fit(ImageOps.exif_transpose(im), (1200, 1200), method=Image.LANCZOS)
            im.save(image, quality=82, optimize=True, progressive=True)
        credits[cle] = {k: c[k] for k in ('title', 'creator', 'creator_url', 'license', 'license_version', 'license_url', 'source', 'foreign_landing_url')}
        fichier_credits.write_text(json.dumps(credits, ensure_ascii=False, indent=1), encoding='utf8')
        print(cle, image.stat().st_size // 1024, 'Ko', flush=True)


if __name__ == '__main__':
    {'candidats': candidats, 'telecharger': telecharger}[sys.argv[1]]()
