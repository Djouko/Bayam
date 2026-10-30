<?php
/**
 * Plugin Name: BAYAM Charte WCFM
 * Description: Charte graphique BAYAM (couleurs, polices, responsive) et traductions francaises pour l'espace vendeur WCFM et les pages boutique.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vrai sur l'espace vendeur (/store-manager/) et sur les pages boutique (/store/...).
 * Test par URL : ces pages sont servies par des pages WordPress ou des rewrite WCFM,
 * et is_wcfm_page() n'est pas fiable avant le hook wp.
 */
function bayam_charte_is_target_page() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	return (bool) preg_match( '#/(store-manager|store|boutiques)(/|$)#', $path );
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! bayam_charte_is_target_page() ) {
		return;
	}
	wp_enqueue_style(
		'bayam-charte-fonts',
		'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700&display=swap',
		array(),
		null
	);
	$file = __DIR__ . '/bayam-charte-wcfm/charte.css';
	wp_enqueue_style(
		'bayam-charte-wcfm',
		content_url( 'mu-plugins/bayam-charte-wcfm/charte.css' ),
		array( 'bayam-charte-fonts' ),
		file_exists( $file ) ? filemtime( $file ) : '1.0.0'
	);
}, 99999 );

add_filter( 'body_class', function ( $classes ) {
	if ( bayam_charte_is_target_page() ) {
		$classes[] = 'bayam-wcfm';
	}
	return $classes;
} );


// Chaines non traduisibles par gettext : messages stockes en base (notifications) et placeholders ajoutes en JS.
add_action( 'wp_footer', function () {
	if ( ! bayam_charte_is_target_page() ) {
		return;
	}
	?>
<script>
(function(){
	var rules=[
		[/You have received an Order\s*(#\d+)\s+for\s+/g,'Nouvelle commande $1 pour '],
		[/You have received an Order\s*(#\d+)/g,'Nouvelle commande $1'],
		[/Envoyer un message direct\s*-\s*Vers boutique Admin/g,"Écrire à l'administrateur BAYAM"],
		[/You have received an Order\s*$/,'Nouvelle commande '],[/^\s+for\s+$/,' pour '],[/is low in stock\. There are (\d+) left\./,'est bientôt en rupture de stock. Il en reste $1.'],
		[/Showing (\d+)[–-](\d+) of (\d+) results/,'Affichage de $1 à $2 sur $3 résultats'],
		[/Earning for /g,'Gain pour '],[/\border #/g,'commande n°'],
		[/Custom:/,'Personnalisé :'],
		[/YYYY-MM-DD/g,'AAAA-MM-JJ'],[/YYYY-DD-MM/g,'AAAA-JJ-MM']
	];
	function fix(s){rules.forEach(function(r){s=s.replace(r[0],r[1]);});return s;}
	function run(){
		var root=document.getElementById('wcfm-main-contentainer')||document.getElementById('primary')||document.body;
		if(!root){return;}
		var w=document.createTreeWalker(root,NodeFilter.SHOW_TEXT),n;
		while((n=w.nextNode())){var t=fix(n.nodeValue);if(t!==n.nodeValue){n.nodeValue=t;}}
		root.querySelectorAll('[placeholder]').forEach(function(e){var p=e.getAttribute('placeholder'),q=fix(p);if(p!==q){e.setAttribute('placeholder',q);}});
	}
	var timer=null;
	new MutationObserver(function(){clearTimeout(timer);timer=setTimeout(run,150);}).observe(document.body,{childList:true,subtree:true});
	run();
})();
</script>
	<?php
}, 99999 );

// Accueil de l'espace vendeur : le logo de la boutique plutot qu'une silhouette grise, et pas de jauges
// « 12 / infini » (limites d'abonnement sans objet chez BAYAM).
add_filter( 'wcfm_defaut_user_avatar', function ( $avatar ) {
	if ( ! function_exists( 'wcfm_is_vendor' ) || ! wcfm_is_vendor() || ! function_exists( 'wcfmmp_get_store' ) ) {
		return $avatar;
	}
	$logo = wcfmmp_get_store( get_current_user_id() )->get_avatar();
	return $logo ? $logo : $avatar;
} );
add_filter( 'wcfm_is_allow_dashboard_limit_stats', '__return_false' );

// Traductions : uniquement les domaines WCFM, uniquement sur les pages ciblees.
require_once __DIR__ . '/bayam-charte-wcfm/traductions.php';
