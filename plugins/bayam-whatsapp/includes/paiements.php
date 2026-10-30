<?php
defined('ABSPATH') || exit;

/**
 * Liens de paiement (« lien 15000 Marie ») et alertes WhatsApp du vendeur : nouvelle commande (application, site
 * ou Copilote) et paiement reçu.
 */
final class Bayam_WA_Paiements
{
    public static function init(): void
    {
        // Après WCFM, qui rattache les articles aux boutiques sur ce même crochet.
        add_action('woocommerce_checkout_order_processed', [self::class, 'nouvelle_commande'], 50, 1);
        add_action('woocommerce_store_api_checkout_order_processed', static fn ($commande) => self::nouvelle_commande($commande->get_id()), 50);
        add_action('woocommerce_order_status_changed', [self::class, 'statut_change'], 20, 3);
    }

    /** Commande à payer pour un montant libre ; rend le lien court vers la page de paiement WooCommerce. */
    public static function creer_lien(int $vendeur, int $montant, string $client): string
    {
        $commande = wc_create_order(['status' => 'pending', 'created_via' => 'bayam-whatsapp']);
        $frais = new WC_Order_Item_Fee();
        $frais->set_name('Achat chez ' . Bayam_WA_Outils::nom_boutique($vendeur) . ($client !== 'Client' ? " ({$client})" : ''));
        $frais->set_amount($montant);
        $frais->set_total($montant);
        $frais->set_tax_status('none');
        $commande->add_item($frais);
        $commande->set_billing_first_name($client);
        $commande->update_meta_data('_bayam_vendeur', $vendeur);
        $commande->update_meta_data('_bayam_lien_paiement', 1);
        $commande->calculate_totals(false);
        $commande->add_order_note('Lien de paiement créé depuis le Copilote WhatsApp.');
        $commande->save();
        return Bayam_WA_Liens::creer($vendeur, $commande->get_checkout_payment_url(), 'paiement');
    }

    /** Vendeurs concernés par une commande : boutiques de ses articles (WCFM) ou vendeur d'un lien de paiement. */
    public static function vendeurs_de(WC_Order $commande): array
    {
        global $wpdb;
        $vendeurs = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT DISTINCT vendor_id FROM {$wpdb->prefix}wcfm_marketplace_orders WHERE order_id = %d", $commande->get_id())));
        $lien = (int) $commande->get_meta('_bayam_vendeur');
        if ($lien) {
            $vendeurs[] = $lien;
        }
        return array_values(array_unique(array_filter($vendeurs)));
    }

    public static function nouvelle_commande(int $id): void
    {
        $commande = wc_get_order($id);
        if (!$commande || $commande->get_created_via() === 'bayam-whatsapp' || $commande->get_meta('_bayam_alerte_nouvelle')) {
            return;
        }
        $commande->update_meta_data('_bayam_alerte_nouvelle', 1);
        $commande->save_meta_data();
        foreach (self::vendeurs_de($commande) as $vendeur) {
            if (get_user_meta($vendeur, 'bayam_wa_statut', true) !== 'WORKING') {
                continue;
            }
            $articles = [];
            foreach ($commande->get_items() as $item) {
                $p = $item->get_product();
                if ($p && (int) get_post_field('post_author', $p->get_parent_id() ?: $p->get_id()) === $vendeur) {
                    $articles[] = $item->get_quantity() . ' × ' . $item->get_name();
                }
            }
            $client = trim($commande->get_billing_first_name() . ' ' . $commande->get_billing_last_name()) ?: 'Client';
            $tel = $commande->get_billing_phone();
            $adresse = trim($commande->get_shipping_address_1() . ', ' . $commande->get_shipping_city(), ' ,');
            Bayam_WA_Regles::au_vendeur($vendeur, "🛒 *Nouvelle commande n° {$commande->get_order_number()}*\n"
                . implode("\n", $articles) . "\n"
                . 'Total : *' . Bayam_WA_Outils::fcfa((float) $commande->get_total()) . "* ({$commande->get_payment_method_title()})\n"
                . "Client : {$client}" . ($tel ? ', ' . Bayam_WA_Outils::numero_lisible(Bayam_WA_Outils::numero($tel) ?? $tel) : '')
                . ($adresse ? "\nLivraison : {$adresse}" : '')
                . ($tel && ($n = Bayam_WA_Outils::numero($tel)) ? "\nÉcrire au client : https://wa.me/{$n}" : ''));
        }
    }

    /** L'argent est-il chez le vendeur ? À la livraison, il l'est quand la commande est terminée (colis remis et payé). */
    public static function encaissee(WC_Order $commande): bool
    {
        return $commande->get_payment_method() === 'cod' ? $commande->has_status('completed') : $commande->is_paid();
    }

    public static function statut_change(int $id, string $avant, string $apres): void
    {
        $commande = wc_get_order($id);
        if (!$commande || !self::encaissee($commande) || $commande->get_meta('_bayam_alerte_paiement') || $commande->get_created_via() === 'bayam-whatsapp' && !$commande->get_meta('_bayam_lien_paiement')) {
            return;
        }
        $commande->update_meta_data('_bayam_alerte_paiement', 1);
        $commande->save_meta_data();
        foreach (self::vendeurs_de($commande) as $vendeur) {
            if (get_user_meta($vendeur, 'bayam_wa_statut', true) === 'WORKING') {
                $client = trim($commande->get_billing_first_name() . ' ' . $commande->get_billing_last_name()) ?: 'le client';
                Bayam_WA_Regles::au_vendeur($vendeur, "💰 *Paiement reçu* : " . Bayam_WA_Outils::fcfa((float) $commande->get_total()) . " de {$client} (commande n° {$commande->get_order_number()}, {$commande->get_payment_method_title()}).");
            }
        }
    }
}
