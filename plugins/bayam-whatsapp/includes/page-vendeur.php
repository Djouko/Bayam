<?php
defined('ABSPATH') || exit;

/**
 * Rubrique « WhatsApp » de l'espace vendeur (WCFM) : connexion par QR code ou par code, consentement éclairé,
 * choix des groupes, réglages du Copilote, tableau de santé du canal, statistiques par groupe, lien unique et QR code.
 */
final class Bayam_WA_Page_Vendeur
{
    public const POINT = 'wcfm-whatsapp';

    public static function init(): void
    {
        add_filter('wcfm_query_vars', static fn ($v) => $v + [self::POINT => 'whatsapp']);
        add_filter('wcfm_endpoints_slug', static fn ($v) => $v + [self::POINT => 'whatsapp']);
        add_filter('wcfm_endpoint_title', static fn ($titre, $point) => $point === self::POINT ? 'WhatsApp' : $titre, 10, 2);
        add_action('init', static function () {
            global $WCFM_Query;
            if ($WCFM_Query && get_option('bayam_wa_point_wcfm') !== '1') {
                $WCFM_Query->init_query_vars();
                $WCFM_Query->add_endpoints();
                flush_rewrite_rules(false);
                update_option('bayam_wa_point_wcfm', '1');
            }
        }, 130);
        add_filter('wcfm_menus', [self::class, 'menu'], 40);
        add_action('wcfm_load_views', [self::class, 'vue'], 30);
        foreach (['connecter', 'etat', 'code', 'deconnecter', 'reglages', 'groupes', 'essai'] as $action) {
            add_action("wp_ajax_bayam_wa_{$action}", [self::class, "ajax_{$action}"]);
        }
    }

    public static function url(): string
    {
        return function_exists('wcfm_get_endpoint_url') ? wcfm_get_endpoint_url(self::POINT, '', get_wcfm_page()) : home_url('/store-manager/whatsapp/');
    }

    public static function menu(array $menus): array
    {
        if (!Bayam_WA_Outils::vendeur_courant()) {
            return $menus;
        }
        return array_slice($menus, 0, 1, true)
            + [self::POINT => ['label' => 'WhatsApp', 'url' => self::url(), 'icon' => 'comments', 'priority' => 2]]
            + array_slice($menus, 1, null, true);
    }

    public static function vue($point): void
    {
        if ($point !== self::POINT) {
            return;
        }
        $vendeur = Bayam_WA_Outils::vendeur_courant();
        if (!$vendeur) {
            echo '<p>Cette rubrique est réservée aux vendeurs.</p>';
            return;
        }
        include BAYAM_WA_DIR . 'views/page-whatsapp.php';
    }

    // --- Ajax (nonce et vendeur vérifiés à chaque appel) ---------------------------------------------------------

    private static function vendeur_ajax(): int
    {
        check_ajax_referer('bayam_wa', 'nonce');
        $vendeur = Bayam_WA_Outils::vendeur_courant();
        if (!$vendeur) {
            wp_send_json_error(['message' => 'Connectez-vous avec votre compte vendeur.'], 403);
        }
        return $vendeur;
    }

    public static function ajax_connecter(): void
    {
        $vendeur = self::vendeur_ajax();
        if (empty($_POST['consentement'])) {
            wp_send_json_error(['message' => "Lisez et acceptez les règles d'usage avant de connecter votre WhatsApp."]);
        }
        Bayam_WA_Stockage::enregistrer($vendeur, ['consentement' => current_time('mysql')]);
        $r = Bayam_WA_Waha::client()->demarrer_session(Bayam_WA_Stockage::reglages($vendeur)['session'], $vendeur);
        is_wp_error($r) ? wp_send_json_error(['message' => $r->get_error_message()]) : wp_send_json_success(self::etat($vendeur));
    }

    public static function ajax_etat(): void
    {
        wp_send_json_success(self::etat(self::vendeur_ajax(), true));
    }

    public static function ajax_code(): void
    {
        $vendeur = self::vendeur_ajax();
        $numero = Bayam_WA_Outils::numero(sanitize_text_field(wp_unslash($_POST['numero'] ?? '')));
        if (!$numero) {
            wp_send_json_error(['message' => 'Numéro WhatsApp invalide (exemple : 6 90 00 00 10).']);
        }
        $r = Bayam_WA_Waha::client()->demander_code(Bayam_WA_Stockage::reglages($vendeur)['session'], $numero);
        is_wp_error($r) ? wp_send_json_error(['message' => $r->get_error_message()]) : wp_send_json_success(['code' => $r['code'] ?? '']);
    }

    public static function ajax_deconnecter(): void
    {
        $vendeur = self::vendeur_ajax();
        Bayam_WA_Waha::client()->deconnecter(Bayam_WA_Stockage::reglages($vendeur)['session']);
        update_user_meta($vendeur, 'bayam_wa_statut', 'STOPPED');
        wp_send_json_success(self::etat($vendeur));
    }

