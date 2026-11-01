<?php
defined('ABSPATH') || exit;

/**
 * Réception des événements WAHA : signature vérifiée, événement enregistré et acquitté tout de suite,
 * traitement en tâche de fond (Action Scheduler de WooCommerce), comme le demande le cahier des charges
 * (webhooks acquittés immédiatement puis traités de façon asynchrone).
 */
final class Bayam_WA_Webhook
{
    /** Âge maximal (secondes) d'un message auquel BAYAM répond encore. */
    private const FRAICHEUR = 900;

    public static function init(): void
    {
        add_action('rest_api_init', static function () {
            register_rest_route('bayam/v1', '/whatsapp/webhook', [
                'methods' => 'POST',
                'callback' => [self::class, 'recevoir'],
                'permission_callback' => [self::class, 'signature_valide'],
            ]);
        });
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
        // Même règle que la passerelle, si les événements arrivent sans elle : chaînes, statuts et listes de diffusion
        // écartés, et des groupes seuls les commentaires sous une publication de BAYAM, avant toute écriture.
        if ($evt['event'] === 'message.any' && !self::utile((array) ($evt['payload'] ?? []))) {
            return new WP_REST_Response(['ok' => true, 'ignore' => true], 200);
        }
        // Un même événement peut arriver deux fois (nouvel essai de WAHA) : son identifiant unique le dédoublonne.
        // Le travailleur lit cette table en continu : aucune autre file à alimenter, la réponse à WAHA reste immédiate.
        $insere = $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . Bayam_WA_Stockage::table('wa_evenements') . ' (evt_id, session, type, charge, recu) VALUES (%s, %s, %s, %s, %s)',
            mb_substr((string) ($evt['id'] ?? wp_generate_uuid4()), 0, 64), sanitize_key($evt['session']), sanitize_text_field((string) $evt['event']), $requete->get_body(), current_time('mysql')
        ));
        // Réveil immédiat des travailleurs (sans Redis, ils relisent la base toutes les demi-secondes).
        if ($insere && ($redis = Bayam_WA_Outils::redis())) {
            try {
                $redis->lPush(Bayam_WA_Outils::FILE_EVENEMENTS, '1');
                $redis->lTrim(Bayam_WA_Outils::FILE_EVENEMENTS, 0, 999);
            } catch (Throwable $e) {
                Bayam_WA_Outils::journal('Redis indisponible', ['erreur' => $e->getMessage()]);
            }
        }
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
        // Message de bienvenue une seule fois par connexion (les redémarrages du service ne le renvoient pas).
        if ($statut === 'WORKING' && !get_user_meta($vendeur, 'bayam_wa_bienvenue', true)) {
            update_user_meta($vendeur, 'bayam_wa_bienvenue', current_time('mysql'));
            Bayam_WA_Regles::au_vendeur($vendeur, "✅ *Votre WhatsApp est connecté à BAYAM.*\nCette discussion « Message à moi-même » est maintenant votre Copilote : écrivez *bayam menu* pour commencer.");
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
        // Discussion concernée : le moteur GOWS la donne dans _data.Info.Chat (et laisse « to » vide pour nos messages) ;
        // les autres moteurs dans « to » (message envoyé) ou « from » (message reçu).
        $info = (array) ($m['_data']['Info'] ?? []);
        $chat = (string) ($info['Chat'] ?? '');
        if ($chat === '') {
            $chat = !empty($m['fromMe']) ? (string) (($m['to'] ?? '') ?: ($m['from'] ?? '')) : (string) ($m['from'] ?? '');
        }
        $chat = str_replace('@s.whatsapp.net', '@c.us', $chat);
        // Groupes : seuls arrivent (par la passerelle) les commentaires sous une publication de BAYAM.
        if (str_contains($chat, '@g.us')) {
            if (empty($m['fromMe']) && time() - (int) ($m['timestamp'] ?? time()) <= self::FRAICHEUR) {
                self::commentaire_groupe($vendeur, $chat, $m, $texte, $id);
            }
            return;
        }
        // Statuts et chaînes ne pilotent rien.
        if ($chat === '' || str_contains($chat, '@newsletter') || str_starts_with($chat, 'status@')) {
            return;
        }
        // Seuls les messages qui commencent par « bayam » (ou la légende d'une photo) s'adressent à BAYAM.
        $demande = Bayam_WA_Outils::appel($texte);
        // Message ancien rejoué par WhatsApp à la reconnexion (après une coupure) : répondre des heures plus tard
        // surprendrait le client et répéterait une réponse déjà faite. On garde la trace, sans répondre.
        $frais = time() - (int) ($m['timestamp'] ?? time()) <= self::FRAICHEUR;
        if (!$frais) {
            $demande = null;
        }

        if (!empty($m['fromMe'])) {
            // « Message à moi-même » est le Copilote, mais le vendeur y garde aussi ses notes : sans « bayam », on se tait.
            if ($demande !== null && self::est_moi($vendeur, $chat)) {
                foreach (Bayam_WA_Copilote::traiter($vendeur, $demande, self::photo($m)) as $reponse) {
                    self::envoyer_reponse($vendeur, null, $reponse);
                }
            } elseif (!self::est_moi($vendeur, $chat)) {
                // Le vendeur écrit lui-même à un client : l'assistant s'efface de cette discussion.
                Bayam_WA_Assistant::vendeur_intervient($vendeur, $chat);
            }
            return;
        }

        // Numéro de téléphone réel du client (WhatsApp ne montre souvent qu'un identifiant interne « LID »).
        $numero = Bayam_WA_Outils::numero_de((string) (($info['SenderAlt'] ?? '') ?: $chat));
        $reglages = Bayam_WA_Stockage::reglages($vendeur);
        // Membre de l'équipe : mêmes commandes que le vendeur, précédées de « bayam » (ou de « / », forme courte).
        $commande = $demande ?? (str_starts_with(trim($texte), '/') ? ltrim(trim($texte), '/') : null);
        $equipe = $commande !== null && in_array($numero, $reglages['equipe'], true);
        // Vie privée : on garde la trace qu'un client a écrit (jamais de premier contact sinon), mais le texte
        // seulement s'il s'adresse à BAYAM (commande d'équipe, demande d'article).
        // Réponse à une carte, un statut ou une liste de BAYAM (« Dispo en L ? ») : le message parle de ces articles.
        $cites = $reglages['mots_cles'] && !empty($m['replyTo']['body']) ? Bayam_WA_Clients::produits_cites($vendeur, (string) $m['replyTo']['body']) : [];
        // Assistant actif (vendeur occupé) et discussion que le vendeur n'a pas reprise : le texte sert au fil de la
        // conversation ; s'il s'avère personnel, l'assistant l'efface aussitôt.
        $assistant = !$equipe && $frais && Bayam_WA_Assistant::actif($vendeur) && !Bayam_WA_Assistant::vendeur_a_la_main($vendeur, $chat);
        $pour_bayam = $equipe || $assistant || $cites || ($reglages['mots_cles'] && Bayam_WA_Clients::recherche($texte) !== null);
        Bayam_WA_Stockage::noter_message($vendeur, $chat, 'in', $pour_bayam ? $texte : '', $id !== '' ? $id : null, 'texte', $numero);
        if ($equipe) {
            foreach (Bayam_WA_Copilote::traiter($vendeur, $commande, self::photo($m), true) as $reponse) {
                self::envoyer_reponse($vendeur, $chat, $reponse);
            }
            return;
        }
        if ($assistant && Bayam_WA_Assistant::message($vendeur, $chat, $numero, $texte, $id !== '' ? $id : null, $cites)) {
            return;
        }
        if ($frais) {
            Bayam_WA_Clients::message($vendeur, $chat, $texte, $id !== '' ? $id : null, $cites);
        }
    }

    /**
     * Commentaire sous une publication BAYAM dans un groupe (« prix ? », « dispo en L ? », « je prends ») :
     * l'assistant y répond en citant le commentaire et invite à continuer en privé ; sans assistant, le vendeur est
     * prévenu dans « Message à moi-même », une fois par personne et par article (pas d'avalanche d'alertes).
     */
    private static function commentaire_groupe(int $vendeur, string $groupe, array $m, string $texte, string $id): void
    {
        $cite = (string) ($m['replyTo']['body'] ?? '');
        $produits = str_starts_with($cite, Bayam_WA_Outils::MARQUE) ? Bayam_WA_Clients::produits_cites($vendeur, $cite) : [];
        if (!$produits) {
            return;
        }
        // Numéro de téléphone de l'auteur (et non son identifiant interne « LID ») pour le lien de réponse.
        $info = (array) ($m['_data']['Info'] ?? []);
        $auteur = '';
        foreach ([$info['SenderAlt'] ?? '', $info['Sender'] ?? '', $m['participant'] ?? ''] as $candidat) {
            if (preg_match('/@(s\.whatsapp\.net|c\.us)$/', (string) $candidat)) {
                $auteur = Bayam_WA_Outils::numero_de((string) $candidat);
                break;
            }
        }
        $nom_groupe = Bayam_WA_Stockage::reglages($vendeur)['groupes'][$groupe] ?? 'un de vos groupes';
        if (Bayam_WA_Assistant::actif($vendeur) && Bayam_WA_Assistant::disponible()) {
            Bayam_WA_Assistant::commentaire($vendeur, $groupe, $nom_groupe, $texte, $id !== '' ? $id : null, $produits);
            return;
        }
        $cle = 'bayam_wa_comm_' . md5($vendeur . '|' . ($auteur ?: $texte) . '|' . $produits[0]->get_id());
        if (get_transient($cle)) {
            return;
        }
        set_transient($cle, 1, HOUR_IN_SECONDS);
        Bayam_WA_Regles::au_vendeur($vendeur, '💬 *Commentaire dans « ' . $nom_groupe . ' »* sur ' . $produits[0]->get_name() . " :\n« " . mb_substr($texte, 0, 200) . ' »'
            . ($auteur !== '' ? ' de ' . Bayam_WA_Outils::numero_lisible($auteur) . "\nRépondre en privé : https://wa.me/{$auteur}" : '')
            . "\n*bayam je suis occupé* pour que votre assistant réponde à votre place.");
    }

    /** Message utile à BAYAM : privé, ou commentaire de groupe qui répond à une publication de BAYAM. */
    public static function utile(array $p): bool
    {
        $chat = (string) (($p['_data']['Info']['Chat'] ?? '') ?: ($p['from'] ?? ''));
        if (preg_match('/@newsletter|@broadcast|^status@/', $chat)) {
            return false;
        }
        if (str_contains($chat, '@g.us')) {
            return empty($p['fromMe']) && str_starts_with((string) ($p['replyTo']['body'] ?? ''), Bayam_WA_Outils::MARQUE);
        }
        return true;
    }

    /** Photo jointe au message, téléchargée seulement quand le message s'adresse à BAYAM. */
    private static function photo(array $m): ?array
    {
        if (empty($m['hasMedia']) || empty($m['media']['url']) || !str_starts_with((string) ($m['media']['mimetype'] ?? 'image/'), 'image/')) {
            return null;
        }
        $binaire = Bayam_WA_Waha::client()->telecharger((string) $m['media']['url']);
        return is_wp_error($binaire) ? null : ['binaire' => $binaire];
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
