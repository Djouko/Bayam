<?php
/**
 * Plugin Name: BAYAM Commerce Cameroun
 * Description: Adresses à la camerounaise pour la commande (sans code postal ni région, quartier et point de repère) et téléphone obligatoire pour la livraison.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Au Cameroun, on livre au quartier et au point de repère : pas de code postal, pas de région à saisir.
// Le tableau de commande en blocs lit aussi ce filtre, comme l'ancien formulaire.
add_filter( 'woocommerce_get_country_locale', function ( $locale ) {
	$locale['CM'] = array_replace_recursive(
		$locale['CM'] ?? array(),
		array(
			'postcode'  => array( 'required' => false, 'hidden' => true ),
			'state'     => array( 'required' => false, 'hidden' => true ),
			'address_1' => array(
				'label'       => 'Quartier et point de repère',
				'placeholder' => 'Bonapriso, face à la pharmacie du Centre',
			),
			'city'      => array( 'label' => 'Ville' ),
		)
	);
	return $locale;
} );

// Le livreur et la boutique joignent le client par téléphone ou WhatsApp : le numéro est indispensable.
add_filter( 'pre_option_woocommerce_checkout_phone_field', function () {
	return 'required';
} );

// Heures à la française (17:27 et non 5:27 pm), partout : site, espace vendeur, courriels.
add_filter( 'pre_option_time_format', function () {
	return 'H:i';
} );

// Coupon supprimé (espace vendeur, administration ou suppression directe) : WooCommerce garde en cache
// « code => identifiant », et avec le cache persistant Redis un code supprimé paraît encore pris.
add_action( 'deleted_post', function ( $id, $article ) {
	if ( $article instanceof WP_Post && 'shop_coupon' === $article->post_type && class_exists( 'WC_Cache_Helper' ) ) {
		WC_Cache_Helper::invalidate_cache_group( 'coupons' );
	}
}, 10, 2 );

// Stripe (cartes bancaires, extension officielle WooCommerce Stripe Gateway) : clés lues dans l'environnement (.env),
// jamais enregistrées en base ; mode test tant que STRIPE_MODE ne vaut pas « live ».
add_filter( 'option_woocommerce_stripe_settings', function ( $reglages ) {
	$publique = (string) getenv( 'STRIPE_PUBLISHABLE_KEY' );
	$secrete  = (string) getenv( 'STRIPE_SECRET_KEY' );
	if ( '' === $publique || '' === $secrete ) {
		return $reglages;
	}
	$test    = 'live' !== getenv( 'STRIPE_MODE' );
	$prefixe = $test ? 'test_' : '';
	return array_merge( is_array( $reglages ) ? $reglages : array(), array(
		'enabled'                   => 'yes',
		'testmode'                  => $test ? 'yes' : 'no',
		'title'                     => 'Carte bancaire (Visa, Mastercard)',
		$prefixe . 'publishable_key' => $publique,
		$prefixe . 'secret_key'      => $secrete,
		$prefixe . 'webhook_secret'  => (string) getenv( 'STRIPE_WEBHOOK_SECRET' ),
	) );
} );
