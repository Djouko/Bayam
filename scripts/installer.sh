#!/usr/bin/env bash
# Installation complète de la plateforme locale (WordPress + WooCommerce + WCFM + MStore API), sans intervention.
# Lancé par : docker compose run --rm cli bash /scripts/installer.sh   (peut être relancé : chaque étape vérifie l'existant)
set -euo pipefail
cd /var/www/html

echo "== 1. WordPress"
if ! wp core is-installed 2>/dev/null; then
  wp core install --url="$WP_URL" --title="BAYAM Démo" --admin_user="$WP_ADMIN" \
    --admin_password="$WP_ADMIN_PASSWORD" --admin_email="admin@bayam.local" --skip-email
fi
for essai in 1 2 3 4 5; do wp language core install fr_FR --activate && break || sleep 5; done
wp option update timezone_string "Africa/Douala"
wp option update date_format "d/m/Y"
wp rewrite structure '/%postname%/' --hard

echo "== 2. Extensions (versions figées, compatibles FluxStore 6.3.0)"
# Le réseau peut couper : chaque téléchargement est retenté jusqu'à cinq fois.
installer() {
  for essai in 1 2 3 4 5; do
    wp plugin is-installed "$1" && break
    wp plugin install "$1" --version="$2" || sleep 5
  done
  wp plugin activate "$1"
}
installer woocommerce 11.2.1
installer mstore-api 4.21.3  # 4.21.0 (citée par FluxStore 6.3.0) n’est pas publiée ; 4.21.3 est le correctif de la même branche
installer wc-frontend-manager 6.8.1   # requis par la place de marché WCFM
installer wc-multivendor-marketplace 3.8.3
installer wc-multivendor-membership 2.12.0
installer wordpress-importer 0.9.6
# Extension BAYAM WhatsApp (code dans plugins/bayam-whatsapp, monté dans le conteneur) : Copilote, mots-clés, cartes.
wp plugin activate bayam-whatsapp
# Paiement Mobile Money par CinetPay (plugins/bayam-cinetpay, monté) ; identifiants dans .env.
wp plugin activate bayam-cinetpay
# Cartes bancaires : extension officielle Stripe ; clés dans .env (STRIPE_*), lues par le mu-plugin bayam-commerce.
installer woocommerce-gateway-stripe 11.1.1
# Cache d'objets persistant dans Redis (service redis) : moins de requêtes à la base à chaque page.
installer redis-cache 3.0.0
wp redis enable || true   # déjà activé : la commande le signale sans rien changer
for ext in woocommerce wc-multivendor-marketplace wc-frontend-manager wc-multivendor-membership woocommerce-gateway-stripe; do
  wp language plugin install "$ext" fr_FR || true   # certaines extensions n'ont pas de traduction publiée
done

# Thème classique officiel de WooCommerce : l’espace vendeur WCFM ne s’affiche pas avec le thème par blocs par défaut
# (menus en texte brut dans l’application). Le site public BAYAM aura son propre habillage (étape web, plus tard).
for essai in 1 2 3 4 5; do wp theme is-installed storefront && break; wp theme install storefront --version=4.6.1 || sleep 5; done
wp theme activate storefront

echo "== 3. Boutique réglée pour le Cameroun"
wp option update woocommerce_default_country "CM"
wp option update woocommerce_store_city "Douala"
wp option update woocommerce_store_address "Akwa"
wp option update woocommerce_currency "XAF"
wp option update woocommerce_currency_pos "right_space"
wp option update woocommerce_price_thousand_sep " "
wp option update woocommerce_price_decimal_sep ","
wp option update woocommerce_price_num_decimals 0
wp option update woocommerce_allowed_countries "specific"
wp option update woocommerce_specific_allowed_countries '["CM"]' --format=json
wp option update woocommerce_ship_to_countries ""
wp option update woocommerce_calc_taxes "no"
wp option update woocommerce_enable_guest_checkout "yes"
# Inscription ouverte (clients et vendeurs depuis l'application) : WordPress la ferme par défaut (« Registration is not enabled »).
wp option update users_can_register 1
wp option update default_role "customer"
wp option update woocommerce_enable_myaccount_registration "yes"
wp option update woocommerce_coming_soon "no"
wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json
wp option update woocommerce_cod_settings '{"enabled":"yes","title":"Paiement à la livraison","description":"Vous payez en espèces ou par Mobile Money à la livraison.","instructions":"","enable_for_methods":[],"enable_for_virtual":"yes"}' --format=json
wp option update woocommerce_bacs_settings '{"enabled":"no"}' --format=json

