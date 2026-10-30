<?php
/**
 * BAYAM Vitrine : thème enfant de Storefront.
 * En-tête, pied de page, accueil et habillage WooCommerce passent par les hooks de Storefront.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/inc/icones.php';
require_once __DIR__ . '/inc/accueil.php';

/** Chemin de fichier + version = date de modification : le cache navigateur suit chaque changement. */
function bayam_vitrine_version( $relatif ) {
	$fichier = get_stylesheet_directory() . '/' . $relatif;
	return file_exists( $fichier ) ? (string) filemtime( $fichier ) : '1.0.0';
}

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style(
		'bayam-vitrine',
		get_stylesheet_uri(),
		array( 'storefront-style', 'storefront-woocommerce-style' ),
		bayam_vitrine_version( 'style.css' )
	);
	wp_enqueue_script(
		'bayam-vitrine',
		get_stylesheet_directory_uri() . '/assets/js/vitrine.js',
		array(),
		bayam_vitrine_version( 'assets/js/vitrine.js' ),
		true
	);
}, 20 );

// Les polices sont chargées par le CSS ; on les précharge pour éviter le saut de texte.
add_action( 'wp_head', function () {
	foreach ( array( 'Inter-Medium', 'Poppins-Bold' ) as $police ) {
		printf(
			'<link rel="preload" href="%s" as="font" type="font/ttf" crossorigin>' . "\n",
			esc_url( get_stylesheet_directory_uri() . '/assets/fonts/' . $police . '.ttf' )
		);
	}
	echo '<script>document.documentElement.classList.add("bv-js")</script>' . "\n";
}, 1 );

/* Devise : « FCFA » au lieu de « XAF ». */
add_filter( 'woocommerce_currency_symbol', function ( $symbole, $devise ) {
	return 'XAF' === $devise ? 'FCFA' : $symbole;
}, 10, 2 );

/* Pleine largeur partout : plus de barre latérale. */
add_action( 'init', function () {
	remove_action( 'storefront_sidebar', 'storefront_get_sidebar', 10 );
} );
add_filter( 'body_class', function ( $classes ) {
	$classes = array_diff( $classes, array( 'right-sidebar', 'left-sidebar' ) );
	$classes[] = 'storefront-full-width-content';
	return $classes;
}, 20 );

/* Grille de la boutique : 4 colonnes, 12 produits par page. */
add_filter( 'loop_shop_columns', function () {
	return 4;
}, 20 );
add_filter( 'loop_shop_per_page', function () {
	return 12;
}, 20 );

/* Catégorie par défaut « Non classé » : jamais proposée aux visiteurs. */
function bayam_vitrine_categories() {
	return get_terms( array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => true,
		'exclude'    => array( (int) get_option( 'default_product_cat' ) ),
		'orderby'    => 'name',
	) );
}

/* ---------- En-tête ---------- */

add_action( 'init', function () {
	$retraits = array(
		'storefront_header_container'                 => 0,
		'storefront_site_branding'                    => 20,
		'storefront_secondary_navigation'             => 30,
		'storefront_header_container_close'           => 41,
		'storefront_primary_navigation_wrapper'       => 42,
		'storefront_primary_navigation'               => 50,
		'storefront_primary_navigation_wrapper_close' => 68,
	);
	foreach ( $retraits as $fonction => $priorite ) {
		remove_action( 'storefront_header', $fonction, $priorite );
	}
	// Barre de recherche et panier d'origine : remplacés par les nôtres.
	remove_action( 'storefront_header', 'storefront_product_search', 40 );
	remove_action( 'storefront_header', 'storefront_header_cart', 60 );
	add_action( 'storefront_header', 'bayam_vitrine_entete', 10 );
}, 20 );

