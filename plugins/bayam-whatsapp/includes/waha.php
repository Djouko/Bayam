<?php
defined('ABSPATH') || exit;

/**
 * Client de l'API WAHA (WhatsApp HTTP API, connecteur libre). Routes et formats repris de la description OpenAPI
 * de WAHA 2026.10.1 (moteur GOWS). Chaque appel rend un tableau décodé ou un WP_Error explicite.
 */
class Bayam_WA_Waha
{
    private static ?Bayam_WA_Waha $instance = null;

    /** Remplaçable dans les essais automatiques (client factice qui enregistre les envois). */
    public static function client(): Bayam_WA_Waha
    {
        return self::$instance ??= new self();
    }

    public static function remplacer(?Bayam_WA_Waha $client): void
    {
        self::$instance = $client;
    }

    protected function url(): string
    {
        return rtrim(getenv('WAHA_URL') ?: 'http://waha:3000', '/');
    }

    /** @return array|WP_Error */
    /**
     * Pendant une reconnexion à WhatsApp (coupure réseau, quelques secondes), WAHA refuse les envois : on réessaie
     * après 3, puis 8 secondes, plutôt que de perdre la réponse au client.
     */
    protected function appel(string $methode, string $chemin, ?array $corps = null, array $requete = [], int $delai = 20)
    {
        $r = $this->appel_une_fois($methode, $chemin, $corps, $requete, $delai);
        foreach ([3, 8] as $pause) {
            if (!is_wp_error($r) || $methode !== 'POST' || !str_contains($r->get_error_message(), 'Session status is not as expected')) {
                break;
            }
            sleep($pause);
            $r = $this->appel_une_fois($methode, $chemin, $corps, $requete, $delai);
        }
        return $r;
    }

    private function appel_une_fois(string $methode, string $chemin, ?array $corps, array $requete, int $delai)
    {
        $url = $this->url() . $chemin . ($requete ? '?' . http_build_query($requete) : '');
        $reponse = wp_remote_request($url, [
            'method' => $methode,
            'timeout' => $delai,
            'headers' => array_filter([
                'X-Api-Key' => getenv('WAHA_API_KEY') ?: '',
                'Content-Type' => $corps !== null ? 'application/json' : null,
                'Accept' => 'application/json',
            ]),
            'body' => $corps !== null ? wp_json_encode($corps) : null,
        ]);
        if (is_wp_error($reponse)) {
            Bayam_WA_Outils::journal('WAHA injoignable', ['chemin' => $chemin, 'erreur' => $reponse->get_error_message()]);
            return new WP_Error('waha_injoignable', 'Le service WhatsApp ne répond pas. Réessayez dans un instant.');
        }
        $code = wp_remote_retrieve_response_code($reponse);
        $type = wp_remote_retrieve_header($reponse, 'content-type');
        $brut = wp_remote_retrieve_body($reponse);
        if ($code >= 400) {
            $detail = json_decode($brut, true);
            Bayam_WA_Outils::journal('WAHA erreur', ['chemin' => $chemin, 'code' => $code, 'corps' => mb_substr($brut, 0, 500)]);
            return new WP_Error('waha_' . $code, is_array($detail) && isset($detail['message']) ? (is_array($detail['message']) ? implode(' ', $detail['message']) : $detail['message']) : "Erreur WhatsApp ($code).", ['code' => $code]);
        }
        if ($type && str_starts_with($type, 'image/')) {
            return ['mimetype' => $type, 'data' => base64_encode($brut)];
        }
        $donnees = json_decode($brut, true);
        return is_array($donnees) ? $donnees : ['brut' => $brut];
    }

    // --- Sessions -------------------------------------------------------------------------------------------

    /** Crée (ou met à jour) puis démarre la session WhatsApp du vendeur, avec son webhook signé. */
    public function demarrer_session(string $session, int $vendeur)
    {
        $config = [
            'metadata' => ['bayam.vendeur' => (string) $vendeur],
            'client' => ['deviceName' => 'BAYAM', 'browserName' => 'Chrome'],
            // Messages de groupes, statuts, chaînes et listes de diffusion : jamais lus par BAYAM, donc jamais envoyés
            // (un commerçant actif est dans des centaines de groupes : c'est l'essentiel du trafic).
            'ignore' => ['status' => true, 'channels' => true, 'groups' => true, 'broadcast' => true],
            'webhooks' => [[
                'url' => bayam_wa_url_interne('/wp-json/bayam/v1/whatsapp/webhook'),
                'events' => ['message.any', 'session.status'],
                'hmac' => ['key' => getenv('WAHA_WEBHOOK_SECRET') ?: ''],
                'retries' => ['policy' => 'constant', 'delaySeconds' => 3, 'attempts' => 5],
            ]],
        ];
        $existe = $this->appel('GET', "/api/sessions/$session");
        if (is_wp_error($existe)) {
            return $this->appel('POST', '/api/sessions', ['name' => $session, 'start' => true, 'config' => $config]);
        }
        $maj = $this->appel('PUT', "/api/sessions/$session", ['name' => $session, 'config' => $config]);
        if (is_wp_error($maj)) {
            return $maj;
        }
        return in_array($existe['status'] ?? '', ['WORKING', 'SCAN_QR_CODE', 'STARTING'], true) ? $existe : $this->appel('POST', "/api/sessions/$session/start");
    }