# Zone de livraison « Cameroun » : forfait en ville et retrait en boutique (API PHP de WooCommerce, wp-cli ne sait pas créer de zone).
wp eval '
  WC()->shipping()->load_shipping_methods();   // sans cela, en ligne de commande, aucun mode de livraison n’est connu
  foreach (WC_Shipping_Zones::get_zones() as $z) {
    if ($z["zone_name"] !== "Cameroun") { continue; }
    if ($z["shipping_methods"]) { return; }
    WC_Shipping_Zones::delete_zone($z["id"]);   // zone vide laissée par une installation interrompue
  }
  $zone = new WC_Shipping_Zone();
  $zone->set_zone_name("Cameroun");
  $zone->add_location("CM", "country");
  $zone->save();
  $forfait = $zone->add_shipping_method("flat_rate");
  update_option("woocommerce_flat_rate_{$forfait}_settings", ["title" => "Livraison en ville", "cost" => "1000", "tax_status" => "none"]);
  $retrait = $zone->add_shipping_method("local_pickup");
  update_option("woocommerce_local_pickup_{$retrait}_settings", ["title" => "Retrait en boutique", "cost" => "", "tax_status" => "none"]);
  echo "Zone Cameroun créée
";'

echo "== 4. Produits de démonstration (jeu officiel WooCommerce, prix convertis en FCFA)"
if [ "$(wp post list --post_type=product --format=count)" -eq 0 ]; then
  wp import wp-content/plugins/woocommerce/sample-data/sample_products.xml --authors=create --quiet
  # Prix d'exemple en dollars convertis en FCFA (1 USD = 600 FCFA environ), arrondis à 50 FCFA.
  wp eval '
    foreach (wc_get_products(["limit" => -1, "type" => ["simple", "variable", "grouped", "external"]]) as $p) {
      foreach ($p->is_type("variable") ? $p->get_children() : [$p->get_id()] as $id) {
        $v = wc_get_product($id);
        foreach (["regular", "sale"] as $k) {
          $prix = $v->{"get_{$k}_price"}("edit");
          if ($prix !== "") { $v->{"set_{$k}_price"}((string) (round($prix * 600 / 50) * 50)); }
        }
        $v->save();
      }
      // Le prix affiché des produits variables et groupés se recalcule à partir de leurs déclinaisons.
      if ($p->is_type("variable")) { WC_Product_Variable::sync($p->get_id()); }
      if ($p->is_type("grouped")) { $p->get_data_store()->sync_price($p); }
    }
    wc_delete_product_transients();'
fi

