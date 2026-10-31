<?php
/**
 * Plugin Name: BAYAM CinetPay
 * Description: Paiement Mobile Money (MTN MoMo, Orange Money) par CinetPay pour les commandes BAYAM : site, application et liens de paiement WhatsApp.
 * Version: 1.0.0
 * Requires PHP: 8.2
 * Text Domain: bayam-cinetpay
 */

defined('ABSPATH') || exit;

define('BAYAM_CINETPAY_DIR', __DIR__ . '/');

require_once BAYAM_CINETPAY_DIR . 'includes/api.php';

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    require_once BAYAM_CINETPAY_DIR . 'includes/passerelle.php';
    add_filter('woocommerce_payment_gateways', static fn (array $passerelles) => array_merge($passerelles, ['Bayam_CinetPay_Passerelle']));
    add_action('rest_api_init', [Bayam_CinetPay_Passerelle::class, 'routes']);
    // Retour du client sur la page « Commande reçue » : on vérifie aussitôt, sans attendre la notification.
    add_action('woocommerce_thankyou', [Bayam_CinetPay_Passerelle::class, 'verifier_au_retour'], 5);
});

// Les commandes en blocs (panier et commande du site) affichent la passerelle classique par ce pont officiel.
add_action('woocommerce_blocks_loaded', static function (): void {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }
    require_once BAYAM_CINETPAY_DIR . 'includes/blocs.php';
    add_action('woocommerce_blocks_payment_method_type_registration', static function ($registre): void {
        $registre->register(new Bayam_CinetPay_Blocs());
    });
});
