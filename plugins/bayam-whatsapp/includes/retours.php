<?php
/**
 * Retours des utilisateurs dans WordPress : transmission au service des retours (signalements du Copilote vendeur)
 * et tableau de bord de l'équipe dans l'administration (menu « Retours »). Les données vivent dans le service
 * (doublons regroupés en sujets, urgence, historique) ; WordPress n'en garde aucune copie.
 */

defined('ABSPATH') || exit;

final class Bayam_WA_Retours
{
    public const STATUTS = ['nouveau' => 'Nouveau', 'confirme' => 'Confirmé', 'en_cours' => 'En cours', 'corrige' => 'Corrigé', 'ferme' => 'Fermé', 'rejete' => 'Rejeté', 'doublon' => 'Doublon'];
    public const CATEGORIES = [
        'securite' => 'Sécurité', 'paiement' => 'Paiement', 'livraison' => 'Livraison', 'commande' => 'Commande', 'connexion' => 'Connexion',
        'whatsapp' => 'WhatsApp', 'facebook' => 'Facebook', 'application' => 'Application', 'photos' => 'Photos', 'disponibilite' => 'Panne détectée', 'autre' => 'Autre',
    ];
    public const GRAVITES = [1 => ['Basse', '#64748b'], 2 => ['Moyenne', '#0ea5e9'], 3 => ['Haute', '#f59e0b'], 4 => ['Critique', '#dc2626']];
    public const TYPES = ['souci' => 'Souci', 'idee' => 'Idée', 'question' => 'Question', 'incident' => 'Panne'];
    public const INDISPONIBLE = "Votre message n'a pas pu être enregistré pour l'instant. Réessayez dans quelques minutes 🙏";
    private const PAGE = 'bayam-retours';

    /** @var callable|null Essais : remplace le service des retours. */
    private static $simulation = null;

    public static function remplacer(?callable $simulation): void
    {
        self::$simulation = $simulation;
    }

    public static function init(): void
    {
        add_action('admin_menu', static function () {
            add_menu_page('Retours des utilisateurs', 'Retours', 'manage_options', self::PAGE, [self::class, 'page'], 'dashicons-megaphone', 58);
        });
        add_action('admin_post_bayam_retours_maj', [self::class, 'enregistrer']);
    }

    /**
     * Retour envoyé par un vendeur depuis le Copilote (« bayam signaler … », « bayam idée … ») : renvoie la réponse à lui
     * donner, avec son numéro de suivi, ou un message honnête si le service ne répond pas.
     */
    public static function creer(string $type, string $texte, string $canal, int $vendeur = 0, string $auteur = ''): string
    {
        $corps = array_filter([
            'type' => in_array($type, ['souci', 'idee', 'question'], true) ? $type : 'souci',
            'texte' => mb_substr(trim(wp_strip_all_tags($texte)), 0, 2000),
            'canal' => $canal === 'vendeur' ? 'copilote' : $canal,
            'auteur' => mb_substr($auteur !== '' ? $auteur : ($vendeur ? Bayam_WA_Outils::nom_boutique($vendeur) : ''), 0, 120),
            'vendeur' => $vendeur ?: null,
            'identifiant' => $vendeur ? "vendeur:{$vendeur}" : null,
        ], static fn ($v) => $v !== null && $v !== '');
        $r = self::appel('POST', '/interne/signalements', $corps);
        if (!isset($r['message'])) {
            Bayam_WA_Outils::journal('Retour non transmis au service', ['canal' => $canal, 'vendeur' => $vendeur]);
            return self::INDISPONIBLE;
        }
        return (string) $r['message'];
    }

