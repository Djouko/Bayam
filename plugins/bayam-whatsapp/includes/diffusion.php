<?php
defined('ABSPATH') || exit;

/**
 * Publication dans les groupes administrés et le statut (mode connecté), avec envoi étalé, choix des groupes et
 * suivi des clics par groupe (cahier des charges, exigence DIF). Les envois partent d'une file traitée chaque minute.
 */
final class Bayam_WA_Diffusion
{
    public static function init(): void
    {
        add_action('bayam_wa_diffuser', [self::class, 'traiter_file']);
        add_action('init', static function () {
            if (function_exists('as_has_scheduled_action') && !as_has_scheduled_action('bayam_wa_diffuser')) {
                as_schedule_recurring_action(time() + 60, 60, 'bayam_wa_diffuser', [], 'bayam-whatsapp');
            }
        });
    }

    /** Programme la publication des produits dans les groupes choisis (ou le statut) ; rend le message au vendeur. */
    /**
     * Programme la publication d'articles dans les groupes choisis, dans le statut, ou les deux (« partout »).
     * Rythme d'un vendeur qui transfère lui-même : les photos d'un même groupe s'enchaînent (3 à 8 s), le groupe
     * suivant vient 10 à 25 s plus tard, les statuts toutes les 4 à 10 s. Assez rapide pour le vendeur, assez
     * humain pour WhatsApp.
     *
     * @param string $ou « groupes », « statut » ou « partout »
     * @param array|null $groupes groupes visés [id => nom], sinon ceux choisis dans l'espace vendeur
     */
    /**
     * Programme la publication d'articles dans les groupes choisis, dans le statut, ou les deux (« partout »).
     * Rythme d'un vendeur qui transfère lui-même : les photos d'un même groupe s'enchaînent (3 à 8 s), le groupe
     * suivant vient 10 à 25 s plus tard, les statuts toutes les 4 à 10 s. Assez rapide pour le vendeur, assez
     * humain pour WhatsApp.
     *
     * @param string $ou « groupes », « statut » ou « partout »
     * @param array|null $groupes groupes visés [id => nom], sinon ceux choisis dans l'espace vendeur
     */
    public static function programmer(int $vendeur, array $produits, string $ou = 'groupes', ?array $groupes = null): string
    {
        global $wpdb;
        if (get_user_meta($vendeur, 'bayam_wa_statut', true) !== 'WORKING') {
            return "Votre WhatsApp n'est pas connecté à BAYAM : je ne peux pas publier pour vous. Écrivez *bayam cartes …* pour recevoir les cartes à transférer vous-même.";
        }
        $groupes = $groupes ?? Bayam_WA_Stockage::reglages($vendeur)['groupes'];
        if ($ou !== 'statut' && !$groupes) {
            return "Choisissez d'abord vos groupes de publication dans votre espace vendeur BAYAM, rubrique WhatsApp. En attendant : *bayam publier … statut*, ou *bayam cartes …* pour les transférer vous-même.";
        }
        $plan = [];
        if ($ou !== 'statut') {
            foreach ($groupes as $chat => $nom) {
                foreach ($produits as $p) {
                    $plan[] = ['groupe', $chat, $p];
                }
            }
        }
        if ($ou !== 'groupes') {
            foreach ($produits as $p) {
                $plan[] = ['statut', null, $p];
            }
        }
        $minuit = wp_date('Y-m-d 00:00:00');
        $restant = [
            'groupe' => Bayam_WA_Regles::PLAFONDS['diffusion'] - Bayam_WA_Stockage::envois_depuis($vendeur, $minuit, 'diffusion') - self::en_attente($vendeur, 'groupe'),
            'statut' => Bayam_WA_Regles::PLAFONDS['statut'] - Bayam_WA_Stockage::envois_depuis($vendeur, $minuit, 'statut') - self::en_attente($vendeur, 'statut'),
        ];
        $quand = time();
        $faits = ['groupe' => 0, 'statut' => 0];
        foreach ($plan as $i => [$type, $chat, $p]) {
            if ($restant[$type] <= 0) {
                continue;
            }
            $wpdb->insert(Bayam_WA_Stockage::table('diffusions'), [
                'vendeur' => $vendeur, 'type' => $type, 'chat' => $chat, 'produit' => $p->get_id(), 'prevu' => wp_date('Y-m-d H:i:s', $quand),
            ]);
            $restant[$type]--;
            $faits[$type]++;
            $suivant = $plan[$i + 1] ?? null;
            $quand += $type === 'statut' ? mt_rand(4, 10) : ($suivant && $suivant[1] === $chat ? mt_rand(3, 8) : mt_rand(10, 25));
        }
        if (!array_sum($faits)) {
            return "Le nombre de publications du jour est atteint, pour protéger votre numéro. La suite demain, ou transférez les cartes vous-même (*bayam cartes …*).";
        }
        $parties = [];
        if ($faits['groupe']) {
            $parties[] = $faits['groupe'] . ' dans ' . count($groupes) . ' groupe' . (count($groupes) > 1 ? 's' : '');
        }
        if ($faits['statut']) {
            $parties[] = $faits['statut'] . ' en statut';
        }
        $duree = $quand - time();
        $delai = $duree < 90 ? "environ {$duree} s" : 'environ ' . (int) round($duree / 60) . ' min';
        $manque = count($plan) - array_sum($faits);
        return '📣 ' . array_sum($faits) . ' publication(s) : ' . implode(', ', $parties) . ", envoyées en {$delai}"
            . ($manque > 0 ? " (plafond du jour atteint pour {$manque} autre(s))" : '') . '. Je vous fais le bilan à la fin.';
    }

