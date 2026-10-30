<?php
defined('ABSPATH') || exit;

/**
 * Réception des événements WAHA : signature vérifiée, événement enregistré et acquitté tout de suite,
 * traitement en tâche de fond (Action Scheduler de WooCommerce), comme le demande le cahier des charges
 * (webhooks acquittés immédiatement puis traités de façon asynchrone).
 */
final class Bayam_WA_Webhook
{
    public static function init(): void
    {
        add_action('rest_api_init', static function () {
            register_rest_route('bayam/v1', '/whatsapp/webhook', [
                'methods' => 'POST',
                'callback' => [self::class, 'recevoir'],
                'permission_callback' => [self::class, 'signature_valide'],
            ]);
        });
        add_action('bayam_wa_evenement', [self::class, 'traiter'], 10, 1);
    }

    /** Signature HMAC de WAHA (en-têtes X-Webhook-Hmac et X-Webhook-Hmac-Algorithm) sur le corps brut. */
    public static function signature_valide(WP_REST_Request $requete): bool
    {
        $secret = getenv('WAHA_WEBHOOK_SECRET') ?: '';
        $recue = (string) $requete->get_header('x_webhook_hmac');
        if ($secret === '' || $recue === '') {
            return false;
        }
        $algo = strtolower((string) ($requete->get_header('x_webhook_hmac_algorithm') ?: 'sha512'));
        if (!in_array($algo, ['sha512', 'sha256'], true)) {
            return false;
        }
        return hash_equals(hash_hmac($algo, $requete->get_body(), $secret), $recue);
    }

