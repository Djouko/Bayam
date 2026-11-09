<?php
/**
 * Retours des utilisateurs : soucis et idées d'amélioration, envoyés depuis WhatsApp ou Messenger (« bayam signaler … »,
 * « bayam idée … », menu du vendeur), par les vendeurs comme par leurs clients. Chaque retour reçoit un numéro, arrive
 * dans l'administration (menu « Retours ») et prévient l'équipe par e-mail. L'utilisateur n'a rien à installer.
 */

defined('ABSPATH') || exit;

final class Bayam_WA_Retours
{
    public const TYPES = ['souci' => 'Souci', 'idee' => 'Idée'];

    public static function init(): void
    {
        add_action('init', static function () {
            register_post_type('bayam_retour', [
                'labels' => ['name' => 'Retours (soucis et idées)', 'singular_name' => 'Retour', 'menu_name' => 'Retours', 'all_items' => 'Tous les retours', 'edit_item' => 'Retour'],
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => true,
                'menu_icon' => 'dashicons-megaphone',
                'menu_position' => 58,
                'supports' => ['title', 'editor'],
                'capability_type' => 'post',
                'map_meta_cap' => true,
            ]);
        });
        add_filter('manage_bayam_retour_posts_columns', static fn (array $c) => array_slice($c, 0, 2) + ['bayam_type' => 'Type', 'bayam_canal' => 'Canal', 'bayam_boutique' => 'Boutique'] + $c);
        add_action('manage_bayam_retour_posts_custom_column', static function (string $colonne, int $id) {
            $valeurs = [
                'bayam_type' => self::TYPES[get_post_meta($id, '_bayam_type', true)] ?? '',
                'bayam_canal' => (string) get_post_meta($id, '_bayam_canal', true),
                'bayam_boutique' => ($v = (int) get_post_meta($id, '_bayam_vendeur', true)) ? Bayam_WA_Outils::nom_boutique($v) : 'Client',
            ];
            echo esc_html($valeurs[$colonne] ?? '');
        }, 10, 2);
    }

    /** Enregistre un retour et prévient l'équipe ; renvoie son numéro (« R-1234 »). */
    public static function creer(string $type, string $texte, string $canal, int $vendeur = 0, string $auteur = ''): string
    {
        $type = isset(self::TYPES[$type]) ? $type : 'souci';
        $texte = trim(wp_strip_all_tags($texte));
        $id = wp_insert_post([
            'post_type' => 'bayam_retour',
            'post_status' => 'private',
            'post_title' => self::TYPES[$type] . ' : ' . mb_strimwidth($texte, 0, 80, '…', 'UTF-8'),
            'post_content' => $texte,
            'meta_input' => ['_bayam_type' => $type, '_bayam_canal' => $canal, '_bayam_vendeur' => $vendeur, '_bayam_auteur' => $auteur],
        ]);
        if (!$id || is_wp_error($id)) {
            Bayam_WA_Outils::journal('Retour non enregistré', ['canal' => $canal, 'vendeur' => $vendeur]);
            return '';
        }
        $numero = 'R-' . $id;
        $qui = $vendeur ? Bayam_WA_Outils::nom_boutique($vendeur) : ($auteur ?: 'un client');
        wp_mail((string) get_option('admin_email'), "BAYAM : {$numero}, " . strtolower(self::TYPES[$type]) . " ({$canal})",
            "{$numero} de {$qui}, depuis {$canal} :\n\n{$texte}\n\n" . admin_url("post.php?post={$id}&action=edit"));
        return $numero;
    }

    /** Réponse à l'utilisateur après son retour. */
    public static function merci(string $numero, string $type): string
    {
        return $numero === ''
            ? "Votre message n'a pas pu être enregistré. Réessayez dans un instant 🙏"
            : "🙏 Merci ! Votre " . ($type === 'idee' ? 'idée' : 'signalement') . " ({$numero}) est bien arrivé(e) à l'équipe BAYAM. Nous vous répondons ici si besoin.";
    }
}