    public function session(string $session)
    {
        return $this->appel('GET', "/api/sessions/$session");
    }

    public function qr(string $session)
    {
        return $this->appel('GET', "/api/$session/auth/qr", null, ['format' => 'image']);
    }

    /** Appairage par code à 8 caractères, pour qui ne peut pas scanner (un seul téléphone). */
    public function demander_code(string $session, string $numero)
    {
        return $this->appel('POST', "/api/$session/auth/request-code", ['phoneNumber' => $numero]);
    }

    public function deconnecter(string $session)
    {
        return $this->appel('POST', "/api/sessions/$session/logout");
    }

    public function plafonds(string $session)
    {
        return $this->appel('GET', "/api/sessions/$session/capping");
    }

    public function blocage(string $session)
    {
        return $this->appel('GET', "/api/sessions/$session/timelock");
    }

    // --- Messages --------------------------------------------------------------------------------------------

    public function texte(string $session, string $chat, string $texte)
    {
        return $this->appel('POST', '/api/sendText', ['session' => $session, 'chatId' => $chat, 'text' => $texte, 'linkPreview' => true]);
    }

    /** $fichier : ['mimetype' => ..., 'data' => base64] ou ['mimetype' => ..., 'url' => ...]. */
    public function image(string $session, string $chat, array $fichier, string $legende = '')
    {
        return $this->appel('POST', '/api/sendImage', ['session' => $session, 'chatId' => $chat, 'file' => $fichier, 'caption' => $legende], [], 40);
    }

    public function vu(string $session, string $chat, ?string $message_id = null)
    {
        return $this->appel('POST', '/api/sendSeen', array_filter(['session' => $session, 'chatId' => $chat, 'messageId' => $message_id]));
    }

    public function frappe(string $session, string $chat, bool $commence)
    {
        return $this->appel('POST', $commence ? '/api/startTyping' : '/api/stopTyping', ['session' => $session, 'chatId' => $chat]);
    }

    public function discussions(string $session, int $limite = 100)
    {
        return $this->appel('GET', "/api/$session/chats", null, ['limit' => $limite, 'sortBy' => 'conversationTimestamp', 'sortOrder' => 'desc']);
    }

    // --- Groupes et statut ------------------------------------------------------------------------------------

    public function groupes(string $session, int $limite = 100, int $decalage = 0)
    {
        return $this->appel('GET', "/api/$session/groups", null, ['limit' => $limite, 'offset' => $decalage, 'sortBy' => 'subject', 'sortOrder' => 'asc'], 40);
    }

    /** $contacts vide : statut vu par tous les contacts ; sinon, seulement par ces numéros. */
    public function statut_image(string $session, array $fichier, string $legende = '', array $contacts = [])
    {
        $corps = ['file' => $fichier, 'caption' => $legende];
        if ($contacts) {
            $corps['contacts'] = array_map([Bayam_WA_Outils::class, 'chat_id'], $contacts);
        }
        return $this->appel('POST', "/api/$session/status/image", $corps, [], 40);
    }

    // --- Médias reçus ------------------------------------------------------------------------------------------

    /** Télécharge un média reçu (adresse fournie par WAHA) : contenu binaire ou WP_Error. */
    public function telecharger(string $url)
    {
        // WAHA donne une adresse vue de l'extérieur (localhost:3000) : on passe par le réseau Docker.
        $url = preg_replace('#^https?://[^/]+#', $this->url(), $url);
        $reponse = wp_remote_get($url, ['timeout' => 40, 'headers' => ['X-Api-Key' => getenv('WAHA_API_KEY') ?: '']]);
        if (is_wp_error($reponse) || wp_remote_retrieve_response_code($reponse) >= 400) {
            return new WP_Error('media', 'Impossible de récupérer la photo envoyée.');
        }
        return wp_remote_retrieve_body($reponse);
    }
}

/** Adresse de WordPress vue depuis le conteneur WAHA (réseau Docker), et non depuis le navigateur. */
function bayam_wa_url_interne(string $chemin): string
{
    $base = getenv('BAYAM_WP_URL_INTERNE') ?: 'http://wordpress';
    return rtrim($base, '/') . $chemin;
}