echo "== 4 bis. Vendeurs de la place de marché (comme une inscription WCFM : rôle, profil de boutique, livraison par zone)"
wp eval '
  $vendeurs = [
    ["aicha", "Aïcha", "Mode Aïcha", "Akwa", "+237 690 00 00 01", ["clothing", "tshirts", "hoodies"], 4.0469, 9.6948],
    ["akwa", "Paul", "Accessoires Akwa", "Akwa Nord", "+237 690 00 00 02", ["accessories"], 4.0610, 9.7010],
    ["bonamoussadi", "Grace", "Musique Bonamoussadi", "Bonamoussadi", "+237 690 00 00 03", ["music", "decor"], 4.0927, 9.7432],
  ];
  foreach ($vendeurs as [$login, $prenom, $boutique, $quartier, $tel, $categories, $lat, $lng]) {
    $user = get_user_by("login", $login);
    if (!$user) {
      $id = wp_insert_user(["user_login" => $login, "user_pass" => getenv("WP_VENDOR_PASSWORD"), "user_email" => "$login@bayam.local",
                            "first_name" => $prenom, "display_name" => $boutique, "role" => "wcfm_vendor"]);
      if (is_wp_error($id)) { WP_CLI::error($id->get_error_message()); }
      $user = get_user_by("id", $id);
    }
    $adresse = ["street_1" => $quartier, "city" => "Douala", "country" => "CM", "state" => "", "zip" => ""];
    update_user_meta($user->ID, "wcfmmp_profile_settings", [
      "store_name" => $boutique, "social" => [], "payment" => [], "phone" => $tel, "show_email" => "no",
      "location" => "", "find_address" => "", "banner" => 0, "address" => $adresse,
      "store_location" => "$quartier, Douala", "store_lat" => $lat, "store_lng" => $lng,
      "customer_support" => ["email" => $user->user_email, "phone" => $tel, "address1" => $quartier, "city" => "Douala", "country" => "CM", "state" => ""]]);
    update_user_meta($user->ID, "wcfmmp_store_name", $boutique);
    // Position de la boutique pour la carte des boutiques (application et site).
    update_user_meta($user->ID, "_wcfm_store_location", "$quartier, Douala");
    update_user_meta($user->ID, "_wcfm_store_lat", $lat);
    update_user_meta($user->ID, "_wcfm_store_lng", $lng);
    update_user_meta($user->ID, "_wcfmmp_shipping", ["_wcfmmp_user_shipping_enable" => "yes", "_wcfmmp_user_shipping_type" => "by_zone"]);
    foreach (wc_get_products(["limit" => -1, "category" => $categories, "return" => "ids"]) as $pid) {
      wp_update_post(["ID" => $pid, "post_author" => $user->ID]);
    }
    WP_CLI::log("Vendeur $boutique ($login) prêt");
  }'

echo "== 4 bis bis. Client de démonstration (même mot de passe que les vendeurs, WP_VENDOR_PASSWORD)"
wp eval '
  $user = get_user_by("login", "brice");
  if (!$user) {
    $id = wp_insert_user(["user_login" => "brice", "user_pass" => getenv("WP_VENDOR_PASSWORD"), "user_email" => "brice@bayam.local",
                          "first_name" => "Brice", "last_name" => "Essomba", "display_name" => "Brice Essomba", "role" => "customer"]);
    if (is_wp_error($id)) { WP_CLI::error($id->get_error_message()); }
    $user = get_user_by("id", $id);
  }
  $client = new WC_Customer($user->ID);
  foreach (["billing", "shipping"] as $type) {
    $client->{"set_{$type}_first_name"}("Brice");
    $client->{"set_{$type}_last_name"}("Essomba");
    $client->{"set_{$type}_city"}("Douala");
    $client->{"set_{$type}_address_1"}("Akwa, près de la pharmacie du Château");
    $client->{"set_{$type}_country"}("CM");
  }
  $client->set_billing_phone("690000010");
  $client->set_billing_email("brice@bayam.local");
  $client->save();
  WP_CLI::log("Client brice prêt");'

