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

/* ---------- Barre d'onglets du téléphone, comme dans l'application ---------- */

add_action( 'init', function () {
	remove_action( 'storefront_footer', 'storefront_handheld_footer_bar', 999 );
}, 20 );

add_action( 'wp_footer', function () {
	$nombre  = function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
	$onglets = array(
		array( 'home', 'Accueil', home_url( '/' ), is_front_page() ),
		array( 'store', 'Boutiques', bayam_vitrine_url_page( 'boutiques' ), is_page( 'boutiques' ) || ( function_exists( 'wcfm_is_store_page' ) && wcfm_is_store_page() ) ),
		array( 'grid', 'Catégories', wc_get_page_permalink( 'shop' ), is_shop() || is_product_taxonomy() || is_product() ),
		array( 'bag', 'Panier', wc_get_cart_url(), is_cart() || is_checkout() ),
		array( 'user', 'Profil', wc_get_page_permalink( 'myaccount' ), is_account_page() ),
	);
	echo '<nav class="bv-onglets" aria-label="' . esc_attr__( 'Navigation principale', 'bayam-vitrine' ) . '"><ul>';
	foreach ( $onglets as list( $icone, $libelle, $url, $actif ) ) {
		$badge = 'bag' === $icone ? '<span class="bv-onglets__nb' . ( $nombre ? '' : ' is-empty' ) . '">' . esc_html( $nombre ) . '</span>' : '';
		printf(
			'<li><a href="%s"%s>%s%s<span>%s</span></a></li>',
			esc_url( $url ),
			$actif ? ' class="is-actif" aria-current="page"' : '',
			bayam_icone( $icone ), // phpcs:ignore WordPress.Security.EscapeOutput
			$badge, // phpcs:ignore WordPress.Security.EscapeOutput
			esc_html( $libelle )
		);
	}
	echo '</ul></nav>';
} );

/* Le compteur de la barre d'onglets suit le panier, comme celui de l'en-tête. */
add_filter( 'woocommerce_add_to_cart_fragments', function ( $fragments ) {
	$nombre = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
	$fragments['span.bv-onglets__nb'] = '<span class="bv-onglets__nb' . ( $nombre ? '' : ' is-empty' ) . '">' . esc_html( $nombre ) . '</span>';
	return $fragments;
} );

/* ---------- Cartes produit : remise en pour cent et disponibilité, comme dans l'application ---------- */

add_filter( 'woocommerce_sale_flash', function ( $html, $post, $produit ) {
	$remise = bayam_vitrine_remise( $produit );
	return $remise ? '<span class="onsale">-' . esc_html( $remise ) . '%</span>' : $html;
}, 10, 3 );

add_filter( 'woocommerce_format_price_range', function ( $prix, $de ) {
	return '<span class="bv-des-prix"><span class="bv-des">' . esc_html__( 'dès', 'bayam-vitrine' ) . '</span> ' . wc_price( $de ) . '</span>';
}, 10, 2 );

add_action( 'woocommerce_after_shop_loop_item_title', function () {
	global $product;
	if ( $product && $product->is_in_stock() ) {
		echo '<span class="bv-stock">' . esc_html__( 'En stock', 'bayam-vitrine' ) . '</span>';
	} elseif ( $product ) {
		echo '<span class="bv-stock is-out">' . esc_html__( 'Épuisé', 'bayam-vitrine' ) . '</span>';
	}
}, 11 );

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

/* ---------- Fiche produit : WhatsApp, réassurance, onglets ---------- */

/** Numéro WhatsApp d'une boutique : celui connecté à BAYAM, sinon le téléphone de la boutique (237…). */
function bayam_vitrine_whatsapp_boutique( $vendeur ) {
	$numero = preg_replace( '/\D+/', '', (string) get_user_meta( $vendeur, 'bayam_wa_moi', true ) );
	if ( ! $numero ) {
		$profil = (array) get_user_meta( $vendeur, 'wcfmmp_profile_settings', true );
		$numero = preg_replace( '/\D+/', '', (string) ( $profil['phone'] ?? '' ) );
	}
	if ( 9 === strlen( $numero ) ) {
		$numero = '237' . $numero;
	}
	return strlen( $numero ) >= 11 ? $numero : '';
}

/** Modes de livraison réellement réglés (zones WooCommerce), pour la réassurance. */
function bayam_vitrine_livraison() {
	$modes = array();
	foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
		foreach ( $zone['shipping_methods'] as $methode ) {
			if ( $methode->is_enabled() ) {
				$cout    = (float) $methode->get_option( 'cost', 0 );
				$modes[] = $methode->get_title() . ' : ' . ( $cout > 0 ? wp_strip_all_tags( wc_price( $cout ) ) : 'gratuit' );
			}
		}
	}
	return $modes;
}

