"""Catalogue réaliste des trois boutiques d'essai (prix du marché de Douala, en FCFA).

Chaque article : clé stable, identifiant existant à reprendre (les essais automatiques en dépendent) ou None,
boutique, rayon, nom, prix, prix promo éventuel, stock (0 = épuisé) ou déclinaisons avec leur stock, descriptions,
recherche de photo (banque d'images libres Openverse). Produit catalogue.json, lu par catalogue_reel.php.
Usage : python catalogue.py
"""
import json
import pathlib

AICHA, AKWA, BONAMOUSSADI = 3, 4, 5

RAYONS = {
    'robes': 'Robes',
    'pagnes': 'Pagnes et tissus',
    'chemises': 'Chemises',
    'tshirts': 'T-shirts et polos',
    'hoodies': 'Sweats à capuche',
    'sacs': 'Sacs',
    'chaussures': 'Chaussures',
    'montres-bijoux': 'Montres et bijoux',
    'accessories': 'Accessoires',
    'audio': 'Son et audio',
    'telephonie': 'Accessoires téléphone',
    'instruments': 'Instruments de musique',
}

LIVRAISON = "Livraison à Douala sous 24 h, retrait possible en boutique."


def article(cle, boutique, rayon, nom, prix, courte, description, recherche, stock=0, promo=None, declinaisons=None, id=None):
    return {
        'cle': cle, 'id': id, 'boutique': boutique, 'rayon': rayon, 'nom': nom, 'prix': prix, 'promo': promo,
        'stock': stock, 'declinaisons': declinaisons, 'courte': courte, 'description': description, 'recherche': recherche,
    }


