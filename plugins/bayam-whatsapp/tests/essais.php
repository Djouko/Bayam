<?php
/**
 * Essais de bout en bout de BAYAM WhatsApp, sans téléphone : un faux client WAHA enregistre les envois, on rejoue
 * des événements WhatsApp réels (vendeur, équipe, clients, photo) sur la boutique Mode Aïcha, on vérifie réponses et
 * effets en base, puis on remet tout en état.
 * Lancer : docker compose run --rm cli wp eval-file /var/www/html/wp-content/plugins/bayam-whatsapp/tests/essais.php
 */

final class Bayam_Faux_Waha extends Bayam_WA_Waha
{
    public array $envois = [];
    public string $statut = 'WORKING';

    protected function appel(string $methode, string $chemin, ?array $corps = null, array $requete = [], int $delai = 20)
    {
        $this->envois[] = ['methode' => $methode, 'chemin' => $chemin, 'corps' => $corps];
        if (str_ends_with($chemin, '/timelock')) {
            return ['isActive' => false];
        }
        if (str_ends_with($chemin, '/capping')) {
            return ['cappingStatus' => 'NONE'];
        }
        if (str_ends_with($chemin, '/chats')) {
            return [['id' => '237677000001@c.us']];
        }
        if (str_ends_with($chemin, '/channels')) {
            // Une chaîne du vendeur, une autre qu'il suit seulement.
            return [['id' => '120363000000000777@newsletter', 'name' => 'Nouveautés Mode Aïcha', 'role' => 'OWNER'], ['id' => '120363000000000888@newsletter', 'name' => 'Actus foot', 'role' => 'SUBSCRIBER']];
        }
        if (in_array($chemin, ['/api/sendText', '/api/sendImage'], true) || str_contains($chemin, '/status/')) {
            return ['id' => 'true_' . count($this->envois) . '_BAYAMESSAI'];
        }
        if (str_ends_with($chemin, '/auth/request-code')) {
            return ['code' => 'ABCD-1234'];
        }
        if (str_starts_with($chemin, '/api/sessions/')) {
            return ['status' => $this->statut, 'me' => ['id' => '237690000003@c.us', 'pushName' => 'Mode Aïcha']];
        }
        return [];
    }

    public function telecharger(string $url)
    {
        return file_get_contents(get_attached_file(wc_get_product(17)->get_image_id()));
    }
}

$vendeur = 3;
// Les essais tournent sur une vraie boutique : on garde son WhatsApp (réglages, identité) et ses données pour les rendre à la fin.
global $wpdb;
// Instantané gardé en base le temps des essais : après un passage interrompu, le suivant restaure le vrai état
// (et non l'état d'essai laissé derrière lui).
$sauvegarde = get_option('bayam_wa_essais_sauvegarde');
if (!is_array($sauvegarde)) {
    $sauvegarde = [];
    foreach (['bayam_wa', 'bayam_wa_moi', 'bayam_wa_lid', 'bayam_wa_statut', 'bayam_wa_dernier_rapport', 'bayam_wa_bienvenue'] as $cle) {
        $sauvegarde[$cle] = get_user_meta($vendeur, $cle, true);
    }
    update_option('bayam_wa_essais_sauvegarde', $sauvegarde, false);
}
$depart = [];
foreach (['dettes', 'diffusions', 'wa_messages', 'liens'] as $table) {
    $depart[$table] = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM " . Bayam_WA_Stockage::table($table));
}
// Le travailleur permanent se met en pause : il n'enverra pas pour de vrai les publications des essais.
update_option('bayam_wa_essais_jusqua', time() + 600, false);
$faux = $GLOBALS['faux'] = new Bayam_Faux_Waha();
// Restes d'un passage interrompu (coupure, arrêt forcé) : code promo, compteurs de rafale, stock du T-shirt,
// dette, publications et liens des groupes d'essai ; les essais repartent toujours du même état.
foreach ($wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_title = 'bayamessai'") as $id_coupon) {
    wp_delete_post((int) $id_coupon, true);
}
foreach (['237677000001@c.us', '237677000002@c.us'] as $chat_essai) {
    delete_transient('bayam_wa_rafale_' . md5($vendeur . '|' . $chat_essai));
}
// Stocks réels des articles que les essais font varier : gardés en base le temps des essais (comme l'instantané
// ci-dessus), restaurés à la fin, même après un passage interrompu.
$stocks_reels = get_option('bayam_wa_essais_stocks');
if (!is_array($stocks_reels)) {
    $stocks_reels = [];
    foreach (array_merge([17, 25, 237], wc_get_product(15)->get_children()) as $id_stock) {
        $p_stock = wc_get_product($id_stock);
        $stocks_reels[$id_stock] = [$p_stock->managing_stock(), $p_stock->get_stock_quantity(), $p_stock->get_stock_status()];
    }
    update_option('bayam_wa_essais_stocks', $stocks_reels, false);
}
$tshirt_essai = wc_get_product(17);
$tshirt_essai->set_manage_stock(false);
$tshirt_essai->set_stock_status('instock');
$tshirt_essai->save();
$wpdb->query($wpdb->prepare('DELETE FROM ' . Bayam_WA_Stockage::table('dettes') . " WHERE vendeur = %d AND client = 'Ndzana'", $vendeur));
$groupes_essai = "('120363000000000001@g.us', '120363000000000002@g.us', '120363000000000777@newsletter')";
delete_transient("bayam_wa_chaines_{$vendeur}"); // chaînes du faux WAHA : jamais gardées pour les vrais envois
$wpdb->query('DELETE c FROM ' . Bayam_WA_Stockage::table('clics') . ' c JOIN ' . Bayam_WA_Stockage::table('liens') . " l ON l.id = c.lien WHERE l.groupe IN $groupes_essai");
$wpdb->query('DELETE FROM ' . Bayam_WA_Stockage::table('liens') . " WHERE groupe IN $groupes_essai");
$wpdb->query('DELETE FROM ' . Bayam_WA_Stockage::table('diffusions') . " WHERE chat IN $groupes_essai");
if (get_option('bayam_wa_essais_debut')) {
    bayam_supprimer_produits_essai($vendeur, get_option('bayam_wa_essais_debut'));
}
Bayam_WA_Waha::remplacer($faux);
// Service Facebook simulé : les essais ne publient jamais sur une vraie Page. $pages_fb décide des Pages « reliées ».
$pages_fb = [];
$appels_fb = [];
Bayam_WA_Facebook::remplacer(static function (string $methode, string $chemin, ?array $corps) use (&$pages_fb, &$appels_fb) {
    $appels_fb[] = [$methode, $chemin, $corps];
    if ($methode === 'GET' && str_ends_with($chemin, '/pages')) {
        return $pages_fb;
    }
    if ($methode === 'DELETE') {
        return ['retiree' => 'ok'];
    }
    return $methode === 'POST' && $chemin === '/interne/publications' ? ['pages' => count($pages_fb)] : null;
});
wp_cache_delete("bayam_fb_pages_{$vendeur}", 'bayam');
// Modèle de langage simulé : aucun appel réel pendant les essais ; par défaut il ne comprend rien.
$ia_ne_comprend_pas = static fn () => ['comprise' => false, 'commande' => '', 'explication' => ''];
Bayam_WA_IA::remplacer($ia_ne_comprend_pas);
update_user_meta($vendeur, 'bayam_wa_moi', '237690000003@c.us');
update_user_meta($vendeur, 'bayam_wa_statut', 'WORKING');
delete_user_meta($vendeur, 'bayam_wa');
$debut = current_time('mysql');
$debut_ts = time();
update_option('bayam_wa_essais_debut', $debut, false);
$GLOBALS['reussis'] = 0;
$GLOBALS['echecs'] = [];

/** Rejoue un message et rend les textes (et légendes d'images) envoyés en réponse. */
/** Articles créés par les essais depuis $depuis (brouillons compris), avec leurs tailles et leur photo. */
function bayam_supprimer_produits_essai(int $vendeur, string $depuis): void
{
    foreach (get_posts(['post_type' => 'product', 'author' => $vendeur, 'date_query' => [['after' => $depuis, 'inclusive' => true]], 'numberposts' => 20, 'post_status' => 'any']) as $p) {
        $w = wc_get_product($p->ID);
        if ($w) {
            foreach ($w->get_children() as $c) {
                wp_delete_post($c, true);
            }
            wp_delete_attachment($w->get_image_id(), true);
            $w->delete(true);
        }
    }
}

function bayam_dire(string $texte, string $de = '237690000003@c.us', bool $moi = true, bool $photo = false): array
{
    $faux = $GLOBALS['faux'];
    $faux->envois = [];
    $payload = ['id' => 'false_' . wp_generate_password(10, false), 'from' => $moi ? '237690000003@c.us' : $de, 'to' => $moi ? '237690000003@c.us' : '237690000003@c.us', 'fromMe' => $moi, 'body' => $texte];
    if ($photo) {
        $payload += ['hasMedia' => true, 'media' => ['url' => 'http://localhost:3000/api/files/photo.jpg', 'mimetype' => 'image/jpeg']];
    }
    Bayam_WA_Webhook::evenement(['event' => 'message.any', 'session' => 'boutique-3', 'me' => ['id' => '237690000003@c.us'], 'payload' => $payload]);
    $sorties = [];
    foreach ($faux->envois as $e) {
        if (in_array($e['chemin'], ['/api/sendText', '/api/sendImage'], true) || str_contains($e['chemin'], '/status/')) {
            $sorties[] = ($e['corps']['text'] ?? $e['corps']['caption'] ?? '') . (isset($e['corps']['file']) ? ' [image]' : '');
        }
    }
    return $sorties;
}

