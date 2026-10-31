<?php
defined('ABSPATH') || exit;

/**
 * Côté clients du vendeur : réponses automatiques aux messages adressés à BAYAM (« #robes », « bayam robe rouge »,
 * ou une réponse à une carte ou un statut publié par BAYAM : « Dispo en L ? »), et envoi d'une collection.
 * Une conversation ordinaire (« combien tu as payé ? ») n'est jamais interprétée. Jamais de premier contact : on
 * répond à qui a écrit, sinon on prépare un lien wa.me que le vendeur envoie lui-même.
 * Les clients écrivent en français, en anglais ou en pidgin : la réponse suit leur langue.
 */
final class Bayam_WA_Clients
{
    /** Mots sans valeur pour la recherche (français, anglais, pidgin) : salutations, questions, politesse. */
    private const VIDES = ['prix', 'combien', 'le', 'la', 'les', 'des', 'de', 'du', 'un', 'une', 'vos', 'votre', 'vous', 'avez', 'dispo', 'disponible', 'disponibles',
        'catalogue', 'je', 'cherche', 'veux', 'il', 'reste', 'en', 'stock', 'svp', 'stp', 'bonjour', 'bonsoir', 'salut', 'slt', 'bjr', 'cest', 'c\'est', 'est', 'ce', 'que',
        'quoi', 'pour', 'a', 'et', 'ou', 'avec', 'moi', 'taille', 'couleur', 'encore', 'toujours', 'merci', 'ya', 'y', 'avoir', 'on', 'peut',
        'do', 'you', 'have', 'has', 'any', 'how', 'much', 'price', 'please', 'pls', 'plz', 'the', 'an', 'i', 'want', 'need', 'una', 'get', 'wetin', 'abeg',
        'is', 'are', 'for', 'size', 'colour', 'color', 'available', 'in', 'hello', 'hi', 'good', 'morning', 'evening', 'me', 'my', 'dey', 'fit', 'na',
        'buy', 'still', 'u', 'dis', 'this', 'that', 'there', 'what', 'which', 'some', 'of', 'it'];

    /** Mots anglais et variantes courantes ramenés au vocabulaire des fiches (rédigées en français). */
    private const SYNONYMES = ['dress' => 'robe', 'dresses' => 'robe', 'shirt' => 't-shirt', 'tshirt' => 't-shirt', 'teeshirt' => 't-shirt', 'tee' => 't-shirt',
        'shoe' => 'chaussure', 'shoes' => 'chaussure', 'cap' => 'casquette', 'caps' => 'casquette', 'hat' => 'bonnet', 'beanie' => 'bonnet', 'belt' => 'ceinture',
        'bag' => 'sac', 'bags' => 'sac', 'trouser' => 'pantalon', 'trousers' => 'pantalon', 'pants' => 'pantalon', 'skirt' => 'jupe', 'hoodie' => 'sweat',
        'sweater' => 'sweat', 'pullover' => 'sweat', 'glasses' => 'lunettes', 'sunglasses' => 'lunettes', 'watch' => 'montre', 'perfume' => 'parfum',
        'wig' => 'perruque', 'slippers' => 'sandale', 'sandals' => 'sandale', 'polos' => 'polo',
        'red' => 'rouge', 'blue' => 'bleu', 'green' => 'vert', 'yellow' => 'jaune', 'black' => 'noir', 'white' => 'blanc',
        'grey' => 'gris', 'gray' => 'gris', 'pink' => 'rose', 'brown' => 'marron'];

    /**
     * Réponses automatiques par discussion : au plus 6 en 10 minutes. Assez pour un client qui demande article,
     * taille, couleur ; une rafale au-delà (jeu, robot) ne fait pas bloquer le numéro du vendeur.
     */
    private const RAFALE = 6;

