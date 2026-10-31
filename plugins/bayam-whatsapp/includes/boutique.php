<?php
defined('ABSPATH') || exit;

/** Données d'une boutique WCFM : produits, variantes, stock, ventes, commandes, bilans, clients, promos. */
final class Bayam_WA_Boutique
{
    /** Produits publiés du vendeur dont le nom correspond aux mots (pluriels et fautes légères tolérés). */
    public static function chercher(int $vendeur, string $mots, bool $en_stock = true, int $limite = 8): array
    {
        $mots = trim(Bayam_WA_Outils::normaliser(ltrim($mots, '#')));
        $tous = self::produits($vendeur);
        if ($mots === '' || in_array($mots, ['tout', 'tous', 'catalogue', 'produits', 'articles'], true)) {
            $choix = $tous;
        } else {
            $racines = array_map([self::class, 'racine'], explode(' ', $mots));
            // Mots retrouvés (nom ou catégorie) pour choisir les articles ; poids pour les classer : un mot du nom
            // compte plus qu'un mot de catégorie, et la couleur ou la taille demandée fait monter les articles qui l'ont.
            $scores = $poids = [];
            foreach ($tous as $i => $p) {
                $index = self::index_mots($p);
                $scores[$i] = $poids[$i] = 0;
                foreach ($racines as $r) {
                    if (self::correspond($r, $index['nom'])) {
                        $scores[$i]++;
                        $poids[$i] += 4;
                    } elseif (self::correspond($r, $index['categorie'])) {
                        $scores[$i]++;
                        $poids[$i] += 2;
                    } elseif (in_array($r, $index['declinaisons'], true)) {
                        $poids[$i] += 1;
                    }
                }
            }
            // Tous les mots d'abord ; sinon les articles qui en ont le plus : « polo rouge », « robe taille L ».
            $meilleur = $scores ? max($scores) : 0;
            $seuil = $meilleur === count($racines) ? $meilleur : max(1, $meilleur);
            $retenus = $meilleur ? array_keys(array_filter($scores, static fn ($s) => $s >= $seuil)) : [];
            usort($retenus, static fn ($a, $b) => [$poids[$b], $a] <=> [$poids[$a], $b]); // à poids égal, le plus récent
            $choix = array_map(static fn ($i) => $tous[$i], $retenus);
        }
        if ($en_stock) {
            $choix = array_filter($choix, static fn (WC_Product $p) => $p->is_in_stock());
        }
        return array_slice(array_values($choix), 0, $limite);
    }