function bayam_verifier(string $nom, bool $condition, $detail = ''): void
{
    if ($condition) {
        $GLOBALS['reussis']++;
        echo "  ok    $nom\n";
    } else {
        $GLOBALS['echecs'][] = $nom;
        echo "  ÉCHEC $nom\n" . ($detail !== '' ? '        ' . mb_substr(is_string($detail) ? $detail : wp_json_encode($detail, JSON_UNESCAPED_UNICODE), 0, 400) . "\n" : '');
    }
}

$joint = static fn (array $s) => implode("\n---\n", $s);

echo "Copilote du vendeur\n";
$r = bayam_dire('Bayam menu');
bayam_verifier('menu', count($r) === 1 && str_contains($r[0], 'Copilote BAYAM') && str_contains($r[0], 'vendu robe rouge'), $r);
$r = bayam_dire('BAYAM sweats');
bayam_verifier('recherche « sweats » (pluriel)', str_contains($joint($r), 'Sweat à capuche') && str_contains($joint($r), '/b/'), $r);
$r = bayam_dire('bayam #tshirt');
bayam_verifier('« #tshirt » sans tiret ne trouve rien proprement', count($r) === 1, $r);
$r = bayam_dire('bayan catalgue');
bayam_verifier('faute de frappe « catalgue »', str_contains($joint($r), 'Votre catalogue'), $r);
$r = bayam_dire('Bayam, cartes polo');
bayam_verifier('cartes : image + conseil de transfert', count($r) === 2 && str_contains($r[0], '[image]') && str_contains($r[1], 'Transférer'), $r);
$r = bayam_dire('@bayam stock sweat capuche');
bayam_verifier('stock par variante', str_contains($joint($r), 'Stock : Sweat à capuche') && substr_count($joint($r), "\n") >= 3, $r);
$r = bayam_dire('bayam: +5 t-shirt');
bayam_verifier('entrée de stock +5', str_contains($joint($r), 'Stock : 5'), $r);
$r = bayam_dire('bayam -2 t-shirt');
bayam_verifier('sortie de stock -2', str_contains($joint($r), 'Stock : 3'), $r);
$r = bayam_dire('bayam vendu t-shirt x2 20000 momo');
bayam_verifier('vente directe au prix négocié', str_contains($joint($r), 'enregistrée') && str_contains($joint($r), "20\u{202F}000") && str_contains($joint($r), 'Stock restant : 1'), $r);
$r = bayam_dire('bayam vendu t-shirt x5 cash');
bayam_verifier('vente refusée si stock insuffisant', str_contains($joint($r), 'Stock insuffisant'), $r);
$r = bayam_dire('bayam vendu sweat capuche');
bayam_verifier('vente d’un produit à variantes : demande la variante', str_contains($joint($r), 'Précisez la variante'), $r);
$r = bayam_dire('bayam vendu sweat capuche rouge non 25000 cash');
bayam_verifier('vente d’une variante (rouge, sans logo)', str_contains($joint($r), 'enregistrée'), $r);
$r = bayam_dire('bayam lien 15000 Marie');
bayam_verifier('lien de paiement', str_contains($joint($r), '15') && str_contains($joint($r), 'Marie') && str_contains($joint($r), '/b/'), $r);
$r = bayam_dire('bayam lien 8500 nadège');
bayam_verifier('nom du client avec ses accents (« Nadège »)', str_contains($joint($r), 'Nadège'), $r);
$r = bayam_dire('bayamm commandes');
bayam_verifier('commandes en cours (lien de paiement en attente)', str_contains($joint($r), 'En attente de paiement') && str_contains($joint($r), 'Marie'), $r);
$r = bayam_dire('Bayma bilan');
bayam_verifier('bilan du jour', str_contains($joint($r), "Bilan aujourd'hui") && str_contains($joint($r), 'Chiffre d'), $r);
$r = bayam_dire('bayam bilan semaine');
bayam_verifier('bilan de la semaine', str_contains($joint($r), 'cette semaine'), $r);
// Nom propre aux essais : la boutique a ses vraies dettes.
$r = bayam_dire('bayam dette Ndzana 5000 15/11');
bayam_verifier('dette notée avec échéance', str_contains($joint($r), 'Ndzana') && str_contains($joint($r), '15/11'), $r);
$r = bayam_dire('bayam dettes');
bayam_verifier('liste des dettes', str_contains($joint($r), 'Qui me doit quoi') && str_contains($joint($r), 'Ndzana'), $r);
$r = bayam_dire('bayam payé Ndzana 2000');
bayam_verifier('paiement partiel d’une dette', str_contains($joint($r), "Reste à payer : 3\u{202F}000"), $r);

echo "\nPhrases naturelles de vendeurs\n";
$r = bayam_dire('Bayam Ndzana a payé 1000');
bayam_verifier('« Ndzana a payé 1000 » : paiement de dette', str_contains($joint($r), "Reste à payer : 2\u{202F}000"), $r);
$r = bayam_dire('Bayam Zacharie m’a payé 500');
bayam_verifier('paiement d’un inconnu : aucune liste d’articles au hasard', str_contains($joint($r), 'Je ne trouve pas de dette'), $r);
$r = bayam_dire('bayam qui me doit ?');
bayam_verifier('« qui me doit ? » : liste des dettes', str_contains($joint($r), 'Qui me doit quoi'), $r);
$r = bayam_dire('Bayam combien de polo il reste ?');
bayam_verifier('« combien de polo il reste ? » : stock du Polo', str_contains($joint($r), '*Stock : Polo*'), $r);
$r = bayam_dire('bayam stock');
bayam_verifier('« bayam stock » seul : état du stock de la boutique', str_contains($joint($r), 'Stock de votre boutique'), $r);
$r = bayam_dire('bayam mes ventes de la semaine');
bayam_verifier('« mes ventes de la semaine » : bilan de la semaine', str_contains($joint($r), 'cette semaine'), $r);
$r = bayam_dire('bayam montre moi mes commandes');
bayam_verifier('« montre-moi mes commandes » : commandes en cours', str_contains($joint($r), 'commandes'), $r);
$r = bayam_dire("bayam j'ai reçu 5 t-shirt");
bayam_verifier('« j’ai reçu 5 t-shirt » : entrée de stock', str_contains($joint($r), 'Entrée de 5'), $r);
Bayam_WA_Boutique::mouvement(wc_get_product(17), -5);
$r = bayam_dire('bayam fais moi un café');
bayam_verifier('phrase inconnue : « pas compris », jamais d’articles au hasard', str_contains($joint($r), "pas compris"), $r);
$r = bayam_dire('bayam promo BAYAMESSAI 10%');
bayam_verifier('code promo et message prêt à partager', count($r) === 2 && str_contains($r[0], 'BAYAMESSAI') && str_contains($r[1], '-10 %'), $r);
$r = bayam_dire('bayam promo BAYAMESSAI 10%');
bayam_verifier('code promo en double refusé', str_contains($joint($r), 'existe déjà'), $r);
$r = bayam_dire('bayam rapport 21h');
bayam_verifier('heure du résumé quotidien', str_contains($joint($r), '21 h') && Bayam_WA_Stockage::reglages($vendeur)['heure_rapport'] === 21, $r);
$r = bayam_dire('bayam boutique');
bayam_verifier('lien unique de la boutique', str_contains($joint($r), '/b/'), $r);
$r = bayam_dire('bayam publier polo');
bayam_verifier('publier sans groupe choisi : explique quoi faire', str_contains($joint($r), 'Choisissez d'), $r);
Bayam_WA_Stockage::enregistrer($vendeur, ['groupes' => ['120363000000000001@g.us' => 'Clientes Mode Aïcha', '120363000000000002@g.us' => 'Bonnes affaires Akwa']]);
$r = bayam_dire('bayam publier polo');
bayam_verifier('publier dans 2 groupes : 2 envois programmés', str_contains($joint($r), '2 publication(s)'), $r);
$r = bayam_dire('bayam comment tu vas');
bayam_verifier('message incompris : renvoie à l’aide', str_contains($joint($r), "Je n'ai pas compris") && str_contains($joint($r), 'bayam aide'), $r);
$r = bayam_dire('bayam');
bayam_verifier('« bayam » seul : menu', str_contains($joint($r), 'Copilote BAYAM') && str_contains($joint($r), 'bayam aide'), $r);

