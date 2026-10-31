<?php
/** Données et sections de la page d'accueil : tout vient de la base, rien n'est écrit en dur. */

defined( 'ABSPATH' ) || exit;

/** Boutiques actives (rôle wcfm_vendor) ayant au moins un produit publié, avec les informations d'affichage. */
function bayam_vitrine_boutiques( $limite = 6 ) {
	$resultat = array();
	foreach ( get_users( array( 'role' => 'wcfm_vendor', 'orderby' => 'ID' ) ) as $utilisateur ) {
		$nombre = (int) count_user_posts( $utilisateur->ID, 'product', true );
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

/** Carte d'une boutique (accueil et annuaire « Nos boutiques »). */
function bayam_vitrine_carte_boutique( $boutique, $rang = 0 ) {
	?>
	<a class="bv-shop bv-reveal" style="--i:<?php echo (int) $rang; ?>" href="<?php echo esc_url( $boutique['url'] ); ?>">
		<span class="bv-shop__banniere"<?php echo $boutique['banniere'] ? ' style="background-image:url(' . esc_url( $boutique['banniere'] ) . ')"' : ''; ?>></span>
		<span class="bv-shop__logo">
			<?php if ( $boutique['logo'] ) : ?>
				<img src="<?php echo esc_url( $boutique['logo'] ); ?>" alt="" width="64" height="64" loading="lazy">
			<?php else : ?>
				<?php echo esc_html( mb_strtoupper( mb_substr( $boutique['nom'], 0, 1 ) ) ); ?>
			<?php endif; ?>
		</span>
		<span class="bv-shop__corps">
			<strong><?php echo esc_html( $boutique['nom'] ); ?></strong>
			<?php if ( $boutique['lieu'] ) : ?><span class="bv-shop__lieu"><?php echo bayam_icone( 'pin' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html( $boutique['lieu'] ); ?></span><?php endif; ?>
			<span class="bv-shop__nb"><?php echo esc_html( sprintf( _n( '%d produit', '%d produits', $boutique['produits'], 'bayam-vitrine' ), $boutique['produits'] ) ); ?></span>
		</span>
	</a>
	<?php
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

/** Remise d'un produit en pour cent (la plus forte de ses déclinaisons), 0 sans promotion. */
function bayam_vitrine_remise( $produit ) {
	$prix = $produit->is_type( array( 'variable', 'grouped' ) ) ? array_filter( array_map( 'wc_get_product', $produit->get_children() ) ) : array( $produit );
	$remise = 0;
	foreach ( $prix as $p ) {
		$normal = (float) $p->get_regular_price();
		$solde  = (float) $p->get_sale_price();
		if ( $normal > 0 && $solde > 0 && $solde < $normal ) {
			$remise = max( $remise, (int) floor( ( $normal - $solde ) / $normal * 100 ) );
		}
	}
	return $remise;
}

/** Plus forte remise parmi les produits en promotion (pastille « Jusqu'à -X % » calculée, jamais écrite en dur). */
function bayam_vitrine_remise_max( $ids ) {
	$max = 0;
	foreach ( array_filter( array_map( 'wc_get_product', array_unique( array_map( 'bayam_vitrine_parent_ou_soi', $ids ) ) ) ) as $produit ) {
		$max = max( $max, bayam_vitrine_remise( $produit ) );
	}
	return $max;
}

/** Produit parent d'une déclinaison, ou le produit lui-même. */
function bayam_vitrine_parent_ou_soi( $id ) {
	$parent = wp_get_post_parent_id( $id );
	return $parent ? $parent : $id;
}

/** Pictogramme de l'application pour une catégorie, sinon l'image de la catégorie. */
function bayam_vitrine_picto_categorie( $categorie ) {
	$pictos = array( 'tshirts' => 'tshirts', 'hoodies' => 'sweats', 'accessories' => 'accessoires', 'music' => 'musique', 'clothing' => 'vetements', 'decor' => 'decoration' );
	if ( isset( $pictos[ $categorie->slug ] ) ) {
		return esc_url( get_stylesheet_directory_uri() . '/assets/img/app/categorie_' . $pictos[ $categorie->slug ] . '.webp' );
	}
	$image = get_term_meta( $categorie->term_id, 'thumbnail_id', true );
	return esc_url( $image ? wp_get_attachment_image_url( $image, 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' ) );
}

/** Bannières de l'application, chacune seulement si ce qu'elle annonce est vrai sur la plate-forme. */
function bayam_vitrine_bannieres() {
	$bannieres = array();
	$aicha     = get_user_by( 'login', 'aicha' );
	if ( $aicha && function_exists( 'wcfmmp_get_store_url' ) ) {
		$bannieres[] = array( 'image' => 'banniere_collection.webp', 'url' => wcfmmp_get_store_url( $aicha->ID ), 'texte' => 'Nouveautés : la collection de Mode Aïcha, t-shirts et sweats livrés à Douala. Voir la boutique.' );
	}
	$bannieres[] = array( 'image' => 'banniere_livraison.webp', 'url' => wc_get_page_permalink( 'shop' ), 'texte' => 'Livré chez vous à Douala : 1 000 FCFA en ville, retrait gratuit en boutique. Commander maintenant.' );
	$passerelles = WC()->payment_gateways() ? WC()->payment_gateways()->get_available_payment_gateways() : array();
	if ( isset( $passerelles['bayam_cinetpay'] ) ) {
		$bannieres[] = array( 'image' => 'banniere_paiement.webp', 'url' => wc_get_page_permalink( 'shop' ), 'texte' => 'Payez comme vous voulez : MTN MoMo, Orange Money ou à la livraison.' );
	}
	return $bannieres;
}

/** Rayons de l'accueil : les catégories les plus fournies (trois produits au moins). */
function bayam_vitrine_rayons( $categories, $nombre ) {
	$rayons = array_filter( $categories, static fn ( $c ) => $c->count >= 3 );
	usort( $rayons, static fn ( $a, $b ) => $b->count <=> $a->count );
	return array_slice( $rayons, 0, $nombre );
}

/** Lien d'une page par son chemin, ou chaîne vide. */
function bayam_vitrine_url_page( $chemin ) {
	$page = get_page_by_path( $chemin );
	return $page ? get_permalink( $page ) : '';
}
