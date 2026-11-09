<?php
/**
 * Interrupteurs des fonctions (même rangement que les microservices : table Redis « bayam:fonctions ») : une fonction
 * en maintenance répond par un message clair, le reste de BAYAM continue. Réglés par scripts/fonctions.sh, sans
 * redéploiement. Un préfixe vaut pour toutes ses fonctions (« whatsapp » coupe tout WhatsApp).
 */

defined('ABSPATH') || exit;

final class Bayam_WA_Fonctions
{
    private static ?array $reglages = null;

    /** @return array{etat: string, message: string, jusqua: ?string} la plus restrictive entre la fonction et ses préfixes */
    public static function etat(string $nom, int $vendeur = 0): array
    {
        if (self::$reglages === null) {
            $redis = Bayam_WA_Outils::redis();
            $brut = $redis ? ($redis->hGetAll('bayam:fonctions') ?: []) : [];
            self::$reglages = array_filter(array_map(static fn ($v) => json_decode((string) $v, true), $brut), 'is_array');
        }
        $pire = ['etat' => 'actif', 'message' => '', 'jusqua' => null];
        $parties = explode('.', $nom);
        for ($i = 1; $i <= count($parties); $i++) {
            $r = self::$reglages[implode('.', array_slice($parties, 0, $i))] ?? null;
            if (!$r || !in_array($r['etat'] ?? '', ['maintenance', 'arret'], true)) {
                continue;
            }
            // Heure de fin passée : terminée ; testeurs de l'équipe : gardent l'accès pour vérifier la correction.
            if ((!empty($r['jusqua']) && strtotime($r['jusqua']) <= time()) || ($vendeur && in_array($vendeur, (array) ($r['testeurs'] ?? []), true))) {
                continue;
            }
            if ($pire['etat'] === 'actif' || $r['etat'] === 'arret') {
                $pire = ['etat' => $r['etat'], 'message' => (string) ($r['message'] ?? ''), 'jusqua' => $r['jusqua'] ?? null];
            }
        }
        return $pire;
    }

    public static function disponible(string $nom, int $vendeur = 0): bool
    {
        return self::etat($nom, $vendeur)['etat'] === 'actif';
    }

    /** Message pour l'utilisateur : celui de l'équipe, sinon un message simple avec l'heure de retour (heure de Douala). */
    public static function message(array $etat): string
    {
        if ($etat['message'] !== '') {
            return $etat['message'];
        }
        $retour = $etat['jusqua'] ? ' Elle revient vers ' . wp_date('H:i', strtotime($etat['jusqua'])) . '.' : '';
        return $etat['etat'] === 'arret'
            ? 'Cette fonction est momentanément indisponible. Le reste de BAYAM marche normalement 🙏'
            : "Cette fonction est en maintenance pour quelques minutes.{$retour} Le reste de BAYAM marche normalement 🙏";
    }
}
