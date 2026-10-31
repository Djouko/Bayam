<?php
defined('ABSPATH') || exit;

/** Petits outils partagés : texte, montants, numéros camerounais, identifiants WhatsApp. */
final class Bayam_WA_Outils
{
    /** Marque invisible en tête de chaque message envoyé par BAYAM : le Copilote ne se répond jamais à lui-même. */
    public const MARQUE = "\u{2063}";

    /** Mot qui adresse un message à BAYAM, en tête : « bayam stock robe », « Bayam, bilan », « @BAYAM menu ». */
    public const APPEL = 'bayam';

    /**
     * Le message s'adresse-t-il à BAYAM ? Rend la demande qui suit le mot « bayam » (chaîne vide pour « bayam » seul),
     * ou null pour un message ordinaire, que BAYAM ne doit ni garder ni interpréter. Majuscules, accents, ponctuation
     * et une faute de frappe sont tolérés (« Bayan », « bayamm », « bayma ») ; « bayam-sellam » reste un mot ordinaire.
     */
    public static function appel(string $texte): ?string
    {
        if (!preg_match('/^\s*[@\/]?\s*(\p{L}{3,7})(?=$|[\s,:;.!?])[\s,:;.!?]*(.*)$/su', $texte, $m)) {
            return null;
        }
        return self::proche(remove_accents(mb_strtolower($m[1])), self::APPEL) ? trim($m[2]) : null;
    }

    /** Au plus une faute : lettre en trop, en moins, remplacée, ou deux lettres voisines interverties. */
    private static function proche(string $mot, string $cible): bool
    {
        if (levenshtein($mot, $cible) <= 1) {
            return true;
        }
        if (strlen($mot) !== strlen($cible)) {
            return false;
        }
        $ecarts = array_keys(array_diff_assoc(str_split($mot), str_split($cible)));
        return count($ecarts) === 2 && $ecarts[1] === $ecarts[0] + 1
            && $mot[$ecarts[0]] === $cible[$ecarts[1]] && $mot[$ecarts[1]] === $cible[$ecarts[0]];
    }

    /** Minuscules, sans accents ni espaces superflus : « Robe  Rouge » et « robe rouge » se valent. */
    public static function normaliser(string $texte): string
    {
        $texte = remove_accents(mb_strtolower(trim($texte)));
        return preg_replace('/\s+/u', ' ', $texte);
    }

    /** « 15000 », « 15 000 », « 15.000 », « 15k », « 15 000 F » : montant en francs CFA, ou null. */
    public static function montant(string $texte): ?int
    {
        $t = self::normaliser($texte);
        if (preg_match('/^(\d+(?:[ .]\d{3})*)\s*(k|mille)?\s*(f|fcfa|xaf|frs?)?$/', $t, $m)) {
            $n = (int) str_replace([' ', '.'], '', $m[1]);
            return !empty($m[2]) ? $n * 1000 : $n;
        }
        return null;
    }

    public static function fcfa(float $montant): string
    {
        return number_format($montant, 0, ',', "\u{202F}") . "\u{00A0}FCFA";
    }

    /** Numéro camerounais en format international sans « + » (237 et 9 chiffres), ou null. */
    public static function numero(string $texte): ?string
    {
        $chiffres = preg_replace('/\D/', '', $texte);
        if (strlen($chiffres) === 9 && str_starts_with($chiffres, '6')) {
            return '237' . $chiffres;
        }
        if (strlen($chiffres) === 12 && str_starts_with($chiffres, '2376')) {
            return $chiffres;
        }
        // Autres pays : on accepte un numéro international complet (10 à 15 chiffres).
        return (strlen($chiffres) >= 10 && strlen($chiffres) <= 15) ? $chiffres : null;
    }

    public static function chat_id(string $numero): string
    {
        return $numero . '@c.us';
    }

    /** Partie numérique d'un identifiant WhatsApp (« 2376...@c.us », « ...@s.whatsapp.net »), sans le suffixe d'appareil. */
    public static function numero_de(string $id): string
    {
        return preg_replace('/[:@].*$/', '', $id);
    }

    /** « 237690000010 » devient « 6 90 00 00 10 » pour l'affichage. */
    public static function numero_lisible(string $numero): string
    {
        $local = str_starts_with($numero, '237') ? substr($numero, 3) : $numero;
        return strlen($local) === 9 ? trim(chunk_split(substr($local, 0, 1), 1, ' ') . chunk_split(substr($local, 1), 2, ' ')) : $local;
    }

    public static function nom_boutique(int $vendeur): string
    {
        $nom = function_exists('wcfm_get_vendor_store_name') ? wp_strip_all_tags((string) wcfm_get_vendor_store_name($vendeur)) : '';
        return $nom !== '' ? $nom : (string) get_the_author_meta('display_name', $vendeur);
    }

    /** Vendeur WCFM courant (null si l'utilisateur connecté n'en est pas un). */
    public static function vendeur_courant(): ?int
    {
        $id = get_current_user_id();
        if (!$id) {
            return null;
        }
        if (function_exists('wcfm_is_vendor') && wcfm_is_vendor($id)) {
            return $id;
        }
        return user_can($id, 'manage_woocommerce') ? $id : null;
    }

    public static function journal(string $message, array $contexte = []): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->info($message . ($contexte ? ' ' . wp_json_encode($contexte) : ''), ['source' => 'bayam-whatsapp']);
        }
    }
}
