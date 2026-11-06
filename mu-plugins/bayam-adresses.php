<?php
/**
 * Plugin Name: BAYAM Adresses du site
 * Description: Le même site sous plusieurs adresses : le poste (localhost:8080, aussi utilisé par l'application via adb reverse) et l'adresse publique du tunnel, pour ouvrir depuis un téléphone les liens envoyés sur WhatsApp.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Adresse publique du tunnel (https://….trycloudflare.com), ou null hors démonstration. Relue toutes les 5 minutes. */
function bayam_url_publique() {
	static $en_cours = false;
	$metriques = getenv( 'BAYAM_TUNNEL_METRICS' );
	if ( ! $metriques || $en_cours ) {
		return null;
	}
	$url = get_transient( 'bayam_url_publique' );
	if ( false === $url ) {
		// La requête HTTP de WordPress lit elle-même l'adresse du site : on évite de revenir ici en boucle.
		$en_cours = true;
		$reponse  = wp_remote_get( rtrim( $metriques, '/' ) . '/quicktunnel', array( 'timeout' => 3 ) );
		$en_cours = false;
		$hote     = is_wp_error( $reponse ) ? '' : (string) ( json_decode( wp_remote_retrieve_body( $reponse ), true )['hostname'] ?? '' );
		$url      = preg_match( '/^[a-z0-9-]+\.trycloudflare\.com$/', $hote ) ? 'https://' . $hote : '';
		// Une minute : l'adresse change à chaque relance du tunnel, un lien envoyé ne doit pas pointer vers l'ancienne.
		set_transient( 'bayam_url_publique', $url, MINUTE_IN_SECONDS );
	}
	return $url ? $url : null;
}

/** Hôte du tunnel si la page est demandée par là (seuls les sous-domaines trycloudflare.com sont acceptés). */
function bayam_hote_tunnel() {
	$hote = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
	return preg_match( '/^[a-z0-9-]+\.trycloudflare\.com$/', $hote ) ? $hote : null;
}

if ( bayam_hote_tunnel() ) {
	$GLOBALS['bayam_base_tunnel'] = 'https://' . bayam_hote_tunnel();
	$GLOBALS['bayam_base_poste']  = untrailingslashit( (string) get_option( 'home' ) );

	add_filter( 'option_home', function () {
		return $GLOBALS['bayam_base_tunnel'];
	} );
	add_filter( 'option_siteurl', function () {
		return $GLOBALS['bayam_base_tunnel'];
	} );

	// Les menus et certains contenus gardent l'adresse du poste en dur : on la remplace dans toute la page servie,
	// y compris sous sa forme échappée des réponses JSON (panier et commande en blocs).
	ob_start( function ( $html ) {
		$poste  = $GLOBALS['bayam_base_poste'];
		$tunnel = $GLOBALS['bayam_base_tunnel'];
		return str_replace(
			array( $poste, str_replace( '/', '\/', $poste ) ),
			array( $tunnel, str_replace( '/', '\/', $tunnel ) ),
			$html
		);
	} );
}