/* Commander sur WhatsApp : l'acheteur écrit à la boutique comme il en a l'habitude, avec l'article déjà cité. */
add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( ! $product || ! function_exists( 'wcfm_get_vendor_id_by_post' ) ) {
		return;
	}
	$vendeur = wcfm_get_vendor_id_by_post( $product->get_id() );
	$numero  = $vendeur ? bayam_vitrine_whatsapp_boutique( $vendeur ) : '';
	if ( ! $numero ) {
		return;
	}
	$boutique = function_exists( 'wcfmmp_get_store' ) ? wcfmmp_get_store( $vendeur )->get_shop_name() : '';
	$prix     = html_entity_decode( wp_strip_all_tags( wc_price( (float) $product->get_price() ) ), ENT_QUOTES, 'UTF-8' );
	$lien     = get_permalink( $product->get_id() );
	// Le vendeur ouvre ce lien sur son téléphone : jamais l'adresse locale du poste, toujours l'adresse publique.
	$publique = function_exists( 'bayam_url_publique' ) ? bayam_url_publique() : null;
	if ( $publique ) {
		$lien = $publique . substr( $lien, strlen( untrailingslashit( home_url() ) ) );
	}
	$message = sprintf( 'Bonjour %s, je suis intéressé(e) par « %s » à %s : %s', $boutique, $product->get_name(), $prix, $lien );
	printf(
		'<a class="bv-whatsapp" href="%s" target="_blank" rel="noopener">%s<span>%s</span></a>',
		esc_url( 'https://wa.me/' . $numero . '?text=' . rawurlencode( $message ) ),
		bayam_icone( 'chat' ), // phpcs:ignore WordPress.Security.EscapeOutput
		esc_html__( 'Commander sur WhatsApp', 'bayam-vitrine' )
	);
}, 31 );

/* Réassurance : livraison et paiements réellement proposés, vendeur joignable. */
add_action( 'woocommerce_single_product_summary', function () {
	$paiements = array();
	foreach ( WC()->payment_gateways() ? WC()->payment_gateways()->get_available_payment_gateways() : array() as $passerelle ) {
		$paiements[] = $passerelle->get_title();
	}
	$lignes = array(
		array( 'box', 'Livraison', implode( ' · ', bayam_vitrine_livraison() ) ),
		array( 'card', 'Paiement', implode( ' · ', $paiements ) ),
		array( 'chat', 'Une question ?', __( 'Le vendeur vous répond sur WhatsApp.', 'bayam-vitrine' ) ),
	);
	echo '<ul class="bv-rassure">';
	foreach ( $lignes as list( $icone, $titre, $texte ) ) {
		if ( '' !== $texte ) {
			printf( '<li>%s<span><strong>%s</strong>%s</span></li>', bayam_icone( $icone ), esc_html( $titre ), esc_html( $texte ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
	echo '</ul>';
}, 32 );

/* Onglets : titres en français ; la référence interne (UGS) n'a pas de sens pour l'acheteur. */
add_filter( 'woocommerce_product_tabs', function ( $onglets ) {
	if ( isset( $onglets['wcfm_policies_tab'] ) ) {
		$onglets['wcfm_policies_tab']['title'] = __( 'Conditions de la boutique', 'bayam-vitrine' );
	}
	if ( isset( $onglets['wcfm_enquiry_tab'] ) ) {
		$onglets['wcfm_enquiry_tab']['title'] = __( 'Questions', 'bayam-vitrine' );
	}
	return $onglets;
}, 99 );
add_action( 'template_redirect', function () {
	if ( is_product() ) {
		add_filter( 'wc_product_sku_enabled', '__return_false' );
	}
} );

/* Barre d'achat en verre sur téléphone (comme dans l'application) : prix, panier, WhatsApp, toujours à portée. */
add_action( 'woocommerce_after_single_product', function () {
	global $product;
	if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
		return;
	}
	printf(
		'<div class="bv-achat" data-achat><span class="bv-achat__prix">%s</span><button type="button" class="button alt bv-achat__panier" data-achat-panier>%s</button></div>',
		wp_kses_post( $product->get_price_html() ),
		esc_html( $product->is_type( 'simple' ) ? __( 'Ajouter au panier', 'bayam-vitrine' ) : __( 'Choisir', 'bayam-vitrine' ) )
	);
} );

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