echo "\nAide et demandes libres\n";
$r = bayam_dire('bayam aide');
bayam_verifier('« bayam aide » : toutes les actions par thème', str_contains($joint($r), 'Tout ce que BAYAM fait pour vous') && str_contains($joint($r), 'Publier et faire connaître') && str_contains($joint($r), '*publier*'), $r);
$r = bayam_dire('bayam aide publier');
bayam_verifier('« bayam aide publier » : détail et exemples', str_contains($joint($r), 'Publier des articles') && str_contains($joint($r), 'Exemples') && str_contains($joint($r), 'bayam publier nouveautés partout'), $r);
$r = bayam_dire('Bayam aide plancher');
bayam_verifier('aide sur le prix minimum', str_contains($joint($r), 'prix minimum'), $r);
$r = bayam_dire("bayam c'est quoi le plancher ?");
bayam_verifier('« c’est quoi le plancher ? » : explication', str_contains($joint($r), 'prix minimum'), $r);
$r = bayam_dire('bayam comment créer une promo ?');
bayam_verifier('« comment créer une promo ? » : l’aide de la promo, pas de la photo', str_contains($joint($r), 'Créer un code promo'), $r);
$r = bayam_dire('bayam aide vendre');
bayam_verifier('aide par synonyme (« vendre »)', str_contains($joint($r), 'Enregistrer une vente'), $r);
$r = bayam_dire('bayam aide xyz');
bayam_verifier('aide sur une action inconnue : liste complète', str_contains($joint($r), "Je ne connais pas l'action") && str_contains($joint($r), 'Tout ce que BAYAM'), $r);
$appels_ia = [];
Bayam_WA_IA::remplacer(static function ($consignes, $contexte, $messages) use (&$appels_ia) {
    $appels_ia[] = [$contexte, $messages[0]['content']];
    return str_contains($messages[0]['content'], 'niveau')
        ? ['comprise' => true, 'commande' => 'bayam stock polo', 'explication' => 'Stock du polo.']
        : ['comprise' => true, 'commande' => 'bayam vendu polo 12000 cash', 'explication' => 'Vente d’un polo à 12 000 F en espèces.'];
});
$r = bayam_dire('bayam check moi le niveau des polos');
bayam_verifier('demande libre de consultation : comprise et faite tout de suite', str_contains($joint($r), "J'ai compris") && str_contains($joint($r), 'Stock : Polo'), $r);
bayam_verifier('le modèle reçoit les noms des articles de la boutique', str_contains($appels_ia[0][0] ?? '', 'Polo'));
$r = bayam_dire("bayam j'ai écoulé un polo à 12 mille en espèces");
bayam_verifier('demande libre qui modifie : proposée, pas faite', str_contains($joint($r), 'Je propose') && str_contains($joint($r), 'bayam oui') && !str_contains($joint($r), 'enregistrée'), $r);
$r = bayam_dire('bayam oui');
bayam_verifier('« bayam oui » : la vente proposée est enregistrée', str_contains($joint($r), 'enregistrée'), $r);
$r = bayam_dire('bayam oui');
bayam_verifier('« bayam oui » sans proposition : rien ne se passe', str_contains($joint($r), 'Aucune proposition'), $r);
Bayam_WA_IA::remplacer(static fn () => ['comprise' => false, 'commande' => '', 'explication' => 'Il manque le montant du lien de paiement.']);
$r = bayam_dire('bayam fais un lien pour Marie');
bayam_verifier('demande incomplète : le modèle dit ce qui manque', str_contains($joint($r), 'Il manque le montant'), $r);
Bayam_WA_IA::remplacer($ia_ne_comprend_pas);
$r = bayam_dire('bayam vendu casquette brodée 6000 credit Nadège');
bayam_verifier('vente à crédit : dette notée au nom du client, accents gardés', str_contains($joint($r), 'dette de Nadège notée'), $r);

echo "\nPaiement, acompte, périodes, phrases libres, tournures apprises\n";
$r = bayam_dire('bayam paiement');
bayam_verifier('« bayam paiement » : à la livraison par défaut', str_contains($joint($r), 'Paiement à la livraison'), $r);
$r = bayam_dire('bayam paiement acompte 30%');
bayam_verifier('acompte de 30 % choisi ; le numéro qui reçoit l’argent est demandé', str_contains($joint($r), 'Acompte de 30 %') && str_contains($joint($r), 'bayam momo'), $r);
$r = bayam_dire('bayam momo 6 54 77 00 63 Aïcha Mbarga');
bayam_verifier('numéro MoMo et nom enregistrés', str_contains($joint($r), 'MTN MoMo au 6 54 77 00 63 (Aïcha Mbarga)'), $r);
$conditions = Bayam_WA_Boutique::conditions_paiement($vendeur, 18500.0);
bayam_verifier('sommes calculées : acompte arrondi à la centaine, reste à la livraison', str_contains($conditions, "5\u{202F}600") && str_contains($conditions, "12\u{202F}900") && str_contains($conditions, '6 54 77 00 63'), $conditions);
$r = Bayam_WA_Copilote::traiter($vendeur, 'momo 6 99 99 99 99', null, true);
bayam_verifier('un membre de l’équipe ne peut pas changer le numéro qui reçoit l’argent', str_contains($r[0]['texte'] ?? '', 'Seul le propriétaire'), $r);
$r = bayam_dire('bayam paiement à la livraison');
bayam_verifier('retour au paiement à la livraison', str_contains($joint($r), 'Paiement à la livraison'), $r);
$r = bayam_dire('bayam vendu polo 12000 acompte 5000 momo Clarisse');
bayam_verifier('vente avec acompte : reste à encaisser noté au nom de la cliente', str_contains($joint($r), 'Acompte reçu') && str_contains($joint($r), "7\u{202F}000") && str_contains($joint($r), 'Clarisse'), $r);
$acompte = wc_get_orders(['meta_key' => '_bayam_acompte', 'limit' => 1, 'orderby' => 'ID', 'order' => 'DESC'])[0] ?? null;
bayam_verifier('vente avec acompte : en attente du reste', $acompte && $acompte->has_status('on-hold'));
$r = bayam_dire('bayam payé Clarisse 7000');
bayam_verifier('reste payé : plus de dette et la vente est terminée', str_contains($joint($r), 'Plus aucune dette') && $acompte && wc_get_order($acompte->get_id())->has_status('completed'), $r);
$r = bayam_dire("bayam j'ai vendu 1 polo à 12 mille par momo");
bayam_verifier('« j’ai vendu 1 polo à 12 mille par momo » : une vente', str_contains($joint($r), 'enregistrée') && str_contains($joint($r), "12\u{202F}000"), $r);
$r = bayam_dire("bayam qu'est-ce que j'ai vendu hier ?");
bayam_verifier('« qu’est-ce que j’ai vendu hier ? » : le bilan d’hier', str_contains($joint($r), "Bilan d'hier"), $r);
$r = bayam_dire('bayam les commandes');
bayam_verifier('« les commandes »', str_contains($joint($r), 'commandes en cours') || str_contains($joint($r), 'Aucune commande'), $r);
$r = bayam_dire("bayam j'ai besoin d'aide");
bayam_verifier('« j’ai besoin d’aide » : toutes les actions', str_contains($joint($r), 'Tout ce que BAYAM'), $r);
$r = bayam_dire("bayam c'est quoi le stock d'aujourd'hui ?");
bayam_verifier('« c’est quoi le stock d’aujourd’hui ? » : le stock, pas l’aide', str_contains($joint($r), 'Stock de votre boutique'), $r);
$r = bayam_dire('bayam comment je fais pour le paiement à la livraison ?');
bayam_verifier('« comment … paiement à la livraison ? » : l’aide des conditions de paiement', str_contains($joint($r), 'conditions de paiement'), $r);
$aujourdhui = new DateTimeImmutable('today', wp_timezone());
$hier = Bayam_WA_Rapports::periode('hier');
bayam_verifier('période « hier » : de minuit hier à minuit aujourd’hui (le jour même exclu)', $hier[0] === $aujourdhui->modify('-1 day')->format('Y-m-d H:i:s') && $hier[1] === $aujourdhui->format('Y-m-d H:i:s'), $hier);
$avant = Bayam_WA_Rapports::periode('avant-hier');
bayam_verifier('période « avant-hier »', $avant[0] === $aujourdhui->modify('-2 days')->format('Y-m-d H:i:s') && $avant[1] === $hier[0], $avant);
$semaine = Bayam_WA_Rapports::periode('la semaine passée');
bayam_verifier('période « la semaine passée » : jusqu’à lundi de cette semaine', $semaine[1] === $aujourdhui->modify('monday this week')->format('Y-m-d H:i:s'), $semaine);
$jour = Bayam_WA_Rapports::periode('le 3 novembre');
bayam_verifier('période « le 3 novembre »', substr($jour[0], 5, 5) === '11-03' && $jour[2] === 'du 3 novembre', $jour);
$appels_tournure = 0;
Bayam_WA_IA::remplacer(static function () use (&$appels_tournure) {
    $appels_tournure++;
    return ['comprise' => true, 'commande' => 'bayam stock polo', 'explication' => ''];
});
$r = bayam_dire('bayam donne le niveau du polo stp');
$r = bayam_dire('bayam donne le niveau du polo stp');
bayam_verifier('tournure apprise : comprise la seconde fois sans rappeler le modèle', $appels_tournure === 1 && str_contains($joint($r), 'Stock : Polo'), $r);
$r = bayam_dire('bayam raccourcis');
bayam_verifier('« bayam raccourcis » : ce que BAYAM a appris', str_contains($joint($r), 'niveau du polo') && str_contains($joint($r), 'bayam stock polo'), $r);
$r = bayam_dire('bayam raccourcis effacer');
bayam_verifier('tournures apprises effacées', str_contains($joint($r), 'effacées') && !get_user_meta($vendeur, 'bayam_wa_raccourcis', true), $r);
Bayam_WA_IA::remplacer($ia_ne_comprend_pas);
$r = bayam_dire('bayam cartes polo');
bayam_verifier('article partagé seul : vraie photo, détails en légende (rien de répété sur l’image)', str_contains($r[0] ?? '', '[image]') && str_contains($r[0] ?? '', '💰') && str_contains($r[0] ?? '', '👉 Commander'), $r);
$r = bayam_dire('Acheter du pain et payer le loyer demain');
bayam_verifier('note personnelle sans « bayam » : silence', $r === [], $r);
$r = bayam_dire('stock polo');
bayam_verifier('commande sans « bayam » : silence (le vendeur prend des notes ici)', $r === [], $r);
$r = bayam_dire('Bayam-sellam du marché Mokolo à rappeler');
bayam_verifier('« bayam-sellam » reste un mot ordinaire', $r === [], $r);
$r = bayam_dire('Robe verte 9000', photo: true);
bayam_verifier('photo sans « bayam » en légende : aucun produit créé', $r === [], $r);
$r = bayam_dire('bayam ROBE ROUGE 15000 M:3 L:2', photo: true);
bayam_verifier('photo + légende : produit à tailles créé', str_contains($joint($r), 'Produit créé') && str_contains($joint($r), 'M : 3'), $r);
$robe = get_posts(['post_type' => 'product', 'author' => $vendeur, 'title' => 'Robe rouge', 'post_status' => 'any', 'date_query' => [['after' => $debut, 'inclusive' => true]]]);
$robe = $robe ? wc_get_product($robe[0]->ID) : null;
bayam_verifier('produit créé : publié, au vendeur, complet', $robe && $robe->get_status() === 'publish' && count($robe->get_children()) === 2 && (float) $robe->get_price() === 15000.0);
$r = bayam_dire('bayam casquette brodée 6500 4', photo: true);
bayam_verifier('photo : accents gardés, quantité après le prix', str_contains($joint($r), '*Casquette brodée*') && str_contains($joint($r), "6\u{202F}500") && str_contains($joint($r), '4 pièce(s)'), $r);
$r = bayam_dire('bayam Sandales pointure 42 25 000 42:2', photo: true);
bayam_verifier('photo : nombre du nom ignoré pour le prix', str_contains($joint($r), '*Sandales pointure 42*') && str_contains($joint($r), "25\u{202F}000") && str_contains($joint($r), '42 : 2'), $r);