    public static function ajax_groupes(): void
    {
        $vendeur = self::vendeur_ajax();
        $r = Bayam_WA_Waha::client()->groupes(Bayam_WA_Stockage::reglages($vendeur)['session']);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()]);
        }
        $moi = Bayam_WA_Outils::numero_de((string) get_user_meta($vendeur, 'bayam_wa_moi', true));
        $groupes = [];
        foreach ((array) $r as $cle => $g) {
            $id = (string) ($g['id'] ?? $cle);
            if (!str_contains($id, '@g.us')) {
                continue;
            }
            // Seuls les groupes où le vendeur est administrateur (publier ailleurs serait du démarchage).
            $admin = false;
            foreach ((array) ($g['participants'] ?? []) as $p) {
                $pid = is_array($p['id'] ?? null) ? ($p['id']['_serialized'] ?? '') : (string) ($p['id'] ?? ($p['jid'] ?? ''));
                if (Bayam_WA_Outils::numero_de($pid) === $moi && (!empty($p['isAdmin']) || !empty($p['isSuperAdmin']) || in_array($p['role'] ?? '', ['admin', 'superadmin'], true))) {
                    $admin = true;
                }
            }
            $groupes[] = ['id' => $id, 'nom' => (string) ($g['subject'] ?? ($g['name'] ?? $id)), 'admin' => $admin, 'membres' => count((array) ($g['participants'] ?? []))];
        }
        wp_send_json_success(['groupes' => $groupes, 'choisis' => Bayam_WA_Stockage::reglages($vendeur)['groupes']]);
    }

    public static function ajax_reglages(): void
    {
        $vendeur = self::vendeur_ajax();
        $groupes = [];
        foreach ((array) ($_POST['groupes'] ?? []) as $id => $nom) {
            if (preg_match('/^[\d-]+@g\.us$/', (string) $id)) {
                $groupes[(string) $id] = sanitize_text_field(wp_unslash($nom));
            }
        }
        Bayam_WA_Stockage::enregistrer($vendeur, [
            'groupes' => $groupes,
            'mots_cles' => !empty($_POST['mots_cles']),
            'rapport_actif' => !empty($_POST['rapport_actif']),
            'heure_rapport' => max(0, min(23, (int) ($_POST['heure_rapport'] ?? 20))),
        ]);
        wp_send_json_success(['message' => 'Réglages enregistrés.']);
    }

    public static function ajax_essai(): void
    {
        $vendeur = self::vendeur_ajax();
        Bayam_WA_Regles::au_vendeur($vendeur, "👋 Message d'essai envoyé depuis votre espace vendeur BAYAM. Votre Copilote est prêt : écrivez *menu*.");
        wp_send_json_success(['message' => "Message envoyé dans votre discussion « Message à moi-même »."]);
    }

    /** État du canal pour la page (et le tableau de santé). */
    public static function etat(int $vendeur, bool $avec_qr = false): array
    {
        $r = Bayam_WA_Stockage::reglages($vendeur);
        $session = Bayam_WA_Waha::client()->session($r['session']);
        $statut = is_wp_error($session) ? 'ABSENTE' : (string) ($session['status'] ?? 'INCONNU');
        // Session absente (jamais connectée) : on travaille sur un tableau vide plutôt que sur l'erreur.
        $session = is_array($session) ? $session : [];
        if (!is_wp_error($session) && $statut !== get_user_meta($vendeur, 'bayam_wa_statut', true)) {
            update_user_meta($vendeur, 'bayam_wa_statut', $statut);
        }
        if (!empty($session['me']['id'])) {
            update_user_meta($vendeur, 'bayam_wa_moi', $session['me']['id']);
        }
        $etat = [
            'statut' => $statut,
            'numero' => !empty($session['me']['id']) ? Bayam_WA_Outils::numero_lisible(Bayam_WA_Outils::numero_de($session['me']['id'])) : null,
            'nom' => $session['me']['pushName'] ?? null,
            'consentement' => $r['consentement'],
        ];
        if ($avec_qr && $statut === 'SCAN_QR_CODE') {
            $qr = Bayam_WA_Waha::client()->qr($r['session']);
            $etat['qr'] = is_wp_error($qr) ? null : 'data:' . ($qr['mimetype'] ?? 'image/png') . ';base64,' . ($qr['data'] ?? '');
        }
        if ($statut === 'WORKING') {
            $minuit = wp_date('Y-m-d 00:00:00');
            $etat['sante'] = [
                'reponses' => Bayam_WA_Stockage::envois_depuis($vendeur, $minuit, 'reponse') . ' / ' . Bayam_WA_Regles::PLAFONDS['reponse'],
                'diffusions' => Bayam_WA_Stockage::envois_depuis($vendeur, $minuit, 'diffusion') . ' / ' . Bayam_WA_Regles::PLAFONDS['diffusion'],
                'statuts' => Bayam_WA_Stockage::envois_depuis($vendeur, $minuit, 'statut') . ' / ' . Bayam_WA_Regles::PLAFONDS['statut'],
                'restreint' => Bayam_WA_Regles::restreint($vendeur),
            ];
            $plafond = Bayam_WA_Waha::client()->plafonds($r['session']);
            if (is_array($plafond) && isset($plafond['cappingStatus'])) {
                $etat['sante']['whatsapp'] = $plafond['cappingStatus'];
            }
        }
        return $etat;
    }
}
