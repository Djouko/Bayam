<?php
defined('ABSPATH') || exit;

/**
 * Client de l'API REST CinetPay (version 2026, SDK officiel cinetpay-php-sdk) : jeton Bearer obtenu sur
 * /v1/oauth/login et réutilisé jusqu'à son expiration, création et lecture d'un paiement.
 * Identifiants dans l'environnement (.env), jamais en base : CINETPAY_API_KEY, CINETPAY_API_PASSWORD, CINETPAY_ENV.
 * Un compte CinetPay correspond à un pays : celui de BAYAM est le Cameroun (XAF, méthodes MTN_CM et OM_CM).
 */
final class Bayam_CinetPay_Api
{
    private const URLS = ['sandbox' => 'https://api.cinetpay.net', 'production' => 'https://api.cinetpay.co'];

    /** Statuts définitifs d'un paiement : la réponse ne changera plus. */
    public const FINAUX = ['SUCCESS', 'TRANSACTION_EXIST', 'INSUFFICIENT_BALANCE', 'FAILED'];

    public static function configuree(): bool
    {
        return (string) getenv('CINETPAY_API_KEY') !== '' && (string) getenv('CINETPAY_API_PASSWORD') !== '';
    }

    public static function environnement(): string
    {
        return getenv('CINETPAY_ENV') === 'production' ? 'production' : 'sandbox';
    }

    public static function creer_paiement(array $champs)
    {
        return self::appel('POST', '/v1/payment', $champs);
    }

    /** État d'un paiement par l'identifiant de BAYAM (merchant_transaction_id) ou celui de CinetPay. */
    public static function paiement(string $transaction)
    {
        return self::appel('GET', '/v1/payment/' . rawurlencode($transaction));
    }

    /** Appel authentifié ; un jeton refusé ou expiré est renouvelé une seule fois, comme le fait le SDK officiel. */
    private static function appel(string $methode, string $chemin, ?array $corps = null)
    {
        $jeton = self::jeton();
        if (is_wp_error($jeton)) {
            return $jeton;
        }
        $reponse = self::requete($methode, $chemin, $corps, $jeton);
        if (is_wp_error($reponse) && in_array($reponse->get_error_code(), ['EXPIRED_TOKEN', 'INVALID_TOKEN'], true)) {
            $jeton = self::jeton(true);
            $reponse = is_wp_error($jeton) ? $jeton : self::requete($methode, $chemin, $corps, $jeton);
        }
        return $reponse;
    }

    /** Jeton Bearer, gardé jusqu'à 30 secondes avant son expiration. */
    private static function jeton(bool $renouveler = false)
    {
        $cle = 'bayam_cinetpay_jeton_' . md5(getenv('CINETPAY_API_KEY') . self::environnement());
        if (!$renouveler && ($jeton = get_transient($cle))) {
            return $jeton;
        }
        $r = self::requete('POST', '/v1/oauth/login', ['api_key' => getenv('CINETPAY_API_KEY'), 'api_password' => getenv('CINETPAY_API_PASSWORD')]);
        if (is_wp_error($r)) {
            return $r;
        }
        $donnees = is_array($r['data'] ?? null) ? $r['data'] : [];
        $jeton = (string) ($r['access_token'] ?? ($donnees['token'] ?? ''));
        if ($jeton === '') {
            return new WP_Error('cinetpay_jeton', "CinetPay n'a pas renvoyé de jeton d'accès.");
        }
        $duree = (int) ($r['expires_in'] ?? ($donnees['expires_in'] ?? 300));
        set_transient($cle, $jeton, max(60, $duree - 30));
        return $jeton;
    }

    private static function requete(string $methode, string $chemin, ?array $corps = null, ?string $jeton = null)
    {
        $entetes = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'User-Agent' => 'BAYAM/1.0 (WordPress)'];
        if ($jeton !== null) {
            $entetes['Authorization'] = 'Bearer ' . $jeton;
        }
        $args = ['method' => $methode, 'timeout' => 15, 'headers' => $entetes];
        if ($corps !== null) {
            $args['body'] = wp_json_encode($corps);
        }
        $reponse = wp_remote_request(self::URLS[self::environnement()] . $chemin, $args);
        if (is_wp_error($reponse)) {
            return new WP_Error('cinetpay_reseau', 'CinetPay est injoignable : ' . $reponse->get_error_message());
        }
        $http = (int) wp_remote_retrieve_response_code($reponse);
        $donnees = json_decode((string) wp_remote_retrieve_body($reponse), true);
        if (!is_array($donnees)) {
            return new WP_Error('cinetpay_reponse', "CinetPay a renvoyé une réponse illisible (HTTP {$http}).");
        }
        $statut = is_string($donnees['status'] ?? null) ? $donnees['status'] : '';
        $message = (string) ($donnees['description'] ?? ($donnees['message'] ?? ($statut ?: "HTTP {$http}")));
        if ($http === 401 || in_array($statut, ['INVALID_CREDENTIALS', 'INVALID_TOKEN', 'EXPIRED_TOKEN'], true)) {
            return new WP_Error($statut ?: 'INVALID_CREDENTIALS', 'CinetPay refuse les identifiants : ' . $message);
        }
        if ($http < 200 || $http >= 300) {
            return new WP_Error($statut ?: 'cinetpay_http_' . $http, 'CinetPay : ' . $message, $donnees);
        }
        return $donnees;
    }
}
