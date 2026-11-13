<?php
/**
 * Plugin Name: BAYAM, sécurité de base
 * Description: Comptes non énumérables (API des utilisateurs et archives d'auteur fermées aux visiteurs), XML-RPC coupé.
 */

defined('ABSPATH') || exit;

// Liste des comptes : réservée aux personnes connectées (l'application et l'espace vendeur s'authentifient).
add_filter('rest_endpoints', static function (array $routes): array {
    if (!is_user_logged_in()) {
        foreach (array_keys($routes) as $route) {
            if (str_starts_with($route, '/wp/v2/users')) {
                unset($routes[$route]);
            }
        }
    }
    return $routes;
});

// « /?author=1 » révélait l'identifiant de connexion par la redirection vers /author/<identifiant>.
add_action('template_redirect', static function (): void {
    if (!is_user_logged_in() && (isset($_GET['author']) || is_author())) {
        wp_safe_redirect(home_url('/'), 301);
        exit;
    }
});

// XML-RPC : aucun usage chez BAYAM, porte classique des essais de mots de passe en masse.
add_filter('xmlrpc_enabled', '__return_false');