    /**
     * Message d'un client : rend true si BAYAM a répondu.
     *
     * @param WC_Product[] $cites produits de la carte, du statut ou de la liste BAYAM à laquelle le client répond
     */
    public static function message(int $vendeur, string $chat, string $texte, ?string $message_id, array $cites = []): bool
    {
        if (!Bayam_WA_Stockage::reglages($vendeur)['mots_cles']) {
            return false;
        }
        $mots = self::recherche($texte);
        if ($mots === null && !$cites) {
            return false; // conversation ordinaire : le vendeur répond lui-même
        }
        if (!self::rafale_permise($vendeur, $chat)) {
            return false;
        }
        $en = self::anglais($texte);
        $boutique = Bayam_WA_Outils::nom_boutique($vendeur);
        if ($mots === '' && !$cites) {
            return Bayam_WA_Regles::repondre($vendeur, $chat, $en
                ? "Hello 👋 I'm the BAYAM assistant of *{$boutique}*. Type # followed by the item you want, for example *#dress* or *bayam red dress*: I'll show you what is in stock, with prices and photos."
                : "Bonjour 👋 Je suis l'assistant BAYAM de *{$boutique}*. Écrivez # suivi de l'article recherché, par exemple *#robe* ou *bayam robe rouge* : je vous montre ce qui est en stock, avec les prix et les photos.", $message_id);
        }
        $produits = $mots !== null && $mots !== '' ? Bayam_WA_Boutique::chercher($vendeur, $mots, true, 5) : $cites;
        if (!$produits) {
            // Rien en stock : réponse polie avec les mots du client (« shoes », pas leur traduction), le vendeur
            // garde la main pour proposer autre chose.
            $cherche = mb_substr(trim(ltrim(Bayam_WA_Outils::appel($texte) ?? $texte, "# \t")), 0, 40);
            return Bayam_WA_Regles::repondre($vendeur, $chat, $en
                ? "Hello 👋 Thank you for your message. We don't have « {$cherche} » in stock right now at {$boutique}; we will get back to you very soon with other options."
                : "Bonjour 👋 Merci de votre message. Nous n'avons pas « {$cherche} » en stock pour le moment chez {$boutique} ; nous revenons vers vous très vite avec d'autres propositions.", $message_id);
        }
        $blocs = array_map(static fn (WC_Product $p) => self::fiche($vendeur, $p, $texte, $en), $produits);
        return Bayam_WA_Regles::repondre($vendeur, $chat, ($en
                ? "Hello 👋 Here is what *{$boutique}* has for you:\n\n"
                : "Bonjour 👋 Voici ce que nous avons chez *{$boutique}* :\n\n")
            . implode("\n\n", $blocs)
            . ($en
                ? "\n\nTap a link to see the photos and order, or tell us the quantity and the delivery place: the shop will reply."
                : "\n\nTouchez un lien pour voir les photos et commander, ou dites-nous la quantité et le lieu de livraison : la boutique vous répond."), $message_id);
    }

    /** Fiche d'un article : prix, disponibilité (selon la taille ou la couleur demandée), lien. */
    private static function fiche(int $vendeur, WC_Product $p, string $texte, bool $en): string
    {
        $ligne = '*' . $p->get_name() . '* : ' . Bayam_WA_Outils::fcfa((float) $p->get_price());
        return $ligne . "\n" . self::disponibilite($p, $texte, $en) . "\n" . Bayam_WA_Liens::produit($vendeur, $p, 'reponse');
    }

    /**
     * Disponibilité : pour un article à tailles ou couleurs, la déclinaison demandée (« L », « rouge ») si le client
     * en cite une, sinon la liste de ce qui reste ; pour un article simple, en stock ou non.
     */
    private static function disponibilite(WC_Product $p, string $texte, bool $en): string
    {
        if (!$p->is_type('variable')) {
            return $p->is_in_stock() ? ($en ? '✅ In stock' : '✅ En stock') : ($en ? '❌ Sold out for now' : '❌ Épuisé pour le moment');
        }
        $demandes = self::precisions($p, $texte);
        $dispo = [];
        $voulues = [];
        foreach ($p->get_children() as $id) {
            $v = wc_get_product($id);
            if (!$v) {
                continue;
            }
            $libelle = wc_get_formatted_variation($v, true, false, false);
            $valeurs = self::valeurs($v);
            $quantite = $v->managing_stock() ? (int) $v->get_stock_quantity() : null;
            $en_stock = $v->is_in_stock();
            if ($en_stock) {
                $dispo[] = $libelle . ($quantite ? " ($quantite)" : '');
            }
            if ($demandes && !array_diff($demandes, $valeurs)) {
                $voulues[] = [$libelle, $en_stock, $quantite];
            }
        }
        $reste = $dispo ? ($en ? 'Available: ' : 'Disponible en : ') . implode(' · ', $dispo) : ($en ? '❌ Sold out for now' : '❌ Épuisé pour le moment');
        if (!$voulues) {
            return $reste;
        }
        $lignes = [];
        foreach ($voulues as [$libelle, $en_stock, $quantite]) {
            $lignes[] = $en_stock
                ? '✅ ' . $libelle . ($en ? ': available' : ' : disponible') . ($quantite ? " ($quantite)" : '')
                : '❌ ' . $libelle . ($en ? ': sold out for now' : ' : épuisé pour le moment');
        }
        $tout_epuise = !array_filter($voulues, static fn ($x) => $x[1]);
        return implode("\n", $lignes) . ($tout_epuise && $dispo ? "\n" . $reste : '');
    }

