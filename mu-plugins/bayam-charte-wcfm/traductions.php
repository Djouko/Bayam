<?php
/**
 * Traductions francaises pour les chaines WCFM restees en anglais.
 * Cle = texte source exact (msgid), valeur = texte francais.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bayam_charte_domaines() {
	return array( 'wc-frontend-manager', 'wc-multivendor-marketplace', 'wc-multivendor-membership', 'wc-frontend-manager-ultimate', 'wc-frontend-manager-groups-staffs', 'mstore-api' );
}

function bayam_charte_map() {
	static $map = null;
	if ( null === $map ) {
		$map = array(
			'Welcome to the BAYAM Dashboard'    => 'Bienvenue dans votre espace BAYAM',
			'Welcome to the %s Dashboard'       => 'Bienvenue dans votre espace %s',
			'Last Login:'                       => 'Dernière connexion :',
			'Last Login'                        => 'Dernière connexion',
			'You have received an Order'        => 'Nouvelle commande reçue',
			'for'                               => 'pour',
			'Archived'                          => 'Archivés',
			'Filter by category ...'            => 'Filtrer par catégorie ...',
			'Filter by product ...'             => 'Filtrer par produit ...',
			'Show all Marques de produits'      => 'Toutes les marques',
			'Show all Brands'                   => 'Toutes les marques',
			'Show all'                          => 'Tout afficher',
			'All product types'                 => 'Tous les types de produit',
			'Choose Date Range ...'             => 'Choisir une période ...',
			'Choose Categories ...'             => 'Choisir des catégories ...',
			'Customers Limit'                   => 'Limite de clients',
			'Articles Limit'                    => "Limite d'articles",
			'Products Limit'                    => 'Limite de produits',
			'Unpaid'                            => 'Non payé',
			'Refund Requested'                  => 'Remboursement demandé',
			'Company Name'                      => "Nom de l'entreprise",
			'Sales by Date'                     => 'Ventes par date',
			'total refund'                      => 'total des remboursements',
			'total tax'                         => 'total des taxes',
			'Print Report'                      => 'Imprimer le rapport',
			'This is the sum of the refunds and partial refunds.' => 'Somme des remboursements et des remboursements partiels.',
			'Product Reviews'                   => 'Avis sur les produits',
			'Product Review'                    => 'Avis produit',
			'Direct Message'                    => 'Message direct',
			'Low Stock Product'                 => 'Produit en stock faible',
			'Status Updated'                    => 'Statut mis à jour',
			'Withdrawal Requests'               => 'Demandes de retrait',
			'Refund Requests'                   => 'Demandes de remboursement',
			'New Magasin'                       => 'Nouvelle boutique',
			'Disable Magasin'                   => 'Boutique désactivée',
			'Enable Magasin'                    => 'Boutique activée',
			'Pay for Product Credit'            => 'Crédit produit payé',
			'Select all for mark as read or delete' => 'Tout sélectionner pour marquer comme lu ou supprimer',
			'Bulk Delete'                       => 'Suppression groupée',
			'Inquiry Board'                     => 'Questions des clients',
			'Earning for'                       => 'Gain pour',
			'order #'                           => 'commande n°',
			'Withdrawal charges will be re-calculated depending upon total withdrawal amount.' => 'Les frais de retrait seront recalculés selon le montant total du retrait.',
			'Set New Password – Leave blank to retain current password' => "Nouveau mot de passe (laissez vide pour conserver l'actuel)",
			'YouTube Channel Name'              => 'Nom de la chaîne YouTube',
			'Social'                            => 'Réseaux sociaux',
			'Upload'                            => 'Téléverser',
			'Remove'                            => 'Retirer',
			'Show on store'                     => 'Afficher sur la boutique',
			'Check this box if you want to show this coupon in store page.' => 'Cochez cette case pour afficher ce code promo sur la page de la boutique.',
			'Store Slug'                        => 'Adresse de la boutique',
			'Store Email'                       => 'E-mail de la boutique',
			'Store Brand Setup'                 => 'Identité de la boutique',
			'Store Logo'                        => 'Logo de la boutique',
			'Store Banner Type'                 => 'Type de bannière',
			'Static Image'                      => 'Image fixe',
			'Store Banner'                      => 'Bannière de la boutique',
			'Video Banner'                      => 'Bannière vidéo',
			'Mobile Banner'                     => 'Bannière mobile',
			'Store List Banner Type'            => 'Type de bannière de la liste',
			'Store List Banner'                 => 'Bannière de la liste des boutiques',
			'Store List Video Banner'           => 'Bannière vidéo de la liste',
			'Store Visibility Setup'            => 'Visibilité de la boutique',
			'Hide Email from Store'             => "Masquer l'e-mail",
			'Hide Phone from Store'             => 'Masquer le téléphone',
			'Hide Address from Store'           => "Masquer l'adresse",
			'Hide Map from Store'               => 'Masquer la carte',
			'Hide About from Store'             => 'Masquer la présentation',
			'Hide Policy from Store'            => 'Masquer les politiques',
			'Preferred Payment Method'          => 'Moyen de paiement préféré',
			'Choose Withdrawal Payment Method'  => 'Choisir le moyen de retrait',
			'Email Skrill'                      => 'E-mail Skrill',
			'Swift Code'                        => 'Code Swift',
			'IFSC Code'                         => 'Code IFSC',
			'IFSC code'                         => 'Code IFSC',
			'Shipping By Weight'                => 'Livraison selon le poids',
			'Shipping By Distance'              => 'Livraison selon la distance',
			'Shipping By Distance is disabled by Admin. Please contact admin for details' => "La livraison selon la distance est désactivée par l'administrateur. Contactez-le pour plus de détails.",
			'Store Policies'                    => 'Politiques de la boutique',
			'Policies Setting'                  => 'Réglage des politiques',
			'Preferred  size is (125x125) pixels.' => 'Taille conseillée : 125 x 125 pixels.',
			'Preferred size is (125x125) pixels.'  => 'Taille conseillée : 125 x 125 pixels.',
			'Upload a banner for your store. Banner size is (1650x350) pixels.' => 'Téléversez la bannière de votre boutique. Taille conseillée : 1650 x 350 pixels.',
			'This Banner will be visible when someone browse store from Mobile. Preferred size is (520x150) pixels.' => 'Cette bannière s\'affiche quand la boutique est consultée sur mobile. Taille conseillée : 520 x 150 pixels.',
			'This Banner will be visible at Store List Page.' => "Cette bannière s'affiche dans la liste des boutiques.",
			'Store name position at you Store Page.' => 'Position du nom de la boutique sur votre page.',
			'No of products at you Store Page.' => 'Nombre de produits affichés sur votre page boutique.',
			'Street address'                    => 'Adresse',
			'Drag to re-arrange blocks'         => 'Glissez pour réorganiser les blocs',
			'Remove Block'                      => 'Retirer le bloc',
			'Add New Block'                     => 'Ajouter un bloc',
			'Dimensions (in)'                   => 'Dimensions (cm)',
			'1-2 business days'                 => '1 à 2 jours ouvrés',
			'1-3 business days'                 => '1 à 3 jours ouvrés',
			'3-5 business days'                 => '3 à 5 jours ouvrés',
			'Before you can add a variation you need to add some variation attributes on the Attributes tab.' => "Avant d'ajouter une variation, ajoutez des attributs de variation dans l'onglet Attributs.",
			'Create variations from all attributes' => 'Créer des variations à partir de tous les attributs',
			'Enable all Variations'             => 'Activer toutes les variations',
			'Disable all Variations'            => 'Désactiver toutes les variations',
			'Set variations "Virtual"'          => 'Définir les variations comme « Virtuelles »',
			'Set variations "Non-Virtual"'      => 'Définir les variations comme « Non virtuelles »',
			'ON "Manage stock"'                 => 'Activer « Gérer le stock »',
			'OFF "Manage stock"'                => 'Désactiver « Gérer le stock »',
			'Increase Stock'                    => 'Augmenter le stock',
			'Set Status - In stock'             => 'Statut : en stock',
			'Set Status - Out of stock'         => 'Statut : rupture de stock',
			'Set Status - On backorder'         => 'Statut : en réapprovisionnement',
			'Override Policy Fields'            => 'Remplacer les politiques',
			'Product Limit Stats'               => 'Limite de produits',
			'Check to associate this attribute with the product' => 'Cochez pour associer cet attribut au produit',
			'Search for an attribute ...'       => 'Rechercher un attribut ...',
			'Enable this checkbox to set product-based policies.' => 'Cochez cette case pour définir des politiques propres à ce produit.',
			'Message ... YYYY-MM-DD'            => 'Message ... AAAA-MM-JJ',
			'Message... YYYY-MM-DD'             => 'Message... AAAA-MM-JJ',
			'Message... YYYY-DD-MM'             => 'Message... AAAA-JJ-MM',
			'%1$s top seller in last 7 days (sold %2$d)' => '%1$s meilleure vente des 7 derniers jours (%2$d vendus)',
			'Show all '                         => 'Toutes les ',
			'Show all product types'            => 'Tous les types de produit',
			'Choose Date Range'                 => 'Choisir une période',
			'Archive Product'                   => 'Archiver le produit',
			'Earning for %s order #%s.'         => 'Gain pour %s, commande n°%s.',
			'Send Direct Message'               => 'Envoyer un message direct',
			'Upload a banner for your store. Banner size is (%sx%s) pixels.' => 'Téléversez la bannière de votre boutique. Taille conseillée : %sx%s pixels.',
			'This Banner will be visible when someone browse store from Mobile.' => 'Cette bannière s\'affiche quand la boutique est consultée sur mobile.',
			'Preferred size is (%sx%s) pixels.' => 'Taille conseillée : %sx%s pixels.',
			'Customers Limit: '                 => 'Limite de clients : ',
			'Articles Limit: '                  => 'Limite d\'articles : ',
			'Products Limit: '                  => 'Limite de produits : ',
			'%s total tax'                      => '%s de taxes au total',
			'%s total refund'                   => '%s de remboursements au total',
			'General Setting'                   => 'Réglages généraux',
			'Policy'                            => 'Politique',
			'Refund'                            => 'Remboursement',
			'Report'                            => 'Signalement',
			'Review'                            => 'Avis',
			'Vacation'                          => 'Vacances',
			'Category'                          => 'Catégorie',
			'Code Postal'                       => 'Code postal',
			'Print'                             => 'Imprimer',
			'Facebook Description'              => 'Description Facebook',
			'Twitter Description'               => 'Description Twitter',
			'Logout'                            => 'Déconnexion',
			'My Store'                          => 'Ma boutique',
			'Dashboard'                         => 'Tableau de bord',
			'Ledger Book'                       => 'Relevé de compte',
			'There are no enquiries yet.'       => 'Aucune question de client pour le moment.',
			'There is no topic yet!!'           => 'Aucune annonce pour le moment.',
			'Latest Topics'                     => 'Annonces BAYAM',
			'Store Stats'                       => 'Ma boutique en bref',
			'Store Analytics'                   => 'Visites de la boutique',
			'gross sales in this month'         => 'ventes du mois',
			'earnings in this month'            => 'gains du mois',
			// Erreurs du fichier français de WCFM : ces deux textes y sont traduits par « Stats du magasin ».
			'United States'                     => 'États-Unis',
			'Store Sidebar'                     => 'Colonne de la boutique',
		);
	}
	return $map;
}

add_filter( 'gettext', function ( $translation, $text, $domain ) {
	if ( ! in_array( $domain, bayam_charte_domaines(), true ) || ! bayam_charte_is_target_page() ) {
		return $translation;
	}
	$map = bayam_charte_map();
	return isset( $map[ $text ] ) ? $map[ $text ] : $translation;
}, 20, 3 );

// Pluriels : « 1 objet » de WCFM devient « 1 article » (compteur du tableau de bord et autres listes).
add_filter( 'ngettext', function ( $translation, $single, $plural, $number, $domain ) {
	$articles = array(
		'%d item'                   => array( '%d article', '%d articles' ),
		'<strong>%s item</strong>'  => array( '<strong>%s article</strong>', '<strong>%s articles</strong>' ),
	);
	if ( ! isset( $articles[ $single ] ) || ! in_array( $domain, bayam_charte_domaines(), true ) || ! bayam_charte_is_target_page() ) {
		return $translation;
	}
	return $articles[ $single ][ 1 === (int) $number ? 0 : 1 ];
}, 20, 5 );
