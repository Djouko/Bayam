<?php
defined('ABSPATH') || exit;

/**
 * Bilans (« bilan », « bilan semaine »), rapports automatiques (résumé quotidien à l'heure choisie, bilan
 * hebdomadaire le lundi, mensuel le 1er) et carnet de dettes des clients.
 */
final class Bayam_WA_Rapports
{
    public static function init(): void
    {
        add_action('bayam_wa_rapports_horaires', [self::class, 'envoyer_rapports']);
        add_action('init', static function () {
            if (function_exists('as_has_scheduled_action') && !as_has_scheduled_action('bayam_wa_rapports_horaires')) {
                // À chaque heure pile (heure du serveur) ; le contrôle d'heure se fait à Douala.
                as_schedule_recurring_action(strtotime(gmdate('Y-m-d H:00:00')) + HOUR_IN_SECONDS, HOUR_IN_SECONDS, 'bayam_wa_rapports_horaires', [], 'bayam-whatsapp');
            }
        });
    }

    /**
     * Période dite en clair : « hier », « avant-hier », « cette semaine », « la semaine passée », « ce mois »,
     * « le mois dernier », « lundi », « le 3 novembre », « 03/11 ». Par défaut aujourd'hui.
     *
     * @return array{0: string, 1: ?string, 2: string} début, fin exclue (null = jusqu'à maintenant), libellé
     */
    public static function periode(string $texte): array
    {
        $t = Bayam_WA_Outils::normaliser($texte);
        $jour = new DateTimeImmutable('today', wp_timezone());
        $f = static fn (DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s');
        $date = static fn (DateTimeImmutable $d): string => wp_date('j F', $d->getTimestamp());
        if (preg_match('/avant[- ]?hier/', $t)) {
            return [$f($jour->modify('-2 days')), $f($jour->modify('-1 day')), "d'avant-hier"];
        }
        if (preg_match('/\bhier\b/', $t)) {
            return [$f($jour->modify('-1 day')), $f($jour), "d'hier"];
        }
        if (preg_match('/semaine (?:passee|derniere)|derniere semaine/', $t)) {
            $lundi = $jour->modify('monday this week');
            return [$f($lundi->modify('-7 days')), $f($lundi), 'de la semaine passée'];
        }
        if (preg_match('/\bsemaine\b/', $t)) {
            return [$f($jour->modify('monday this week')), null, 'cette semaine'];
        }
        if (preg_match('/mois (?:passe|dernier)|dernier mois/', $t)) {
            $premier = $jour->modify('first day of this month');
            return [$f($premier->modify('-1 month')), $f($premier), 'du mois passé'];
        }
        if (preg_match('/\bmois\b/', $t)) {
            return [$f($jour->modify('first day of this month')), null, 'ce mois-ci'];
        }
        $jours = ['lundi' => 'monday', 'mardi' => 'tuesday', 'mercredi' => 'wednesday', 'jeudi' => 'thursday', 'vendredi' => 'friday', 'samedi' => 'saturday', 'dimanche' => 'sunday'];
        foreach ($jours as $fr => $en) {
            if (preg_match("/\\b{$fr}\\b/", $t)) {
                $d = strtolower($jour->format('l')) === $en ? $jour : $jour->modify("last {$en}");
                return [$f($d), $f($d->modify('+1 day')), "du {$fr} " . $date($d)];
            }
        }
        $mois = ['janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6, 'juillet' => 7, 'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12];
        if (preg_match('/\b(\d{1,2})\s*[\/-]\s*(\d{1,2})\b/', $t, $m) || (preg_match('/\b(\d{1,2})(?:er)?\s+(' . implode('|', array_keys($mois)) . ')\b/', $t, $m) && ($m[2] = $mois[$m[2]]))) {
            $d = $jour->setDate((int) $jour->format('Y'), (int) $m[2], (int) $m[1]);
            if ($d > $jour) {
                $d = $d->modify('-1 year'); // « le 28/12 » dit en janvier : l'année passée
            }
            return [$f($d), $f($d->modify('+1 day')), 'du ' . $date($d)];
        }
        return [$f($jour), null, "aujourd'hui"];
    }

    public static function texte_bilan(int $vendeur, string $depuis, string $libelle, ?string $fin = null): string
    {
        $b = Bayam_WA_Boutique::bilan($vendeur, $depuis, $fin);
        $texte = "📊 *Bilan {$libelle}*\n"
            . 'Chiffre d\'affaires : *' . Bayam_WA_Outils::fcfa($b['ca']) . "*\n"
            . "Commandes : {$b['commandes']}\n"
            . 'Encaissé : ' . Bayam_WA_Outils::fcfa($b['encaisse']);
        if ($b['top']) {
            $texte .= "\nArticles vendus : " . implode(', ', $b['top']);
        }
        if ($b['ruptures']) {
            $texte .= "\n⚠️ En rupture : " . implode(', ', array_slice($b['ruptures'], 0, 5));
        }
        $dettes = self::total_dettes($vendeur);
        if ($dettes > 0) {
            $texte .= "\n📝 À recouvrer : " . Bayam_WA_Outils::fcfa($dettes);
        }
        return $texte;
    }

    /** Appelée chaque heure : envoie les rapports des vendeurs dont c'est l'heure (heure de Douala). */
    public static function envoyer_rapports(): void
    {
        $heure = (int) wp_date('G');
        $aujourdhui = wp_date('Y-m-d');
        foreach (get_users(['meta_key' => 'bayam_wa_statut', 'meta_value' => 'WORKING', 'fields' => 'ID']) as $vendeur) {
            $vendeur = (int) $vendeur;
            $r = Bayam_WA_Stockage::reglages($vendeur);
            if (!$r['rapport_actif'] || (int) $r['heure_rapport'] !== $heure || get_user_meta($vendeur, 'bayam_wa_dernier_rapport', true) === $aujourdhui) {
                continue;
            }
            update_user_meta($vendeur, 'bayam_wa_dernier_rapport', $aujourdhui);
            Bayam_WA_Regles::au_vendeur($vendeur, self::texte_bilan($vendeur, wp_date('Y-m-d 00:00:00'), "du jour") . "\n\nBonne soirée ! (*bayam rapport 21h* pour changer l'heure)");
            if (wp_date('N') === '1') {
                Bayam_WA_Regles::au_vendeur($vendeur, self::texte_bilan($vendeur, wp_date('Y-m-d 00:00:00', strtotime('monday last week')), 'de la semaine passée'));
            }
            if (wp_date('j') === '1') {
                Bayam_WA_Regles::au_vendeur($vendeur, self::texte_bilan($vendeur, wp_date('Y-m-01 00:00:00', strtotime('first day of last month')), 'du mois passé'));
            }
            $echues = self::dettes_echues($vendeur);
            if ($echues) {
                Bayam_WA_Regles::au_vendeur($vendeur, "⏰ Dettes arrivées à échéance :\n• " . implode("\n• ", $echues));
            }
        }
    }

    // --- Dettes -------------------------------------------------------------------------------------------------

    /** $commande : vente à laquelle la dette se rattache (acompte, crédit) ; elle passe en « terminée » une fois soldée. */
    public static function ajouter_dette(int $vendeur, string $client, int $montant, ?string $echeance, ?string $numero = null, ?int $commande = null): void
    {
        global $wpdb;
        $wpdb->insert(Bayam_WA_Stockage::table('dettes'), [
            'vendeur' => $vendeur, 'client' => $client, 'numero' => $numero, 'montant' => $montant, 'reste' => $montant,
            'echeance' => $echeance, 'note' => $commande ? "commande:{$commande}" : null, 'cree' => current_time('mysql'),
        ]);
    }

    /** Paiement d'un client : solde ses dettes de la plus ancienne à la plus récente. */
    public static function encaisser_dette(int $vendeur, string $client, int $montant): string
    {
        global $wpdb;
        $table = Bayam_WA_Stockage::table('dettes');
        $dettes = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE vendeur = %d AND reste > 0 AND client = %s ORDER BY cree", $vendeur, $client));
        if (!$dettes) {
            return "Je ne trouve pas de dette au nom de {$client}. *bayam dettes* pour voir la liste.";
        }
        $a_repartir = $montant;
        foreach ($dettes as $d) {
            if ($a_repartir <= 0) {
                break;
            }
            $paye = min($a_repartir, (int) $d->reste);
            $reste = (int) $d->reste - $paye;
            $wpdb->update($table, ['reste' => $reste, 'solde' => $reste === 0 ? current_time('mysql') : null], ['id' => $d->id]);
            $a_repartir -= $paye;
            // Vente avec acompte ou à crédit entièrement payée : la commande est terminée (et comptée comme encaissée).
            if ($reste === 0 && preg_match('/^commande:(\d+)$/', (string) $d->note, $c) && ($vente = wc_get_order((int) $c[1])) && $vente->has_status('on-hold')) {
                $vente->update_status('completed', 'Soldée : le client a tout payé.');
            }
        }
        $du = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(reste), 0) FROM $table WHERE vendeur = %d AND client = %s", $vendeur, $client));
        return "💰 Paiement de " . Bayam_WA_Outils::fcfa($montant) . " noté pour {$client}. "
            . ($du > 0 ? 'Reste à payer : ' . Bayam_WA_Outils::fcfa($du) . '.' : 'Plus aucune dette. 🎉')
            . ($a_repartir > 0 ? ' (Trop-perçu : ' . Bayam_WA_Outils::fcfa($a_repartir) . '.)' : '');
    }

