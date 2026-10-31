<?php
defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/** « Mobile Money » dans le panier et la commande en blocs du site (même modèle que le paiement à la livraison). */
final class Bayam_CinetPay_Blocs extends AbstractPaymentMethodType
{
    protected $name = 'bayam_cinetpay';

    public function initialize(): void
    {
        $this->settings = get_option('woocommerce_bayam_cinetpay_settings', []);
    }

    public function is_active(): bool
    {
        $passerelles = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        return isset($passerelles['bayam_cinetpay']) && $passerelles['bayam_cinetpay']->is_available();
    }

    public function get_payment_method_script_handles(): array
    {
        wp_register_script('bayam-cinetpay-blocs', plugins_url('assets/blocs.js', BAYAM_CINETPAY_DIR . 'bayam-cinetpay.php'),
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'], '1.0.0', true);
        return ['bayam-cinetpay-blocs'];
    }

    public function get_payment_method_data(): array
    {
        return [
            'title' => $this->get_setting('title', 'Mobile Money : MTN MoMo ou Orange Money'),
            'description' => $this->get_setting('description', ''),
            'supports' => $this->get_supported_features(),
        ];
    }
}