ARTICLES = [
    # --- Mode Aïcha (Akwa) : pagne, robes, chemises, basiques ---
    article('robe-sirene-wax', AICHA, 'robes', 'Robe sirène en pagne wax', 18500,
            'Robe longue ajustée en wax hollandais, coupe sirène, fermeture éclair au dos.',
            "Coupée et cousue à Douala dans un wax hollandais 100 % coton aux couleurs vives qui tiennent au lavage. "
            "Ajustée jusqu'aux genoux puis évasée, elle se porte pour les mariages, dots et cérémonies. Doublure légère, "
            "fermeture éclair invisible au dos. Lavage à la main à l'envers, repassage doux. " + LIVRAISON,
            ['african print dress', 'ankara dress', 'wax print dress'],
            declinaisons={'Taille': {'S': 2, 'M': 3, 'L': 0, 'XL': 1}}),
    article('kaba-ngondo', AICHA, 'robes', 'Kaba ngondo brodé', 15000,
            'Grande robe ample traditionnelle douala, broderies au col, taille unique.',
            "Le kaba ngondo des fêtes et des dimanches : ample, frais et confortable sous la chaleur de Douala. Tissu "
            "coton, broderies faites main autour du col et des manches. Taille unique, convient du 38 au 52. " + LIVRAISON,
            ['kaba dress', 'african kaftan dress', 'boubou dress'], stock=6),
    article('robe-courte-wax', AICHA, 'robes', 'Robe courte évasée en wax', 14000,
            'Robe courte trapèze en wax, col rond, poches cachées.',
            "Robe de tous les jours en wax coton, coupe trapèze qui tombe juste au-dessus du genou, deux poches "
            "cachées dans les coutures. Idéale pour le bureau ou les sorties. " + LIVRAISON,
            ['african print short dress', 'ankara short dress', 'colorful print dress'], promo=12500,
            declinaisons={'Taille': {'S': 1, 'M': 4, 'L': 2}}),
    article('ensemble-pagne', AICHA, 'robes', 'Ensemble jupe et haut en pagne', 22000,
            'Jupe longue et haut assorti en wax, fait sur mesure en 72 h.',
            "Ensemble deux pièces : jupe longue fendue et haut ajusté à manches bouffantes, dans le même wax. "
            "Actuellement épuisé : écrivez-nous sur WhatsApp pour une confection sur mesure en 72 h. " + LIVRAISON,
            ['african print skirt set', 'ankara two piece', 'african fashion woman'], stock=0),
    article('pagne-wax-6y', AICHA, 'pagnes', 'Pagne wax hollandais 6 yards', 18000,
            'Pagne wax hollandais, 6 yards (5,48 m), 100 % coton.',
            "Pagne wax hollandais véritable, 6 yards (5,48 m sur 1,20 m), 100 % coton, impression des deux côtés. "
            "De quoi coudre une robe longue ou un ensemble. Les motifs changent selon les arrivages : demandez les "
            "photos du jour sur WhatsApp. " + LIVRAISON,
            ['wax print fabric', 'african fabric', 'ankara fabric'], stock=3, id=520),
    article('super-wax-6y', AICHA, 'pagnes', 'Super wax 6 yards', 27500,
            'Super wax haut de gamme, couleurs intenses, 6 yards.',
            "Super wax de qualité supérieure, toucher plus doux et couleurs plus profondes que le wax classique. "
            "6 yards (5,48 m), 100 % coton. Idéal pour les grandes cérémonies. Plus que deux pièces en boutique. " + LIVRAISON,
            ['african wax fabric colorful', 'kente fabric', 'printed cotton fabric africa'], stock=2),
    article('chemise-pagne-homme', AICHA, 'chemises', 'Chemise homme en pagne', 11000,
            'Chemise manches courtes en wax, coupe droite, boutons nacrés.',
            "Chemise homme en wax coton, manches courtes, coupe droite qui se porte dans ou hors du pantalon. "
            "Parfaite pour les vendredis pagne au bureau et les cérémonies. " + LIVRAISON,
            ['african print shirt man', 'ankara shirt', 'man wearing african shirt'],
            declinaisons={'Taille': {'M': 2, 'L': 3, 'XL': 2, 'XXL': 0}}),
    article('chemise-wax-longue', AICHA, 'chemises', 'Chemise longue en wax bleu', 12000,
            'Chemise homme manches longues en wax, motifs bleus.',
            "Chemise homme à manches longues dans un wax aux motifs bleus et blancs, coupe droite, col classique. "
            "Pour les cérémonies, le culte du dimanche ou le bureau. Lavage à la main à l'envers. " + LIVRAISON,
            ['african print long sleeve shirt', 'blue wax shirt'],
            declinaisons={'Taille': {'M': 3, 'L': 2, 'XL': 1}}),
    article('polo', AICHA, 'tshirts', 'Polo', 12000,
            'Polo homme en piqué de coton, col boutonné, coupe ajustée.',
            "Polo en piqué de coton épais qui ne se déforme pas au lavage, col trois boutons, coupe ajustée. "
            "Coloris bleu ciel. " + LIVRAISON,
            ['polo shirt', 'blue polo shirt', 'man polo shirt'], stock=8, id=25),
    article('t-shirt', AICHA, 'tshirts', 'T-shirt', 5000,
            'T-shirt col rond 100 % coton, coupe unisexe.',
            "T-shirt basique col rond en coton peigné 180 g, coupe unisexe. Le bon basique sous une chemise ou seul. " + LIVRAISON,
            ['white t-shirt', 'plain t-shirt', 'cotton t-shirt'], stock=12, id=17),
    article('t-shirt-col-v', AICHA, 'tshirts', 'T-shirt col V', 4500,
            'T-shirt col V en coton, plusieurs tailles.',
            "T-shirt col V en coton léger, idéal par temps chaud. " + LIVRAISON,
            ['v-neck t-shirt', 'v neck shirt', 'grey t-shirt'], id=14),
    article('sweat-capuche', AICHA, 'hoodies', 'Sweat à capuche', 15000,
            'Sweat à capuche molletonné, poche kangourou, plusieurs couleurs.',
            "Sweat à capuche en molleton coton et polyester, poche kangourou et cordon de serrage. Pour les soirées "
            "fraîches de saison des pluies et les voyages à l'Ouest. " + LIVRAISON,
            ['hoodie', 'red hoodie', 'sweatshirt hood'], id=15),
    article('casquette-brodee', AICHA, 'accessories', 'Casquette brodée', 6500,
            'Casquette en coton, broderie sur le devant, réglable.',
            "Casquette six panneaux en coton, broderie sur le devant, fermeture réglable à l'arrière. " + LIVRAISON,
            ['embroidered cap', 'baseball cap', 'cap embroidery'], stock=4, id=237),

    # --- Accessoires Akwa : sacs, chaussures, montres, bijoux ---
    article('sac-main-cuir', AKWA, 'sacs', 'Sac à main en cuir', 25000,
            'Sac à main en cuir véritable, deux anses, bandoulière amovible.',
            "Sac à main en cuir de vachette, deux grandes poches intérieures dont une zippée, bandoulière amovible. "
            "Assez grand pour un téléphone, un portefeuille et des documents A5. " + LIVRAISON,
            ['leather handbag', 'brown leather bag', 'women handbag'], stock=4),
    article('sac-dos-cuir', AKWA, 'sacs', 'Sac à dos en cuir', 18500,
            'Sac à dos en cuir, compartiment ordinateur 14 pouces.',
            "Sac à dos en cuir synthétique résistant, compartiment rembourré pour ordinateur 14 pouces, poche "
            "anti-vol au dos. Victime de son succès : nouvel arrivage annoncé. " + LIVRAISON,
            ['leather backpack', 'backpack', 'brown backpack'], stock=0),
    article('pochette-soiree', AKWA, 'sacs', 'Pochette de soirée argentée', 9000,
            'Pochette argentée à fermoir métal, chaîne fine.',
            "Pochette de soirée argentée, fermoir en métal et chaîne fine pour la porter à l'épaule. Pour les "
            "mariages et les soirées. " + LIVRAISON,
            ['clutch bag', 'evening clutch', 'gold clutch'], stock=5),
    article('baskets-blanches', AKWA, 'chaussures', 'Baskets blanches', 22000,
            'Baskets en cuir blanc, semelle confortable.',
            "Baskets basses en cuir blanc, semelle en caoutchouc souple, se nettoient d'un coup d'éponge. " + LIVRAISON,
            ['white sneakers', 'white shoes', 'sneakers'],
            declinaisons={'Pointure': {'40': 2, '41': 3, '42': 0, '43': 2, '44': 1}}),
    article('sandales-cuir', AKWA, 'chaussures', 'Sandales en cuir faites main', 8500,
            'Sandales en cuir, fabriquées à la main au Cameroun.',
            "Sandales en cuir tanné, cousues à la main par un artisan de Douala. Semelle solide qui tient la route "
            "en saison des pluies. " + LIVRAISON,
            ['leather sandals', 'handmade sandals', 'brown sandals'], promo=7500,
            declinaisons={'Pointure': {'39': 2, '40': 3, '41': 3, '42': 2, '43': 0}}),
    article('escarpins-noirs', AKWA, 'chaussures', 'Escarpins noirs', 15000,
            'Escarpins noirs, talon 7 cm, bout pointu.',
            "Escarpins noirs vernis, talon de 7 cm stable, bout pointu. Le classique du bureau et des cérémonies. " + LIVRAISON,
            ['black high heels', 'black pumps', 'high heel shoes'],
            declinaisons={'Pointure': {'37': 1, '38': 2, '39': 2, '40': 0}}),
    article('montre-homme', AKWA, 'montres-bijoux', 'Montre homme en acier', 35000,
            'Montre à quartz, bracelet acier, étanche 30 m.',
            "Montre homme à quartz, boîtier et bracelet en acier inoxydable, verre minéral, étanche 30 m. "
            "Garantie 6 mois. " + LIVRAISON,
            ['men wrist watch', 'steel watch', 'wristwatch'], stock=2),
    article('montre-femme', AKWA, 'montres-bijoux', 'Montre femme argentée', 28000,
            'Montre fine argentée, bracelet métal ajouré.',
            "Montre femme à quartz, boîtier ovale argenté, bracelet en métal ajouré. Élégante au bureau comme en "
            "soirée. Garantie 6 mois. " + LIVRAISON,
            ['gold watch women', 'women watch', 'golden wristwatch'], promo=24000, stock=3),
    article('collier-perles', AKWA, 'montres-bijoux', 'Collier de perles africaines', 6000,
            'Collier en perles de verre faites main, longueur 45 cm.',
            "Collier en perles de verre colorées, monté à la main, fermoir réglable. Va avec le pagne comme avec le "
            "jean. " + LIVRAISON,
            ['african beads necklace', 'bead necklace', 'colorful necklace'], stock=10),
    article('bracelet-jonc', AKWA, 'montres-bijoux', 'Bracelet jonc doré', 4500,
            "Jonc ouvert en acier doré, s'ajuste au poignet.",
            "Bracelet jonc ouvert en acier inoxydable doré, fin et léger, s'ajuste à tous les poignets. Ne noircit "
            "pas au contact de l'eau. Se porte seul ou par trois. " + LIVRAISON,
            ['gold bangle', 'gold bracelet'], stock=7),
    article('ceinture-cuir', AKWA, 'accessories', 'Ceinture en cuir', 7500,
            'Ceinture en cuir pleine fleur, boucle métal, recoupable.',
            "Ceinture en cuir pleine fleur, boucle en métal brossé, longueur recoupable à votre taille en boutique. " + LIVRAISON,
            ['leather belt', 'brown belt', 'belt buckle'], stock=6, id=19),
    article('casquette', AKWA, 'accessories', 'Casquette', 4000,
            'Casquette unie en coton, réglable.',
            "Casquette unie en coton, visière courbée, fermeture réglable. " + LIVRAISON,
            ['cap', 'baseball cap plain', 'hat cap'], stock=9, id=20),
    article('lunettes-soleil', AKWA, 'accessories', 'Lunettes de soleil', 6500,
            'Lunettes de soleil polarisées, protection UV400.',
            "Lunettes de soleil à verres polarisés, protection UV400, monture légère. Étui fourni. " + LIVRAISON,
            ['sunglasses', 'black sunglasses', 'sun glasses'], stock=5, id=21),

    # --- Musique Bonamoussadi : son, téléphone, instruments ---
    article('enceinte-bluetooth', BONAMOUSSADI, 'audio', 'Enceinte Bluetooth portable', 18000,
            'Enceinte sans fil, 12 h d’autonomie, résiste aux éclaboussures.',
            "Enceinte Bluetooth 5.3, 20 W, 12 heures d'autonomie, résiste aux éclaboussures. Entrée carte mémoire "
            "et clé USB pour écouter sans téléphone. Garantie 3 mois. " + LIVRAISON,
            ['bluetooth speaker', 'portable speaker', 'wireless speaker'], stock=6),
    article('ecouteurs-sans-fil', BONAMOUSSADI, 'audio', 'Écouteurs sans fil', 15000,
            'Écouteurs Bluetooth avec boîtier de charge, 24 h au total.',
            "Écouteurs sans fil Bluetooth, 6 heures d'écoute et 24 heures avec le boîtier de charge, micro pour les "
            "appels. Garantie 3 mois. " + LIVRAISON,
            ['wireless earbuds', 'earbuds case', 'bluetooth earphones'], promo=12500, stock=8),
    article('casque-audio', BONAMOUSSADI, 'audio', 'Casque audio', 15000,
            'Casque fermé pliable, câble et Bluetooth.',
            "Casque audio fermé, pliable, coussinets confortables, fonctionne en Bluetooth ou avec câble. " + LIVRAISON,
            ['headphones', 'over ear headphones', 'black headphones'], stock=3),
    article('micro-cravate', BONAMOUSSADI, 'audio', 'Micro-cravate pour téléphone', 6500,
            'Micro-cravate filaire, pour vidéos et lives TikTok.',
            "Micro-cravate filaire de 1,5 m pour téléphone, son net pour vos vidéos, lives et présentations de "
            "produits. " + LIVRAISON,
            ['lavalier microphone', 'clip microphone', 'microphone'], stock=10),
    article('chargeur-rapide', BONAMOUSSADI, 'telephonie', 'Chargeur rapide hoco 20 W et câble', 5500,
            'Chargeur hoco 20 W USB-C avec câble 1 m.',
            "Chargeur secteur 20 W à charge rapide, prise USB-C, câble de 1 m fourni. " + LIVRAISON,
            ['phone charger', 'usb charger', 'charger cable'], stock=15),
    article('power-bank', BONAMOUSSADI, 'telephonie', 'Batterie externe 20 000 mAh', 14000,
            'Batterie externe 20 000 mAh, deux ports USB.',
            "Batterie externe de 20 000 mAh, de quoi recharger un téléphone quatre fois pendant les coupures de "
            "courant. Deux ports USB et un port USB-C. Épuisée : arrivage attendu. " + LIVRAISON,
            ['power bank', 'portable battery', 'powerbank'], stock=0),
    article('guitare-acoustique', BONAMOUSSADI, 'instruments', 'Guitare acoustique', 65000,
            'Guitare folk, table épicéa, housse offerte.',
            "Guitare folk taille adulte, table en épicéa, livrée accordée avec housse et médiators. Dernière pièce. " + LIVRAISON,
            ['acoustic guitar', 'guitar', 'folk guitar'], stock=1),
    article('djembe', BONAMOUSSADI, 'instruments', 'Djembé', 30000,
            'Djembé en bois sculpté, peau de chèvre, hauteur 60 cm.',
            "Djembé en bois massif sculpté à la main, peau de chèvre tendue, hauteur 60 cm. Son profond, idéal "
            "pour les chorales et animations. " + LIVRAISON,
            ['djembe', 'african drum', 'djembe drum'], stock=2),
]

# Articles d'exemple de WooCommerce qui n'ont rien de réel ici (logos WordPress, téléchargements, doublons).
A_RETIRER = [16, 18, 22, 23, 24, 26, 27, 34, 35, 36, 37]

if __name__ == '__main__':
    cles = [a['cle'] for a in ARTICLES]
    assert len(cles) == len(set(cles)), 'clé en double'
    assert all(a['rayon'] in RAYONS for a in ARTICLES)
    sortie = pathlib.Path(__file__).with_name('catalogue.json')
    sortie.write_text(json.dumps({'rayons': RAYONS, 'articles': ARTICLES, 'a_retirer': A_RETIRER}, ensure_ascii=False, indent=1), encoding='utf8')
    print(f'{len(ARTICLES)} articles, {len(RAYONS)} rayons -> {sortie.name}')
