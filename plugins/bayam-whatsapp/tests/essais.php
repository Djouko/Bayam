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
        if (in_array($chemin, ['/api/sendText', '/api/sendImage'], true) || str_contains($chemin, '/status/')) {
            return ['id' => 'true_' . count($this->envois) . '_BAYAMESSAI'];
        }
        if (str_starts_with($chemin, '/api/sessions/')) {
            return ['status' => 'WORKING', 'me' => ['id' => '237690000003@c.us', 'pushName' => 'Mode Aïcha']];
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
$sauvegarde = [];
foreach (['bayam_wa', 'bayam_wa_moi', 'bayam_wa_lid', 'bayam_wa_statut', 'bayam_wa_dernier_rapport', 'bayam_wa_bienvenue'] as $cle) {
    $sauvegarde[$cle] = get_user_meta($vendeur, $cle, true);
}
$depart = [];
foreach (['dettes', 'diffusions', 'wa_messages', 'liens'] as $table) {
    $depart[$table] = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM " . Bayam_WA_Stockage::table($table));
}
$faux = $GLOBALS['faux'] = new Bayam_Faux_Waha();
Bayam_WA_Waha::remplacer($faux);
update_user_meta($vendeur, 'bayam_wa_moi', '237690000003@c.us');
update_user_meta($vendeur, 'bayam_wa_statut', 'WORKING');
delete_user_meta($vendeur, 'bayam_wa');
$debut = current_time('mysql');
$debut_ts = time();
$GLOBALS['reussis'] = 0;
$GLOBALS['echecs'] = [];

/** Rejoue un message et rend les textes (et légendes d'images) envoyés en réponse. */
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
$r = bayam_dire('menu');
bayam_verifier('menu', count($r) === 1 && str_contains($r[0], 'Copilote BAYAM') && str_contains($r[0], 'vendu robe rouge'), $r);
$r = bayam_dire('sweats');
bayam_verifier('recherche « sweats » (pluriel)', str_contains($joint($r), 'Sweat à capuche') && str_contains($joint($r), '/b/'), $r);
$r = bayam_dire('#tshirt');
bayam_verifier('« #tshirt » sans tiret ne trouve rien proprement', count($r) === 1, $r);
$r = bayam_dire('catalgue');
bayam_verifier('faute de frappe « catalgue »', str_contains($joint($r), 'Votre catalogue'), $r);
$r = bayam_dire('cartes polo');
bayam_verifier('cartes : image + conseil de transfert', count($r) === 2 && str_contains($r[0], '[image]') && str_contains($r[1], 'Transférer'), $r);
$r = bayam_dire('stock sweat capuche');
bayam_verifier('stock par variante', str_contains($joint($r), 'Stock : Sweat à capuche') && substr_count($joint($r), "\n") >= 3, $r);
$r = bayam_dire('+5 t-shirt');
bayam_verifier('entrée de stock +5', str_contains($joint($r), 'Stock : 5'), $r);
$r = bayam_dire('-2 t-shirt');
bayam_verifier('sortie de stock -2', str_contains($joint($r), 'Stock : 3'), $r);
$r = bayam_dire('vendu t-shirt x2 20000 momo');
bayam_verifier('vente directe au prix négocié', str_contains($joint($r), 'enregistrée') && str_contains($joint($r), "20\u{202F}000") && str_contains($joint($r), 'Stock restant : 1'), $r);
$r = bayam_dire('vendu t-shirt x5 cash');
bayam_verifier('vente refusée si stock insuffisant', str_contains($joint($r), 'Stock insuffisant'), $r);
$r = bayam_dire('vendu sweat capuche');
bayam_verifier('vente d’un produit à variantes : demande la variante', str_contains($joint($r), 'Précisez la variante'), $r);
$r = bayam_dire('vendu sweat capuche rouge non 25000 cash');
bayam_verifier('vente d’une variante (rouge, sans logo)', str_contains($joint($r), 'enregistrée'), $r);
$r = bayam_dire('lien 15000 Marie');
bayam_verifier('lien de paiement', str_contains($joint($r), '15') && str_contains($joint($r), 'Marie') && str_contains($joint($r), '/b/'), $r);
$r = bayam_dire('commandes');
bayam_verifier('commandes en cours (lien de paiement en attente)', str_contains($joint($r), 'En attente de paiement') && str_contains($joint($r), 'Marie'), $r);
$r = bayam_dire('bilan');
bayam_verifier('bilan du jour', str_contains($joint($r), "Bilan aujourd'hui") && str_contains($joint($r), 'Chiffre d'), $r);
$r = bayam_dire('bilan semaine');
bayam_verifier('bilan de la semaine', str_contains($joint($r), 'cette semaine'), $r);
// Nom propre aux essais : la boutique a ses vraies dettes.
$r = bayam_dire('dette Ndzana 5000 15/11');
bayam_verifier('dette notée avec échéance', str_contains($joint($r), 'Ndzana') && str_contains($joint($r), '15/11'), $r);
$r = bayam_dire('dettes');
bayam_verifier('liste des dettes', str_contains($joint($r), 'Qui me doit quoi') && str_contains($joint($r), 'Ndzana'), $r);
$r = bayam_dire('payé Ndzana 2000');
bayam_verifier('paiement partiel d’une dette', str_contains($joint($r), "Reste à payer : 3\u{202F}000"), $r);
$r = bayam_dire('promo BAYAMESSAI 10%');
bayam_verifier('code promo et message prêt à partager', count($r) === 2 && str_contains($r[0], 'BAYAMESSAI') && str_contains($r[1], '-10 %'), $r);
$r = bayam_dire('promo BAYAMESSAI 10%');
bayam_verifier('code promo en double refusé', str_contains($joint($r), 'existe déjà'), $r);
$r = bayam_dire('rapport 21h');
bayam_verifier('heure du résumé quotidien', str_contains($joint($r), '21 h') && Bayam_WA_Stockage::reglages($vendeur)['heure_rapport'] === 21, $r);
$r = bayam_dire('boutique');
bayam_verifier('lien unique de la boutique', str_contains($joint($r), '/b/'), $r);
$r = bayam_dire('publier polo');
bayam_verifier('publier sans groupe choisi : explique quoi faire', str_contains($joint($r), 'Choisissez d'), $r);
Bayam_WA_Stockage::enregistrer($vendeur, ['groupes' => ['120363000000000001@g.us' => 'Clientes Mode Aïcha', '120363000000000002@g.us' => 'Bonnes affaires Akwa']]);
$r = bayam_dire('publier polo');
bayam_verifier('publier dans 2 groupes : 2 envois programmés', str_contains($joint($r), '2 publication(s)'), $r);
$r = bayam_dire('comment tu vas');
bayam_verifier('message incompris : renvoie au menu', str_contains($joint($r), "Je n'ai pas compris") && str_contains($joint($r), 'menu'), $r);
$r = bayam_dire('ROBE ROUGE 15000 M:3 L:2', photo: true);
bayam_verifier('photo + légende : produit à tailles créé', str_contains($joint($r), 'Produit créé') && str_contains($joint($r), 'M : 3'), $r);
$r = bayam_dire('casquette brodée 6500 4', photo: true);
bayam_verifier('photo : accents gardés, quantité après le prix', str_contains($joint($r), '*Casquette brodée*') && str_contains($joint($r), "6\u{202F}500") && str_contains($joint($r), '4 pièce(s)'), $r);
$r = bayam_dire('Sandales pointure 42 25 000 42:2', photo: true);
bayam_verifier('photo : nombre du nom ignoré pour le prix', str_contains($joint($r), '*Sandales pointure 42*') && str_contains($joint($r), "25\u{202F}000") && str_contains($joint($r), '42 : 2'), $r);

echo "\nÉquipe (numéro autorisé, commandes avec « / »)\n";
$r = bayam_dire('pin 4826');
bayam_verifier('PIN choisi par le vendeur', str_contains($joint($r), 'PIN'), $r);
$r = bayam_dire('equipe ajouter 6 77 00 00 02');
bayam_verifier('membre ajouté', str_contains($joint($r), 'Ajouté'), $r);
$r = bayam_dire('/stock polo', '237677000002@c.us', false);
bayam_verifier('membre : stock sans PIN', str_contains($joint($r), 'Stock : Polo'), $r);
$r = bayam_dire('/vendu polo 12000 cash', '237677000002@c.us', false);
bayam_verifier('membre : vente refusée sans PIN', str_contains($joint($r), 'code PIN'), $r);
$r = bayam_dire('/vendu polo 12000 cash pin 4826', '237677000002@c.us', false);
bayam_verifier('membre : vente acceptée avec PIN', str_contains($joint($r), 'enregistrée'), $r);
$r = bayam_dire('/pin 1111', '237677000002@c.us', false);
bayam_verifier('membre : ne change pas le PIN', str_contains($joint($r), 'propriétaire'), $r);

echo "\nClients du vendeur\n";
$r = bayam_dire('#polo', '237677000001@c.us', false);
bayam_verifier('client « #polo » : articles en stock', str_contains($joint($r), 'Polo') && str_contains($joint($r), 'Bonjour'), $r);
$r = bayam_dire('Bonsoir, c\'est combien les sweats svp ?', '237677000001@c.us', false);
bayam_verifier('client « combien les sweats » : réponse', str_contains($joint($r), 'Sweat'), $r);
$r = bayam_dire('Merci beaucoup, à demain', '237677000001@c.us', false);
bayam_verifier('client sans demande d’article : pas de réponse automatique', $r === [], $r);
$r = bayam_dire('vous avez des chaussures ?', '237677000001@c.us', false);
bayam_verifier('article absent : réponse polie', str_contains($joint($r), "n'avons pas"), $r);
$r = bayam_dire(Bayam_WA_Outils::MARQUE . 'menu');
bayam_verifier('nos propres messages sont ignorés (pas de boucle)', $r === [], $r);

echo "\nEnvoi d'une collection\n";
$r = bayam_dire('envoie polo 6 77 00 00 01');
bayam_verifier('client qui a déjà écrit : collection envoyée', str_contains($joint($r), 'Collection envoyée'), $r);
$r = bayam_dire('envoie polo 6 99 99 99 99');
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

// --- Remise en état ---------------------------------------------------------------------------------------------
echo "\nRemise en état\n";
foreach (wc_get_orders(['created_via' => 'bayam-whatsapp', 'limit' => -1, 'date_created' => '>=' . ($debut_ts - 5)]) as $c) {
    $c->delete(true);
}
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wcfm_marketplace_orders WHERE vendor_id = 3 AND created >= %s", $debut));
if ($id = wc_get_coupon_id_by_code('BAYAMESSAI')) {
    wp_delete_post($id, true);
}
foreach (get_posts(['post_type' => 'product', 'author' => 3, 'date_query' => [['after' => $debut, 'inclusive' => true]], 'numberposts' => 10, 'post_status' => 'any']) as $p) {
    $w = wc_get_product($p->ID);
    if ($w) {
        foreach ($w->get_children() as $c) {
            wp_delete_post($c, true);
        }
        wp_delete_attachment($w->get_image_id(), true);
        $w->delete(true);
    }
}
$tshirt = wc_get_product(17);
$tshirt->set_manage_stock(false);
$tshirt->set_stock_status('instock');
$tshirt->save();
foreach ([31, 25] as $id) {
    $p = wc_get_product($id);
    if ($p && $p->managing_stock()) {
        $p->set_manage_stock(false);
        $p->set_stock_status('instock');
        $p->save();
    }
}
foreach (['dettes', 'diffusions', 'wa_messages', 'liens'] as $table) {
    $wpdb->query("DELETE FROM " . Bayam_WA_Stockage::table($table) . " WHERE vendeur = 3 AND id > {$depart[$table]}");
}
foreach ($sauvegarde as $cle => $valeur) {
    $valeur === '' ? delete_user_meta($vendeur, $cle) : update_user_meta($vendeur, $cle, $valeur);
}
Bayam_WA_Waha::remplacer(null);
echo "  données de démonstration restaurées\n";

$echecs = $GLOBALS['echecs'];
echo "\n" . ($echecs ? 'ÉCHECS : ' . count($echecs) . ' (' . implode(' ; ', $echecs) . ')' : 'TOUT EST BON') . ', ' . $GLOBALS['reussis'] . " vérification(s) réussie(s).\n";
