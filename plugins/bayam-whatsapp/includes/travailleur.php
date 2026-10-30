<?php
defined('ABSPATH') || exit;

/**
 * Travailleur permanent (commande WP-CLI « wp bayam-wa travailleur », lancée par le conteneur « travailleur ») :
 * traite les événements WhatsApp en moins d'une seconde et fait tourner les tâches programmées (publications
 * étalées, rapports), sans dépendre des visites du site comme le cron de WordPress.
 */
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('bayam-wa travailleur', static function ($args, $options) {
        $fin = time() + (int) ($options['duree'] ?? 3600); // redémarré par Docker toutes les heures (mémoire propre)
        $prochaine_file = 0;
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
            $ids = $wpdb->get_col('SELECT id FROM ' . Bayam_WA_Stockage::table('wa_evenements') . ' WHERE traite = 0 ORDER BY id LIMIT 20');
            foreach ($ids as $id) {
                Bayam_WA_Webhook::traiter((int) $id);
            }
            if (time() >= $prochaine_file && class_exists('ActionScheduler_QueueRunner')) {
                // Tâches dues (publications étalées chaque minute, rapports chaque heure) du groupe de l'extension.
                ActionScheduler_QueueRunner::instance()->run('bayam-wa-travailleur');
                $prochaine_file = time() + 20;
            }
            $wpdb->flush();
            if (!$ids) {
                usleep(500000);
            }
        }
    });
}