echo "== 4 quater. Produits et attributs en français"
wp eval '
  $noms = ["T-Shirt with Logo" => "T-shirt logo", "Beanie with Logo" => "Bonnet logo", "Logo Collection" => "Lot logo",
    "WordPress Pennant" => "Fanion du Cameroun", "Cap" => "Casquette", "Sunglasses" => "Lunettes de soleil",
    // Articles de démonstration à l’effigie de WordPress et WooCommerce : remplacés par des articles crédibles (visuels à l’étape 4 quinquies).
    "Fanion décoratif" => "Fanion du Cameroun", "Album" => "Album makossa", "Single" => "Single bikutsi",
    "Hoodie with Pocket" => "Sweat à poche", "Hoodie with Zipper" => "Sweat zippé", "Long Sleeve Tee" => "T-shirt manches longues",
    "V-Neck T-Shirt" => "T-shirt col V", "Hoodie" => "Sweat à capuche", "Hoodie with Logo" => "Sweat logo",
    "T-Shirt" => "T-shirt", "Beanie" => "Bonnet", "Belt" => "Ceinture"];
  foreach (wc_get_products(["limit" => -1]) as $p) {
    if (isset($noms[$p->get_name()])) { $p->set_name($noms[$p->get_name()]); $p->save(); }
  }
  foreach (["color" => "Couleur", "size" => "Taille"] as $slug => $libelle) {
    $id = wc_attribute_taxonomy_id_by_name($slug);
    if ($id) { wc_update_attribute($id, ["name" => $libelle, "slug" => $slug]); }
  }
  $termes = ["pa_color" => ["Blue" => "Bleu", "Gray" => "Gris", "Green" => "Vert", "Red" => "Rouge", "Yellow" => "Jaune"],
             "pa_size" => ["Large" => "L", "Medium" => "M", "Small" => "S"]];
  foreach ($termes as $tax => $table) {
    foreach ($table as $avant => $apres) {
      $t = get_term_by("name", $avant, $tax);
      if ($t) { wp_update_term($t->term_id, $tax, ["name" => $apres]); }
    }
  }
  // Couleur selon les visuels BAYAM redessinés (les données WooCommerce disaient gris et rouge).
  foreach (["T-shirt logo" => "Vert", "Bonnet logo" => "Bleu"] as $nom => $couleur) {
    $ids = get_posts(["post_type" => "product", "title" => $nom, "fields" => "ids", "posts_per_page" => 1]);
    $terme = get_term_by("name", $couleur, "pa_color");
    if (!$ids || !$terme) { continue; }
    $p = wc_get_product($ids[0]);
    $attributs = $p->get_attributes();
    if (!isset($attributs["pa_color"])) { continue; }
    $attribut = clone $attributs["pa_color"];
    $attribut->set_options([$terme->term_id]);
    $attributs["pa_color"] = $attribut;
    $p->set_attributes($attributs);
    $p->save();
    wp_set_object_terms($p->get_id(), [$terme->term_id], "pa_color");
  }
  wc_delete_product_transients();'

echo "== 4 ter. Pages en français et annuaire des boutiques"
wp eval '
  foreach (["shop" => "Boutique", "cart" => "Panier", "checkout" => "Commande", "my-account" => "Mon compte", "store-manager" => "Espace vendeur"] as $slug => $titre) {
    $page = get_page_by_path($slug);
    if ($page && $page->post_title !== $titre) { wp_update_post(["ID" => $page->ID, "post_title" => $titre]); }
  }
  foreach (["accessories" => "Accessoires", "clothing" => "Vêtements", "decor" => "Décoration", "hoodies" => "Sweats à capuche", "music" => "Musique", "tshirts" => "T-shirts", "uncategorized" => "Non classé"] as $slug => $nom) {
    $cat = get_term_by("slug", $slug, "product_cat");
    if ($cat && $cat->name !== $nom) { wp_update_term($cat->term_id, "product_cat", ["name" => $nom]); }
  }
  if (!get_page_by_path("boutiques")) {
    wp_insert_post(["post_type" => "page", "post_status" => "publish", "post_title" => "Nos boutiques", "post_name" => "boutiques", "post_content" => "[wcfm_stores]"]);
  }'