echo "\nÉquipe (numéro autorisé, commandes avec « / »)\n";
delete_transient('bayam_wa_pin_echecs_' . $vendeur);
Bayam_WA_Outils::redis()?->del('bayam:bayam_wa_pin_echecs_' . $vendeur);
$r = bayam_dire('bayam pin 4826');
bayam_verifier('PIN choisi par le vendeur', str_contains($joint($r), 'PIN'), $r);
$r = bayam_dire('bayam equipe ajouter 6 77 00 00 02');
bayam_verifier('membre ajouté', str_contains($joint($r), 'Ajouté'), $r);
$r = bayam_dire('stock polo', '237677000002@c.us', false);
bayam_verifier('équipe sans « bayam » ni « / » : conversation ordinaire, silence', $r === [], $r);
$r = bayam_dire('Bayam stock polo', '237677000002@c.us', false);
bayam_verifier('équipe « Bayam stock polo »', str_contains($joint($r), 'Polo'), $r);
$r = bayam_dire('/stock polo', '237677000002@c.us', false);
bayam_verifier('membre : stock sans PIN', str_contains($joint($r), 'Stock : Polo'), $r);
$r = bayam_dire('/vendu polo 12000 cash', '237677000002@c.us', false);
bayam_verifier('membre : vente refusée sans PIN', str_contains($joint($r), 'code PIN'), $r);
$r = bayam_dire('/vendu polo 12000 cash pin 4826', '237677000002@c.us', false);
bayam_verifier('membre : vente acceptée avec PIN', str_contains($joint($r), 'enregistrée'), $r);
$r = bayam_dire('/pin 1111', '237677000002@c.us', false);
bayam_verifier('membre : ne change pas le PIN', str_contains($joint($r), 'propriétaire'), $r);
$r = bayam_dire('bayam lien 5000 Awa pin 1111', '237677000002@c.us', false);
bayam_verifier('membre : PIN faux signalé comme tel', str_contains($joint($r), 'Code PIN incorrect'), $r);
foreach (['2222', '3333', '4444'] as $faux_pin) {
    bayam_dire("bayam lien 5000 Awa pin $faux_pin", '237677000002@c.us', false);
}
$faux->envois = [];
bayam_dire('bayam lien 5000 Awa pin 5555', '237677000002@c.us', false);
$alerte = array_filter($faux->envois, static fn ($e) => str_contains((string) ($e['corps']['text'] ?? ''), '5 codes PIN erronés'));
bayam_verifier('5e PIN faux : le vendeur est alerté', (bool) $alerte, array_column($faux->envois, 'chemin'));
$r = bayam_dire('bayam lien 5000 Awa pin 4826', '237677000002@c.us', false);
bayam_verifier('après 5 erreurs : même le bon PIN est bloqué 15 min', str_contains($joint($r), 'bloquées pendant 15 minutes'), $r);
delete_transient('bayam_wa_pin_echecs_' . $vendeur);
Bayam_WA_Outils::redis()?->del('bayam:bayam_wa_pin_echecs_' . $vendeur); // compteur atomique des essais de PIN

echo "\nClients du vendeur\n";
$r = bayam_dire('#polo', '237677000001@c.us', false);
bayam_verifier('client « #polo » : articles en stock', str_contains($joint($r), 'Polo') && str_contains($joint($r), 'Bonjour'), $r);
$r = bayam_dire('Bonsoir, c\'est combien les sweats svp ?', '237677000001@c.us', false);
bayam_verifier('conversation ordinaire (« combien ») : silence', $r === [], $r);
$r = bayam_dire('Bayam, vous avez des sweats ?', '237677000001@c.us', false);
bayam_verifier('client « Bayam, vous avez des sweats ? » : articles en stock', str_contains($joint($r), 'Sweat'), $r);
$r = bayam_dire('BAYAM', '237677000001@c.us', false);
bayam_verifier('client « BAYAM » seul : présentation de l’assistant', str_contains($joint($r), 'assistant BAYAM') && str_contains($joint($r), '#robe'), $r);
$r = bayam_dire('Merci beaucoup, à demain', '237677000001@c.us', false);
bayam_verifier('client sans demande d’article : pas de réponse automatique', $r === [], $r);
$r = bayam_dire('bayam vous avez des parapluies ?', '237677000001@c.us', false);
bayam_verifier('article absent : réponse polie', str_contains($joint($r), "n'avons pas"), $r);
$r = bayam_dire(Bayam_WA_Outils::MARQUE . 'menu');
bayam_verifier('nos propres messages sont ignorés (pas de boucle)', $r === [], $r);
$faux->envois = [];
Bayam_WA_Webhook::evenement(['event' => 'message.any', 'session' => 'boutique-3', 'payload' => [
    'id' => 'false_ancien', 'from' => '237677000001@c.us', 'fromMe' => false, 'body' => '#polo', 'timestamp' => time() - 3600,
]]);
bayam_verifier('message rejoué une heure après (reconnexion) : pas de réponse tardive', $faux->envois === [], array_column($faux->envois, 'chemin'));

echo "\nAssistant du vendeur occupé (modèle simulé : aucun appel réel)\n";
$appels_modele = [];
// Modèle simulé : décide selon le dernier message du client, et garde ce qu'on lui a transmis.
Bayam_WA_Assistant::remplacer(static function (string $systeme, string $boutique, array $messages) use (&$appels_modele) {
    $appels_modele[] = ['boutique' => $boutique, 'messages' => $messages];
    $dernier = mb_strtolower(end($messages)['content']);
    $commande = ['produit_id' => 0, 'quantite' => 0, 'prix_unitaire' => 0, 'precision' => '', 'livraison' => '', 'nom_client' => ''];
    if (str_contains($dernier, 'maman')) {
        return ['action' => 'ignorer', 'message' => '', 'commande' => $commande, 'resume_vendeur' => ''];
    }
    if (str_contains($dernier, 'je prends')) {
        $prix = str_contains($dernier, '5000') ? 5000 : 11000;
        return ['action' => 'commande', 'message' => 'C’est noté Nadège, la boutique vous recontacte pour la livraison.', 'resume_vendeur' => 'Cliente sérieuse, veut être livrée ce soir.',
            'commande' => ['produit_id' => 25, 'quantite' => 2, 'prix_unitaire' => $prix, 'precision' => '', 'livraison' => 'Bonamoussadi, face Total', 'nom_client' => 'Nadège']];
    }
    return ['action' => 'repondre', 'message' => 'Bonjour ! Oui le polo est disponible à 12 000 FCFA.', 'commande' => $commande, 'resume_vendeur' => ''];
});
$client_a = '237677000201@c.us';
$r = bayam_dire('Bayam je suis occupé');
bayam_verifier('« je suis occupé » : l’assistant prend le relais', str_contains($joint($r), 'prend le relais') && Bayam_WA_Assistant::actif($vendeur), $r);
$r = bayam_dire('Bonjour, le polo que vous avez posté est à combien ?', $client_a, false);
bayam_verifier('client qui demande le prix sans mot-clé : l’assistant répond', str_contains($joint($r), 'disponible à 12'), $r);
bayam_verifier('le modèle reçoit le catalogue avec les prix minimums', str_contains(end($appels_modele)['boutique'], 'Polo') && str_contains(end($appels_modele)['boutique'], 'prix minimum'), end($appels_modele)['boutique']);
$r = bayam_dire('Maman dit que tu passes ce soir', '237677000202@c.us', false);
bayam_verifier('message personnel : silence et texte effacé', $r === [] && !$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Bayam_WA_Stockage::table('wa_messages') . " WHERE vendeur = %d AND chat = %s AND texte LIKE %s", $vendeur, '237677000202@c.us', '%Maman%')), $r);
$faux->envois = [];
$r = bayam_dire('Ok je prends 2 à 11000, livrez à Bonamoussadi', $client_a, false);
$alerte = implode(' ', array_map(static fn ($e) => (string) ($e['corps']['text'] ?? ''), $faux->envois));
preg_match('/n° (\d+), à confirmer/', $alerte, $num_commande);
$commande_assistant = !empty($num_commande[1]) ? wc_get_order((int) $num_commande[1]) : null;
bayam_verifier('commande négociée : commande à confirmer au prix convenu, vendeur alerté', $commande_assistant && $commande_assistant->has_status('on-hold') && (int) $commande_assistant->get_total() === 22000 && str_contains($alerte, 'bayam ok'), $alerte);
$faux->envois = [];
bayam_dire('Ok je prends 2 à 5000 seulement', '237677000203@c.us', false);
$alerte = implode(' ', array_map(static fn ($e) => (string) ($e['corps']['text'] ?? ''), $faux->envois));
bayam_verifier('prix sous le minimum : jamais conclu, demande transmise au vendeur', str_contains($alerte, 'vous transmet') && !str_contains($alerte, 'Commande conclue'), $alerte);
$r = bayam_dire('bayam ok ' . ($num_commande[1] ?? '0'));
bayam_verifier('« bayam ok N » : commande confirmée, client prévenu', str_contains($joint($r), 'confirmée') && wc_get_order((int) ($num_commande[1] ?? 0))->has_status('processing'), $r);
// Le vendeur écrit lui-même au client : l'assistant s'efface de cette discussion.
Bayam_WA_Webhook::evenement(['event' => 'message.any', 'session' => 'boutique-3', 'payload' => ['id' => 'false_vendeur1', 'from' => '237690000003@c.us', 'to' => '237677000204@c.us', 'fromMe' => true, 'body' => 'Je vous appelle tout de suite', '_data' => ['Info' => ['Chat' => '237677000204@c.us']]]]);
$appels_avant = count($appels_modele);
$r = bayam_dire('D’accord je vous attends', '237677000204@c.us', false);
bayam_verifier('vendeur qui a repris la discussion : l’assistant ne répond pas', $r === [] && count($appels_modele) === $appels_avant, $r);
$r = bayam_dire('bayam plancher polo 11500');
bayam_verifier('« plancher polo 11500 » : prix minimum fixé', str_contains($joint($r), 'Prix minimum de *Polo*') && Bayam_WA_Assistant::plancher($vendeur, wc_get_product(25)) === 11500, $r);
$r = bayam_dire('bayam plancher polo 50000');
bayam_verifier('prix minimum au-dessus du prix affiché : refusé', str_contains($joint($r), 'dépasse le prix affiché'), $r);
$r = bayam_dire('bayam remise 15%');
bayam_verifier('« remise 15% » : remise maximale de l’assistant', str_contains($joint($r), '15 %') && (int) Bayam_WA_Stockage::reglages($vendeur)['remise_max'] === 15, $r);
$r = bayam_dire('bayam je reprends');
bayam_verifier('« je reprends » : l’assistant s’arrête', str_contains($joint($r), 'reprenez la main') && !Bayam_WA_Assistant::actif($vendeur), $r);
$appels_avant = count($appels_modele);
$r = bayam_dire('Bonjour vous êtes ouvert dimanche ?', '237677000205@c.us', false);
bayam_verifier('assistant arrêté : aucun appel au modèle', count($appels_modele) === $appels_avant, $r);