    /** Égalité, préfixe (« rob » pour « robe ») ou une seule faute de frappe sur un mot de 5 lettres ou plus. */
    private static function correspond(string $r, array $mots): bool
    {
        foreach ($mots as $m) {
            if ($m === $r || (strlen($r) >= 3 && str_starts_with($m, $r)) || (strlen($r) >= 5 && levenshtein($m, $r) <= 1)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mots d'un article (nom, catégories, valeurs de ses déclinaisons), gardés dans le cache d'objets (Redis)
     * jusqu'à la prochaine modification de l'article : la recherche ne relit pas chaque déclinaison à chaque message.
     */
    private static function index_mots(WC_Product $p): array
    {
        $cle = 'mots_' . $p->get_id() . '_' . ($p->get_date_modified() ? $p->get_date_modified()->getTimestamp() : 0);
        $index = wp_cache_get($cle, 'bayam_wa');
        if (is_array($index)) {
            return $index;
        }
        $racines = static fn (string $texte) => array_values(array_unique(array_map([self::class, 'racine'], preg_split('/[\s-]+/', Bayam_WA_Outils::normaliser($texte), -1, PREG_SPLIT_NO_EMPTY))));
        $declinaisons = [];
        foreach ($p->is_type('variable') ? $p->get_children() : [] as $id) {
            $v = wc_get_product($id);
            $declinaisons = array_merge($declinaisons, $v ? self::valeurs_declinaison($v) : []);
        }
        $index = [
            'nom' => $racines($p->get_name()),
            'categorie' => $racines(implode(' ', wp_get_post_terms($p->get_id(), 'product_cat', ['fields' => 'names']))),
            'declinaisons' => array_values(array_unique(array_map([self::class, 'racine'], $declinaisons))),
        ];
        wp_cache_set($cle, $index, 'bayam_wa', DAY_IN_SECONDS);
        return $index;
    }

    /**
     * Valeurs d'une déclinaison, normalisées : identifiant (« red ») et nom affiché (« rouge ») des attributs de
     * catalogue, valeur telle quelle des attributs propres au produit.
     */
    public static function valeurs_declinaison(WC_Product $v): array
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

    /** @return WC_Product[] */
    public static function produits(int $vendeur): array
    {
        $ids = get_posts(['post_type' => 'product', 'post_status' => 'publish', 'author' => $vendeur, 'numberposts' => 200, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC']);
        return array_values(array_filter(array_map('wc_get_product', $ids)));
    }

    /** Singulier approximatif : « robes » et « robe », « chapeaux » et « chapeau ». */
    public static function racine(string $mot): string
    {
        return strlen($mot) > 3 ? preg_replace('/(x|s)$/', '', $mot) : $mot;
    }

    /**
     * Produit et variante désignés par un texte libre (« robe rouge M ») : le produit au nom le plus proche,
     * puis la variante dont toutes les valeurs (couleur, taille) figurent dans le texte.
     *
     * @return array{0: ?WC_Product, 1: ?WC_Product_Variation}
     */
    public static function designer(int $vendeur, string $texte): array
    {
        $texte = Bayam_WA_Outils::normaliser($texte);
        $jetons = explode(' ', $texte);
        $meilleur = null;
        $score = 0;
        foreach (self::produits($vendeur) as $p) {
            $nom = explode(' ', Bayam_WA_Outils::normaliser($p->get_name()));
            $communs = 0;
            foreach ($nom as $m) {
                foreach ($jetons as $j) {
                    if (self::racine($m) === self::racine($j) || (strlen($j) >= 5 && levenshtein($m, $j) <= 1)) {
                        $communs++;
                        break;
                    }
                }
            }
            $s = $communs / max(1, count($nom));
            if ($communs > 0 && $s > $score) {
                $meilleur = $p;
                $score = $s;
            }
        }
        if (!$meilleur || !$meilleur->is_type('variable')) {
            return [$meilleur, null];
        }
        foreach ($meilleur->get_children() as $id) {
            $v = wc_get_product($id);
            if (!$v) {
                continue;
            }
            $valeurs = array_filter(array_map(static fn ($x) => Bayam_WA_Outils::normaliser((string) $x), $v->get_attributes()));
            $noms = array_map(static function ($slug) {
                $t = get_term_by('slug', $slug, 'pa_color') ?: get_term_by('slug', $slug, 'pa_size');
                return $t ? Bayam_WA_Outils::normaliser($t->name) : $slug;
            }, $valeurs);
            if ($noms && !array_diff($noms, $jetons)) {
                return [$meilleur, $v];
            }
        }
        return [$meilleur, null];
    }

    /** Quantités par variante (« Rouge, M : 3 »), ou la quantité du produit simple. */
    public static function stock_lignes(WC_Product $p): array
    {
        if (!$p->is_type('variable')) {
            return [self::quantite($p)];
        }
        $lignes = [];
        foreach ($p->get_children() as $id) {
            $v = wc_get_product($id);
            if ($v) {
                $lignes[] = wc_get_formatted_variation($v, true, false, false) . ' : ' . self::quantite($v);
            }
        }
        return $lignes;
    }

    private static function quantite(WC_Product $p): string
    {
        if (!$p->managing_stock()) {
            return $p->is_in_stock() ? 'en stock' : 'épuisé';
        }
        $q = (int) $p->get_stock_quantity();
        return $q > 0 ? ($q . ' en stock') : 'épuisé';
    }

    /** Mouvement de stock (+5 / -2) sur un produit simple ou une variante ; rend la nouvelle quantité. */
    public static function mouvement(WC_Product $cible, int $delta): int
    {
        if (!$cible->managing_stock()) {
            $cible->set_manage_stock(true);
            $cible->set_stock_quantity(0);
            $cible->save();
        }
        $nouveau = wc_update_product_stock($cible, abs($delta), $delta >= 0 ? 'increase' : 'decrease');
        return (int) $nouveau;
    }

    /**
     * Vente enregistrée par le vendeur (« vendu robe rouge M x2 15000 cash ») : commande terminée à son nom,
     * stock décrémenté par WooCommerce, ligne de commission WCFM créée.
     */
    public static function vendre(int $vendeur, WC_Product $p, ?WC_Product $variante, int $quantite, ?int $prix_total, string $mode, string $client = '')
    {
        $article = $variante ?: $p;
        $commande = wc_create_order(['status' => 'pending', 'created_via' => 'bayam-whatsapp']);
        // Prix négocié (« vendu robe x2 20000 ») : il remplace le prix du catalogue pour cette vente.
        $commande->add_product($article, $quantite, $prix_total !== null ? ['subtotal' => $prix_total, 'total' => $prix_total] : []);
        $libelles = ['cash' => 'Espèces', 'momo' => 'Mobile Money', 'om' => 'Orange Money', 'credit' => 'À crédit'];
        $commande->set_payment_method($mode === 'credit' ? 'cod' : 'bacs');
        $commande->set_payment_method_title($libelles[$mode] ?? 'Espèces');
        if ($client !== '') {
            $commande->set_billing_first_name($client);
        }
        $commande->add_order_note('Vente enregistrée depuis le Copilote WhatsApp (' . ($libelles[$mode] ?? 'Espèces') . ').');
        $commande->calculate_totals(false);
        $commande->save();
        // WCFM rattache la commande à la boutique (table des commandes vendeur) au traitement de la commande.
        do_action('woocommerce_checkout_order_processed', $commande->get_id(), [], $commande);
        $commande->update_status($mode === 'credit' ? 'on-hold' : 'completed', 'Vente directe.');
        return $commande;
    }

    /** Commandes de la boutique encore ouvertes, regroupées par étape. */
    public static function commandes_ouvertes(int $vendeur): array
    {
        $etapes = ['pending' => 'En attente de paiement', 'on-hold' => 'En attente', 'processing' => 'À préparer'];
        $groupes = [];
        // Liens de paiement du Copilote (sans article de la boutique, donc absents de la table WCFM).
        $liens = wc_get_orders(['limit' => 30, 'status' => array_keys($etapes), 'meta_key' => '_bayam_vendeur', 'meta_value' => $vendeur, 'return' => 'ids']);
        foreach (array_unique(array_merge(self::ids_commandes($vendeur, 60), $liens)) as $id) {
            $c = wc_get_order($id);
            if ($c && isset($etapes[$c->get_status()])) {
                $groupes[$etapes[$c->get_status()]][] = $c;
            }
        }
        return $groupes;
    }

    /** Identifiants des commandes contenant des articles du vendeur (table WCFM), des plus récentes aux plus anciennes. */
    public static function ids_commandes(int $vendeur, int $limite = 200): array
    {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT order_id FROM {$wpdb->prefix}wcfm_marketplace_orders WHERE vendor_id = %d AND is_trashed = 0 ORDER BY order_id DESC LIMIT %d",
            $vendeur, $limite
        )));
    }

    /** Bilan d'une période : chiffre d'affaires, commandes, encaissé, 3 meilleurs produits, ruptures. */
    public static function bilan(int $vendeur, string $debut): array
    {
        global $wpdb;
        $lignes = $wpdb->get_results($wpdb->prepare(
            "SELECT order_id, product_id, quantity, item_total, order_status FROM {$wpdb->prefix}wcfm_marketplace_orders
             WHERE vendor_id = %d AND is_trashed = 0 AND created >= %s AND order_status NOT IN ('cancelled','failed','refunded')",
            $vendeur, $debut
        ));
        $ca = 0.0;
        $encaisse = 0.0;
        $commandes = [];
        $ventes = [];
        $paye = [];
        foreach ($lignes as $l) {
            $ca += (float) $l->item_total;
            $paye[$l->order_id] ??= ($c = wc_get_order((int) $l->order_id)) && Bayam_WA_Paiements::encaissee($c);
            if ($paye[$l->order_id]) {
                $encaisse += (float) $l->item_total;
            }
            $commandes[$l->order_id] = true;
            $ventes[$l->product_id] = ($ventes[$l->product_id] ?? 0) + (int) $l->quantity;
        }
        // Liens de paiement : commandes sans article (une ligne de frais), donc absentes du registre WCFM.
        $liens = wc_get_orders([
            'limit' => -1, 'meta_key' => '_bayam_vendeur', 'meta_value' => $vendeur,
            'status' => ['pending', 'on-hold', 'processing', 'completed'],
            'date_created' => '>=' . strtotime(get_gmt_from_date($debut) . ' UTC'),
        ]);
        foreach ($liens as $c) {
            $ca += (float) $c->get_total();
            if (Bayam_WA_Paiements::encaissee($c)) {
                $encaisse += (float) $c->get_total();
            }
            $commandes['lien-' . $c->get_id()] = true;
        }
        arsort($ventes);
        $top = [];
        foreach (array_slice($ventes, 0, 3, true) as $id => $q) {
            $p = wc_get_product($id);
            $top[] = ($p ? $p->get_name() : "#$id") . " ($q)";
        }
        $ruptures = array_map(static fn (WC_Product $p) => $p->get_name(), array_filter(self::produits($vendeur), static fn (WC_Product $p) => !$p->is_in_stock()));
        return ['ca' => $ca, 'commandes' => count($commandes), 'encaisse' => $encaisse, 'top' => $top, 'ruptures' => array_values($ruptures)];
    }

    /** Produit créé depuis le Copilote (« [photo] robe rouge 15000 M:3 L:2 »). */
    public static function creer_produit(int $vendeur, string $nom, int $prix, array $tailles, int $stock_simple, ?int $image_id)
    {
        // Casse d'une phrase, comme le reste du catalogue (« Robe rouge ») ; un nom tapé tout en capitales est adouci.
        $nom = trim($nom);
        $nom = mb_strtoupper($nom, 'UTF-8') === $nom ? mb_strtolower($nom, 'UTF-8') : $nom;
        $nom = mb_strtoupper(mb_substr($nom, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($nom, 1, null, 'UTF-8');
        if ($tailles) {
            $p = new WC_Product_Variable();
            $attribut = new WC_Product_Attribute();
            $attribut->set_name('Taille');
            $attribut->set_options(array_keys($tailles));
            $attribut->set_visible(true);
            $attribut->set_variation(true);
            $p->set_attributes([$attribut]);
        } else {
            $p = new WC_Product_Simple();
            $p->set_regular_price((string) $prix);
            $p->set_manage_stock(true);
            $p->set_stock_quantity($stock_simple);
        }
        $p->set_name($nom);
        $p->set_status('publish');
        if ($image_id) {
            $p->set_image_id($image_id);
        }
        $id = $p->save();
        wp_update_post(['ID' => $id, 'post_author' => $vendeur]);
        foreach ($tailles as $taille => $quantite) {
            $v = new WC_Product_Variation();
            $v->set_parent_id($id);
            $v->set_attributes(['taille' => $taille]);
            $v->set_regular_price((string) $prix);
            $v->set_manage_stock(true);
            $v->set_stock_quantity($quantite);
            $v->save();
        }
        if ($tailles) {
            WC_Product_Variable::sync($id);
        }
        return wc_get_product($id);
    }

    /** Photo reçue sur WhatsApp ajoutée à la médiathèque du vendeur. */
    public static function importer_image(int $vendeur, string $binaire, string $nom): ?int
    {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $fichier = wp_upload_bits(sanitize_file_name($nom) . '-' . wp_generate_password(6, false) . '.jpg', null, $binaire);
        if (!empty($fichier['error'])) {
            return null;
        }
        $id = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => $nom, 'post_author' => $vendeur, 'post_status' => 'inherit'], $fichier['file']);
        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $fichier['file']));
        return $id ?: null;
    }