    public static function texte_dettes(int $vendeur): string
    {
        global $wpdb;
        $lignes = $wpdb->get_results($wpdb->prepare(
            'SELECT client, SUM(reste) AS du, MIN(echeance) AS echeance FROM ' . Bayam_WA_Stockage::table('dettes') . ' WHERE vendeur = %d AND reste > 0 GROUP BY client ORDER BY du DESC',
            $vendeur
        ));
        if (!$lignes) {
            return '👍 Personne ne vous doit d\'argent. Pour noter une dette : *bayam dette Paul 5000*.';
        }
        $total = array_sum(array_map(static fn ($l) => (int) $l->du, $lignes));
        $texte = '📝 *Qui me doit quoi* (' . Bayam_WA_Outils::fcfa($total) . ")\n";
        foreach ($lignes as $l) {
            $texte .= '• ' . $l->client . ' : ' . Bayam_WA_Outils::fcfa((float) $l->du) . ($l->echeance ? ' (échéance ' . wp_date('d/m', strtotime($l->echeance)) . ')' : '') . "\n";
        }
        return rtrim($texte);
    }

    public static function total_dettes(int $vendeur): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(reste), 0) FROM ' . Bayam_WA_Stockage::table('dettes') . ' WHERE vendeur = %d', $vendeur));
    }

    public static function dette_de(int $vendeur, string $numero): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(reste), 0) FROM ' . Bayam_WA_Stockage::table('dettes') . ' WHERE vendeur = %d AND numero = %s', $vendeur, $numero));
    }

    private static function dettes_echues(int $vendeur): array
    {
        global $wpdb;
        $lignes = $wpdb->get_results($wpdb->prepare(
            'SELECT client, reste FROM ' . Bayam_WA_Stockage::table('dettes') . ' WHERE vendeur = %d AND reste > 0 AND echeance IS NOT NULL AND echeance <= %s',
            $vendeur, wp_date('Y-m-d')
        ));
        return array_map(static fn ($l) => $l->client . ' : ' . Bayam_WA_Outils::fcfa((float) $l->reste), $lignes);
    }
}