// Commentaires sous une publication BAYAM dans un groupe (arrivés par la passerelle).
$carte_polo = Bayam_WA_Outils::MARQUE . "Polo : 12 000 FCFA\nCommander : " . Bayam_WA_Liens::produit($vendeur, wc_get_product(25), 'groupe', '120363000000000001@g.us');
$commenter = static function (string $texte, string $cite, string $id) use ($faux) {
    $faux->envois = [];
    Bayam_WA_Webhook::evenement(['event' => 'message.any', 'session' => 'boutique-3', 'payload' => [
        'id' => $id, 'from' => '120363000000000001@g.us', 'fromMe' => false, 'body' => $texte, 'participant' => '41000000000301@lid',
        'replyTo' => ['id' => 'CARTE-GROUPE', 'body' => $cite],
        '_data' => ['Info' => ['Chat' => '120363000000000001@g.us', 'Sender' => '41000000000301@lid', 'SenderAlt' => '237677000301@s.whatsapp.net']],
    ]]);
    return $faux->envois;
};
$envois = $commenter('Prix en L svp ?', $carte_polo, 'false_comm1');
$alerte = implode(' ', array_map(static fn ($e) => (string) ($e['corps']['text'] ?? ''), $envois));
bayam_verifier('commentaire de groupe, assistant arrêté : le vendeur est prévenu avec le lien du client', str_contains($alerte, 'Commentaire dans « Clientes Mode Aïcha »') && str_contains($alerte, 'wa.me/237677000301'), $alerte);
$envois = $commenter('Prix en L svp ??', $carte_polo, 'false_comm2');
bayam_verifier('même client, même article : pas d’alerte en double', $envois === [], array_column($envois, 'chemin'));
$envois = $commenter('Rdv à 18h devant la pharmacie', 'Qui vient au mariage samedi ?', 'false_comm3');
bayam_verifier('réponse de groupe à un message ordinaire : ignorée', $envois === [], array_column($envois, 'chemin'));
Bayam_WA_Assistant::activer($vendeur);
$envois = $commenter('Bonsoir, c’est combien ?', $carte_polo, 'false_comm4');
$reponse = array_values(array_filter($envois, static fn ($e) => $e['chemin'] === '/api/sendText'));
bayam_verifier('commentaire de groupe, assistant actif : réponse publique qui cite le commentaire', $reponse && ($reponse[0]['corps']['reply_to'] ?? '') === 'false_comm4' && ($reponse[0]['corps']['chatId'] ?? '') === '120363000000000001@g.us', $envois);
Bayam_WA_Assistant::desactiver($vendeur);
delete_transient('bayam_wa_comm_' . md5($vendeur . '|237677000301|25'));
$groupe = ['from' => '120363000000000001@g.us', '_data' => ['Info' => ['Chat' => '120363000000000001@g.us']]];
bayam_verifier('webhook : commentaire sous une publication BAYAM accepté', Bayam_WA_Webhook::utile($groupe + ['replyTo' => ['body' => $carte_polo]]));
bayam_verifier('webhook : message ordinaire de groupe écarté avant écriture', !Bayam_WA_Webhook::utile($groupe + ['body' => 'Bonsoir la famille']));
bayam_verifier('webhook : statuts et chaînes écartés', !Bayam_WA_Webhook::utile(['from' => 'status@broadcast']) && !Bayam_WA_Webhook::utile(['from' => '1203@newsletter']));
$polo = wc_get_product(25);
$polo->delete_meta_data('_bayam_prix_plancher');
$polo->save();
Bayam_WA_Assistant::remplacer(null);
foreach (['237677000201@c.us', '237677000202@c.us', '237677000203@c.us', '237677000204@c.us', '237677000205@c.us'] as $chat_essai) {
    delete_transient('bayam_wa_rafale_' . md5($vendeur . '|' . $chat_essai));
    delete_transient('bayam_wa_main_' . md5($vendeur . '|' . Bayam_WA_Outils::numero_de($chat_essai)));
}

echo "\nClients camerounais (couleur, taille, anglais, pidgin, réponses aux cartes, rafales)\n";
// Un numéro par cas : la limite de rafale (6 réponses par discussion en 10 min) ne doit pas fausser les essais.
$client_n = static fn (int $n) => sprintf('2376770001%02d@c.us', $n);
foreach (range(1, 12) as $n) {
    delete_transient('bayam_wa_rafale_' . md5($vendeur . '|' . $client_n($n)));
}
$r = bayam_dire('#polo rouge', $client_n(1), false);
bayam_verifier('« #polo rouge » : le Polo est proposé (la couleur n’écarte pas l’article)', str_contains($joint($r), '*Polo*'), $r);
$r = bayam_dire('Bayam vous avez le sweat à capuche en rouge svp ?', $client_n(2), false);
bayam_verifier('sweat en rouge : la déclinaison rouge annoncée disponible', str_contains($joint($r), 'Sweat à capuche') && str_contains($joint($r), 'Rouge') && str_contains($joint($r), 'disponible'), $r);
$r = bayam_dire('bayam do you have red hoodie please', $client_n(3), false);
bayam_verifier('client anglophone : réponse en anglais, sweat proposé', str_contains($joint($r), 'Hello') && str_contains($joint($r), 'Sweat'), $r);
$r = bayam_dire('Bayam una get cap?', $client_n(4), false);
bayam_verifier('pidgin « una get cap » : casquettes, réponse en anglais', str_contains($joint($r), 'Hello') && str_contains($joint($r), 'Casquette'), $r);
$r = bayam_dire('#wig', $client_n(5), false);
bayam_verifier('« #wig » absent : réponse polie en anglais', str_contains($joint($r), "We don't have"), $r);
$lien_polo = Bayam_WA_Liens::produit($vendeur, wc_get_product(25), 'statut');
$cite = static function (string $texte, string $quote, string $de) use ($faux, $joint) {
    $faux->envois = [];
    Bayam_WA_Webhook::evenement(['event' => 'message.any', 'session' => 'boutique-3', 'payload' => [
        'id' => 'false_' . wp_generate_password(10, false), 'from' => $de, 'fromMe' => false, 'body' => $texte,
        'replyTo' => ['id' => 'CARTE1', 'body' => $quote],
    ]]);
    return array_values(array_filter(array_map(static fn ($e) => (string) ($e['corps']['text'] ?? ''), $faux->envois)));
};
$r = $cite('C\'est combien ?', "Polo : 12 000 FCFA\nCommander : $lien_polo", $client_n(6));
bayam_verifier('réponse « C’est combien ? » à un statut BAYAM : prix du Polo', str_contains($joint($r), '*Polo*') && str_contains($joint($r), "12\u{202F}000"), $r);
$r = $cite('Dispo en L ?', "Sweat à capuche : 25 200 FCFA\nCommander : " . Bayam_WA_Liens::produit($vendeur, wc_get_product(15), 'groupe'), $client_n(7));
bayam_verifier('réponse « Dispo en L ? » à une carte BAYAM : disponibilité du sweat', str_contains($joint($r), 'Sweat à capuche') && str_contains($joint($r), 'Disponible en'), $r);
$r = $cite('Ok à demain alors', 'Tu viens à quelle heure ?', $client_n(8));
bayam_verifier('réponse à un message personnel : silence', $r === [], $r);
$r = $cite('Merci !', 'Lien inconnu https://exemple.cm/b/ZZZZZZ', $client_n(9));
bayam_verifier('citation d’un lien qui n’est pas de cette boutique : silence', $r === [], $r);
foreach (range(1, 6) as $i) {
    bayam_dire('#polo', $client_n(10), false);
}
$r = bayam_dire('#polo', $client_n(10), false);
bayam_verifier('rafale : la 7e demande en 10 min reste sans réponse automatique', $r === [], $r);
$r = bayam_dire('bayam lien 15k Mama Nkeng', '237690000003@c.us', true);
bayam_verifier('« 15k » compris comme 15 000 FCFA', str_contains($joint($r), "15\u{202F}000") && str_contains($joint($r), 'Mama Nkeng'), $r);
$r = bayam_dire('bayam lien 7 500 F Tata Odile', '237690000003@c.us', true);
bayam_verifier('« 7 500 F » compris', str_contains($joint($r), "7\u{202F}500") && str_contains($joint($r), 'Tata Odile'), $r);
$r = bayam_dire('bayam envoie polo +237 6 99 99 99 99', '237690000003@c.us', true);
bayam_verifier('numéro au format +237 compris', str_contains($joint($r), 'wa.me/237699999999'), $r);
foreach (range(1, 12) as $n) {
    delete_transient('bayam_wa_rafale_' . md5($vendeur . '|' . $client_n($n)));
}