echo "== 4 sexies. Descriptions en français, produit externe changé en produit vendu sur la plate-forme"
wp eval '
  // nom => [description courte, description longue]
  $textes = [
    "T-shirt logo" => ["T-shirt en coton doux, logo imprimé sur la poitrine.", "Coupe droite et coton épais qui tient au lavage. Se porte seul ou sous une veste, du bureau au week-end."],
    "Bonnet logo" => ["Bonnet en maille chaude, logo brodé.", "Idéal pour les soirées fraîches de l’Ouest ou la climatisation du bureau. Taille unique, maille souple."],
    "Lot logo" => ["Le lot complet : t-shirt, bonnet et sweat logo.", "Trois pièces assorties à un prix réduit. Parfait pour offrir ou pour toute une équipe."],
    "Fanion du Cameroun" => ["Fanion vert, rouge, jaune à l’étoile, sur baguette en bois.", "Tissu épais et couleurs qui ne passent pas au soleil. Pour les matchs des Lions, la boutique ou la voiture."],
    "Casquette" => ["Casquette réglable en coton, visière courbée.", "Protège du soleil de midi. Attache arrière réglable, une taille pour tous."],
    "Lunettes de soleil" => ["Lunettes de soleil légères, verres teintés.", "Monture souple et confortable, étui de protection fourni. Pour la plage de Kribi comme pour la ville."],
    "Sweat à poche" => ["Sweat molletonné avec grande poche avant.", "Intérieur doux et chaud, poignets côtelés. Le compagnon des matinées fraîches et des voyages en car."],
    "Sweat zippé" => ["Sweat à capuche zippé, molleton épais.", "Fermeture éclair solide, deux poches, capuche doublée. Facile à enfiler, facile à ranger."],
    "T-shirt manches longues" => ["T-shirt à manches longues en coton.", "Léger et respirant, il protège les bras du soleil sans tenir chaud."],
    "T-shirt col V" => ["T-shirt col V, plusieurs couleurs et tailles.", "Coton peigné, coupe ajustée. Choisissez votre couleur et votre taille avant d’ajouter au panier."],
    "Sweat à capuche" => ["Sweat à capuche, plusieurs couleurs et tailles.", "Molleton doux et capuche ajustable. Choisissez votre couleur et votre taille avant d’ajouter au panier."],
    "Sweat logo" => ["Sweat à capuche avec logo imprimé.", "Molleton chaud, grande poche kangourou, logo qui ne s’efface pas au lavage."],
    "T-shirt" => ["T-shirt basique en coton, coupe droite.", "La pièce de tous les jours : coton doux, coutures renforcées, couleur qui tient."],
    "Bonnet" => ["Bonnet en maille côtelée.", "Chaud et extensible, il garde sa forme. Taille unique."],
    "Ceinture" => ["Ceinture en cuir, boucle métal.", "Cuir épais et boucle solide. Se recoupe facilement à la bonne longueur."],
    "Album makossa" => ["Compilation makossa à télécharger, une heure de musique.", "Le fichier est disponible dans Mes commandes dès le paiement confirmé."],
    "Single bikutsi" => ["Titre bikutsi à télécharger.", "Le fichier est disponible dans Mes commandes dès le paiement confirmé."],
    "Polo" => ["Polo en piqué de coton, col boutonné.", "Élégant et respirant, pour le travail comme pour les sorties."],
  ];
  foreach (wc_get_products(["limit" => -1]) as $p) {
    if ($p->is_type("external")) {
      // BAYAM vend sur la plate-forme : pas de renvoi vers un site extérieur. Changer de type = recharger le produit
      // avec la classe du nouveau type (sinon l’enregistrement remet le type d’origine).
      $classe = WC_Product_Factory::get_product_classname($p->get_id(), "simple");
      $p = new $classe($p->get_id());
      delete_post_meta($p->get_id(), "_product_url");
      delete_post_meta($p->get_id(), "_button_text");
      $p->set_manage_stock(false);
      $p->set_stock_status("instock");
    }
    if (isset($textes[$p->get_name()])) {
      [$courte, $longue] = $textes[$p->get_name()];
      $p->set_short_description($courte);
      $p->set_description($longue);
    }
    if ($p->is_type("variable")) {
      // Option locale « Logo » : Oui / Non (au lieu de Yes / No), sur le produit et sur chaque déclinaison.
      $oui_non = ["Yes" => "Oui", "No" => "Non"];
      $attributs = $p->get_attributes();
      foreach ($attributs as $cle => $attribut) {
        if (!$attribut->is_taxonomy()) {
          // Copie : modifier l’objet en place ne marque pas le produit comme changé, rien ne serait enregistré.
          $copie = clone $attribut;
          $copie->set_options(array_map(fn($o) => $oui_non[$o] ?? $o, $attribut->get_options()));
          $attributs[$cle] = $copie;
        }
      }
      $p->set_attributes($attributs);
      foreach ($p->get_children() as $id) {
        $v = wc_get_product($id);
        $valeurs = $v->get_attributes();
        foreach ($valeurs as $cle => $valeur) {
          if (isset($oui_non[$valeur])) { $valeurs[$cle] = $oui_non[$valeur]; }
        }
        $v->set_attributes($valeurs);
        $v->set_description("");  // texte de remplissage en latin du jeu de démonstration
        $v->save();
      }
    }
    $p->save();
    if ($p->is_type("grouped")) {
      // L’import du jeu de démonstration laisse le lot pointer vers d’anciens identifiants (8, 9, 10) :
      // on le rattache aux vrais articles, puis on recalcule son prix (le plus bas de ses articles).
      $articles = [];
      foreach (["T-shirt logo", "Bonnet logo", "Sweat logo"] as $nom) {
        $trouves = get_posts(["post_type" => "product", "title" => $nom, "fields" => "ids", "numberposts" => 1]);  // « name » cherche le slug, resté en anglais
        if ($trouves) { $articles[] = $trouves[0]; }
      }
      $p->set_children($articles);
      $p->save();
      $p->get_data_store()->sync_price($p);
    }
  }
  wc_delete_product_transients();'

