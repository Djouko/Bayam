<?php
/**
 * Plugin Name: BAYAM WhatsApp
 * Description: Vendre depuis WhatsApp : connexion du WhatsApp du vendeur (mode connecté, WAHA), Copilote dans « Message à moi-même », réponses aux mots-clés des clients, cartes produits, publication dans les groupes et le statut, liens de paiement, dettes, promos et bilans.
 * Version: 1.0.0
 * Author: INAF
 * Text Domain: bayam-whatsapp
 * Requires PHP: 8.1
 */

defined('ABSPATH') || exit;

const BAYAM_WA_VERSION = '1.1.0';
define('BAYAM_WA_DIR', __DIR__ . '/');
define('BAYAM_WA_URL', plugin_dir_url(__FILE__));

// SDK Anthropic de l'assistant (docker compose run --rm composer install) ; sans lui, l'assistant reste inactif.
if (is_readable(BAYAM_WA_DIR . 'vendor/autoload.php')) {
    require_once BAYAM_WA_DIR . 'vendor/autoload.php';
}

foreach ([
    'outils', 'fonctions', 'stockage', 'waha', 'regles', 'boutique', 'cartes', 'liens', 'ia', 'aide', 'facebook', 'retours',
    'copilote', 'clients', 'assistant', 'webhook', 'diffusion', 'rapports', 'paiements', 'page-vendeur', 'travailleur', 'interne',
] as $fichier) {
    require_once BAYAM_WA_DIR . "includes/{$fichier}.php";
}

register_activation_hook(__FILE__, ['Bayam_WA_Stockage', 'installer']);

add_action('plugins_loaded', static function () {
    // Mise à jour du schéma sans réactivation (extension montée en volume pendant le développement).
    if (get_option('bayam_wa_version') !== BAYAM_WA_VERSION) {
        Bayam_WA_Stockage::installer();
    }
    Bayam_WA_Webhook::init();
    Bayam_WA_Liens::init();
    Bayam_WA_Diffusion::init();
    Bayam_WA_Rapports::init();
    Bayam_WA_Paiements::init();
    Bayam_WA_Page_Vendeur::init();
    Bayam_WA_Interne::init();
    Bayam_WA_Retours::init();
});