echo "\nEnvoi d'une collection\n";
$r = bayam_dire('bayam envoie polo 6 77 00 00 01');
bayam_verifier('client qui a déjà écrit : collection envoyée', str_contains($joint($r), 'Collection envoyée'), $r);
$r = bayam_dire('bayam envoie polo 6 99 99 99 99');
bayam_verifier('client inconnu : lien wa.me, jamais de premier contact', str_contains($joint($r), 'wa.me/237699999999'), $r);

echo "\nPublication étalée\n";
$t = Bayam_WA_Stockage::table('diffusions');
$wpdb->query("UPDATE $t SET prevu = '2000-01-01 00:00:00' WHERE vendeur = 3 AND statut = 'attente' AND id > {$depart['diffusions']}");
$faux->envois = [];
Bayam_WA_Diffusion::traiter_file();
$images = array_filter($faux->envois, static fn ($e) => $e['chemin'] === '/api/sendImage');
bayam_verifier('2 cartes publiées dans les 2 groupes', count($images) === 2, array_column($faux->envois, 'chemin'));
bayam_verifier('lien suivi par groupe', (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Bayam_WA_Stockage::table('liens') . " WHERE vendeur = 3 AND groupe LIKE '120363%' AND id > {$depart['liens']}") === 2);
bayam_verifier('bilan de série envoyé au vendeur', (bool) array_filter($faux->envois, static fn ($e) => str_contains((string) ($e['corps']['text'] ?? ''), 'Publication terminée')));