echo "== 4 sexies bis. Avis clients de démonstration (bloc « Avis » de la fiche produit)"
wp option update woocommerce_enable_reviews "yes"
wp option update woocommerce_enable_review_rating "yes"
wp eval '
  $avis = [
    ["Sweat à capuche", "Brice Essomba", "brice@bayam.local", 5, "Molleton bien épais, la couleur est fidèle à la photo. Livré le lendemain à Akwa."],
    ["Sweat à capuche", "Carine N.", "carine@bayam.local", 4, "Bonne qualité. Je l’ai pris en M, il taille un peu grand."],
    ["Bonnet logo", "Brice Essomba", "brice@bayam.local", 5, "Chaud et doux, parfait pour le matin."],
  ];
  foreach ($avis as [$nom, $auteur, $courriel, $note, $texte]) {
    $ids = get_posts(["post_type" => "product", "title" => $nom, "fields" => "ids", "numberposts" => 1]);
    if (!$ids) { continue; }
    $deja = get_comments(["post_id" => $ids[0], "author_email" => $courriel, "count" => true]);
    if ($deja) { continue; }  // relance du script : pas de doublon
    $id = wp_insert_comment(["comment_post_ID" => $ids[0], "comment_author" => $auteur, "comment_author_email" => $courriel,
      "comment_content" => $texte, "comment_type" => "review", "comment_approved" => 1]);
    update_comment_meta($id, "rating", $note);
    update_comment_meta($id, "verified", 1);
  }
  foreach (wc_get_products(["limit" => -1, "return" => "ids"]) as $pid) { WC_Comments::clear_transients($pid); }
  WP_CLI::log("Avis posés");'

echo "== 4 quinquies. Logos et bannières des boutiques (images de scripts/medias, créées par medias_vendeurs.py)"
wp eval '
  require_once ABSPATH . "wp-admin/includes/file.php";
  require_once ABSPATH . "wp-admin/includes/media.php";
  require_once ABSPATH . "wp-admin/includes/image.php";
  // Une image déjà importée et inchangée (même empreinte) est réutilisée ; une image regénérée remplace l’ancienne.
  function bayam_media($fichier) {
    $titre = pathinfo($fichier, PATHINFO_FILENAME);
    $empreinte = md5_file($fichier);
    foreach (get_posts(["post_type" => "attachment", "title" => $titre, "post_status" => "inherit", "fields" => "ids", "numberposts" => -1]) as $ancien) {
      if (get_post_meta($ancien, "_bayam_empreinte", true) === $empreinte) { return $ancien; }
      wp_delete_attachment($ancien, true);
    }
    $tmp = wp_tempnam($fichier);
    copy($fichier, $tmp);
    $id = media_handle_sideload(["name" => basename($fichier), "tmp_name" => $tmp], 0, $titre);
    if (is_wp_error($id)) { WP_CLI::error("$fichier : " . $id->get_error_message()); }
    update_post_meta($id, "_bayam_empreinte", $empreinte);
    return $id;
  }
  foreach (["aicha", "akwa", "bonamoussadi"] as $login) {
    $user = get_user_by("login", $login);
    if (!$user) { continue; }
    $banniere = bayam_media("/scripts/medias/banniere_$login.png");
    $reglages = get_user_meta($user->ID, "wcfmmp_profile_settings", true) ?: [];
    $reglages = array_merge($reglages, ["gravatar" => bayam_media("/scripts/medias/logo_$login.png"),
      "banner_type" => "single_img", "banner" => $banniere, "mobile_banner" => $banniere,
      "list_banner_type" => "single_img", "list_banner" => $banniere]);
    update_user_meta($user->ID, "wcfmmp_profile_settings", $reglages);
    WP_CLI::log("Logo et bannière posés : $login");
  }
  // Images des catégories (sinon une icône d’image vide dans l’application et sur le site).
  $images = ["tshirts" => "tshirts", "hoodies" => "sweats", "accessories" => "accessoires", "music" => "musique",
             "clothing" => "vetements", "decor" => "decoration"];
  foreach ($images as $slug => $fichier) {
    $categorie = get_term_by("slug", $slug, "product_cat");
    if ($categorie) {
      update_term_meta($categorie->term_id, "thumbnail_id", bayam_media("/scripts/medias/categorie_$fichier.png"));
    }
  }
  WP_CLI::log("Images des catégories posées");
  // Visuels des articles qui portaient les logos WordPress et WooCommerce (galerie vidée : elle les montrait encore).
  foreach (["Album makossa" => "album", "Single bikutsi" => "single", "Fanion du Cameroun" => "fanion"] as $nom => $fichier) {
    foreach (get_posts(["post_type" => "product", "title" => $nom, "numberposts" => 1, "fields" => "ids"]) as $id) {
      $p = wc_get_product($id);
      $p->set_image_id(bayam_media("/scripts/medias/produit_$fichier.png"));
      $p->set_gallery_image_ids([]);
      $p->save();
    }
  }
  WP_CLI::log("Visuels des articles de musique et du fanion posés");'

