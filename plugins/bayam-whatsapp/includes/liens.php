<?php
defined('ABSPATH') || exit;

/**
 * Liens courts traçables (« /b/Xk3pQ2 ») : chaque lien sait de quelle boutique, de quel canal et de quel groupe
 * il vient, et chaque clic est compté, pour mesurer ce que rapporte chaque groupe WhatsApp.
 */
final class Bayam_WA_Liens
{
    public static function init(): void
    {
        add_action('init', static function () {
            add_rewrite_rule('^b/([A-Za-z0-9]{4,12})/?$', 'index.php?bayam_lien=$matches[1]', 'top');
            add_rewrite_tag('%bayam_lien%', '([A-Za-z0-9]{4,12})');
            if (get_option('bayam_wa_reecriture') !== '1') {
                flush_rewrite_rules(false);
                update_option('bayam_wa_reecriture', '1');
            }
        });
        add_action('template_redirect', [self::class, 'rediriger']);
    }

    public static function rediriger(): void
    {
        $code = get_query_var('bayam_lien');
        if (!$code) {
            return;
        }
        global $wpdb;
        $lien = $wpdb->get_row($wpdb->prepare('SELECT id, cible FROM ' . Bayam_WA_Stockage::table('liens') . ' WHERE code = %s', $code));
        if (!$lien) {
            wp_safe_redirect(home_url('/'), 302);
            exit;
        }
        $wpdb->insert(Bayam_WA_Stockage::table('clics'), ['lien' => $lien->id, 'cree' => current_time('mysql')]);
        wp_redirect($lien->cible, 302);
        exit;
    }

    /** Lien court vers $cible (réutilisé s'il existe déjà pour la même boutique, le même canal et le même groupe). */
    public static function creer(int $vendeur, string $cible, string $canal = 'whatsapp', ?string $groupe = null, ?int $produit = null): string
    {
        global $wpdb;
        $table = Bayam_WA_Stockage::table('liens');
        $code = $wpdb->get_var($wpdb->prepare(
            "SELECT code FROM $table WHERE vendeur = %d AND cible = %s AND canal = %s AND " . ($groupe === null ? 'groupe IS NULL' : $wpdb->prepare('groupe = %s', $groupe)),
            $vendeur, $cible, $canal
        ));
        if (!$code) {
            do {
                $code = wp_generate_password(6, false);
            } while ($wpdb->get_var($wpdb->prepare("SELECT 1 FROM $table WHERE code = %s", $code)));
            $wpdb->insert($table, ['code' => $code, 'vendeur' => $vendeur, 'cible' => $cible, 'canal' => $canal, 'groupe' => $groupe, 'produit' => $produit, 'cree' => current_time('mysql')]);
        }
        return home_url('/b/' . $code);
    }

    public static function produit(int $vendeur, WC_Product $p, string $canal = 'whatsapp', ?string $groupe = null): string
    {
        return self::creer($vendeur, get_permalink($p->get_id()), $canal, $groupe, $p->get_id());
    }

    /** Lien unique de la boutique (biographies TikTok, Instagram, Facebook ; autocollant QR). */
    public static function boutique(int $vendeur, string $canal = 'bio'): string
    {
        $url = function_exists('wcfmmp_get_store_url') ? wcfmmp_get_store_url($vendeur) : home_url('/');
        return self::creer($vendeur, $url, $canal);
    }

    /** Clics et commandes par groupe sur 30 jours (« meilleurs groupes »). */
    public static function statistiques(int $vendeur, int $jours = 30): array
    {
        global $wpdb;
        $l = Bayam_WA_Stockage::table('liens');
        $c = Bayam_WA_Stockage::table('clics');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(l.groupe, l.canal) AS source, COUNT(c.id) AS clics FROM $l l LEFT JOIN $c c ON c.lien = l.id AND c.cree >= %s
             WHERE l.vendeur = %d GROUP BY source ORDER BY clics DESC",
            wp_date('Y-m-d H:i:s', strtotime("-$jours days")), $vendeur
        ), ARRAY_A);
    }
}
