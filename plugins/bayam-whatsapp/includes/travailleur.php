<?php
defined('ABSPATH') || exit;

/**
 * Travailleur permanent (commande WP-CLI « wp bayam-wa travailleur », lancée par le conteneur « travailleur ») :
 * traite les événements WhatsApp en moins d'une seconde et fait tourner les tâches programmées (publications
 * étalées, rapports), sans dépendre des visites du site comme le cron de WordPress.
 */
/**
 * Attente du prochain événement : jusqu'à 1 s sur la file Redis (réveil immédiat par le webhook), sinon une
 * demi-seconde. Plusieurs travailleurs peuvent tourner (« docker compose up --scale travailleur=3 ») : chaque
 * événement et chaque publication est réservé atomiquement en base.
 */
final class Bayam_WA_Travailleur
{
    public static function attendre(): void
    {
        $redis = Bayam_WA_Outils::redis();
        if (!$redis) {
            usleep(500000);
            return;
        }
        try {
            $redis->brPop([Bayam_WA_Outils::FILE_EVENEMENTS], 1);
        } catch (Throwable $e) {
            Bayam_WA_Outils::redis(true); // connexion perdue : nouvelle tentative au tour suivant
            usleep(500000);
        }
    }
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('bayam-wa travailleur', static function ($args, $options) {
        $fin = time() + (int) ($options['duree'] ?? 3600); // redémarré par Docker toutes les heures (mémoire propre)
        $prochaine_file = 0;
        $prochaine_diffusion = 0;
        // Le code chargé reste en mémoire : à la moindre mise à jour de l'extension, on s'arrête et Docker relance.
        $version = static function (): int {
            clearstatcache();
            return (int) max(array_map('filemtime', array_merge(glob(BAYAM_WA_DIR . '*.php'), glob(BAYAM_WA_DIR . 'includes/*.php'))));
        };
        $code = $version();
        $prochain_controle = time() + 5;
        WP_CLI::log('Travailleur BAYAM WhatsApp démarré.');
        while (time() < $fin) {
            if (time() >= $prochain_controle) {
                if ($version() !== $code) {
                    WP_CLI::log('Extension mise à jour : redémarrage du travailleur.');
                    return;
                }
                $prochain_controle = time() + 5;
            }
            global $wpdb;
            // Pendant les essais automatiques, la file appartient aux essais (pas d'envoi réel de leurs données).
            if ((int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'bayam_wa_essais_jusqua')) > time()) {
                sleep(1);
                continue;
            }
            $ids = $wpdb->get_col('SELECT id FROM ' . Bayam_WA_Stockage::table('wa_evenements') . ' WHERE traite = 0 ORDER BY id LIMIT 20');
            if ($ids || time() >= $prochaine_file) {
                // La copie du cache gardée en mémoire par ce processus vieillit : sans ce vidage, un réglage changé
                // sur la page vendeur ne serait vu qu'au redémarrage. Avec Redis, seule cette copie locale est vidée
                // (le cache partagé du site reste intact) ; sans Redis, cela revient à vider le cache du processus.
                wp_cache_flush_runtime();
            }
            foreach ($ids as $id) {
                Bayam_WA_Webhook::traiter((int) $id);
            }
            if (time() >= $prochaine_diffusion) {
                // Publications dues : lues ici toutes les 2 s, sans attendre la file des tâches programmées.
                Bayam_WA_Diffusion::traiter_file();
                $prochaine_diffusion = time() + 2; // rythme des publications à la seconde près
            }
            if (time() >= $prochaine_file && class_exists('ActionScheduler_QueueRunner')) {
                // Tâches dues (publications étalées chaque minute, rapports chaque heure) du groupe de l'extension.
                ActionScheduler_QueueRunner::instance()->run('bayam-wa-travailleur');
                $prochaine_file = time() + 20;
            }
            $wpdb->flush();
            if (!$ids) {
                Bayam_WA_Travailleur::attendre();
            }
        }
    });
}
