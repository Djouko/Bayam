<?php
defined('ABSPATH') || exit;

/** Tables de l'extension et réglages WhatsApp de chaque vendeur. */
final class Bayam_WA_Stockage
{
    public static function table(string $nom): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bayam_' . $nom;
    }

    public static function installer(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        // dbDelta veut deux espaces après PRIMARY KEY et une colonne par ligne.
        dbDelta("CREATE TABLE " . self::table('wa_evenements') . " (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session varchar(64) NOT NULL,
  type varchar(40) NOT NULL,
  charge longtext NOT NULL,
  recu datetime NOT NULL,
  traite tinyint NOT NULL DEFAULT 0,
  erreur text NULL,
  PRIMARY KEY  (id),
  KEY session (session)
) $c;");
        dbDelta("CREATE TABLE " . self::table('wa_messages') . " (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  vendeur bigint unsigned NOT NULL,
  chat varchar(80) NOT NULL,
  sens varchar(8) NOT NULL,
  nature varchar(20) NOT NULL DEFAULT 'texte',
  texte text NULL,
  message_id varchar(190) NULL,
  cree datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY vendeur_cree (vendeur, cree),
  KEY message_id (message_id)
) $c;");
        dbDelta("CREATE TABLE " . self::table('dettes') . " (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  vendeur bigint unsigned NOT NULL,
  client varchar(120) NOT NULL,
  numero varchar(20) NULL,
  montant bigint NOT NULL,
  reste bigint NOT NULL,
  echeance date NULL,
  note varchar(255) NULL,
  cree datetime NOT NULL,
  solde datetime NULL,
  PRIMARY KEY  (id),
  KEY vendeur (vendeur)
) $c;");
        dbDelta("CREATE TABLE " . self::table('liens') . " (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  code varchar(16) NOT NULL,
  vendeur bigint unsigned NOT NULL,
  cible text NOT NULL,
  canal varchar(30) NOT NULL DEFAULT 'whatsapp',
  groupe varchar(80) NULL,
  produit bigint unsigned NULL,
  cree datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY vendeur (vendeur)
) $c;");
        dbDelta("CREATE TABLE " . self::table('clics') . " (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  lien bigint unsigned NOT NULL,
  cree datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY lien (lien)
) $c;");
        dbDelta("CREATE TABLE " . self::table('diffusions') . " (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  vendeur bigint unsigned NOT NULL,
  type varchar(10) NOT NULL,
  chat varchar(80) NULL,
  produit bigint unsigned NOT NULL,
  statut varchar(12) NOT NULL DEFAULT 'attente',
  prevu datetime NOT NULL,
  envoye datetime NULL,
  erreur varchar(255) NULL,
  PRIMARY KEY  (id),
  KEY statut_prevu (statut, prevu)
) $c;");
        update_option('bayam_wa_version', BAYAM_WA_VERSION);
    }

    /** Réglages WhatsApp d'un vendeur, avec leurs valeurs par défaut. */
    public static function reglages(int $vendeur): array
    {
        $r = get_user_meta($vendeur, 'bayam_wa', true);
        return wp_parse_args(is_array($r) ? $r : [], [
            'session' => 'boutique-' . $vendeur,
            'consentement' => null,        // date d'acceptation des règles du mode connecté
            'pin' => '',                   // code des actions sensibles demandées par un membre de l'équipe
            'equipe' => [],                // numéros autorisés à piloter la boutique, en plus du vendeur
            'heure_rapport' => 20,         // résumé quotidien, heure de Douala
            'rapport_actif' => true,
            'mots_cles' => true,           // réponses automatiques aux clients qui écrivent « #robes », « prix ? »...
            'groupes' => [],               // groupes choisis pour les publications [id => nom]
            'statut_actif' => true,
        ]);
    }

    public static function enregistrer(int $vendeur, array $valeurs): void
    {
        update_user_meta($vendeur, 'bayam_wa', array_merge(self::reglages($vendeur), $valeurs));
    }

    /** Vendeur propriétaire d'une session WAHA (« boutique-12 » donne 12). */
    public static function vendeur_de_session(string $session): ?int
    {
        return preg_match('/^boutique-(\d+)$/', $session, $m) ? (int) $m[1] : null;
    }

    public static function noter_message(int $vendeur, string $chat, string $sens, string $texte, ?string $message_id, string $nature = 'texte'): void
    {
        global $wpdb;
        $wpdb->insert(self::table('wa_messages'), [
            'vendeur' => $vendeur, 'chat' => $chat, 'sens' => $sens, 'nature' => $nature,
            'texte' => mb_substr($texte, 0, 1000), 'message_id' => $message_id, 'cree' => current_time('mysql'),
        ]);
    }

    /** Messages envoyés par BAYAM pour ce vendeur depuis $depuis (quotas). */
    public static function envois_depuis(int $vendeur, string $depuis, ?string $nature = null): int
    {
        global $wpdb;
        $sql = $wpdb->prepare("SELECT COUNT(*) FROM " . self::table('wa_messages') . " WHERE vendeur = %d AND sens = 'out' AND cree >= %s", $vendeur, $depuis);
        if ($nature) {
            $sql .= $wpdb->prepare(' AND nature = %s', $nature);
        }
        return (int) $wpdb->get_var($sql);
    }

    public static function est_notre_message(string $message_id): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM " . self::table('wa_messages') . " WHERE message_id = %s AND sens = 'out' LIMIT 1", $message_id));
    }
}
