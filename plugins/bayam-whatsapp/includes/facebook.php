<?php
/**
 * Lien avec le service canal Facebook (Pages des vendeurs, API officielle de Meta) :
 * - lien de connexion signé que le vendeur ouvre pour relier sa Page (« bayam facebook ») ;
 * - Pages reliées, publications et stories demandées depuis le Copilote ;
 * - liens personnels de commande ouverts depuis Messenger, confirmation de la commande dans Messenger.
 * Toutes les adresses /interne/ exigent le jeton de service et ne sont joignables que du réseau interne.
 */

defined('ABSPATH') || exit;

final class Bayam_WA_Facebook
{
    /** Validité du lien de connexion envoyé au vendeur. */
    private const VALIDITE_CONNEXION = 30 * MINUTE_IN_SECONDS;

    /** Service simulé pendant les essais automatiques : aucune requête ne part vers une vraie Page. */
    private static $simulation = null;

    /** @param callable|null $simulation fn(string $methode, string $chemin, ?array $corps): ?array */
    public static function remplacer(?callable $simulation): void
    {
        self::$simulation = $simulation;
    }

    /** Lien signé (jeton de service) qui ouvre la fenêtre Facebook où le vendeur choisit sa Page. */
    public static function lien_connexion(int $vendeur): string
    {
        $echeance = time() + self::VALIDITE_CONNEXION;
        $signature = hash_hmac('sha256', "facebook:{$vendeur}.{$echeance}", (string) getenv('BAYAM_CLE_LIENS'));
        return untrailingslashit(Bayam_WA_Liens::base()) . '/facebook/connexion?' . http_build_query(['v' => $vendeur, 'e' => $echeance, 's' => $signature]);
    }

    /**
     * Lien qui fait du compte Messenger qui l'ouvre l'espace vendeur de la boutique (menu et commandes de BAYAM dans
     * Messenger) : ouvre la discussion avec la Page, signé par le jeton de service, valable 7 jours.
     */
    public static function lien_espace(int $vendeur, string $page): string
    {
        $echeance = time() + DAY_IN_SECONDS;
        $signature = substr(hash_hmac('sha256', "fbvendeur:{$vendeur}.{$echeance}", (string) getenv('BAYAM_CLE_LIENS')), 0, 32);
        return "https://m.me/{$page}?ref=vendeur-{$vendeur}-{$echeance}-{$signature}";
    }

    /**
     * Pages Facebook reliées au vendeur ([['page' => …, 'nom' => …, 'actif' => bool]]), gardées une minute ;
     * null si le service ne répond pas.
     */
    public static function pages(int $vendeur): ?array
    {
        $cle = "bayam_fb_pages_{$vendeur}";
        $connues = wp_cache_get($cle, 'bayam');
        if (is_array($connues)) {
            return $connues;
        }
        $r = self::appel('GET', "/interne/vendeurs/{$vendeur}/pages");
        if (!is_array($r)) {
            return null;
        }
        wp_cache_set($cle, $r, 'bayam', MINUTE_IN_SECONDS);
        return $r;
    }

    /**
     * Publication (photo ou album) ou stories sur les Pages reliées ; renvoie le message pour le vendeur.
     *
     * @param WC_Product[] $produits
     */
    public static function publier(int $vendeur, array $produits, bool $story = false): string
    {
        $pages = array_values(array_filter(self::pages($vendeur) ?? [], static fn ($p) => !empty($p['actif'])));
        if (!$pages) {
            return "📘 Aucune Page Facebook reliée à BAYAM : écrivez *bayam facebook* pour relier la vôtre en une minute.";
        }
        $ids = array_map(static fn (WC_Product $p) => $p->get_id(), array_slice($produits, 0, 10));
        $r = self::appel('POST', '/interne/publications', ['vendeur' => $vendeur, 'produits' => $ids, 'type' => $story ? 'story' : 'publication']);
        if (!is_array($r)) {
            return '📘 Facebook ne répond pas pour le moment : réessayez dans quelques minutes.';
        }
        $noms = implode(', ', array_map(static fn ($p) => '« ' . $p['nom'] . ' »', $pages));
        return $story
            ? '📘 ' . count($ids) . " story(s) en préparation pour {$noms} (visuel avec le prix, visible 24 h). Je vous préviens dès que c'est en ligne."
            : '📘 Publication en préparation sur ' . $noms . ' : ' . (count($ids) > 1 ? count($ids) . ' articles en un album numéroté' : 'photo, prix et lien de commande')
                . ". Chaque commentaire « prix ? » recevra une réponse privée. Je vous envoie le lien dès que c'est en ligne.";
    }