    private static function appel(string $methode, string $chemin, ?array $corps = null): ?array
    {
        if (self::$simulation) {
            return (self::$simulation)($methode, $chemin, $corps);
        }
        $r = wp_remote_request(rtrim((string) (getenv('BAYAM_RETOURS_URL') ?: 'http://retours:8093'), '/') . $chemin, [
            'method' => $methode,
            'timeout' => 8,
            'headers' => ['x-bayam-service' => (string) getenv('BAYAM_SERVICE_JETON')] + ($corps === null ? [] : ['content-type' => 'application/json']),
            'body' => $corps === null ? null : wp_json_encode($corps),
        ]);
        if (is_wp_error($r)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($r);
        $donnees = json_decode((string) wp_remote_retrieve_body($r), true);
        return $code >= 200 && $code < 300 ? (is_array($donnees) ? $donnees : []) : null;
    }

    // --- Tableau de bord de l'équipe -------------------------------------------------------------------------------

    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Accès réservé.');
        }
        echo '<div class="wrap"><h1>Retours des utilisateurs</h1>';
        if (isset($_GET['maj'])) {
            $prevenus = (int) $_GET['maj'];
            echo '<div class="notice notice-success"><p>Sujet mis à jour' . ($prevenus ? esc_html(" : {$prevenus} personne(s) prévenue(s)") : '') . '.</p></div>';
        }
        if (isset($_GET['erreur'])) {
            echo '<div class="notice notice-error"><p>Le service des retours n\'a pas accepté la modification. Réessayez.</p></div>';
        }
        $id = absint($_GET['sujet'] ?? 0);
        $id ? self::detail($id) : self::liste();
        echo '</div>';
    }

    private static function badge(int $gravite): string
    {
        [$nom, $couleur] = self::GRAVITES[$gravite] ?? self::GRAVITES[2];
        return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;color:#fff;background:' . esc_attr($couleur) . '">' . esc_html($nom) . '</span>';
    }

    private static function date(?string $iso): string
    {
        return $iso ? esc_html(wp_date('d/m/Y H:i', (int) strtotime($iso))) : '';
    }

    private static function liste(): void
    {
        $statut = sanitize_key((string) ($_GET['statut'] ?? 'ouverts'));
        $categorie = sanitize_key((string) ($_GET['categorie'] ?? ''));
        $requete = ['statut' => isset(self::STATUTS[$statut]) ? $statut : 'ouverts', 'limite' => 200] + (isset(self::CATEGORIES[$categorie]) ? ['categorie' => $categorie] : []);
        $sujets = self::appel('GET', '/interne/sujets?' . http_build_query($requete));
        $stats = self::appel('GET', '/interne/statistiques');
        if ($sujets === null) {
            echo '<div class="notice notice-error"><p>Le service des retours ne répond pas. Les signalements continuent d\'arriver dans la file dès son retour.</p></div>';
            return;
        }
        if ($stats) {
            $personnes = array_sum(array_column($stats['ouverts'] ?? [], 'personnes'));
            $critiques = count(array_filter($sujets, static fn ($s) => (int) $s['gravite'] === 4));
            $delai = $stats['delaiMedianCorrectionHeures'] ?? null;
            echo '<p style="font-size:14px">';
            echo esc_html(sprintf('%d sujet(s) affiché(s), %d critique(s), %d personne(s) touchée(s) au total sur les sujets ouverts', count($sujets), $critiques, $personnes));
            echo $delai !== null ? esc_html(sprintf(' ; délai médian de correction sur 30 jours : %s h', $delai)) : '';
            echo '</p>';
        }
        echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr(self::PAGE) . '">';
        echo '<select name="statut"><option value="ouverts">À traiter</option>';
        foreach (self::STATUTS as $cle => $nom) {
            echo '<option value="' . esc_attr($cle) . '"' . selected($requete['statut'], $cle, false) . '>' . esc_html($nom) . '</option>';
        }
        echo '</select> <select name="categorie"><option value="">Toutes les catégories</option>';
        foreach (self::CATEGORIES as $cle => $nom) {
            echo '<option value="' . esc_attr($cle) . '"' . selected($categorie, $cle, false) . '>' . esc_html($nom) . '</option>';
        }
        echo '</select> <button class="button">Filtrer</button></form><br>';
        echo '<table class="widefat striped"><thead><tr><th>Urgence</th><th>Gravité</th><th>Sujet</th><th>Type</th><th>Catégorie</th><th>Personnes</th><th>Signalements</th><th>Statut</th><th>Responsable</th><th>Dernier</th></tr></thead><tbody>';
        if (!$sujets) {
            echo '<tr><td colspan="10">Aucun sujet dans cette vue.</td></tr>';
        }
        foreach ($sujets as $s) {
            $lien = add_query_arg(['page' => self::PAGE, 'sujet' => (int) $s['id']], admin_url('admin.php'));
            echo '<tr><td><strong>' . esc_html((string) $s['score']) . '</strong></td><td>' . self::badge((int) $s['gravite']) . '</td>';
            echo '<td><a href="' . esc_url($lien) . '"><strong>#' . (int) $s['id'] . '</strong> ' . esc_html($s['titre']) . '</a></td>';
            echo '<td>' . esc_html(self::TYPES[$s['type']] ?? $s['type']) . '</td><td>' . esc_html(self::CATEGORIES[$s['categorie']] ?? $s['categorie']) . '</td>';
            echo '<td>' . (int) $s['signaleurs'] . '</td><td>' . (int) $s['signalements'] . '</td><td>' . esc_html(self::STATUTS[$s['statut']] ?? $s['statut']) . '</td>';
            echo '<td>' . esc_html((string) ($s['assigne'] ?? '')) . '</td><td>' . self::date($s['dernier'] ?? null) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function detail(int $id): void
    {
        $d = self::appel('GET', "/interne/sujets/{$id}");
        echo '<p><a href="' . esc_url(add_query_arg(['page' => self::PAGE], admin_url('admin.php'))) . '">&larr; Tous les sujets</a></p>';
        if (!$d) {
            echo '<div class="notice notice-error"><p>Sujet introuvable ou service indisponible.</p></div>';
            return;
        }
        $s = $d['sujet'];
        echo '<h2>#' . (int) $s['id'] . ' ' . esc_html($s['titre']) . ' ' . self::badge((int) $s['gravite']) . '</h2>';
        echo '<p>' . esc_html(sprintf('%s, %s, %d personne(s), %d signalement(s), urgence %s. Premier le ', self::TYPES[$s['type']] ?? $s['type'], self::CATEGORIES[$s['categorie']] ?? $s['categorie'], $s['signaleurs'], $s['signalements'], $s['score']));
        echo self::date($s['premier']) . ', dernier le ' . self::date($s['dernier']) . ($s['fonction'] ? esc_html(", fonction {$s['fonction']}") : '') . '.</p>';
        if (!empty($s['resolution'])) {
            echo '<p><strong>Résolution :</strong> ' . esc_html($s['resolution']) . '</p>';
        }
        $joignables = count(array_filter($d['signalements'], static fn ($g) => !empty($g['joignable']) && empty($g['prevenu'])));

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="background:#fff;padding:12px 16px;border:1px solid #dcdcde;max-width:760px">';
        wp_nonce_field('bayam_retours_' . $id);
        echo '<input type="hidden" name="action" value="bayam_retours_maj"><input type="hidden" name="sujet" value="' . (int) $id . '">';
        echo '<p><label>Statut <select name="statut">';
        foreach (self::STATUTS as $cle => $nom) {
            if ($cle !== 'doublon') {
                echo '<option value="' . esc_attr($cle) . '"' . selected($s['statut'], $cle, false) . '>' . esc_html($nom) . '</option>';
            }
        }
        echo '</select></label> <label>Gravité <select name="gravite">';
        foreach (self::GRAVITES as $g => [$nom]) {
            echo '<option value="' . (int) $g . '"' . selected((int) $s['gravite'], $g, false) . '>' . esc_html($nom) . '</option>';
        }
        echo '</select></label> <label>Catégorie <select name="categorie">';
        foreach (self::CATEGORIES as $cle => $nom) {
            echo '<option value="' . esc_attr($cle) . '"' . selected($s['categorie'], $cle, false) . '>' . esc_html($nom) . '</option>';
        }
        echo '</select></label> <label>Responsable <input name="assigne" value="' . esc_attr((string) ($s['assigne'] ?? '')) . '" maxlength="80"></label></p>';
        echo '<p><label>Résolution (ce qui a été fait)<br><textarea name="resolution" rows="2" style="width:100%" maxlength="2000">' . esc_textarea((string) ($s['resolution'] ?? '')) . '</textarea></label></p>';
        echo '<p><label>Note interne<br><textarea name="note" rows="2" style="width:100%" maxlength="2000"></textarea></label></p>';
        echo '<p><label><input type="checkbox" name="prevenir" value="1"> Prévenir les ' . (int) $joignables . ' personne(s) joignable(s) que c\'est réglé (statut Corrigé)</label><br>';
        echo '<label>Message (facultatif)<br><input name="message" style="width:100%" maxlength="1000" placeholder="Bonne nouvelle : le souci que vous nous aviez signalé est réglé…"></label></p>';
        echo '<p><label>Doublon du sujet n° <input name="doublon" type="number" min="1" style="width:90px"></label> ';
        submit_button('Enregistrer', 'primary', 'submit', false);
        echo '</p></form>';

        echo '<h3>Signalements</h3><table class="widefat striped"><thead><tr><th>N°</th><th>Reçu</th><th>Canal</th><th>Auteur</th><th>Texte</th><th>Contexte</th><th>Prévenu</th></tr></thead><tbody>';
        foreach ($d['signalements'] as $g) {
            $contexte = implode(', ', array_map(static fn ($k, $v) => "{$k} : {$v}", array_keys((array) ($g['contexte'] ?? [])), (array) ($g['contexte'] ?? [])));
            echo '<tr><td>R-' . (int) $g['id'] . '</td><td>' . self::date($g['cree']) . '</td><td>' . esc_html($g['canal']) . '</td><td>' . esc_html((string) $g['auteur']) . ($g['vendeur'] ? esc_html(' (' . Bayam_WA_Outils::nom_boutique((int) $g['vendeur']) . ')') : '') . '</td>';
            echo '<td>' . esc_html($g['texte']) . '</td><td>' . esc_html($contexte) . '</td><td>' . ($g['prevenu'] ? self::date($g['prevenu']) : ($g['joignable'] ? 'non' : 'injoignable')) . '</td></tr>';
        }
        echo '</tbody></table><h3>Historique</h3><ul>';
        foreach ($d['historique'] as $h) {
            echo '<li>' . self::date($h['quand']) . ' : ' . esc_html($h['action']) . ' par ' . esc_html($h['auteur']) . ' ' . esc_html(wp_json_encode($h['details'], JSON_UNESCAPED_UNICODE)) . '</li>';
        }
        echo '</ul>';
    }

    public static function enregistrer(): void
    {
        $id = absint($_POST['sujet'] ?? 0);
        if (!current_user_can('manage_options') || !$id) {
            wp_die('Accès réservé.');
        }
        check_admin_referer('bayam_retours_' . $id);
        $auteur = wp_get_current_user()->user_login;
        $doublon = absint($_POST['doublon'] ?? 0);
        $retour = add_query_arg(['page' => self::PAGE, 'sujet' => $doublon ?: $id], admin_url('admin.php'));
        if ($doublon) {
            $r = self::appel('POST', "/interne/sujets/{$id}/fusionner", ['dans' => $doublon, 'auteur' => $auteur]);
            wp_safe_redirect(add_query_arg($r === null ? ['erreur' => 1] : ['maj' => 0], $retour));
            exit;
        }
        $statut = sanitize_key((string) ($_POST['statut'] ?? ''));
        $categorie = sanitize_key((string) ($_POST['categorie'] ?? ''));
        $gravite = absint($_POST['gravite'] ?? 0);
        $corps = array_filter([
            'auteur' => $auteur,
            'statut' => isset(self::STATUTS[$statut]) && $statut !== 'doublon' ? $statut : null,
            'categorie' => isset(self::CATEGORIES[$categorie]) ? $categorie : null,
            'gravite' => isset(self::GRAVITES[$gravite]) ? $gravite : null,
            'assigne' => sanitize_text_field(wp_unslash((string) ($_POST['assigne'] ?? ''))) ?: null,
            'resolution' => sanitize_textarea_field(wp_unslash((string) ($_POST['resolution'] ?? ''))) ?: null,
            'note' => sanitize_textarea_field(wp_unslash((string) ($_POST['note'] ?? ''))) ?: null,
            'prevenir' => !empty($_POST['prevenir']) ?: null,
            'message' => mb_strlen($m = sanitize_text_field(wp_unslash((string) ($_POST['message'] ?? '')))) >= 3 ? $m : null,
        ], static fn ($v) => $v !== null);
        $r = self::appel('PATCH', "/interne/sujets/{$id}", $corps);
        wp_safe_redirect(add_query_arg($r === null ? ['erreur' => 1] : ['maj' => (int) ($r['prevenus'] ?? 0)], $retour));
        exit;
    }
}
