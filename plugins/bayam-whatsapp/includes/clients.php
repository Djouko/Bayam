<?php
defined('ABSPATH') || exit;

/**
 * Côté clients du vendeur : réponses automatiques aux mots-clés (« #robes », « prix des sacs ? ») et envoi d'une
 * collection. Jamais de premier contact : on répond à qui a écrit, sinon on prépare un lien wa.me que le vendeur
 * envoie lui-même.
 */
final class Bayam_WA_Clients
{
    /** Mots qui montrent qu'un client cherche un article. */
    private const DEMANDES = ['prix', 'combien', 'dispo', 'disponible', 'disponibles', 'catalogue', 'vous avez', 'avez-vous', 'avez vous', 'je cherche', 'je veux', 'il reste', 'en stock'];

    /** Mots sans valeur pour la recherche dans une question de client. */
    private const VIDES = ['prix', 'combien', 'le', 'la', 'les', 'des', 'de', 'du', 'un', 'une', 'vos', 'votre', 'vous', 'avez', 'dispo', 'disponible', 'disponibles',
        'catalogue', 'je', 'cherche', 'veux', 'il', 'reste', 'en', 'stock', 'svp', 'stp', 'bonjour', 'bonsoir', 'salut', 'cest', 'c\'est', 'est', 'ce', 'que', 'quoi', 'pour', 'a', 'et', 'ou', 'avec', 'moi'];

    /** Message d'un client : rend true si BAYAM a répondu. */
    public static function message(int $vendeur, string $chat, string $texte, ?string $message_id): bool
    {
        if (!Bayam_WA_Stockage::reglages($vendeur)['mots_cles']) {
            return false;
        }
        $mots = self::recherche($texte);
        if ($mots === null) {
            return false;
        }
        $produits = Bayam_WA_Boutique::chercher($vendeur, $mots, true, 5);
        $boutique = Bayam_WA_Outils::nom_boutique($vendeur);
        if (!$produits) {
            // Rien en stock : réponse polie, le vendeur garde la main pour proposer autre chose.
            return Bayam_WA_Regles::repondre($vendeur, $chat, "Bonjour 👋 Merci de votre message. Nous n'avons pas « {$mots} » en stock pour le moment chez {$boutique} ; nous revenons vers vous très vite avec d'autres propositions.", $message_id);
        }
        $lignes = [];
        foreach ($produits as $i => $p) {
            $lignes[] = ($i + 1) . '. *' . $p->get_name() . '* : ' . Bayam_WA_Outils::fcfa((float) $p->get_price()) . "\n   " . Bayam_WA_Liens::produit($vendeur, $p, 'reponse');
        }
        return Bayam_WA_Regles::repondre($vendeur, $chat, "Bonjour 👋 Voici ce que nous avons en stock chez *{$boutique}* :\n\n" . implode("\n", $lignes)
            . "\n\nTouchez un lien pour voir les photos et commander, ou dites-nous la taille et la couleur souhaitées.", $message_id);
    }

    /** Mots recherchés si le message est une demande d'article (« #robes », « prix robe rouge ? »), sinon null. */
    public static function recherche(string $texte): ?string
    {
        $t = Bayam_WA_Outils::normaliser($texte);
        if (preg_match('/^#\s*([\p{L}\d][\p{L}\d \'-]{1,40})$/u', $t, $m)) {
            return trim($m[1]);
        }
        $demande = false;
        foreach (self::DEMANDES as $d) {
            if (str_contains($t, $d)) {
                $demande = true;
                break;
            }
        }
        if (!$demande || mb_strlen($t) > 120) {
            return null;
        }
        $mots = array_filter(preg_split('/[^\p{L}\d]+/u', $t), static fn ($m) => $m !== '' && !in_array($m, self::VIDES, true) && mb_strlen($m) > 1);
        return $mots ? implode(' ', $mots) : null;
    }

    /** « envoie robes 6 90 00 00 10 » : envoi si le client a déjà écrit au vendeur, sinon lien wa.me prêt à envoyer. */
    public static function envoyer_collection(int $vendeur, string $numero, array $produits): string
    {
        $chat = Bayam_WA_Outils::chat_id($numero);
        $boutique = Bayam_WA_Outils::nom_boutique($vendeur);
        $lignes = [];
        foreach ($produits as $i => $p) {
            $lignes[] = ($i + 1) . '. ' . $p->get_name() . ' : ' . Bayam_WA_Outils::fcfa((float) $p->get_price()) . ' ' . Bayam_WA_Liens::produit($vendeur, $p, 'collection');
        }
        $message = "Bonjour 👋 Voici la sélection de {$boutique} pour vous :\n\n" . implode("\n", $lignes);
        if (self::a_deja_ecrit($vendeur, $chat)) {
            return Bayam_WA_Regles::repondre($vendeur, $chat, $message, null, 'collection')
                ? '📤 Collection envoyée au ' . Bayam_WA_Outils::numero_lisible($numero) . ' (' . count($produits) . ' articles).'
                : "⏳ Plafond d'envois du jour atteint, ou WhatsApp limite temporairement votre numéro : réessayez demain.";
        }
        $lien = 'https://wa.me/' . $numero . '?text=' . rawurlencode($message);
        return "Ce client ne vous a encore jamais écrit : pour protéger votre numéro, BAYAM ne lui écrit pas en premier.\nTouchez ce lien, le message est prêt, il ne reste qu'à l'envoyer :\n{$lien}";
    }

    /** Le client a-t-il déjà écrit à ce vendeur ? (messages reçus enregistrés, puis liste des discussions WAHA) */
    public static function a_deja_ecrit(int $vendeur, string $chat): bool
    {
        global $wpdb;
        $connu = $wpdb->get_var($wpdb->prepare('SELECT 1 FROM ' . Bayam_WA_Stockage::table('wa_messages') . " WHERE vendeur = %d AND chat = %s AND sens = 'in' LIMIT 1", $vendeur, $chat));
        if ($connu) {
            return true;
        }
        $discussions = Bayam_WA_Waha::client()->discussions(Bayam_WA_Stockage::reglages($vendeur)['session'], 300);
        if (is_wp_error($discussions)) {
            return false;
        }
        foreach ($discussions as $d) {
            if (Bayam_WA_Outils::numero_de((string) ($d['id'] ?? '')) === Bayam_WA_Outils::numero_de($chat)) {
                return true;
            }
        }
        return false;
    }
}