/** Nombre d'articles du panier, aussi rafraîchi par les fragments AJAX de WooCommerce. */
function bayam_vitrine_lien_panier() {
	$nombre = function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
	ob_start();
	?>
	<a class="bv-action bv-cart" href="<?php echo esc_url( wc_get_cart_url() ); ?>" aria-label="<?php echo esc_attr( sprintf( _n( 'Panier, %d article', 'Panier, %d articles', $nombre, 'bayam-vitrine' ), $nombre ) ); ?>">
		<?php echo bayam_icone( 'bag' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<span class="bv-cart__count<?php echo $nombre ? '' : ' is-empty'; ?>"><?php echo esc_html( $nombre ); ?></span>
	</a>
	<?php
	return ob_get_clean();
}

add_filter( 'woocommerce_add_to_cart_fragments', function ( $fragments ) {
	$fragments['a.bv-cart'] = bayam_vitrine_lien_panier();
	return $fragments;
} );

function bayam_vitrine_entete() {
	$logo = get_stylesheet_directory_uri() . '/assets/img/logo.png';
	?>
	<div class="bv-bar">
		<button type="button" class="bv-burger" aria-controls="bv-nav" aria-expanded="false" aria-label="<?php esc_attr_e( 'Ouvrir le menu', 'bayam-vitrine' ); ?>">
			<?php echo bayam_icone( 'menu' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</button>
		<a class="bv-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'BAYAM, accueil', 'bayam-vitrine' ); ?>">
			<img src="<?php echo esc_url( $logo ); ?>" width="360" height="113" alt="BAYAM">
		</a>
		<nav id="bv-nav" class="bv-nav" aria-label="<?php esc_attr_e( 'Menu principal', 'bayam-vitrine' ); ?>">
			<div class="bv-nav__head">
				<span><?php esc_html_e( 'Menu', 'bayam-vitrine' ); ?></span>
				<button type="button" class="bv-nav__close" aria-label="<?php esc_attr_e( 'Fermer le menu', 'bayam-vitrine' ); ?>"><?php echo bayam_icone( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
			</div>
			<?php
			wp_nav_menu( array(
				'theme_location' => 'primary',
				'container'      => false,
				'menu_class'     => 'bv-menu',
				'fallback_cb'    => false,
			) );
			?>
		</nav>
		<div class="bv-actions">
			<button type="button" class="bv-action bv-open-search" aria-controls="bv-search" aria-expanded="false" aria-label="<?php esc_attr_e( 'Rechercher un produit', 'bayam-vitrine' ); ?>">
				<?php echo bayam_icone( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</button>
			<a class="bv-action bv-account" href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" aria-label="<?php esc_attr_e( 'Mon compte', 'bayam-vitrine' ); ?>">
				<?php echo bayam_icone( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</a>
			<?php echo bayam_vitrine_lien_panier(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
	</div>
	<div id="bv-search" class="bv-search" hidden>
		<form role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php echo bayam_icone( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<label class="screen-reader-text" for="bv-search-input"><?php esc_html_e( 'Rechercher un produit', 'bayam-vitrine' ); ?></label>
			<input id="bv-search-input" type="search" name="s" placeholder="<?php esc_attr_e( 'Rechercher un produit, une boutique…', 'bayam-vitrine' ); ?>" autocomplete="off">
			<input type="hidden" name="post_type" value="product">
			<button type="submit"><?php esc_html_e( 'Rechercher', 'bayam-vitrine' ); ?></button>
		</form>
	</div>
	<div class="bv-scrim" hidden></div>
	<?php
}

/* Barre du bas (téléphone) : compte, recherche (ouvre la recherche de l'en-tête), panier. */
add_filter( 'storefront_handheld_footer_bar_links', function ( $liens ) {
	if ( isset( $liens['search'] ) ) {
		$liens['search']['callback'] = function () {
			echo '<a href="#bv-search" class="bv-open-search">' . esc_html__( 'Recherche', 'bayam-vitrine' ) . '</a>';
		};
	}
	return $liens;
} );

/* ---------- Pied de page ---------- */

add_action( 'init', function () {
	remove_action( 'storefront_footer', 'storefront_footer_widgets', 10 );
	remove_action( 'storefront_footer', 'storefront_credit', 20 );
	add_action( 'storefront_footer', 'bayam_vitrine_pied', 10 );
}, 20 );

/** Lien d'une page par son chemin : si la page n'existe pas, le lien est omis plutôt que cassé. */
function bayam_vitrine_lien_page( $chemin, $libelle ) {
	$page = get_page_by_path( $chemin );
	if ( ! $page || 'publish' !== $page->post_status ) {
		return '';
	}
	return '<li><a href="' . esc_url( get_permalink( $page ) ) . '">' . esc_html( $libelle ) . '</a></li>';
}

function bayam_vitrine_pied() {
	$boutiques = bayam_vitrine_lien_page( 'boutiques', 'Boutiques' );
	$adhesion  = bayam_vitrine_lien_page( 'vendor-membership', 'Devenir vendeur' );
	$espace    = bayam_vitrine_lien_page( 'store-manager', 'Espace vendeur' );
	?>
	<div class="bv-footer">
		<div class="bv-footer__col bv-footer__brand">
			<a class="bv-footer__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">bayam</a>
			<p><?php esc_html_e( 'BAYAM réunit les boutiques du Cameroun au même endroit. Vous commandez, la boutique vous répond sur WhatsApp et vous livre.', 'bayam-vitrine' ); ?></p>
		</div>
		<div class="bv-footer__col">
			<h2><?php esc_html_e( 'Acheter', 'bayam-vitrine' ); ?></h2>
			<ul>
				<?php echo $boutiques; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<li><a href="<?php echo esc_url( add_query_arg( 'orderby', 'date', wc_get_page_permalink( 'shop' ) ) ); ?>"><?php esc_html_e( 'Nouveautés', 'bayam-vitrine' ); ?></a></li>
				<li><a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>"><?php esc_html_e( 'Mon compte', 'bayam-vitrine' ); ?></a></li>
				<li><a href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php esc_html_e( 'Panier', 'bayam-vitrine' ); ?></a></li>
			</ul>
		</div>
		<div class="bv-footer__col">
			<h2><?php esc_html_e( 'Vendre', 'bayam-vitrine' ); ?></h2>
			<ul>
				<?php echo $adhesion; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php echo $espace; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<li><a href="<?php echo esc_url( home_url( '/store-manager/whatsapp/' ) ); ?>"><?php esc_html_e( 'Connecter WhatsApp', 'bayam-vitrine' ); ?></a></li>
			</ul>
		</div>
		<div class="bv-footer__col">
			<h2><?php esc_html_e( 'Aide', 'bayam-vitrine' ); ?></h2>
			<ul>
				<?php echo bayam_vitrine_lien_page( 'a-propos', 'À propos' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php echo bayam_vitrine_lien_page( 'privacy-policy', 'Conditions et confidentialité' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<li><a href="mailto:contact@bayam.cm">contact@bayam.cm</a></li>
			</ul>
		</div>
	</div>
	<p class="bv-footer__legal">© 2026 BAYAM, Douala, Cameroun</p>
	<?php
}

/* ---------- Fiche produit : bloc « Vendu par » ---------- */

add_action( 'woocommerce_single_product_summary', function () {
	if ( ! function_exists( 'wcfm_get_vendor_id_by_post' ) || ! function_exists( 'wcfmmp_get_store' ) ) {
		return;
	}
	$vendeur = wcfm_get_vendor_id_by_post( get_the_ID() );
	if ( ! $vendeur ) {
		return;
	}
	$boutique = wcfmmp_get_store( $vendeur );
	$nom      = $boutique->get_shop_name() ?: get_the_author_meta( 'display_name', $vendeur );
	$logo     = $boutique->get_info_part( 'gravatar' ) ? $boutique->get_avatar() : '';
	?>
	<a class="bv-vendu-par" href="<?php echo esc_url( $boutique->get_shop_url() ); ?>">
		<?php if ( $logo ) : ?>
			<img src="<?php echo esc_url( $logo ); ?>" alt="" width="44" height="44" loading="lazy">
		<?php else : ?>
			<span class="bv-vendu-par__initiale"><?php echo esc_html( mb_strtoupper( mb_substr( $nom, 0, 1 ) ) ); ?></span>
		<?php endif; ?>
		<span class="bv-vendu-par__texte">
			<small><?php esc_html_e( 'Vendu par', 'bayam-vitrine' ); ?></small>
			<strong><?php echo esc_html( $nom ); ?></strong>
		</span>
		<span class="bv-vendu-par__lien"><?php esc_html_e( 'Voir la boutique', 'bayam-vitrine' ); ?></span>
	</a>
	<?php
}, 35 );

/* Libellés de la barre du bas : Storefront n'a pas de traduction française complète. */
add_filter( 'gettext', function ( $texte, $original, $domaine ) {
	if ( 'storefront' !== $domaine ) {
		return $texte;
	}
	$libelles = array( 'My Account' => 'Compte', 'Cart' => 'Panier', 'Search' => 'Recherche' );
	return isset( $libelles[ $original ] ) ? $libelles[ $original ] : $texte;
}, 10, 3 );