    /** Tailles et couleurs citées par le client qui existent pour cet article (« dispo en L ? », « la rouge »). */
    private static function precisions(WC_Product $p, string $texte): array
    {
        $valeurs = [];
        foreach ($p->get_children() as $id) {
            $v = wc_get_product($id);
            foreach ($v ? self::valeurs($v) : [] as $valeur) {
                $valeurs[$valeur] = true;
            }
        }
        $mots = array_map(static fn ($m) => self::SYNONYMES[$m] ?? $m, preg_split('/[^\p{L}\d]+/u', Bayam_WA_Outils::normaliser($texte), -1, PREG_SPLIT_NO_EMPTY));
        return array_values(array_unique(array_filter($mots, static fn ($m) => isset($valeurs[$m]))));
    }

    /**
     * Valeurs d'une déclinaison, normalisées : identifiant (« red ») et nom affiché (« rouge ») des attributs de
     * catalogue, valeur telle quelle des attributs propres au produit.
     */
    private static function valeurs(WC_Product $v): array
    {
        $valeurs = [];
        foreach ($v->get_attributes() as $attribut => $valeur) {
            if ($valeur === '') {
                continue;
            }
            $valeurs[] = Bayam_WA_Outils::normaliser((string) $valeur);
            $terme = taxonomy_exists($attribut) ? get_term_by('slug', $valeur, $attribut) : null;
            if ($terme) {
                $valeurs[] = Bayam_WA_Outils::normaliser($terme->name);
            }
        }
        return array_values(array_unique($valeurs));
    }

    /**
     * Mots recherchés si le message s'adresse à BAYAM (« #robes », « Bayam, vous avez des robes rouges ? »),
     * chaîne vide pour « bayam » seul, null pour un message ordinaire.
     */
    public static function recherche(string $texte): ?string
    {
        $t = Bayam_WA_Outils::normaliser($texte);
        if (preg_match('/^#\s*([\p{L}\d][\p{L}\d \'-]{1,40})$/u', $t, $m)) {
            return self::vocabulaire($m[1]);
        }
        $demande = Bayam_WA_Outils::appel($texte);
        if ($demande === null || mb_strlen($demande) > 120) {
            return null;
        }
        return self::vocabulaire(Bayam_WA_Outils::normaliser($demande));
    }

    /** Mots utiles d'une demande, en vocabulaire des fiches : « do you have red dress » donne « red robe ». */
    private static function vocabulaire(string $texte): string
    {
        $mots = [];
        foreach (preg_split('/[^\p{L}\d-]+/u', $texte, -1, PREG_SPLIT_NO_EMPTY) as $m) {
            $m = self::SYNONYMES[$m] ?? $m;
            if (!in_array($m, self::VIDES, true) && mb_strlen($m) > 1) {
                $mots[] = $m;
            }
        }
        return implode(' ', array_unique($mots));
    }