    /** Retire une Page de BAYAM (choisie par son nom, sans tenir compte des accents ni des majuscules) ; message pour le vendeur. */
    public static function retirer(int $vendeur, string $nom): string
    {
        $voulu = Bayam_WA_Outils::normaliser($nom);
        $trouvees = array_values(array_filter(self::pages($vendeur) ?? [], static fn ($p) => $voulu !== '' && str_contains(Bayam_WA_Outils::normaliser((string) $p['nom']), $voulu)));
        if (count($trouvees) !== 1) {
            $noms = implode(', ', array_map(static fn ($p) => '« ' . $p['nom'] . ' »', self::pages($vendeur) ?? []));
            return $trouvees ? "Plusieurs Pages correspondent à « {$nom} » : précisez le nom ({$noms})." : "Aucune Page reliée ne s'appelle « {$nom} »." . ($noms ? " Pages reliées : {$noms}." : '');
        }
        $r = self::appel('DELETE', "/interne/vendeurs/{$vendeur}/pages/" . rawurlencode((string) $trouvees[0]['page']));
        wp_cache_delete("bayam_fb_pages_{$vendeur}", 'bayam');
        return is_array($r) ? "✅ Page « {$trouvees[0]['nom']} » retirée de BAYAM : plus aucune réponse automatique sur cette Page, et son message d'accueil Messenger est effacé." : '📘 Facebook ne répond pas pour le moment : réessayez dans quelques minutes.';
    }

    /** Lien personnel de commande créé dans Messenger : même forme que ceux du canal WhatsApp, plus le canal et la Page. */
    public static function jeton(string $jeton): ?array
    {
        $d = self::appel('GET', '/interne/jetons/' . rawurlencode($jeton));
        return is_array($d) && isset($d['produit'], $d['vendeur'], $d['chat'], $d['numero'], $d['page']) ? $d : null;
    }

    /** Confirmation de commande déposée auprès du canal, envoyée dans la discussion Messenger du client. */
    public static function confirmer(string $cle, string $page, string $client, string $texte): bool
    {
        return is_array(self::appel('POST', '/interne/notifications', ['cle' => $cle, 'page' => $page, 'client' => $client, 'texte' => $texte], 3));
    }

    /** Réponse JSON décodée, ou null (service injoignable, refus) avec une trace dans le journal. */
    private static function appel(string $methode, string $chemin, ?array $corps = null, int $delai = 5): ?array
    {
        if (self::$simulation) {
            return (self::$simulation)($methode, $chemin, $corps);
        }
        $r = wp_remote_request(rtrim((string) (getenv('BAYAM_CANAL_FACEBOOK_URL') ?: 'http://canal-facebook:8092'), '/') . $chemin, [
            'method' => $methode,
            'timeout' => $delai,
            // Type JSON annoncé seulement avec un corps : le service refuse un corps JSON vide (GET, DELETE).
            'headers' => ['x-bayam-service' => (string) getenv('BAYAM_SERVICE_JETON')] + ($corps === null ? [] : ['content-type' => 'application/json']),
            'body' => $corps === null ? null : wp_json_encode($corps),
        ]);
        $statut = is_wp_error($r) ? 0 : wp_remote_retrieve_response_code($r);
        if ($statut === 404) {
            return null;
        }
        if ($statut < 200 || $statut >= 300) {
            Bayam_WA_Outils::journal('Canal Facebook injoignable', ['chemin' => preg_replace('#/jetons/.*#', '/jetons/…', $chemin), 'erreur' => is_wp_error($r) ? $r->get_error_message() : $statut]);
            return null;
        }
        $d = json_decode(wp_remote_retrieve_body($r), true);
        return is_array($d) ? $d : null;
    }
}