echo "== 4 septies. Nom du site, conditions et confidentialité, page À propos"
wp option update blogname "BAYAM"
wp option update blogdescription "Les boutiques du Cameroun, à portée de message"
wp eval '
  // WordPress crée cette page en brouillon : le lien de l’application tombait sur « Page introuvable ».
  // Textes définitifs dans scripts/pages/ (conditions et confidentialité selon les lois 2010/021 et 2024/017).
  $page = get_page_by_path("privacy-policy");
  $article = ["post_type" => "page", "post_status" => "publish", "post_title" => "Conditions d’utilisation et confidentialité",
              "post_name" => "privacy-policy", "post_content" => file_get_contents("/scripts/pages/conditions-confidentialite.html")];
  if ($page) { $article["ID"] = $page->ID; wp_update_post($article); } else { wp_insert_post($article); }
  update_option("wp_page_for_privacy_policy", get_page_by_path("privacy-policy")->ID);
  $apropos = ["post_type" => "page", "post_status" => "publish", "post_title" => "À propos de BAYAM", "post_name" => "a-propos",
    "post_content" => file_get_contents("/scripts/pages/a-propos.html")];
  $page = get_page_by_path("a-propos");
  if ($page) { $apropos["ID"] = $page->ID; wp_update_post($apropos); } else { wp_insert_post($apropos); }
  // Carte des boutiques centrée sur Douala (WCFM la centre par défaut sur Le Caire).
  $options = get_option("wcfm_marketplace_options", []);
  $options["default_geolocation"] = ["location" => "Douala, Cameroun", "address" => "Douala, Cameroun", "lat" => 4.0511, "lng" => 9.7679];
  update_option("wcfm_marketplace_options", $options);'

echo "== 5. Clés de l'API WooCommerce pour l'application"
CLES=/scripts/cles_api.txt
if [ ! -s "$CLES" ]; then
  wp eval '
    global $wpdb;
    $user = get_user_by("login", getenv("WP_ADMIN"));
    $ck = "ck_" . wc_rand_hash(); $cs = "cs_" . wc_rand_hash();
    $wpdb->insert($wpdb->prefix . "woocommerce_api_keys", [
      "user_id" => $user->ID, "description" => "Application FluxStore (local)", "permissions" => "read_write",
      "consumer_key" => wc_api_hash($ck), "consumer_secret" => $cs, "truncated_key" => substr($ck, -7)]);
    echo "consumerKey=$ck\nconsumerSecret=$cs\n";' > "$CLES"
fi

