<?php
/**
 * Catalogue réaliste des trois boutiques d'essai :
 *   docker compose run --rm cli wp eval-file /scripts/catalogue/catalogue_reel.php
 * Lit catalogue.json (articles, rayons), credits.json (auteur et licence de chaque photo) et images/<clé>.jpg.
 * Rejouable : un article est retrouvé par sa clé (méta _bayam_cle) ou par l'identifiant qu'il reprend ; une photo
 * déjà importée n'est pas réimportée. Les articles d'exemple irréalistes sont retirés.
 */

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$dossier = '/scripts/catalogue';
$donnees = json_decode((string) file_get_contents("$dossier/catalogue.json"), true);
$credits = is_readable("$dossier/credits.json") ? json_decode((string) file_get_contents("$dossier/credits.json"), true) : [];

// --- Rayons ---------------------------------------------------------------------------------------------------------
$rayons = [];
foreach ($donnees['rayons'] as $slug => $nom) {
    $terme = get_term_by('slug', $slug, 'product_cat');
    if ($terme) {
        wp_update_term($terme->term_id, 'product_cat', ['name' => $nom]);
        $rayons[$slug] = (int) $terme->term_id;
    } else {
        $rayons[$slug] = (int) wp_insert_term($nom, 'product_cat', ['slug' => $slug])['term_id'];
    }
}

/** Article existant : par l'identifiant repris, sinon par la clé. */
function bayam_catalogue_trouver(array $a): ?WC_Product
{
    if ($a['id'] && ($p = wc_get_product((int) $a['id']))) {
        return $p;
    }
    $ids = get_posts(['post_type' => 'product', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => 1, 'meta_key' => '_bayam_cle', 'meta_value' => $a['cle']]);
    return $ids ? wc_get_product($ids[0]) : null;
}

/** Photo de l'article (images/<clé>.jpg) avec la mention de son auteur et de sa licence ; null si absente. */
function bayam_catalogue_photo(string $dossier, array $a, array $credits): ?int
{
    $fichier = "$dossier/images/{$a['cle']}.jpg";
    if (!is_readable($fichier)) {
        return null;
    }
    $empreinte = md5_file($fichier);
    $deja = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'numberposts' => 1, 'meta_key' => '_bayam_photo', 'meta_value' => $empreinte]);
    if ($deja) {
        return (int) $deja[0];
    }
    $temporaire = wp_tempnam($a['cle'] . '.jpg');
    copy($fichier, $temporaire);
    $id = media_handle_sideload(['name' => sanitize_title($a['nom']) . '.jpg', 'tmp_name' => $temporaire], 0, $a['nom']);
    if (is_wp_error($id)) {
        WP_CLI::warning("{$a['cle']} : photo non importée ({$id->get_error_message()})");
        return null;
    }
    $c = $credits[$a['cle']] ?? null;
    $mention = $c ? sprintf('Photo : %s, licence %s %s (%s)', $c['creator'] ?: 'auteur inconnu', strtoupper((string) $c['license']), $c['license_version'], $c['source']) : '';
    wp_update_post(['ID' => $id, 'post_author' => $a['boutique'], 'post_excerpt' => trim($mention)]);
    update_post_meta($id, '_wp_attachment_image_alt', $a['nom']);
    update_post_meta($id, '_bayam_photo', $empreinte);
    return (int) $id;
}

/** Prix et stock d'une déclinaison ou d'un article simple. */
function bayam_catalogue_prix_stock(WC_Product $p, array $a, int $stock): void
{
    $p->set_regular_price((string) $a['prix']);
    $p->set_sale_price($a['promo'] ? (string) $a['promo'] : '');
    $p->set_manage_stock(true);
    $p->set_stock_quantity($stock);
    $p->set_backorders('no');
}

