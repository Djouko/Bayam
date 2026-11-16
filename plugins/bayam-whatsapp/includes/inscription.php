<?php
/**
 * Inscription d'un vendeur en autonomie, depuis son seul téléphone (page /vendre) : nom de la boutique, prénom,
 * numéro. Deux chemins :
 *  - WhatsApp : la page affiche un code d'association à saisir dans WhatsApp (Appareils connectés › Connecter avec le
 *    numéro de téléphone), sur le même téléphone, sans ordinateur ni code QR ; la boutique est reliée dès la saisie ;
 *  - Facebook d'abord : la boutique est créée et la page ouvre directement la connexion de la Page Facebook ; WhatsApp
 *    se relie ensuite depuis Messenger (« bayam whatsapp <numéro> »).
 * Aucun passage par l'équipe. Garde-fous : 3 inscriptions par heure et par adresse, champ piège contre les robots,
 * un numéro = une boutique (une nouvelle demande reprend la même boutique), le code ne sert que sur le téléphone du numéro.
 */

defined('ABSPATH') || exit;

final class Bayam_WA_Inscription
{
    private const ESSAIS_PAR_HEURE = 3;

    public static function init(): void
    {
        add_action('rest_api_init', static function () {
            register_rest_route('bayam/v1', '/inscription', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => [self::class, 'inscrire']]);
            register_rest_route('bayam/v1', '/inscription/(?P<jeton>[\w-]{24,40})', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => [self::class, 'etat']]);
        });
        add_action('template_redirect', static function (): void {
            if (trim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/') === 'vendre') {
                status_header(200);
                nocache_headers();
                header('Content-Type: text/html; charset=utf-8');
                include dirname(__DIR__) . '/views/inscription.php';
                exit;
            }
        });
    }

    /** Boutique déjà inscrite avec ce numéro (une seule boutique par numéro). */
    public static function vendeur_du_numero(string $numero): int
    {
        $ids = get_users(['meta_key' => 'bayam_inscription_numero', 'meta_value' => $numero, 'fields' => 'ID', 'number' => 1]);
        return (int) ($ids[0] ?? 0);
    }

    public static function inscrire(WP_REST_Request $r)
    {
        // Toute tentative compte, valide ou non : un robot ne peut pas marteler le formulaire.
        $cle_debit = 'bayam_insc_' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $essais = (int) get_transient($cle_debit);
        if ($essais >= self::ESSAIS_PAR_HEURE) {
            return new WP_REST_Response(['erreur' => 'Trop de demandes depuis cette connexion. Réessayez dans une heure.'], 429);
        }
        set_transient($cle_debit, $essais + 1, HOUR_IN_SECONDS);
        if ((string) $r->get_param('site') !== '') {
            return new WP_REST_Response(['erreur' => 'Demande refusée.'], 400); // champ piège rempli : robot
        }
        $boutique = trim(sanitize_text_field((string) $r->get_param('boutique')));
        $prenom = trim(sanitize_text_field((string) $r->get_param('prenom')));
        $quartier = trim(sanitize_text_field((string) $r->get_param('quartier')));
        $mode = $r->get_param('mode') === 'facebook' ? 'facebook' : 'whatsapp';
        $numero = Bayam_WA_Outils::numero((string) $r->get_param('telephone'));
        if (mb_strlen($boutique) < 2 || mb_strlen($boutique) > 60 || $prenom === '' || mb_strlen($prenom) > 40 || !$numero) {
            return new WP_REST_Response(['erreur' => 'Indiquez le nom de la boutique, votre prénom et un numéro du Cameroun (6 chiffres et plus, par exemple 6 90 00 00 10).'], 400);
        }
        if (!$r->get_param('accepte')) {
            return new WP_REST_Response(['erreur' => "Merci d'accepter les conditions d'utilisation pour continuer."], 400);
        }
        $vendeur = self::vendeur_du_numero($numero) ?: self::creer($boutique, $prenom, $numero, $quartier);
        if (is_wp_error($vendeur)) {
            Bayam_WA_Outils::journal('Inscription impossible', ['erreur' => $vendeur->get_error_message()]);
            return new WP_REST_Response(['erreur' => 'La boutique n\'a pas pu être créée. Réessayez dans un instant.'], 500);
        }
        $jeton = wp_generate_password(32, false);
        set_transient('bayam_insc_jeton_' . $jeton, $vendeur, 30 * MINUTE_IN_SECONDS);

        if ($mode === 'facebook') {
            return ['jeton' => $jeton, 'mode' => 'facebook', 'lien' => Bayam_WA_Facebook::lien_connexion($vendeur)];
        }
        $session = Bayam_WA_Stockage::reglages($vendeur)['session'];
        $waha = Bayam_WA_Waha::client();
        $etat = $waha->session($session);
        if (!is_wp_error($etat) && ($etat['status'] ?? '') === 'WORKING') {
            return ['jeton' => $jeton, 'mode' => 'whatsapp', 'relie' => true];
        }
        Bayam_WA_Stockage::enregistrer($vendeur, ['consentement' => current_time('mysql')]);
        $demarrage = $waha->demarrer_session($session, $vendeur);
        // Le code ne peut être demandé qu'une fois la session prête à associer un téléphone (quelques secondes).
        for ($i = 0; !is_wp_error($demarrage) && $i < 8; $i++) {
            $etat = $waha->session($session);
            if (!is_wp_error($etat) && ($etat['status'] ?? '') === 'SCAN_QR_CODE') {
                break;
            }
            sleep(2);
        }
        $code = is_wp_error($demarrage) ? $demarrage : $waha->demander_code($session, $numero);
        if (is_wp_error($code) || empty($code['code'])) {
            return new WP_REST_Response(['erreur' => 'Le code WhatsApp n\'a pas pu être créé. Réessayez dans une minute.', 'jeton' => $jeton], 502);
        }
        return ['jeton' => $jeton, 'mode' => 'whatsapp', 'code' => (string) $code['code'], 'numero' => Bayam_WA_Outils::numero_lisible($numero)];
    }

    /** Suivi par la page : la boutique est-elle reliée à WhatsApp (ou à une Page Facebook) ? */
    public static function etat(WP_REST_Request $r)
    {
        $vendeur = (int) get_transient('bayam_insc_jeton_' . $r['jeton']);
        if (!$vendeur) {
            return new WP_REST_Response(['erreur' => 'Lien expiré. Recommencez l\'inscription.'], 404);
        }
        $etat = Bayam_WA_Waha::client()->session(Bayam_WA_Stockage::reglages($vendeur)['session']);
        $whatsapp = !is_wp_error($etat) && ($etat['status'] ?? '') === 'WORKING';
        return ['whatsapp' => $whatsapp, 'facebook' => (bool) Bayam_WA_Facebook::pages($vendeur), 'boutique' => Bayam_WA_Outils::nom_boutique($vendeur)];
    }

    /** Compte vendeur WCFM prêt à vendre (mêmes réglages que les boutiques de démonstration). */
    private static function creer(string $boutique, string $prenom, string $numero, string $quartier)
    {
        $id = wp_insert_user([
            'user_login' => 'v' . $numero,
            'user_pass' => wp_generate_password(24),
            'user_email' => $numero . '@vendeurs.bayam.local',
            'first_name' => $prenom,
            'display_name' => $boutique,
            'role' => 'wcfm_vendor',
        ]);
        if (is_wp_error($id)) {
            return $id;
        }
        $lieu = $quartier !== '' ? $quartier : 'Douala';
        $tel = '+' . $numero;
        update_user_meta($id, 'bayam_inscription_numero', $numero);
        update_user_meta($id, 'bayam_inscription_date', current_time('mysql'));
        update_user_meta($id, 'wcfmmp_store_name', $boutique);
        update_user_meta($id, 'wcfmmp_profile_settings', [
            'store_name' => $boutique, 'social' => [], 'payment' => [], 'phone' => $tel, 'show_email' => 'no',
            'address' => ['street_1' => $lieu, 'city' => 'Douala', 'country' => 'CM', 'state' => '', 'zip' => ''],
            'store_location' => $lieu, 'customer_support' => ['phone' => $tel, 'address1' => $lieu, 'city' => 'Douala', 'country' => 'CM'],
        ]);
        update_user_meta($id, '_wcfmmp_shipping', ['_wcfmmp_user_shipping_enable' => 'yes', '_wcfmmp_user_shipping_type' => 'by_zone']);
        Bayam_WA_Outils::journal('Nouvelle boutique inscrite', ['vendeur' => $id]);
        return $id;
    }
}