echo "== 6. Vitrine : thème BAYAM Vitrine, page Accueil, menus, ménage"
wp theme activate bayam-vitrine
wp eval '
  // Page d’accueil : le contenu vient de front-page.php, la page sert seulement à désigner l’accueil.
  $accueil = get_page_by_path("accueil");
  $id = $accueil ? $accueil->ID : wp_insert_post(["post_type" => "page", "post_status" => "publish", "post_title" => "Accueil", "post_name" => "accueil"]);
  update_option("show_on_front", "page");
  update_option("page_on_front", $id);
  update_option("page_for_posts", 0);

  // Ménage : article et page d’exemple de WordPress (avec leurs commentaires), renommage de la page d’inscription.
  foreach ([["hello-world", "post"], ["sample-page", "page"]] as [$chemin, $type]) {
    $contenu = get_page_by_path($chemin, OBJECT, $type);
    if ($contenu) { wp_delete_post($contenu->ID, true); WP_CLI::log("Supprimé : $chemin"); }
  }
  $inscription = get_page_by_path("vendor-register");
  if ($inscription && $inscription->post_title !== "Inscription vendeur") { wp_update_post(["ID" => $inscription->ID, "post_title" => "Inscription vendeur"]); }

  // Menus : recréés à chaque passage pour rester identiques à la base (catégories réelles).
  function bayam_menu($nom) {
    $ancien = wp_get_nav_menu_object($nom);
    if ($ancien) { wp_delete_nav_menu($ancien->term_id); }
    return wp_create_nav_menu($nom);
  }
  function bayam_entree($menu, $titre, $args) {
    return wp_update_nav_menu_item($menu, 0, array_merge(["menu-item-title" => $titre, "menu-item-status" => "publish"], $args));
  }
  function bayam_page($menu, $chemin, $titre) {
    $page = get_page_by_path($chemin);
    if ($page) {
      bayam_entree($menu, $titre, ["menu-item-type" => "post_type", "menu-item-object" => "page", "menu-item-object-id" => $page->ID]);
    }
  }
  $boutique = wc_get_page_permalink("shop");
  $nouveautes = add_query_arg("orderby", "date", $boutique);

  $principal = bayam_menu("Menu principal");
  bayam_entree($principal, "Accueil", ["menu-item-type" => "custom", "menu-item-url" => home_url("/")]);
  bayam_page($principal, "boutiques", "Boutiques");
  $categories = bayam_entree($principal, "Catégories", ["menu-item-type" => "custom", "menu-item-url" => $boutique]);
  $defaut = (int) get_option("default_product_cat");
  foreach (get_terms(["taxonomy" => "product_cat", "hide_empty" => true, "exclude" => [$defaut], "orderby" => "name"]) as $terme) {
    bayam_entree($principal, $terme->name, ["menu-item-type" => "taxonomy", "menu-item-object" => "product_cat",
      "menu-item-object-id" => $terme->term_id, "menu-item-parent-id" => $categories]);
  }
  bayam_entree($principal, "Nouveautés", ["menu-item-type" => "custom", "menu-item-url" => $nouveautes]);
  bayam_page($principal, "vendor-membership", "Devenir vendeur");

  $mobile = bayam_menu("Menu mobile");
  bayam_entree($mobile, "Accueil", ["menu-item-type" => "custom", "menu-item-url" => home_url("/")]);
  bayam_page($mobile, "boutiques", "Boutiques");
  bayam_entree($mobile, "Nouveautés", ["menu-item-type" => "custom", "menu-item-url" => $nouveautes]);
  bayam_page($mobile, "my-account", "Mon compte");
  bayam_page($mobile, "vendor-membership", "Devenir vendeur");

  set_theme_mod("nav_menu_locations", ["primary" => $principal, "handheld" => $mobile]);
  WP_CLI::log("Accueil, menus et emplacements en place");'
# Texte de confidentialité du formulaire d’inscription (livré en anglais par WooCommerce).
wp option update woocommerce_registration_privacy_policy_text "Vos données personnelles servent à traiter vos commandes, à gérer l’accès à votre compte et à d’autres fins décrites dans notre [privacy_policy]."

wp cache flush
echo "== Terminé. Site : $WP_URL  ·  Administration : $WP_URL/wp-admin  ·  Clés de l'API : plateforme-locale/scripts/cles_api.txt"