$garder = [];
foreach ($donnees['articles'] as $a) {
    $existant = bayam_catalogue_trouver($a);
    $variable = $a['declinaisons'] || ($existant && $existant->is_type('variable'));
    if ($existant && $existant->is_type('variable') === $variable) {
        $p = $existant;
    } else {
        // Brouillon d'abord : l'article n'apparaît qu'une fois complet (prix, déclinaisons, photo).
        $id = wp_insert_post(['post_type' => 'product', 'post_status' => 'draft', 'post_title' => $a['nom'], 'post_author' => $a['boutique']]);
        $p = $variable ? new WC_Product_Variable($id) : new WC_Product_Simple($id);
        if ($existant) {
            $existant->delete(true);
        }
    }
    $p->set_name($a['nom']);
    $p->set_short_description($a['courte']);
    $p->set_description($a['description']);
    $p->set_category_ids([$rayons[$a['rayon']]]);
    $p->set_catalog_visibility('visible');
    $p->set_sku('');
    $p->update_meta_data('_bayam_cle', $a['cle']);
    if ($image = bayam_catalogue_photo($dossier, $a, $credits)) {
        $p->set_image_id($image);
        $p->set_gallery_image_ids([]);
    }
    if (!$variable) {
        bayam_catalogue_prix_stock($p, $a, (int) $a['stock']);
    } elseif ($a['declinaisons']) {
        $attributs = [];
        foreach ($a['declinaisons'] as $nom => $valeurs) {
            $attribut = new WC_Product_Attribute();
            $attribut->set_name($nom);
            $attribut->set_options(array_map('strval', array_keys($valeurs)));
            $attribut->set_visible(true);
            $attribut->set_variation(true);
            $attributs[] = $attribut;
        }
        $p->set_attributes($attributs);
    }
    $p->save();
    wp_update_post(['ID' => $p->get_id(), 'post_author' => $a['boutique']]);

    if ($variable) {
        if ($a['declinaisons']) {
            // Une déclinaison par valeur (un seul attribut par article ici : taille ou pointure).
            $nom = array_key_first($a['declinaisons']);
            $cle_attribut = sanitize_title($nom);
            $voulues = array_map('strval', array_keys($a['declinaisons'][$nom]));
            foreach ($p->get_children() as $enfant) {
                $v = wc_get_product($enfant);
                if (!$v || !in_array((string) ($v->get_attributes()[$cle_attribut] ?? ''), $voulues, true)) {
                    wp_delete_post($enfant, true);
                }
            }
            $existantes = [];
            foreach (wc_get_product($p->get_id())->get_children() as $enfant) {
                $existantes[(string) wc_get_product($enfant)->get_attributes()[$cle_attribut]] = $enfant;
            }
            foreach ($a['declinaisons'][$nom] as $valeur => $stock) {
                $v = isset($existantes[(string) $valeur]) ? wc_get_product($existantes[(string) $valeur]) : new WC_Product_Variation();
                $v->set_parent_id($p->get_id());
                $v->set_attributes([$cle_attribut => (string) $valeur]);
                bayam_catalogue_prix_stock($v, $a, (int) $stock);
                $v->set_status('publish');
                $v->save();
            }
        } else {
            // Déclinaisons d'origine gardées (les essais automatiques s'en servent et en vendent une) : vrais prix,
            // stocks variés, aucune à zéro.
            foreach ($p->get_children() as $rang => $enfant) {
                $v = wc_get_product($enfant);
                bayam_catalogue_prix_stock($v, $a, [4, 3, 5, 2, 6, 3][$rang % 6]);
                $v->save();
            }
        }
        WC_Product_Variable::sync($p->get_id());
    }
    $p = wc_get_product($p->get_id());
    $p->set_status('publish');
    $p->save();
    $garder[] = $p->get_id();
    WP_CLI::log(sprintf('%-28s n° %-4d %s, %s', $a['cle'], $p->get_id(), wp_strip_all_tags(wc_price((float) $p->get_price())), $p->is_in_stock() ? 'en stock' : 'épuisé'));
}

// --- Articles d'exemple retirés (logos WordPress, téléchargements, doublons) -----------------------------------------
foreach ($donnees['a_retirer'] as $id) {
    $p = wc_get_product((int) $id);
    if ($p && !in_array($p->get_id(), $garder, true)) {
        foreach ($p->get_children() as $enfant) {
            wp_delete_post($enfant, true);
        }
        $p->delete(true);
        WP_CLI::log("retiré : n° $id");
    }
}
foreach (['music', 'decor'] as $slug) {
    $terme = get_term_by('slug', $slug, 'product_cat');
    if ($terme && (int) $terme->count === 0) {
        wp_delete_term($terme->term_id, 'product_cat');
    }
}

// --- Page « Crédits photos » (mention exigée par les licences Creative Commons) ---------------------------------------
$lignes = [];
foreach ($donnees['articles'] as $a) {
    $c = $credits[$a['cle']] ?? null;
    if ($c) {
        $lignes[] = sprintf('<li><strong>%s</strong> : photo de %s, licence <a href="%s" rel="nofollow">%s %s</a>, source <a href="%s" rel="nofollow">%s</a>.</li>',
            esc_html($a['nom']), esc_html($c['creator'] ?: 'auteur inconnu'), esc_url((string) $c['license_url']), esc_html(strtoupper((string) $c['license'])),
            esc_html((string) $c['license_version']), esc_url((string) $c['foreign_landing_url']), esc_html((string) $c['source']));
    }
}
$page = get_page_by_path('credits-photos');
$contenu = '<p>Les photos des boutiques de démonstration sont des photos libres de droits. Merci à leurs auteurs.</p><ul>' . implode('', $lignes) . '</ul>';
$id_page = wp_insert_post(['ID' => $page ? $page->ID : 0, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Crédits photos', 'post_name' => 'credits-photos', 'post_content' => $contenu]);
WP_CLI::success(count($garder) . ' articles en place, page des crédits n° ' . $id_page . ' (' . count($lignes) . ' photos).');
