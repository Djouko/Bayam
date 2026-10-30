<?php
/** Données et sections de la page d'accueil : tout vient de la base, rien n'est écrit en dur. */

defined( 'ABSPATH' ) || exit;

/** Boutiques actives (rôle wcfm_vendor) ayant au moins un produit publié, avec les informations d'affichage. */
function bayam_vitrine_boutiques( $limite = 6 ) {
	$resultat = array();
	foreach ( get_users( array( 'role' => 'wcfm_vendor', 'orderby' => 'ID' ) ) as $utilisateur ) {
		$nombre = count( wc_get_products( array( 'author' => $utilisateur->ID, 'status' => 'publish', 'limit' => -1, 'return' => 'ids' ) ) );
		if ( ! $nombre ) {
			continue;
		}
		$boutique = wcfmmp_get_store( $utilisateur->ID );
		$adresse  = (array) $boutique->get_info_part( 'address' );
		$ville    = isset( $adresse['city'] ) ? $adresse['city'] : '';
		$quartier = isset( $adresse['street_1'] ) ? $adresse['street_1'] : '';
		$resultat[] = array(
			'nom'      => $boutique->get_shop_name() ?: $utilisateur->display_name,
			'url'      => $boutique->get_shop_url(),
			'logo'     => $boutique->get_info_part( 'gravatar' ) ? $boutique->get_avatar() : '',
			'banniere' => $boutique->get_list_banner(),
			'lieu'     => trim( $quartier . ( $quartier && $ville ? ', ' : '' ) . $ville ),
			'produits' => $nombre,
		);
		if ( count( $resultat ) >= $limite ) {
			break;
		}
	}
	return $resultat;
}

/** Liste de produits au format WooCommerce (mêmes cartes que la boutique). */
function bayam_vitrine_grille_produits( $args, $colonnes = 4 ) {
	$requete = new WP_Query( array_merge( array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => 8,
		'no_found_rows'  => true,
	), $args ) );
	if ( ! $requete->have_posts() ) {
		return false;
	}
	wc_set_loop_prop( 'columns', $colonnes );
	woocommerce_product_loop_start();
	while ( $requete->have_posts() ) {
		$requete->the_post();
		wc_get_template_part( 'content', 'product' );
	}
	woocommerce_product_loop_end();
	wp_reset_postdata();
	wc_reset_loop();
	return true;
}

/** Titre de section avec lien « Voir tout » optionnel. */
function bayam_vitrine_titre_section( $titre, $sous_titre = '', $url = '', $libelle = '' ) {
	?>
	<header class="bv-section__head bv-reveal">
		<div>
			<h2><?php echo esc_html( $titre ); ?></h2>
			<?php if ( $sous_titre ) : ?><p><?php echo esc_html( $sous_titre ); ?></p><?php endif; ?>
		</div>
		<?php if ( $url ) : ?>
			<a class="bv-more" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $libelle ); ?> <?php echo bayam_icone( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
		<?php endif; ?>
	</header>
	<?php
}

/** Moyens de paiement réellement activés dans WooCommerce, en minuscules, pour la phrase de l'étape 3. */
function bayam_vitrine_moyens_paiement() {
	$titres = array();
	foreach ( WC()->payment_gateways()->payment_gateways() as $passerelle ) {
		if ( 'yes' === $passerelle->enabled ) {
			$titres[] = mb_strtolower( $passerelle->get_title() );
		}
	}
	return $titres;
}

/** Lien d'une page par son chemin, ou chaîne vide. */
function bayam_vitrine_url_page( $chemin ) {
	$page = get_page_by_path( $chemin );
	return $page ? get_permalink( $page ) : '';
}