    /** Achats d'un client (par son numéro) dans cette boutique. */
    public static function client(int $vendeur, string $numero): array
    {
        $local = substr($numero, -9);
        $achats = [];
        $total = 0.0;
        foreach (self::ids_commandes($vendeur, 500) as $id) {
            $c = wc_get_order($id);
            if ($c && str_ends_with(preg_replace('/\D/', '', $c->get_billing_phone()), $local)) {
                $achats[] = $c;
                $total += (float) $c->get_total();
            }
        }
        return ['commandes' => $achats, 'total' => $total];
    }

    /** Code promo de la boutique (« promo NOEL10 10% » ou « promo AMIE 2000 »), limité à ses produits. */
    public static function creer_promo(int $vendeur, string $code, float $valeur, bool $pourcentage)
    {
        $code = strtoupper(sanitize_text_field($code));
        $existant = wc_get_coupon_id_by_code($code);
        if ($existant && get_post_type($existant) !== 'shop_coupon') {
            // Identifiant périmé (coupon supprimé sans passer par WooCommerce) : cache des codes vidé, puis relu.
            WC_Cache_Helper::invalidate_cache_group('coupons');
            $existant = wc_get_coupon_id_by_code($code);
        }
        if ($existant && get_post_status($existant) !== 'trash') {
            return new WP_Error('existe', "Le code $code existe déjà.");
        }
        $coupon = new WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_discount_type($pourcentage ? 'percent' : 'fixed_cart');
        $coupon->set_amount($valeur);
        $coupon->set_product_ids(array_map(static fn (WC_Product $p) => $p->get_id(), self::produits($vendeur)));
        $coupon->set_date_expires(strtotime('+30 days'));
        $id = $coupon->save();
        wp_update_post(['ID' => $id, 'post_author' => $vendeur]);
        return $coupon;
    }
}