echo "\nPublication en quelques secondes\n";
$r = bayam_dire('bayam publier nouveautés partout');
bayam_verifier('« publier nouveautés partout » : groupes, chaîne et statut, 20 publications en 4 min au plus', str_contains($joint($r), 'dans 2 groupes') && str_contains($joint($r), 'dans votre chaîne') && str_contains($joint($r), 'en statut') && preg_match('/environ (\d+) (s|min)/', $joint($r), $duree) && ($duree[2] === 's' || (int) $duree[1] <= 4), $r);
$prevus = $wpdb->get_col($wpdb->prepare("SELECT UNIX_TIMESTAMP(prevu) FROM $t WHERE vendeur = 3 AND statut = 'attente' AND type = 'groupe' AND id > %d ORDER BY prevu", $depart['diffusions']));
$ecarts = array_map(static fn ($a, $b) => $b - $a, array_slice($prevus, 0, -1), array_slice($prevus, 1));
bayam_verifier('rythme humain : quelques secondes entre deux photos, jamais deux envois collés', $ecarts && min($ecarts) >= 3 && max($ecarts) <= 25, $ecarts);
$r = bayam_dire('bayam publier polo dans clientes mode aicha');
bayam_verifier('« publier polo dans Clientes Mode Aïcha » : un seul groupe', str_contains($joint($r), '1 publication(s) : 1 dans 1 groupe'), $r);
$r = bayam_dire('bayam publier polo dans les tontines du quartier');
bayam_verifier('groupe inconnu : expliqué, rien n’est publié', str_contains($joint($r), 'Je ne trouve pas le groupe'), $r);
$r = bayam_dire('bayam publier polo chaîne');
bayam_verifier('« publier polo chaîne » : la chaîne dont le vendeur est propriétaire, pas celle qu’il suit', str_contains($joint($r), '1 publication(s) : 1 dans votre chaîne « Nouveautés Mode Aïcha »')
    && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE vendeur = 3 AND type = 'chaine' AND chat = '120363000000000777@newsletter' AND id > %d", $depart['diffusions'])) >= 1
    && !(int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE chat = '120363000000000888@newsletter' AND id > %d", $depart['diffusions'])), $r);
$r = bayam_dire('bayam publier polo facebook');
bayam_verifier('« publier polo facebook » sans Page reliée : explique comment la relier, rien sur WhatsApp', str_contains($joint($r), 'Aucune Page Facebook reliée') && !str_contains($joint($r), 'publication(s) :'), $r);
$r = bayam_dire('bayam publier polo statut story');
bayam_verifier('« … statut story » : statut WhatsApp et story Facebook demandés ensemble', str_contains($joint($r), 'en statut') && str_contains($joint($r), 'Facebook'), $r);
$r = bayam_dire('bayam facebook');
preg_match('#/facebook/connexion\?v=(\d+)&e=(\d+)&s=([0-9a-f]{64})#', $joint($r), $lien_fb);
bayam_verifier('« bayam facebook » : lien de connexion signé, valable 30 min', $lien_fb && (int) $lien_fb[1] === 3 && abs((int) $lien_fb[2] - time() - 1800) < 60
    && hash_equals(hash_hmac('sha256', "facebook:3.{$lien_fb[2]}", (string) getenv('BAYAM_CLE_LIENS')), $lien_fb[3]), $r);
// Page reliée (simulée) : publication et story demandées au service, avec le bon article ; retrait d'une Page.
$pages_fb = [['page' => '110675147863315', 'vendeur' => 3, 'nom' => 'Kratos Learn', 'actif' => true, 'permissions' => ['pages_manage_posts']]];
wp_cache_delete("bayam_fb_pages_{$vendeur}", 'bayam');
$appels_fb = [];
$r = bayam_dire('bayam publier polo facebook');
$demande_fb = array_values(array_filter($appels_fb, static fn ($a) => $a[1] === '/interne/publications'))[0][2] ?? null;
bayam_verifier('Page reliée : « publier polo facebook » demande la publication du polo, rien sur WhatsApp',
    str_contains($joint($r), 'Publication en préparation sur « Kratos Learn »') && $demande_fb === ['vendeur' => 3, 'produits' => [25], 'type' => 'publication'] && !str_contains($joint($r), 'publication(s) :'), [$r, $appels_fb]);
$appels_fb = [];
$r = bayam_dire('bayam publier polo story');
bayam_verifier('« publier polo story » : une story demandée', (array_values(array_filter($appels_fb, static fn ($a) => $a[1] === '/interne/publications'))[0][2]['type'] ?? '') === 'story', [$r, $appels_fb]);
$r = bayam_dire('bayam facebook');
bayam_verifier('« bayam facebook » avec une Page reliée : son nom, et comment en retirer une', str_contains($joint($r), 'Reliée(s) : « Kratos Learn »') && str_contains($joint($r), 'bayam facebook retirer Kratos Learn'), $r);
$appels_fb = [];
$r = bayam_dire('bayam facebook retirer kratos');
bayam_verifier('« bayam facebook retirer kratos » : la Page désignée par une partie de son nom est retirée',
    str_contains($joint($r), 'Page « Kratos Learn » retirée') && array_filter($appels_fb, static fn ($a) => $a[0] === 'DELETE' && str_ends_with((string) $a[1], '/pages/110675147863315')), [$r, $appels_fb]);
$pages_fb = [];
wp_cache_delete("bayam_fb_pages_{$vendeur}", 'bayam');
$r = bayam_dire('bayam aide facebook');
bayam_verifier('« bayam aide facebook » : ce que fait la Page reliée', str_contains($joint($r), 'Relier votre Page Facebook') && str_contains($joint($r), 'Messenger'), $r);
$r = bayam_dire('bayam Robe wax 15000 M:2 publier partout', photo: true);
bayam_verifier('photo + « publier partout » : article créé et publié en une fois', str_contains($joint($r), 'Produit créé') && str_contains($joint($r), 'publication(s)') && str_contains($joint($r), 'en statut'), $r);
$wpdb->query($wpdb->prepare("UPDATE $t SET statut = 'annule' WHERE vendeur = 3 AND statut = 'attente' AND id > %d", $depart['diffusions']));

echo "\nRapport automatique\n";
Bayam_WA_Stockage::enregistrer($vendeur, ['heure_rapport' => (int) wp_date('G')]);
delete_user_meta($vendeur, 'bayam_wa_dernier_rapport');
$faux->envois = [];
Bayam_WA_Rapports::envoyer_rapports();
bayam_verifier('résumé quotidien à l’heure choisie', (bool) array_filter($faux->envois, static fn ($e) => str_contains((string) ($e['corps']['text'] ?? ''), 'Bilan du jour')));

echo "\nAlertes de commande et de paiement\n";
$textes = static fn () => implode("\n", array_map(static fn ($e) => (string) ($e['corps']['text'] ?? ''), $GLOBALS['faux']->envois));
$polo = wc_get_product(25);
$stock_polo = $polo->get_stock_quantity();
$client = wc_create_order(['status' => 'pending', 'created_via' => 'checkout']);
$client->add_product($polo, 1);
$client->set_address(['first_name' => 'Nadège', 'last_name' => 'Fotso', 'phone' => '690000021', 'address_1' => 'Bonanjo, rue Joss', 'city' => 'Douala', 'country' => 'CM'], 'billing');
$client->set_payment_method('cod');
$client->set_payment_method_title('Paiement à la livraison');
$client->calculate_totals();
$client->save();
$faux->envois = [];
do_action('woocommerce_checkout_order_processed', $client->get_id(), [], $client);
bayam_verifier('commande sur le site : alerte au vendeur avec le lien du client', str_contains($textes(), 'Nouvelle commande n° ' . $client->get_order_number()) && str_contains($textes(), 'wa.me/237690000021'), $textes());
$faux->envois = [];
$client->update_status('processing');
bayam_verifier('paiement à la livraison : rien d’encaissé avant la remise du colis', !str_contains($textes(), 'Paiement reçu'), $textes());
$client->update_status('completed');
bayam_verifier('paiement à la livraison : « Paiement reçu » à la remise du colis', str_contains($textes(), 'Paiement reçu'), $textes());
$client->delete(true);
if ($stock_polo !== null) {
    wc_update_product_stock($polo, $stock_polo);
}

echo "\nCarte produit (image)\n";
$carte = Bayam_WA_Cartes::carte(wc_get_product(15), home_url('/b/Essai1'), 'Mode Aïcha');
$im = imagecreatefromstring(base64_decode($carte['data']));
bayam_verifier('carte 1080 × 1350 en JPEG', $im && imagesx($im) === 1080 && imagesy($im) === 1350);
file_put_contents('/var/www/html/wp-content/uploads/bayam-carte-essai.jpg', base64_decode($carte['data']));
$statut = Bayam_WA_Cartes::carte(wc_get_product(15), home_url('/b/Essai1'), 'Mode Aïcha', true);
file_put_contents('/var/www/html/wp-content/uploads/bayam-statut-essai.jpg', base64_decode($statut['data']));

// --- Menu du vendeur et partage sur Facebook ------------------------------------------------------------------
echo "\nMenu du vendeur et partage sur Facebook\n";
$r = bayam_dire('bayam +2 #25');
bayam_verifier('« +2 #25 » : l’article désigné par son numéro (choisi dans le menu), sans ambiguïté de nom', str_contains($joint($r), 'Entrée de 2 : *Polo*'), $r);
$r = bayam_dire('bayam vendu #25 x1 momo');
bayam_verifier('« vendu #25 x1 momo » : vente du bon article', str_contains($joint($r), 'Vente n°') && str_contains($joint($r), '1 × Polo'), $r);
$r = bayam_dire('bayam publier #25 statut');
bayam_verifier('« publier #25 statut » : l’article choisi, lui seul', str_contains($joint($r), '1 publication(s) : 1 en statut'), $r);
$wpdb->query($wpdb->prepare("UPDATE $t SET statut = 'annule' WHERE vendeur = 3 AND statut = 'attente' AND id > %d", $depart['diffusions']));
$r = bayam_dire('bayam partager polo');
$a_coller = (string) end($r);
bayam_verifier('« partager polo » : texte prêt à coller (prix, lien WhatsApp avec la référence de l’article), sans gras WhatsApp',
    str_contains($joint($r), 'Prêt à publier sur votre profil') && str_contains($a_coller, '💰 ') && str_contains($a_coller, 'https://wa.me/237690000003?text=')
    && str_contains($a_coller, rawurlencode('(réf. P25)')) && !str_contains($a_coller, '*'), $r);
$r = bayam_dire('bayam aide partager');
bayam_verifier('« bayam aide partager » : explique pourquoi et comment', str_contains($joint($r), 'Publier sur votre profil ou vos groupes Facebook'), $r);
$requete = new WP_REST_Request('GET', '/bayam/v1/interne/boutiques/3/articles');
$requete->set_header('x-bayam-service', (string) getenv('BAYAM_SERVICE_JETON'));
$requete->set_param('q', '#25');
$articles = rest_do_request($requete)->get_data();
bayam_verifier('articles du vendeur pour ses menus : l’article demandé, avec son stock exact', ($articles['articles'][0]['id'] ?? 0) === 25 && array_key_exists('stock', $articles['articles'][0]) && $articles['total'] === 1, $articles);
$requete = new WP_REST_Request('GET', '/bayam/v1/interne/boutiques/3/articles');
$tous_articles = rest_do_request($requete)->get_data();
bayam_verifier('API interne du menu : refusée sans le jeton de service', ($tous_articles['code'] ?? '') === 'rest_forbidden', $tous_articles);
$faux->envois = [];
$requete = new WP_REST_Request('POST', '/bayam/v1/interne/boutiques/3/copilote');
$requete->set_header('x-bayam-service', (string) getenv('BAYAM_SERVICE_JETON'));
$requete->set_param('texte', 'stock polo');
$execute = rest_do_request($requete)->get_data();
$au_vendeur = array_filter($faux->envois, static fn ($e) => $e['chemin'] === '/api/sendText' && ($e['corps']['chatId'] ?? '') === '237690000003@c.us');
bayam_verifier('commande choisie dans le menu : exécutée, réponse dans « Message à moi-même »', ($execute['envoyes'] ?? 0) >= 1 && $au_vendeur, [$execute, array_column($faux->envois, 'chemin')]);

// --- Parité WhatsApp et Facebook, retours, maintenance ----------------------------------------------------------
echo "\nRetours, connexion WhatsApp, publication multiple, maintenance\n";
// Service des retours simulé : les essais ne remplissent pas le vrai tableau de bord (vérifié à part par
// scripts/essai_retours.sh contre le service réel).
$appels_retours = [];
$retours_en_panne = false;
Bayam_WA_Retours::remplacer(static function (string $methode, string $chemin, ?array $corps) use (&$appels_retours, &$retours_en_panne) {
    $appels_retours[] = compact('methode', 'chemin', 'corps');
    return $retours_en_panne ? null : ['numero' => 'R-' . count($appels_retours), 'message' => '🙏 Merci ! Votre signalement (R-' . count($appels_retours) . ') est bien arrivé à l’équipe BAYAM.'];
});
$r = bayam_dire('bayam signaler le lien de paiement ne s’ouvre pas');
$envoi = end($appels_retours) ?: [];
bayam_verifier('« bayam signaler … » : transmis au service des retours (Copilote, boutique, identité anonymisée), numéro donné', str_contains($joint($r), 'R-1') && $envoi['chemin'] === '/interne/signalements' && $envoi['corps']['type'] === 'souci' && $envoi['corps']['canal'] === 'copilote' && $envoi['corps']['vendeur'] === 3 && $envoi['corps']['identifiant'] === 'vendeur:3', [$r, $envoi]);
$r = bayam_dire('bayam idée publier sur TikTok');
bayam_verifier('« bayam idée … » : transmise comme idée', (end($appels_retours)['corps']['type'] ?? '') === 'idee' && str_contains($joint($r), 'R-2'), $r);
$retours_en_panne = true;
$r = bayam_dire('bayam signaler le panier se vide tout seul');
$retours_en_panne = false;
bayam_verifier('service des retours en panne : message honnête, aucun faux numéro', str_contains($joint($r), "n'a pas pu être enregistré") && !str_contains($joint($r), 'R-3'), $r);
$r = bayam_dire('bayam whatsapp');
bayam_verifier('« bayam whatsapp » : WhatsApp déjà relié, le numéro est rappelé', str_contains($joint($r), 'WhatsApp relié à BAYAM'), $r);
$faux->statut = 'SCAN_QR_CODE';
$r = bayam_dire('bayam whatsapp 6 90 00 00 10');
$faux->statut = 'WORKING';
bayam_verifier('« bayam whatsapp 6 90 00 00 10 » (depuis Messenger) : code d’association à saisir dans WhatsApp', str_contains($joint($r), 'ABCD-1234') && str_contains($joint($r), 'Connecter avec le numéro de téléphone'), $r);
$jeton_service = (string) getenv('BAYAM_SERVICE_JETON');
$requete = new WP_REST_Request('GET', '/bayam/v1/interne/boutiques/3/destinations');
$requete->set_header('x-bayam-service', $jeton_service);
$destinations = rest_do_request($requete)->get_data();
bayam_verifier('destinations de publication : groupes choisis, chaînes, statut', $destinations['whatsapp'] === true && count($destinations['groupes']) === 2 && isset($destinations['chaines']), $destinations);
$requete = new WP_REST_Request('POST', '/bayam/v1/interne/boutiques/3/publier');
$requete->set_header('x-bayam-service', $jeton_service);
foreach (['quoi' => 'polo', 'groupes' => [$destinations['groupes'][0]['id']], 'statut' => true, 'retour' => true] as $cle => $valeur) {
    $requete->set_param($cle, $valeur);
}
$publication = rest_do_request($requete)->get_data();
bayam_verifier('publication multiple : un groupe choisi et le statut, en une demande, réponse rendue (Messenger)', str_contains($publication['reponses'][0]['texte'] ?? '', '1 dans 1 groupe') && str_contains($publication['reponses'][0]['texte'] ?? '', 'en statut'), $publication);
$wpdb->query($wpdb->prepare("UPDATE $t SET statut = 'annule' WHERE vendeur = 3 AND statut = 'attente' AND id > %d", $depart['diffusions']));
$requete = new WP_REST_Request('POST', '/bayam/v1/interne/boutiques/3/copilote');
$requete->set_header('x-bayam-service', $jeton_service);
$requete->set_param('texte', 'stock polo');
$requete->set_param('retour', true);
$faux->envois = [];
$copilote = rest_do_request($requete)->get_data();
bayam_verifier('Copilote depuis Messenger : réponses rendues à l’appelant, rien envoyé sur WhatsApp', !empty($copilote['reponses'][0]['texte']) && !array_filter($faux->envois, static fn ($e) => $e['chemin'] === '/api/sendText'), $copilote);
$requete = new WP_REST_Request('POST', '/bayam/v1/interne/alerte');
$requete->set_header('x-bayam-service', $jeton_service);
$requete->set_param('texte', '');
$alerte_vide = rest_do_request($requete);
$requete = new WP_REST_Request('POST', '/bayam/v1/interne/alerte');
$requete->set_param('sujet', 1);
$requete->set_param('texte', 'essai');
$alerte_anonyme = rest_do_request($requete);
bayam_verifier('alerte de l’équipe : refusée vide (400) ou sans jeton de service (401)', $alerte_vide->get_status() === 400 && $alerte_anonyme->get_status() === 401, [$alerte_vide->get_status(), $alerte_anonyme->get_status()]);
// Maintenance : le Copilote répond par le message de l'équipe, mais signaler un souci reste possible.
$redis = Bayam_WA_Outils::redis();
$redis?->hSet('bayam:fonctions', 'whatsapp.copilote', wp_json_encode(['etat' => 'maintenance', 'message' => 'Copilote en maintenance (essai), retour dans 5 minutes.']));
$reflet = new ReflectionProperty(Bayam_WA_Fonctions::class, 'reglages');
$reflet->setValue(null, null);
$r = bayam_dire('bayam stock polo');
$r2 = bayam_dire('bayam signaler pendant la maintenance');
$redis?->hDel('bayam:fonctions', 'whatsapp.copilote');
$reflet->setValue(null, null);
bayam_verifier('Copilote en maintenance : message de l’équipe ; signaler un souci reste possible ; rouvert ensuite', $redis && str_contains($joint($r), 'Copilote en maintenance (essai)') && str_contains($joint($r2), 'Merci') && str_contains($joint(bayam_dire('bayam stock polo')), 'Polo'), [$r, $r2]);

// --- Inscription d'un vendeur depuis son seul téléphone (/vendre) ----------------------------------------------------
echo "\nInscription d'un vendeur depuis son téléphone\n";
$inscrire = static function (array $p, string $ip) {
    $_SERVER['REMOTE_ADDR'] = $ip;
    $q = new WP_REST_Request('POST', '/bayam/v1/inscription');
    foreach ($p as $k => $v) {
        $q->set_param($k, $v);
    }
    return rest_do_request($q);
};
$nouvelle = ['boutique' => 'Essai Couture Bali', 'prenom' => 'Awa', 'telephone' => '6 99 88 77 66', 'quartier' => 'Bali', 'accepte' => true];
$robot = $inscrire($nouvelle + ['site' => 'http://spam'], '203.0.113.10');
$mauvais = $inscrire(['boutique' => 'X', 'prenom' => '', 'telephone' => '123', 'accepte' => true], '203.0.113.11');
$sans_accord = $inscrire(array_merge($nouvelle, ['accepte' => false]), '203.0.113.12');
bayam_verifier('inscription : robot (champ piège), champs invalides et conditions non acceptées refusés', $robot->get_status() === 400 && $mauvais->get_status() === 400 && $sans_accord->get_status() === 400, [$robot->get_status(), $mauvais->get_status(), $sans_accord->get_status()]);
$faux->statut = 'SCAN_QR_CODE';
$r = $inscrire($nouvelle, '203.0.113.20');
$d = $r->get_data();
$cree = Bayam_WA_Inscription::vendeur_du_numero('237699887766');
bayam_verifier('inscription par WhatsApp : boutique créée (vendeur WCFM), code d’association affiché, sans QR ni ordinateur',
    $r->get_status() === 200 && ($d['code'] ?? '') === 'ABCD-1234' && $cree > 0 && in_array('wcfm_vendor', (array) get_userdata($cree)->roles, true)
    && get_user_meta($cree, 'wcfmmp_store_name', true) === 'Essai Couture Bali' && str_contains($d['numero'] ?? '', '99'), $d);
$suivi = static function (string $jeton) {
    return rest_do_request(new WP_REST_Request('GET', '/bayam/v1/inscription/' . $jeton))->get_data();
};
$avant = $suivi((string) ($d['jeton'] ?? ''));
$faux->statut = 'WORKING';
$apres = $suivi((string) ($d['jeton'] ?? ''));
bayam_verifier('inscription : la page voit la boutique reliée dès la saisie du code', ($avant['whatsapp'] ?? true) === false && ($apres['whatsapp'] ?? false) === true && ($apres['boutique'] ?? '') === 'Essai Couture Bali', [$avant, $apres]);
$encore = $inscrire($nouvelle, '203.0.113.21')->get_data();
bayam_verifier('même numéro une seconde fois : même boutique, déjà reliée (aucun doublon)', ($encore['relie'] ?? false) === true && Bayam_WA_Inscription::vendeur_du_numero('237699887766') === $cree, $encore);
$fb = $inscrire(['boutique' => 'Essai Page Akwa', 'prenom' => 'Paul', 'telephone' => '6 77 66 55 44', 'accepte' => true, 'mode' => 'facebook'], '203.0.113.22')->get_data();
$cree_fb = Bayam_WA_Inscription::vendeur_du_numero('237677665544');
bayam_verifier('inscription « Facebook d’abord » : boutique créée, lien signé de connexion de la Page', ($fb['mode'] ?? '') === 'facebook' && str_contains($fb['lien'] ?? '', '/facebook/connexion?v=' . $cree_fb), $fb);
$debits = [];
for ($i = 0; $i < 4; $i++) {
    $debits[] = $inscrire(['boutique' => 'X', 'prenom' => '', 'telephone' => '1', 'accepte' => true], '203.0.113.30')->get_status();
}
bayam_verifier('inscriptions en rafale depuis une même adresse : la 4e est refusée (429)', $debits[3] === 429, $debits);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_filter([$cree, $cree_fb]) as $id_essai) {
    wp_delete_user($id_essai); // boutiques des essais
}
foreach (['203.0.113.10', '203.0.113.11', '203.0.113.12', '203.0.113.20', '203.0.113.21', '203.0.113.22', '203.0.113.30'] as $ip) {
    delete_transient('bayam_insc_' . md5($ip));
}

// --- Remise en état ---------------------------------------------------------------------------------------------
echo "\nRemise en état\n";
foreach (wc_get_orders(['created_via' => ['bayam-whatsapp', 'bayam-assistant'], 'limit' => -1, 'date_created' => '>=' . ($debut_ts - 5)]) as $c) {
    $c->delete(true);
}
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wcfm_marketplace_orders WHERE vendor_id = 3 AND created >= %s", $debut));
// Notifications de l'espace vendeur qui pointent vers une commande supprimée (celles des essais).
foreach ($wpdb->get_results("SELECT ID, message FROM {$wpdb->prefix}wcfm_messages WHERE message_type IN ('order', 'status-update')") as $m) {
    if (preg_match('#orders-details/(\d+)#', $m->message, $x) && !wc_get_order((int) $x[1])) {
        $wpdb->delete("{$wpdb->prefix}wcfm_messages", ['ID' => $m->ID]);
        $wpdb->delete("{$wpdb->prefix}wcfm_messages_modifier", ['message' => $m->ID]);
    }
}
// Alertes de stock faible déclenchées par les mouvements de stock des essais (le stock est rétabli plus bas).
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wcfm_messages WHERE message_type = 'product_lowstk' AND created >= %s", $debut));
foreach ($wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_title = 'bayamessai'") as $id_coupon) {
    wp_delete_post((int) $id_coupon, true);
}
bayam_supprimer_produits_essai($vendeur, $debut);
foreach ($stocks_reels as $id => [$suivi, $quantite, $etat]) {
    $p = wc_get_product($id);
    $p->set_manage_stock($suivi);
    $p->set_stock_quantity($quantite);
    $p->set_stock_status($etat);
    $p->save();
}
delete_option('bayam_wa_essais_stocks');
foreach (['dettes', 'diffusions', 'wa_messages', 'liens'] as $table) {
    $wpdb->query("DELETE FROM " . Bayam_WA_Stockage::table($table) . " WHERE vendeur = 3 AND id > {$depart[$table]}");
}
foreach ($sauvegarde as $cle => $valeur) {
    $valeur === '' ? delete_user_meta($vendeur, $cle) : update_user_meta($vendeur, $cle, $valeur);
}
delete_option('bayam_wa_essais_sauvegarde');
delete_option('bayam_wa_essais_debut');
Bayam_WA_Waha::remplacer(null);
Bayam_WA_IA::remplacer(null);
delete_transient("bayam_wa_chaines_{$vendeur}");
Bayam_WA_Facebook::remplacer(null);
Bayam_WA_Retours::remplacer(null);
wp_cache_delete("bayam_fb_pages_{$vendeur}", 'bayam'); // les vraies Pages reliées sont relues au prochain besoin
delete_option('bayam_wa_essais_jusqua');
echo "  données de démonstration restaurées\n";

$echecs = $GLOBALS['echecs'];
echo "\n" . ($echecs ? 'ÉCHECS : ' . count($echecs) . ' (' . implode(' ; ', $echecs) . ')' : 'TOUT EST BON') . ', ' . $GLOBALS['reussis'] . " vérification(s) réussie(s).\n";
