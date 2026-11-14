<?php
/**
 * Plugin Name: BAYAM, clé de l'application
 * Description: La clé WooCommerce embarquée dans l'application mobile ne donne que la lecture du catalogue. Les données
 * personnelles (compte, commandes, notes de commande) ne sortent que pour la personne connectée elle-même, reconnue par
 * son cookie de session (en-tête User-Cookie, comme l'extension MStore API). Toute écriture par la clé est refusée.
 * Raison : une clé dans une application se lit dans le fichier publié ; elle doit donc être sans valeur pour un tiers.
 */

defined('ABSPATH') || exit;

final class Bayam_Cle_Application
{
    /** Ressources jamais lisibles par la clé, même par la personne connectée (passent par MStore API et son jeton). */
    private const INTERDITES = ['#^/wc/v\d/(reports|system_status|webhooks|settings|refunds|taxes/classes)(/|$)#'];

    public static function init(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'controler'], 5, 3);
        add_filter('rest_post_dispatch', [self::class, 'nettoyer'], 10, 3);
    }

    /** Requête authentifiée par une clé de l'API WooCommerce (paramètre, OAuth ou authentification de base). */
    public static function par_cle(WP_REST_Request $r): bool
    {
        return (string) $r->get_param('consumer_key') !== '' || (string) $r->get_param('oauth_consumer_key') !== ''
            || str_starts_with((string) ($_SERVER['PHP_AUTH_USER'] ?? ''), 'ck_');
    }

    /** Personne connectée d'après son cookie de session (0 si absent ou expiré). */
    public static function personne(WP_REST_Request $r): int
    {
        $cookie = (string) $r->get_header('User-Cookie');
        if ($cookie === '') {
            return 0;
        }
        $cookie = function_exists('get_header_user_cookie') ? (string) get_header_user_cookie($cookie) : $cookie;
        return (int) wp_validate_auth_cookie($cookie, 'logged_in');
    }

    /** @return mixed Résultat inchangé, ou erreur 403 si la clé sort de son rôle. */
    public static function controler($resultat, $serveur, WP_REST_Request $r)
    {
        $route = $r->get_route();
        if ($resultat !== null || !preg_match('#^/wc/v\d/#', $route) || str_contains($route, '/flutter/') || !self::par_cle($r)) {
            return $resultat;
        }
        $refus = static fn (string $raison) => new WP_Error('bayam_cle_application', $raison, ['status' => 403]);
        if ($r->get_method() !== 'GET') {
            return $refus("La clé de l'application ne permet que la lecture.");
        }
        foreach (self::INTERDITES as $motif) {
            if (preg_match($motif, $route)) {
                return $refus('Ressource réservée.');
            }
        }
        $moi = self::personne($r);
        if (preg_match('#^/wc/v\d/customers(/(\d+))?(/|$)#', $route, $m)) {
            return isset($m[2]) && $moi > 0 && (int) $m[2] === $moi ? $resultat : $refus('Seul votre propre compte est lisible.');
        }
        if (preg_match('#^/wc/v\d/orders/(\d+)#', $route, $m)) {
            $commande = wc_get_order((int) $m[1]);
            return $commande && $moi > 0 && (int) $commande->get_customer_id() === $moi ? $resultat : $refus('Seules vos propres commandes sont lisibles.');
        }
        if (preg_match('#^/wc/v\d/orders/?$#', $route)) {
            return $moi > 0 && (int) $r->get_param('customer') === $moi ? $resultat : $refus('Seules vos propres commandes sont lisibles.');
        }
        if (preg_match('#^/wc/v\d/coupons/?$#', $route) && (string) $r->get_param('code') === '') {
            return $refus('Un code promo précis est exigé.');
        }
        return $resultat;
    }

    /** Réglages des moyens de paiement (clés, comptes) jamais renvoyés à l'application. */
    public static function nettoyer($reponse, $serveur, WP_REST_Request $r)
    {
        if (!($reponse instanceof WP_REST_Response) || !preg_match('#^/wc/v\d/payment_gateways#', $r->get_route()) || !self::par_cle($r)) {
            return $reponse;
        }
        $donnees = $reponse->get_data();
        $retirer = static function (array $g): array {
            unset($g['settings'], $g['connection_url'], $g['needs_setup'], $g['post_install_scripts']);
            return $g;
        };
        $reponse->set_data(isset($donnees['id']) ? $retirer($donnees) : array_map($retirer, (array) $donnees));
        return $reponse;
    }
}

Bayam_Cle_Application::init();