    public static function recevoir(WP_REST_Request $requete): WP_REST_Response
    {
        global $wpdb;
        $evt = json_decode($requete->get_body(), true);
        if (!is_array($evt) || empty($evt['session']) || empty($evt['event'])) {
            return new WP_REST_Response(['ok' => false], 400);
        }
        $wpdb->insert(Bayam_WA_Stockage::table('wa_evenements'), [
            'session' => sanitize_key($evt['session']),
            'type' => sanitize_text_field((string) $evt['event']),
            'charge' => $requete->get_body(),
            'recu' => current_time('mysql'),
        ]);
        as_enqueue_async_action('bayam_wa_evenement', [(int) $wpdb->insert_id], 'bayam-whatsapp');
        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function traiter(int $id): void
    {
        global $wpdb;
        $table = Bayam_WA_Stockage::table('wa_evenements');
        // Réservation atomique : le travailleur et la file de WordPress ne traitent jamais deux fois le même événement.
        if (!$wpdb->query($wpdb->prepare("UPDATE $table SET traite = 1 WHERE id = %d AND traite = 0", $id))) {
            return;
        }
        $ligne = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
        try {
            self::evenement(json_decode($ligne->charge, true));
        } catch (Throwable $e) {
            $wpdb->update($table, ['erreur' => mb_substr($e->getMessage(), 0, 500)], ['id' => $id]);
            Bayam_WA_Outils::journal('Événement non traité', ['id' => $id, 'erreur' => $e->getMessage()]);
        }
    }

    /** Traitement d'un événement décodé (appelé aussi par les essais automatiques). */
    public static function evenement(array $evt): void
    {
        $vendeur = Bayam_WA_Stockage::vendeur_de_session((string) $evt['session']);
        if (!$vendeur) {
            return;
        }
        if (!empty($evt['me']['id'])) {
            update_user_meta($vendeur, 'bayam_wa_moi', $evt['me']['id']);
            if (!empty($evt['me']['lid'])) {
                update_user_meta($vendeur, 'bayam_wa_lid', $evt['me']['lid']);
            }
        }
        if ($evt['event'] === 'session.status') {
            self::statut_session($vendeur, (string) ($evt['payload']['status'] ?? ''));
            return;
        }
        if (in_array($evt['event'], ['message', 'message.any'], true)) {
            self::message($vendeur, (array) $evt['payload']);
        }
    }

    private static function statut_session(int $vendeur, string $statut): void
    {
        $avant = get_user_meta($vendeur, 'bayam_wa_statut', true);
        update_user_meta($vendeur, 'bayam_wa_statut', $statut);
        update_user_meta($vendeur, 'bayam_wa_statut_depuis', current_time('mysql'));
        if ($statut === 'WORKING' && $avant !== 'WORKING') {
            Bayam_WA_Regles::au_vendeur($vendeur, "✅ *Votre WhatsApp est connecté à BAYAM.*\nCette discussion « Message à moi-même » est maintenant votre Copilote : écrivez *menu* pour commencer.");
        }
        // Surveillance des déconnexions : WhatsApp n'est plus joignable, on prévient par e-mail avec la marche à suivre.
        if ($avant === 'WORKING' && in_array($statut, ['FAILED', 'STOPPED'], true)) {
            $email = get_the_author_meta('user_email', $vendeur);
            if ($email) {
                wp_mail($email, 'BAYAM : votre WhatsApp est déconnecté', "Bonjour,\n\nVotre WhatsApp n'est plus relié à BAYAM : le Copilote, les réponses automatiques et les publications sont en pause.\n"
                    . "Vos clients peuvent toujours commander avec vos liens et vos cartes.\n\nPour reconnecter : espace vendeur BAYAM, rubrique WhatsApp, puis « Afficher le QR code » ("
                    . Bayam_WA_Page_Vendeur::url() . ").\n\nL'équipe BAYAM");
            }
        }
    }

    private static function message(int $vendeur, array $m): void
    {
        $texte = (string) ($m['body'] ?? '');
        $id = is_array($m['id'] ?? null) ? (string) ($m['id']['_serialized'] ?? '') : (string) ($m['id'] ?? '');
        // Nos propres envois reviennent aussi en événement : on les ignore (marque invisible ou identifiant connu).
        if (str_starts_with($texte, Bayam_WA_Outils::MARQUE) || ($id !== '' && Bayam_WA_Stockage::est_notre_message($id))) {
            return;
        }
        $de = (string) ($m['from'] ?? '');
        $a = (string) ($m['to'] ?? '');
        $chat = !empty($m['fromMe']) ? $a : $de;
        // Statuts, groupes et chaînes ne pilotent rien.
        if ($chat === '' || str_contains($chat, '@g.us') || str_contains($chat, '@newsletter') || str_starts_with($chat, 'status@')) {
            return;
        }
        $photo = null;
        if (!empty($m['hasMedia']) && !empty($m['media']['url']) && str_starts_with((string) ($m['media']['mimetype'] ?? 'image/'), 'image/')) {
            $binaire = Bayam_WA_Waha::client()->telecharger((string) $m['media']['url']);
            $photo = is_wp_error($binaire) ? null : ['binaire' => $binaire];
        }

        if (!empty($m['fromMe'])) {
            // Le vendeur écrit dans « Message à moi-même » : c'est le Copilote.
            if (self::est_moi($vendeur, $chat)) {
                foreach (Bayam_WA_Copilote::traiter($vendeur, $texte, $photo) as $reponse) {
                    self::envoyer_reponse($vendeur, null, $reponse);
                }
            }
            return; // Messages du vendeur à ses clients : rien à faire.
        }

        Bayam_WA_Stockage::noter_message($vendeur, $chat, 'in', $texte, $id !== '' ? $id : null);
        $numero = Bayam_WA_Outils::numero_de($chat);
        $reglages = Bayam_WA_Stockage::reglages($vendeur);
        // Membre de l'équipe : ses commandes commencent par « / » (« /stock robe rouge ») pour ne pas confondre
        // avec une conversation normale.
        if (in_array($numero, $reglages['equipe'], true) && (str_starts_with(trim($texte), '/') || $photo)) {
            foreach (Bayam_WA_Copilote::traiter($vendeur, ltrim(trim($texte), '/'), $photo, true) as $reponse) {
                self::envoyer_reponse($vendeur, $chat, $reponse);
            }
            return;
        }
        Bayam_WA_Clients::message($vendeur, $chat, $texte, $id !== '' ? $id : null);
    }

    /** La discussion est-elle « Message à moi-même » ? (numéro du vendeur, ou son identifiant LID) */
    public static function est_moi(int $vendeur, string $chat): bool
    {
        $num = Bayam_WA_Outils::numero_de($chat);
        foreach (['bayam_wa_moi', 'bayam_wa_lid'] as $cle) {
            $moi = (string) get_user_meta($vendeur, $cle, true);
            if ($moi !== '' && Bayam_WA_Outils::numero_de($moi) === $num) {
                return true;
            }
        }
        return false;
    }

    /** Réponse du Copilote : au vendeur (null) ou au membre de l'équipe qui a écrit. */
    private static function envoyer_reponse(int $vendeur, ?string $chat, array $reponse): void
    {
        if ($chat === null) {
            isset($reponse['image'])
                ? Bayam_WA_Regles::image_au_vendeur($vendeur, $reponse['image'], $reponse['legende'] ?? '')
                : Bayam_WA_Regles::au_vendeur($vendeur, $reponse['texte']);
            return;
        }
        isset($reponse['image'])
            ? Bayam_WA_Regles::repondre_image($vendeur, $chat, $reponse['image'], $reponse['legende'] ?? '', 'copilote')
            : Bayam_WA_Regles::repondre($vendeur, $chat, $reponse['texte'], null, 'copilote');
    }
}
