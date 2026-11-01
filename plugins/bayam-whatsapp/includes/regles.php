<?php
defined('ABSPATH') || exit;

/**
 * Règles d'usage responsable du mode connecté (cahier des charges, exigence CAN et guide anti-blocage de WAHA) :
 * jamais de premier contact automatisé, « vu » puis frappe simulée et délai aléatoire avant chaque réponse,
 * plafonds quotidiens, et arrêt des envois vers les clients tant que WhatsApp restreint le numéro.
 * Tous les envois de l'extension passent par ici.
 */
final class Bayam_WA_Regles
{
    /** Plafonds par jour et par boutique (prudents pour un numéro personnel ou WhatsApp Business). */
    public const PLAFONDS = ['reponse' => 300, 'assistant' => 300, 'diffusion' => 40, 'statut' => 10, 'collection' => 30];

    /** Messages au vendeur lui-même (Copilote) : aucune restriction, pas de délai. */
    public static function au_vendeur(int $vendeur, string $texte): void
    {
        $session = Bayam_WA_Stockage::reglages($vendeur)['session'];
        $moi = self::chat_vendeur($vendeur);
        if (!$moi) {
            return;
        }
        self::envoyer($vendeur, $session, $moi, $texte, 'copilote');
    }

    public static function image_au_vendeur(int $vendeur, array $fichier, string $legende): void
    {
        $session = Bayam_WA_Stockage::reglages($vendeur)['session'];
        $moi = self::chat_vendeur($vendeur);
        if (!$moi) {
            return;
        }
        $r = Bayam_WA_Waha::client()->image($session, $moi, $fichier, Bayam_WA_Outils::MARQUE . $legende);
        Bayam_WA_Stockage::noter_message($vendeur, $moi, 'out', $legende, is_array($r) ? self::id_de($r) : null, 'copilote');
    }

    /**
     * Réponse à un client qui vient d'écrire (jamais de premier contact) : vu, frappe simulée, délai, envoi.
     * Rend false si le plafond du jour est atteint ou si WhatsApp restreint le numéro.
     */
    public static function repondre(int $vendeur, string $chat, string $texte, ?string $message_recu = null, string $nature = 'reponse', ?string $citer = null): bool
    {
        if (!self::autorise($vendeur, $nature)) {
            return false;
        }
        $session = Bayam_WA_Stockage::reglages($vendeur)['session'];
        $waha = Bayam_WA_Waha::client();
        $waha->vu($session, $chat, $message_recu);
        $waha->frappe($session, $chat, true);
        // Temps de frappe humain : 1,2 à 3,5 secondes selon la longueur, avec une part de hasard.
        usleep((int) (min(3.5, 1.2 + mb_strlen($texte) / 120) * 1e6 * (0.8 + mt_rand(0, 40) / 100)));
        $waha->frappe($session, $chat, false);
        return self::envoyer($vendeur, $session, $chat, $texte, $nature, $citer);
    }

    public static function repondre_image(int $vendeur, string $chat, array $fichier, string $legende, string $nature = 'reponse'): bool
    {
        if (!self::autorise($vendeur, $nature)) {
            return false;
        }
        $session = Bayam_WA_Stockage::reglages($vendeur)['session'];
        usleep(mt_rand(800, 2000) * 1000);
        $r = Bayam_WA_Waha::client()->image($session, $chat, $fichier, Bayam_WA_Outils::MARQUE . $legende);
        if (is_wp_error($r)) {
            return false;
        }
        Bayam_WA_Stockage::noter_message($vendeur, $chat, 'out', $legende, self::id_de($r), $nature);
        return true;
    }

    /** Plafond du jour et restriction WhatsApp (« timelock ») vérifiés avant tout envoi vers un tiers. */
    public static function autorise(int $vendeur, string $nature): bool
    {
        $minuit = wp_date('Y-m-d 00:00:00');
        $plafond = self::PLAFONDS[$nature] ?? self::PLAFONDS['reponse'];
        if (Bayam_WA_Stockage::envois_depuis($vendeur, $minuit, $nature) >= $plafond) {
            return false;
        }
        return !self::restreint($vendeur);
    }

    /** WhatsApp limite-t-il ce numéro ? (réponse gardée 10 minutes ; on ne redémarre jamais la session pour autant). */
    public static function restreint(int $vendeur): bool
    {
        $cle = 'bayam_wa_blocage_' . $vendeur;
        $etat = get_transient($cle);
        if ($etat === false) {
            $r = Bayam_WA_Waha::client()->blocage(Bayam_WA_Stockage::reglages($vendeur)['session']);
            $etat = (is_array($r) && !empty($r['isActive'])) ? 'oui' : 'non';
            set_transient($cle, $etat, 10 * MINUTE_IN_SECONDS);
        }
        return $etat === 'oui';
    }

    /** Discussion « Message à moi-même » du vendeur (son propre numéro), connue une fois la session active. */
    public static function chat_vendeur(int $vendeur): ?string
    {
        $moi = get_user_meta($vendeur, 'bayam_wa_moi', true);
        return $moi ? Bayam_WA_Outils::chat_id(Bayam_WA_Outils::numero_de($moi)) : null;
    }

    private static function envoyer(int $vendeur, string $session, string $chat, string $texte, string $nature, ?string $citer = null): bool
    {
        $r = Bayam_WA_Waha::client()->texte($session, $chat, Bayam_WA_Outils::MARQUE . $texte, $citer);
        if (is_wp_error($r)) {
            Bayam_WA_Outils::journal('Envoi refusé', ['vendeur' => $vendeur, 'erreur' => $r->get_error_message()]);
            return false;
        }
        Bayam_WA_Stockage::noter_message($vendeur, $chat, 'out', $texte, self::id_de($r), $nature);
        return true;
    }

    /** Identifiant du message envoyé, selon la forme de la réponse du moteur. */
    public static function id_de(array $reponse): ?string
    {
        $id = $reponse['id'] ?? ($reponse['key']['id'] ?? null);
        if (is_array($id)) {
            $id = $id['_serialized'] ?? ($id['id'] ?? null);
        }
        return $id ? (string) $id : null;
    }
}