    /** Le client écrit-il en anglais ou en pidgin ? (réponse dans sa langue) */
    public static function anglais(string $texte): bool
    {
        $t = ' ' . Bayam_WA_Outils::normaliser($texte) . ' ';
        $anglais = preg_match('/\b(do you|have you|you have|how much|price|please|pls|plz|i want|i need|any|available|una|wetin|abeg|hello|hi|good morning|good evening|size)\b/', $t)
            || array_intersect(preg_split('/[^\p{L}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY), array_keys(self::SYNONYMES)); // « #shoes »
        $francais = preg_match('/\b(bonjour|bonsoir|salut|combien|vous|avez|je|prix|svp|stp|dispo|taille|merci)\b/', $t);
        return $anglais && !$francais;
    }

    /**
     * Produits de la carte, du statut ou de la liste BAYAM citée par le client : leurs liens courts (/b/…)
     * appartiennent à ce vendeur. Une citation qui ne vient pas de BAYAM ne donne rien.
     *
     * @return WC_Product[]
     */
    public static function produits_cites(int $vendeur, string $cite): array
    {
        if (!preg_match_all('#/b/([A-Za-z0-9]{4,12})#', $cite, $m)) {
            return [];
        }
        global $wpdb;
        $codes = array_slice(array_unique($m[1]), 0, 5);
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT produit FROM ' . Bayam_WA_Stockage::table('liens') . ' WHERE vendeur = %d AND produit IS NOT NULL AND code IN (' . implode(',', array_fill(0, count($codes), '%s')) . ')',
            $vendeur, ...$codes
        ));
        return array_values(array_filter(array_map('wc_get_product', array_map('intval', $ids)), static fn ($p) => $p && $p->get_status() === 'publish'));
    }

    /** Au plus RAFALE réponses automatiques par discussion en 10 minutes. */
    private static function rafale_permise(int $vendeur, string $chat): bool
    {
        $cle = 'bayam_wa_rafale_' . md5($vendeur . '|' . $chat);
        $nombre = (int) get_transient($cle);
        if ($nombre >= self::RAFALE) {
            return false;
        }
        set_transient($cle, $nombre + 1, 10 * MINUTE_IN_SECONDS);
        return true;
    }

    /** « envoie robes 6 90 00 00 10 » : envoi si le client a déjà écrit au vendeur, sinon lien wa.me prêt à envoyer. */
    public static function envoyer_collection(int $vendeur, string $numero, array $produits): string
    {
        $chat = Bayam_WA_Outils::chat_id($numero);
        $boutique = Bayam_WA_Outils::nom_boutique($vendeur);
        $lignes = [];
        foreach ($produits as $i => $p) {
            $lignes[] = ($i + 1) . '. ' . $p->get_name() . ' : ' . Bayam_WA_Outils::fcfa((float) $p->get_price()) . ' ' . Bayam_WA_Liens::produit($vendeur, $p, 'collection');
        }
        $message = "Bonjour 👋 Voici la sélection de {$boutique} pour vous :\n\n" . implode("\n", $lignes);
        if (self::a_deja_ecrit($vendeur, $chat)) {
            return Bayam_WA_Regles::repondre($vendeur, $chat, $message, null, 'collection')
                ? '📤 Collection envoyée au ' . Bayam_WA_Outils::numero_lisible($numero) . ' (' . count($produits) . ' articles).'
                : "⏳ Plafond d'envois du jour atteint, ou WhatsApp limite temporairement votre numéro : réessayez demain.";
        }
        $lien = 'https://wa.me/' . $numero . '?text=' . rawurlencode($message);
        return "Ce client ne vous a encore jamais écrit : pour protéger votre numéro, BAYAM ne lui écrit pas en premier.\nTouchez ce lien, le message est prêt, il ne reste qu'à l'envoyer :\n{$lien}";
    }

    /** Le client a-t-il déjà écrit à ce vendeur ? (messages reçus enregistrés, puis liste des discussions WAHA) */
    public static function a_deja_ecrit(int $vendeur, string $chat): bool
    {
        global $wpdb;
        $connu = $wpdb->get_var($wpdb->prepare('SELECT 1 FROM ' . Bayam_WA_Stockage::table('wa_messages') . " WHERE vendeur = %d AND (chat = %s OR numero = %s) AND sens = 'in' LIMIT 1", $vendeur, $chat, Bayam_WA_Outils::numero_de($chat)));
        if ($connu) {
            return true;
        }
        $discussions = Bayam_WA_Waha::client()->discussions(Bayam_WA_Stockage::reglages($vendeur)['session'], 300);
        if (is_wp_error($discussions)) {
            return false;
        }
        foreach ($discussions as $d) {
            $id = is_array($d['id'] ?? null) ? (string) ($d['id']['_serialized'] ?? '') : (string) ($d['id'] ?? ($d['JID'] ?? ''));
            if (Bayam_WA_Outils::numero_de($id) === Bayam_WA_Outils::numero_de($chat)) {
                return true;
            }
        }
        return false;
    }
}
