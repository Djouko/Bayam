<?php
/**
 * Lien avec les microservices BAYAM (canal WhatsApp) pendant la migration hors de WordPress :
 * - API interne de lecture du catalogue et des commandes d'une boutique ;
 * - liens personnels de commande envoyés sur WhatsApp ou Messenger (/commander/<jeton>) : panier prêt, numéro du
 *   client rempli quand on le connaît ;
 * - confirmation de la commande au client, dans sa discussion WhatsApp ou Messenger, déposée auprès du canal.
 * Réservé au réseau interne : jeton de service exigé (BAYAM_SERVICE_JETON) ; le répartiteur d'entrée refuse
 * ces adresses depuis l'extérieur.
 */

defined('ABSPATH') || exit;

final class Bayam_WA_Interne
{
    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('template_redirect', [self::class, 'lien_commande'], 1);
        add_action('woocommerce_store_api_checkout_update_order_meta', [self::class, 'commande_depuis_lien']);
        add_action('woocommerce_checkout_create_order', [self::class, 'commande_depuis_lien']);
        // Catalogue modifié : le cache du canal repart de zéro pour cette boutique.
        foreach (['woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status', 'before_delete_post', 'wp_trash_post'] as $crochet) {
            add_action($crochet, [self::class, 'catalogue_modifie']);
        }
    }

    public static function routes(): void
    {
        $lecture = ['methods' => 'GET', 'permission_callback' => [self::class, 'autorise']];
        register_rest_route('bayam/v1', '/interne/boutiques/(?P<id>\d+)', $lecture + ['callback' => [self::class, 'boutique']]);
        register_rest_route('bayam/v1', '/interne/boutiques/(?P<id>\d+)/rayons/(?P<rayon>\d+)', $lecture + ['callback' => [self::class, 'rayon']]);
        register_rest_route('bayam/v1', '/interne/boutiques/(?P<id>\d+)/produits/(?P<produit>\d+)', $lecture + ['callback' => [self::class, 'produit']]);
        register_rest_route('bayam/v1', '/interne/boutiques/(?P<id>\d+)/commandes', $lecture + ['callback' => [self::class, 'commandes']]);
        // Menu du vendeur (canal WhatsApp) : ses articles, épuisés compris, et l'exécution d'une commande du Copilote.
        register_rest_route('bayam/v1', '/interne/boutiques/(?P<id>\d+)/articles', $lecture + ['callback' => [self::class, 'articles']]);
        register_rest_route('bayam/v1', '/interne/boutiques/(?P<id>\d+)/copilote', ['methods' => 'POST', 'permission_callback' => [self::class, 'autorise'], 'callback' => [self::class, 'copilote']]);
    }

    public static function autorise(WP_REST_Request $requete): bool
    {
        $attendu = (string) getenv('BAYAM_SERVICE_JETON');
        return $attendu !== '' && hash_equals($attendu, (string) $requete->get_header('x-bayam-service'));
    }

    // --- Lecture du catalogue ---------------------------------------------------------------------------------

    public static function boutique(WP_REST_Request $r)
    {
        $id = self::vendeur($r);
        if (!$id) {
            return new WP_REST_Response(['erreur' => 'boutique inconnue'], 404);
        }
        $chat = Bayam_WA_Regles::chat_vendeur($id);
        return [
            'id' => $id,
            'nom' => html_entity_decode(Bayam_WA_Outils::nom_boutique($id), ENT_QUOTES, 'UTF-8'),
            'actif' => (bool) Bayam_WA_Stockage::reglages($id)['mots_cles'],
            'chat_vendeur' => $chat,
            'identifiants' => array_values(array_unique(array_filter([$chat, (string) get_user_meta($id, 'bayam_wa_moi', true), (string) get_user_meta($id, 'bayam_wa_lid', true)]))),
            'base_publique' => untrailingslashit(Bayam_WA_Liens::base()),
            'rayons' => self::rayons($id),
            'promos' => count(self::promos($id)),
            'livraison' => Bayam_WA_Boutique::modes_livraison(),
            'paiements' => Bayam_WA_Boutique::moyens_paiement(),
            'conditions' => Bayam_WA_Boutique::conditions_paiement($id),
        ];
    }

    public static function rayon(WP_REST_Request $r)
    {
        $id = self::vendeur($r);
        if (!$id) {
            return new WP_REST_Response(['erreur' => 'boutique inconnue'], 404);
        }
        // Rayon 0 : les articles en promotion de la boutique.
        $filtre = (int) $r['rayon'] === 0
            ? ['post__in' => self::promos($id) ?: [0]]
            : ['tax_query' => [['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => (int) $r['rayon'], 'include_children' => false]]];
        $q = new WP_Query($filtre + [
            'post_type' => 'product', 'post_status' => 'publish', 'author' => $id, 'fields' => 'ids',
            'posts_per_page' => min(10, max(1, (int) ($r['par'] ?? 4))), 'paged' => max(1, (int) ($r['page'] ?? 1)),
            'orderby' => 'date', 'order' => 'DESC',
            'meta_query' => [['key' => '_stock_status', 'value' => 'instock']],
        ]);
        $produits = array_filter(array_map('wc_get_product', $q->posts));
        return ['total' => (int) $q->found_posts, 'produits' => array_values(array_map(static fn (WC_Product $p) => self::fiche($id, $p), $produits))];
    }

    public static function produit(WP_REST_Request $r)
    {
        $id = self::vendeur($r);
        $p = $id ? wc_get_product((int) $r['produit']) : null;
        if (!$p || $p->get_status() !== 'publish' || (int) get_post_field('post_author', $p->get_id()) !== $id) {
            return new WP_REST_Response(['erreur' => 'article inconnu'], 404);
        }
        return self::fiche($id, $p);
    }

    public static function commandes(WP_REST_Request $r)
    {
        $id = self::vendeur($r);
        $numero = Bayam_WA_Outils::numero((string) ($r['numero'] ?? ''));
        if (!$id || !$numero) {
            return new WP_REST_Response(['erreur' => 'boutique ou numéro inconnu'], 404);
        }
        $liste = Bayam_WA_Boutique::client($id, $numero)['commandes'];
        usort($liste, static fn (WC_Order $a, WC_Order $b) => $b->get_date_created() <=> $a->get_date_created());
        return array_map(static fn (WC_Order $c) => [
            'numero' => (string) $c->get_order_number(),
            'total' => (float) $c->get_total(),
            'statut' => wc_get_order_status_name($c->get_status()),
            'date' => $c->get_date_created() ? $c->get_date_created()->date_i18n('d/m/Y') : '',
        ], array_slice($liste, 0, 5));
    }

    private static function vendeur(WP_REST_Request $r): ?int
    {
        $id = (int) $r['id'];
        return $id && function_exists('wcfm_is_vendor') && wcfm_is_vendor($id) ? $id : null;
    }

    /** Rayons où la boutique a des articles en vente, les plus fournis d'abord ; les articles sans rayon à la fin. */
    private static function rayons(int $vendeur): array
    {
        global $wpdb;
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_stock_status' AND s.meta_value = 'instock'
             WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.post_author = %d",
            $vendeur
        )));
        if (!$ids) {
            return [];
        }
        $defaut = (int) get_option('default_product_cat');
        $rayons = [];
        foreach (wp_get_object_terms($ids, 'product_cat', ['fields' => 'all_with_object_id']) as $t) {
            $rayons[$t->term_id] ??= ['id' => (int) $t->term_id, 'nom' => (int) $t->term_id === $defaut ? 'Autres articles' : html_entity_decode($t->name, ENT_QUOTES, 'UTF-8'), 'nombre' => 0];
            $rayons[$t->term_id]['nombre']++;
        }
        usort($rayons, static fn ($a, $b) => [$a['id'] === $defaut, -$a['nombre'], $a['nom']] <=> [$b['id'] === $defaut, -$b['nombre'], $b['nom']]);
        return array_values($rayons);
    }

    /** Articles en promotion de la boutique (en vente et en stock). */
    private static function promos(int $vendeur): array
    {
        $ids = array_map('intval', wc_get_product_ids_on_sale());
        if (!$ids) {
            return [];
        }
        return get_posts(['post_type' => 'product', 'post_status' => 'publish', 'author' => $vendeur, 'fields' => 'ids', 'numberposts' => 100,
            'post__in' => $ids, 'meta_query' => [['key' => '_stock_status', 'value' => 'instock']]]);
    }

    /** Pièces en stock quand le stock est suivi (somme des déclinaisons suivies), sinon null. */
    private static function stock(WC_Product $p): ?int
    {
        if (!$p->is_type('variable')) {
            return $p->managing_stock() ? max(0, (int) $p->get_stock_quantity()) : null;
        }
        $total = null;
        foreach ($p->get_children() as $id) {
            $v = wc_get_product($id);
            if ($v && $v->managing_stock()) {
                $total = (int) $total + max(0, (int) $v->get_stock_quantity());
            }
        }
        return $total;
    }

    private static function fiche(int $vendeur, WC_Product $p): array
    {
        $chemin = static function (string $taille) use ($p): ?string {
            $url = $p->get_image_id() ? (string) wp_get_attachment_image_url($p->get_image_id(), $taille) : '';
            return $url !== '' ? (string) wp_parse_url($url, PHP_URL_PATH) : null;
        };
        $variable = $p->is_type('variable');
        return [
            'id' => $p->get_id(),
            'nom' => html_entity_decode($p->get_name(), ENT_QUOTES, 'UTF-8'),
            'prix' => (float) $p->get_price(),
            'prix_regulier' => (float) ($variable ? $p->get_variation_regular_price('min') : $p->get_regular_price()),
            'en_stock' => $p->is_in_stock(),
            'variable' => $variable,
            'stock' => self::stock($p),
            'options' => Bayam_WA_Boutique::options_disponibles($p),
            // Chemin seul : le service choisit l'origine (réseau interne aujourd'hui, stockage d'objets demain).
            // Photo 768 px : assez nette pour la vitrine comme pour la fiche ; le service l'allège avant l'envoi.
            'photo' => $chemin('medium_large'),
            // Autres photos de l'article (trois au plus), envoyées à la suite quand le client le choisit.
            'galerie' => array_values(array_filter(array_map(static function ($image) {
                $url = (string) wp_get_attachment_image_url((int) $image, 'medium_large');
                return $url !== '' ? (string) wp_parse_url($url, PHP_URL_PATH) : null;
            }, array_slice($p->get_gallery_image_ids(), 0, 3)))),
            'lien' => Bayam_WA_Liens::produit($vendeur, $p, 'catalogue'),
            'resume' => html_entity_decode(wp_trim_words(wp_strip_all_tags($p->get_short_description() ?: $p->get_description()), 24, '…'), ENT_QUOTES, 'UTF-8'),
        ];
    }

    /** Version du catalogue de la boutique, incrémentée à chaque modification d'article (clé de cache du canal). */
    public static function catalogue_modifie($objet): void
    {
        $id = $objet instanceof WC_Product ? $objet->get_id() : (int) $objet;
        $type = get_post_type($id);
        if ($type !== 'product' && $type !== 'product_variation') {
            return;
        }
        $parent = (int) wp_get_post_parent_id($id);
        $vendeur = (int) get_post_field('post_author', $parent ?: $id);
        $redis = Bayam_WA_Outils::redis();
        if ($vendeur && $redis) {
            $redis->incr("canal:catalogue:version:{$vendeur}");
        }
    }

    // --- Menu du vendeur --------------------------------------------------------------------------------------

    /**
     * Articles du vendeur pour ses menus (stock, vente, publication) : tous, épuisés compris, avec le stock de chaque
     * variante. « q » filtre par le nom ; pages de « par » articles, par ordre alphabétique.
     */
    public static function articles(WP_REST_Request $r)
    {
        $vendeur = self::vendeur($r);
        if (!$vendeur) {
            return new WP_REST_Response(['erreur' => 'boutique inconnue'], 404);
        }
        $q = trim((string) $r->get_param('q'));
        $produits = $q !== '' ? Bayam_WA_Boutique::chercher($vendeur, $q, false, 20) : Bayam_WA_Boutique::produits($vendeur);
        if ($q === '') {
            usort($produits, static fn (WC_Product $a, WC_Product $b) => strcasecmp(remove_accents($a->get_name()), remove_accents($b->get_name())));
        }
        $par = max(1, min(20, (int) ($r->get_param('par') ?: 9)));
        $page = max(1, (int) ($r->get_param('page') ?: 1));
        $fiche = static function (WC_Product $p): array {
            $variantes = [];
            foreach ($p->is_type('variable') ? $p->get_children() : [] as $id) {
                $v = wc_get_product($id);
                if ($v) {
                    $variantes[] = ['id' => $v->get_id(), 'nom' => wc_get_formatted_variation($v, true, false, false), 'stock' => $v->managing_stock() ? (int) $v->get_stock_quantity() : null];
                }
            }
            return [
                'id' => $p->get_id(), 'nom' => html_entity_decode($p->get_name(), ENT_QUOTES, 'UTF-8'), 'prix' => (float) $p->get_price(),
                'stock' => $p->is_type('variable') ? null : ($p->managing_stock() ? (int) $p->get_stock_quantity() : null),
                'en_stock' => $p->is_in_stock(), 'variable' => $p->is_type('variable'), 'variantes' => $variantes,
            ];
        };
        return ['total' => count($produits), 'articles' => array_map($fiche, array_slice($produits, ($page - 1) * $par, $par))];
    }

    /**
     * Commande du Copilote choisie dans le menu (« +5 #25 M », « vendu #25 x2 momo », « bilan hier ») : exécutée
     * comme si le vendeur l'avait tapée, réponses envoyées dans sa discussion « Message à moi-même ». Photo d'un nouvel
     * article : adresse du média chez WAHA, téléchargée ici.
     */
    public static function copilote(WP_REST_Request $r)
    {
        $vendeur = self::vendeur($r);
        $texte = trim((string) $r->get_param('texte'));
        if (!$vendeur || $texte === '' || mb_strlen($texte) > 500) {
            return new WP_REST_Response(['erreur' => 'demande invalide'], 400);
        }
        $photo = null;
        if (($url = (string) $r->get_param('photo')) !== '') {
            $binaire = Bayam_WA_Waha::client()->telecharger($url);
            if (is_wp_error($binaire)) {
                return new WP_REST_Response(['erreur' => 'photo introuvable'], 422);
            }
            $photo = ['binaire' => $binaire];
        }
        $reponses = Bayam_WA_Copilote::traiter($vendeur, $texte, $photo);
        foreach ($reponses as $reponse) {
            isset($reponse['image'])
                ? Bayam_WA_Regles::image_au_vendeur($vendeur, $reponse['image'], $reponse['legende'] ?? '')
                : Bayam_WA_Regles::au_vendeur($vendeur, $reponse['texte']);
        }
        return ['envoyes' => count($reponses)];
    }

    // --- Lien personnel de commande ----------------------------------------------------------------------------

    /** /commander/<jeton> : l'article au panier, le numéro WhatsApp du client rempli, puis la page de commande. */
    public static function lien_commande(): void
    {
        $chemin = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (!preg_match('#^/commander/([A-Za-z0-9_-]{24})/?$#', $chemin, $m) || !function_exists('WC') || !WC()->session) {
            return;
        }
        $j = self::jeton($m[1]);
        $p = $j ? wc_get_product((int) $j['produit']) : null;
        nocache_headers();
        if (!$p || (int) get_post_field('post_author', $p->get_id()) !== (int) $j['vendeur'] || !$p->is_purchasable() || !$p->is_in_stock()) {
            wc_add_notice(__('Ce lien de commande a expiré ou l\'article n\'est plus disponible. Demandez un nouveau lien à la boutique, sur WhatsApp ou Messenger.', 'bayam-whatsapp'), 'notice');
            wp_safe_redirect($p ? get_permalink($p->get_id()) : wc_get_page_permalink('shop'));
            exit;
        }
        if (!WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }
        if ($j['numero'] !== '') {
            WC()->customer->set_billing_phone('+' . $j['numero']);
            WC()->customer->save();
        }
        WC()->session->set('bayam_jeton', ['jeton' => $m[1], 'vendeur' => (int) $j['vendeur'], 'chat' => (string) $j['chat'], 'canal' => (string) ($j['canal'] ?? 'whatsapp'), 'page' => (string) ($j['page'] ?? '')]);
        if (!$p->is_type('simple')) {
            wp_safe_redirect(get_permalink($p->get_id())); // taille ou couleur à choisir sur la fiche
            exit;
        }
        $deja = false;
        foreach (WC()->cart->get_cart() as $ligne) {
            $deja = $deja || (int) $ligne['product_id'] === $p->get_id();
        }
        if (!$deja) {
            WC()->cart->add_to_cart($p->get_id(), 1);
        }
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    private static function jeton(string $jeton): ?array
    {
        $r = wp_remote_get(self::canal() . '/interne/jetons/' . rawurlencode($jeton), ['timeout' => 5, 'headers' => ['x-bayam-service' => (string) getenv('BAYAM_SERVICE_JETON')]]);
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) {
            return Bayam_WA_Facebook::jeton($jeton); // lien envoyé dans Messenger
        }
        $d = json_decode(wp_remote_retrieve_body($r), true);
        return is_array($d) && isset($d['produit'], $d['vendeur'], $d['chat'], $d['numero']) ? $d : null;
    }

    /** Commande passée depuis un lien personnel : on garde la discussion du client (WhatsApp ou Messenger) pour lui confirmer. */
    public static function commande_depuis_lien($commande): void
    {
        if (!$commande instanceof WC_Order || !function_exists('WC') || !WC()->session) {
            return;
        }
        $j = WC()->session->get('bayam_jeton');
        if (!is_array($j)) {
            return;
        }
        $commande->update_meta_data('_bayam_jeton', $j['jeton']);
        $commande->update_meta_data('_bayam_chat_client', $j['chat']);
        $commande->update_meta_data('_bayam_vendeur_lien', (int) $j['vendeur']);
        $commande->update_meta_data('_bayam_canal', ($j['canal'] ?? 'whatsapp') === 'facebook' ? 'facebook' : 'whatsapp');
        if (!empty($j['page'])) {
            $commande->update_meta_data('_bayam_page', (string) $j['page']);
        }
        if ($commande->get_id()) {
            $commande->save_meta_data();
        }
        WC()->session->set('bayam_jeton', null);
    }

    /**
     * Confirmation au client dans sa discussion WhatsApp ou Messenger, déposée auprès du canal (envoi durable).
     * Ne ralentit jamais la validation de la commande : dépôt seulement, l'envoi se fait à côté.
     */
    public static function confirmer_au_client(WC_Order $commande): void
    {
        $chat = (string) $commande->get_meta('_bayam_chat_client');
        $vendeur = (int) $commande->get_meta('_bayam_vendeur_lien');
        $messenger = $commande->get_meta('_bayam_canal') === 'facebook';
        if ($chat === '' || !$vendeur || $commande->get_meta('_bayam_confirmation_client') || (!$messenger && get_user_meta($vendeur, 'bayam_wa_statut', true) !== 'WORKING')) {
            return;
        }
        $articles = [];
        foreach ($commande->get_items() as $item) {
            $articles[] = $item->get_quantity() . ' × ' . $item->get_name();
        }
        $adresse = trim($commande->get_shipping_address_1() . ', ' . $commande->get_shipping_city(), ' ,');
        $texte = '✅ *Commande n° ' . $commande->get_order_number() . ' reçue* chez *' . html_entity_decode(Bayam_WA_Outils::nom_boutique($vendeur), ENT_QUOTES, 'UTF-8') . "*\n"
            . implode("\n", $articles) . "\n"
            . 'Total : *' . Bayam_WA_Outils::fcfa((float) $commande->get_total()) . '* (' . $commande->get_payment_method_title() . ')'
            . ($adresse !== '' ? "\nLivraison : {$adresse}" : '')
            // Payée en ligne : rien à envoyer ; sinon les conditions de la boutique (acompte, avance, à la livraison).
            . "\n\n" . ($commande->is_paid() ? 'Paiement reçu, merci.' : Bayam_WA_Boutique::conditions_paiement($vendeur, (float) $commande->get_total()))
            . "\nLa boutique vous écrit ici pour la livraison. Merci pour votre confiance 🙏";
        if ($messenger) {
            if (Bayam_WA_Facebook::confirmer('commande-' . $commande->get_id(), (string) $commande->get_meta('_bayam_page'), $chat, $texte)) {
                $commande->update_meta_data('_bayam_confirmation_client', 1);
                $commande->save_meta_data();
            }
            return;
        }
        $r = wp_remote_post(self::canal() . '/interne/notifications', [
            'timeout' => 3,
            'headers' => ['x-bayam-service' => (string) getenv('BAYAM_SERVICE_JETON'), 'content-type' => 'application/json'],
            'body' => wp_json_encode(['cle' => 'commande-' . $commande->get_id(), 'session' => Bayam_WA_Stockage::reglages($vendeur)['session'], 'chat' => $chat, 'texte' => $texte]),
        ]);
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) >= 300) {
            Bayam_WA_Outils::journal('Confirmation client non déposée', ['commande' => $commande->get_id(), 'erreur' => is_wp_error($r) ? $r->get_error_message() : wp_remote_retrieve_response_code($r)]);
            return;
        }
        $commande->update_meta_data('_bayam_confirmation_client', 1);
        $commande->save_meta_data();
    }

    private static function canal(): string
    {
        return rtrim((string) (getenv('BAYAM_CANAL_URL') ?: 'http://canal-whatsapp:8091'), '/');
    }
}