    private static function en_attente(int $vendeur, string $type): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Bayam_WA_Stockage::table('diffusions') . " WHERE vendeur = %d AND type = %s AND statut IN ('attente', 'envoi')", $vendeur, $type));
    }

    /** Envoie les publications arrivées à échéance (au plus 4 par minute) et prévient le vendeur à la fin d'une série. */
    public static function traiter_file(): void
    {
        global $wpdb;
        $table = Bayam_WA_Stockage::table('diffusions');
        $dues = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE statut = 'attente' AND prevu <= %s ORDER BY prevu LIMIT 4", current_time('mysql')));
        $vendeurs = [];
        foreach ($dues as $d) {
            // Réservation atomique : un seul travailleur envoie cette publication, même s'ils sont plusieurs.
            if (!$wpdb->query($wpdb->prepare("UPDATE $table SET statut = 'envoi' WHERE id = %d AND statut = 'attente'", $d->id))) {
                continue;
            }
            $vendeur = (int) $d->vendeur;
            $p = wc_get_product((int) $d->produit);
            $erreur = null;
            if (!$p || !$p->is_in_stock()) {
                $erreur = 'Article épuisé ou supprimé entre-temps.';
            } elseif (Bayam_WA_Regles::restreint($vendeur)) {
                $erreur = 'WhatsApp limite temporairement le numéro.';
            } else {
                $session = Bayam_WA_Stockage::reglages($vendeur)['session'];
                $boutique = Bayam_WA_Outils::nom_boutique($vendeur);
                $lien = Bayam_WA_Liens::produit($vendeur, $p, $d->type === 'statut' ? 'statut' : 'groupe', $d->chat);
                $carte = Bayam_WA_Cartes::carte($p, $lien, $boutique, $d->type === 'statut');
                $legende = $p->get_name() . ' : ' . Bayam_WA_Outils::fcfa((float) $p->get_price()) . "\nCommander : " . $lien;
                $r = $d->type === 'statut'
                    ? Bayam_WA_Waha::client()->statut_image($session, $carte, $legende, Bayam_WA_Stockage::reglages($vendeur)['statut_contacts'])
                    : Bayam_WA_Waha::client()->image($session, $d->chat, $carte, Bayam_WA_Outils::MARQUE . $legende);
                if (is_wp_error($r)) {
                    $erreur = mb_substr($r->get_error_message(), 0, 250);
                } else {
                    Bayam_WA_Stockage::noter_message($vendeur, $d->chat ?? 'status@broadcast', 'out', $legende, Bayam_WA_Regles::id_de($r), $d->type === 'statut' ? 'statut' : 'diffusion');
                }
            }
            $wpdb->update($table, ['statut' => $erreur ? 'echec' : 'envoye', 'envoye' => current_time('mysql'), 'erreur' => $erreur], ['id' => $d->id]);
            $vendeurs[$vendeur] = true;
        }
        foreach (array_keys($vendeurs) as $vendeur) {
            if (self::en_attente($vendeur, 'groupe') + self::en_attente($vendeur, 'statut') === 0) {
                self::bilan_serie($vendeur);
            }
        }
    }

    private static function bilan_serie(int $vendeur): void
    {
        global $wpdb;
        $depuis = wp_date('Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS);
        $lignes = $wpdb->get_results($wpdb->prepare('SELECT statut, erreur FROM ' . Bayam_WA_Stockage::table('diffusions') . ' WHERE vendeur = %d AND envoye >= %s', $vendeur, $depuis));
        $ok = count(array_filter($lignes, static fn ($l) => $l->statut === 'envoye'));
        $echecs = array_filter($lignes, static fn ($l) => $l->statut === 'echec');
        Bayam_WA_Regles::au_vendeur($vendeur, "✅ Publication terminée : {$ok} envoi(s) réussi(s)" . ($echecs ? ', ' . count($echecs) . ' non envoyé(s) (' . reset($echecs)->erreur . ')' : '') . '. Les clics de chaque groupe sont suivis dans votre espace vendeur.');
    }
}
