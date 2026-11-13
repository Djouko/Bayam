<?php
defined('ABSPATH') || exit;

/**
 * Moyen de paiement WooCommerce « Mobile Money » : le client est envoyé sur la page sécurisée de CinetPay (MTN MoMo,
 * Orange Money), puis la commande n'est marquée payée qu'après lecture du paiement auprès de l'API CinetPay :
 * la notification n'est qu'un signal (jeton vérifié), jamais une preuve de paiement.
 */
final class Bayam_CinetPay_Passerelle extends WC_Payment_Gateway
{
    public const ID = 'bayam_cinetpay';

    public function __construct()
    {
        $this->id = self::ID;
        $this->method_title = 'CinetPay (Mobile Money)';
        $this->method_description = 'MTN Mobile Money et Orange Money par CinetPay. Identifiants lus dans l’environnement du serveur (CINETPAY_API_KEY, CINETPAY_API_PASSWORD, CINETPAY_ENV), jamais en base.';
        $this->has_fields = false;
        $this->supports = ['products'];
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => ['title' => 'Activer', 'type' => 'checkbox', 'label' => 'Proposer le paiement Mobile Money', 'default' => 'yes'],
            'title' => ['title' => 'Titre', 'type' => 'text', 'default' => 'Mobile Money : MTN MoMo ou Orange Money'],
            'description' => ['title' => 'Description', 'type' => 'textarea',
                'default' => 'Vous payez sur la page sécurisée de CinetPay avec MTN Mobile Money ou Orange Money, puis vous revenez ici automatiquement.'],
        ];
    }

    public function is_available(): bool
    {
        return parent::is_available() && Bayam_CinetPay_Api::configuree() && get_woocommerce_currency() === 'XAF';
    }

    public function process_payment($order_id): array
    {
        $commande = wc_get_order($order_id);
        // Identifiant unique de 30 caractères au plus ; un nouvel essai après un échec crée un nouveau paiement.
        $transaction = 'BYM' . $commande->get_id() . 'T' . time();
        $prenom = trim($commande->get_billing_first_name());
        $nom = trim($commande->get_billing_last_name());
        $champs = [
            'currency' => 'XAF',
            'merchant_transaction_id' => $transaction,
            'amount' => (int) round((float) $commande->get_total()),
            'success_url' => self::url_retour($commande),
            'failed_url' => self::url_retour($commande),
            'notify_url' => self::base_publique() . '/wp-json/bayam/v1/cinetpay',
            'lang' => 'fr',
            'designation' => 'Commande n° ' . $commande->get_order_number() . ' sur BAYAM',
            // CinetPay exige prénom et nom de deux lettres au moins, et une adresse électronique : un lien de
            // paiement WhatsApp ne connaît souvent que le prénom du client.
            'client_first_name' => mb_strlen($prenom) >= 2 ? $prenom : 'Client',
            'client_last_name' => mb_strlen($nom) >= 2 ? $nom : (mb_strlen($prenom) >= 2 ? $prenom : 'BAYAM'),
            'client_email' => is_email($commande->get_billing_email()) ? $commande->get_billing_email() : 'paiements@bayam.cm',
        ];
        $telephone = preg_replace('/\D+/', '', $commande->get_billing_phone());
        if (strlen($telephone) === 9) {
            $champs['client_phone_number'] = '+237' . $telephone;
        } elseif (str_starts_with($telephone, '237') && strlen($telephone) === 12) {
            $champs['client_phone_number'] = '+' . $telephone;
        }
        $r = Bayam_CinetPay_Api::creer_paiement($champs);
        if (is_wp_error($r) || empty($r['payment_url'])) {
            $message = is_wp_error($r) ? $r->get_error_message() : 'CinetPay n’a pas fourni de page de paiement.';
            $commande->add_order_note('Paiement Mobile Money non lancé : ' . $message);
            wc_add_notice('Le paiement Mobile Money n’a pas pu démarrer. Réessayez dans un instant ou choisissez un autre moyen de paiement.', 'error');
            return ['result' => 'failure'];
        }
        $commande->update_meta_data('_bayam_cp_transaction', $transaction);
        $commande->update_meta_data('_bayam_cp_jeton', (string) ($r['notify_token'] ?? ''));
        $commande->update_meta_data('_bayam_cp_id', (string) ($r['transaction_id'] ?? ''));
        $commande->add_order_note('Paiement Mobile Money lancé chez CinetPay (' . $transaction . ').');
        $commande->save();
        return ['result' => 'success', 'redirect' => $r['payment_url']];
    }

    /** Adresse joignable par CinetPay : celle du tunnel pendant les démonstrations, sinon celle du site. */
    private static function base_publique(): string
    {
        return untrailingslashit(function_exists('bayam_url_publique') && bayam_url_publique() ? bayam_url_publique() : home_url());
    }

    /** Retour du client (succès ou échec) : adresse courte, CinetPay en limite la longueur à 120 caractères. */
    private static function url_retour(WC_Order $commande): string
    {
        return add_query_arg('cle', $commande->get_order_key(), home_url('/wp-json/bayam/v1/cinetpay/retour/' . $commande->get_id()));
    }

    public static function routes(): void
    {
        register_rest_route('bayam/v1', '/cinetpay', [
            'methods' => 'POST',
            'callback' => [self::class, 'notification'],
            'permission_callback' => '__return_true', // authentifiée par le jeton de notification propre à chaque paiement
        ]);
        register_rest_route('bayam/v1', '/cinetpay/retour/(?P<commande>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'retour'],
            'permission_callback' => '__return_true',
        ]);
    }

    /** Notification de CinetPay : jeton comparé en temps constant, puis paiement relu auprès de l'API. */
    public static function notification(WP_REST_Request $requete): WP_REST_Response
    {
        $p = $requete->get_json_params() ?: $requete->get_body_params();
        $transaction = (string) ($p['merchant_transaction_id'] ?? '');
        $jeton = (string) ($p['notify_token'] ?? '');
        $commandes = $transaction === '' ? [] : wc_get_orders(['limit' => 1, 'meta_key' => '_bayam_cp_transaction', 'meta_value' => $transaction]);
        $commande = $commandes[0] ?? null;
        if (!$commande || $jeton === '' || !hash_equals((string) $commande->get_meta('_bayam_cp_jeton'), $jeton)) {
            return new WP_REST_Response(['ok' => false], 403);
        }
        self::confirmer($commande, (string) ($p['transaction_id'] ?? ''));
        return new WP_REST_Response(['ok' => true], 200);
    }

    /** Le client revient de CinetPay : on vérifie sans attendre la notification, puis page « Commande reçue ». */
    public static function retour(WP_REST_Request $requete): void
    {
        $commande = wc_get_order((int) $requete['commande']);
        // Clé de la commande exigée : sans elle, un numéro tiré au hasard ne mène à rien.
        if (!$commande || $commande->get_payment_method() !== self::ID || !hash_equals($commande->get_order_key(), (string) $requete->get_param('cle'))) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
        self::confirmer($commande);
        wp_safe_redirect($commande->is_paid() ? $commande->get_checkout_order_received_url() : $commande->get_checkout_payment_url());
        exit;
    }

    public static function verifier_au_retour($order_id): void
    {
        $commande = wc_get_order($order_id);
        if ($commande && $commande->get_payment_method() === self::ID && !$commande->is_paid()) {
            self::confirmer($commande);
        }
    }

    /** Lit le paiement chez CinetPay et met la commande à jour (une seule fois : une commande payée ne bouge plus). */
    public static function confirmer(WC_Order $commande, string $id_signale = ''): void
    {
        $transaction = (string) $commande->get_meta('_bayam_cp_transaction');
        if ($transaction === '' || $commande->is_paid()) {
            return;
        }
        // Notification et retour du client arrivent souvent ensemble : un verrou (insertion unique) évite de
        // valider deux fois le même paiement.
        $verrou = 'bayam_cp_verrou_' . $commande->get_id();
        if (!add_option($verrou, time(), '', false)) {
            return;
        }
        try {
            self::lire_et_appliquer($commande, $transaction, $id_signale);
        } finally {
            delete_option($verrou);
        }
    }

    private static function lire_et_appliquer(WC_Order $commande, string $transaction, string $id_signale): void
    {
        $commande = wc_get_order($commande->get_id()); // état à jour, une autre requête a pu passer avant le verrou
        if ($commande->is_paid()) {
            return;
        }
        $r = Bayam_CinetPay_Api::paiement($transaction);
        if (is_wp_error($r)) {
            $commande->add_order_note('Vérification CinetPay impossible : ' . $r->get_error_message());
            return;
        }
        $statut = (string) ($r['status'] ?? '');
        $id = (string) ($r['transaction_id'] ?? '');
        if (($r['merchant_transaction_id'] ?? '') !== $transaction || ($id_signale !== '' && !hash_equals($id, $id_signale))) {
            $commande->add_order_note('Notification CinetPay ignorée : identifiants différents de ceux de la transaction.');
            return;
        }
        if ($statut === 'SUCCESS') {
            $commande->payment_complete($id);
            $commande->add_order_note('Paiement Mobile Money confirmé par CinetPay (' . $id . ').');
        } elseif (in_array($statut, Bayam_CinetPay_Api::FINAUX, true)) {
            $commande->add_order_note('Paiement Mobile Money non abouti chez CinetPay : ' . $statut . '. Le client peut réessayer.');
        }
    }
}
