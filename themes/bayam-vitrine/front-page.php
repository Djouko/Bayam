<?php
/**
 * Page d'accueil de BAYAM : sections dynamiques (boutiques, catégories, produits réels).
 */

defined( 'ABSPATH' ) || exit;

get_header();

$url_boutique   = wc_get_page_permalink( 'shop' );
$url_boutiques  = bayam_vitrine_url_page( 'boutiques' );
$url_adhesion   = bayam_vitrine_url_page( 'vendor-membership' );
$url_whatsapp   = home_url( '/store-manager/whatsapp/' );
$url_nouveautes = add_query_arg( 'orderby', 'date', $url_boutique );
$boutiques      = bayam_vitrine_boutiques( 6 );
$categories     = bayam_vitrine_categories();
$produits_total = (int) ( wp_count_posts( 'product' )->publish );
$vedettes       = wc_get_products( array( 'limit' => 3, 'status' => 'publish', 'orderby' => 'date', 'order' => 'DESC' ) );
$promos         = wc_get_product_ids_on_sale();
$paiements      = bayam_vitrine_moyens_paiement();
?>
<main id="main" class="bv-home site-main">

	<section class="bv-hero">
		<div class="bv-hero__inner">
			<div class="bv-hero__text">
				<p class="bv-eyebrow"><?php esc_html_e( 'Place de marché du Cameroun', 'bayam-vitrine' ); ?></p>
				<h1><?php esc_html_e( 'Les boutiques du Cameroun, à portée de message', 'bayam-vitrine' ); ?></h1>
				<p class="bv-hero__lead"><?php esc_html_e( 'Trouvez des produits de vraies boutiques de chez vous, commandez en quelques clics et échangez directement avec le vendeur.', 'bayam-vitrine' ); ?></p>
				<form class="bv-hero__search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
					<?php echo bayam_icone( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<label class="screen-reader-text" for="bv-hero-search"><?php esc_html_e( 'Rechercher un produit', 'bayam-vitrine' ); ?></label>
					<input id="bv-hero-search" type="search" name="s" placeholder="<?php esc_attr_e( 'Rechercher un produit', 'bayam-vitrine' ); ?>" autocomplete="off">
					<input type="hidden" name="post_type" value="product">
					<button type="submit"><?php esc_html_e( 'Rechercher', 'bayam-vitrine' ); ?></button>
				</form>
				<div class="bv-hero__cta">
					<?php if ( $url_boutiques ) : ?><a class="bv-btn bv-btn--soleil" href="<?php echo esc_url( $url_boutiques ); ?>"><?php esc_html_e( 'Découvrir les boutiques', 'bayam-vitrine' ); ?></a><?php endif; ?>
					<?php if ( $url_adhesion ) : ?><a class="bv-btn bv-btn--verre" href="<?php echo esc_url( $url_adhesion ); ?>"><?php esc_html_e( 'Ouvrir ma boutique', 'bayam-vitrine' ); ?></a><?php endif; ?>
				</div>
				<ul class="bv-hero__chiffres">
					<li><strong><?php echo esc_html( count( $boutiques ) ); ?></strong> <?php echo esc_html( _n( 'boutique', 'boutiques', count( $boutiques ), 'bayam-vitrine' ) ); ?></li>
					<li><strong><?php echo esc_html( $produits_total ); ?></strong> <?php echo esc_html( _n( 'produit', 'produits', $produits_total, 'bayam-vitrine' ) ); ?></li>
					<li><strong>Douala</strong></li>
				</ul>
			</div>
			<?php if ( $vedettes ) : ?>
			<div class="bv-hero__visuel" aria-hidden="true">
				<?php foreach ( $vedettes as $rang => $produit ) : ?>
					<a class="bv-hero__carte bv-hero__carte--<?php echo esc_attr( $rang + 1 ); ?>" href="<?php echo esc_url( $produit->get_permalink() ); ?>" tabindex="-1">
						<?php echo wp_kses_post( $produit->get_image( 'woocommerce_thumbnail' ) ); ?>
						<span><?php echo esc_html( $produit->get_name() ); ?></span>
						<strong><?php echo wp_kses_post( $produit->get_price_html() ); ?></strong>
					</a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
	</section>

	<?php if ( $categories ) : ?>
	<section class="bv-section">
		<?php bayam_vitrine_titre_section( 'Parcourir par catégorie', 'Trouvez plus vite ce que vous cherchez.', $url_boutique, 'Tous les produits' ); ?>
		<div class="bv-cats">
			<?php foreach ( $categories as $categorie ) :
				$image = get_term_meta( $categorie->term_id, 'thumbnail_id', true );
				$src   = $image ? wp_get_attachment_image_url( $image, 'medium' ) : '';
				?>
				<a class="bv-cat bv-reveal" href="<?php echo esc_url( get_term_link( $categorie ) ); ?>">
					<?php if ( $src ) : ?><img src="<?php echo esc_url( $src ); ?>" alt="" loading="lazy" width="300" height="300"><?php endif; ?>
					<span class="bv-cat__nom"><?php echo esc_html( $categorie->name ); ?></span>
					<span class="bv-cat__nb"><?php echo esc_html( sprintf( _n( '%d produit', '%d produits', $categorie->count, 'bayam-vitrine' ), $categorie->count ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $boutiques ) : ?>
	<section class="bv-section bv-section--teinte">
		<div class="bv-section__in">
			<?php bayam_vitrine_titre_section( 'Boutiques à la une', 'Des vendeurs de Douala, joignables directement.', $url_boutiques, 'Toutes les boutiques' ); ?>
			<div class="bv-shops">
				<?php foreach ( $boutiques as $boutique ) : ?>
					<a class="bv-shop bv-reveal" href="<?php echo esc_url( $boutique['url'] ); ?>">
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
						<span class="bv-shop__voir"><?php esc_html_e( 'Visiter la boutique', 'bayam-vitrine' ); ?> <?php echo bayam_icone( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
	<?php endif; ?>

	<section class="bv-section">
		<?php bayam_vitrine_titre_section( 'Nouveautés', 'Les derniers produits ajoutés par les boutiques.', $url_nouveautes, 'Voir les nouveautés' ); ?>
		<?php bayam_vitrine_grille_produits( array( 'posts_per_page' => 8, 'orderby' => 'date', 'order' => 'DESC' ) ); ?>
	</section>

	<?php if ( $promos ) : ?>
	<section class="bv-section bv-section--promo">
		<div class="bv-section__in">
			<?php bayam_vitrine_titre_section( 'Promotions', 'Les bonnes affaires du moment.', $url_boutique, 'Voir la boutique' ); ?>
			<?php bayam_vitrine_grille_produits( array( 'post__in' => $promos, 'posts_per_page' => 4 ) ); ?>
		</div>
	</section>
	<?php endif; ?>

	<section class="bv-section">
		<?php bayam_vitrine_titre_section( 'Comment ça marche', 'Acheter sur BAYAM prend trois étapes.' ); ?>
		<ol class="bv-steps">
			<li class="bv-step bv-reveal">
				<span class="bv-step__ico"><?php echo bayam_icone( 'store' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<h3><?php esc_html_e( 'Choisissez une boutique', 'bayam-vitrine' ); ?></h3>
				<p><?php esc_html_e( 'Parcourez les catégories ou visitez directement la boutique d’un vendeur.', 'bayam-vitrine' ); ?></p>
			</li>
			<li class="bv-step bv-reveal">
				<span class="bv-step__ico"><?php echo bayam_icone( 'bag' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<h3><?php esc_html_e( 'Ajoutez au panier', 'bayam-vitrine' ); ?></h3>
				<p><?php esc_html_e( 'Sélectionnez vos produits, vérifiez votre panier, puis validez la commande.', 'bayam-vitrine' ); ?></p>
			</li>
			<li class="bv-step bv-reveal">
				<span class="bv-step__ico"><?php echo bayam_icone( 'card' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<h3><?php esc_html_e( 'Payez à la réception', 'bayam-vitrine' ); ?></h3>
				<p>
					<?php
					echo $paiements
						? esc_html( sprintf( 'Moyen de paiement proposé : %s.', implode( ', ', $paiements ) ) )
						: esc_html__( 'La boutique vous précise le mode de paiement à la validation.', 'bayam-vitrine' );
					?>
				</p>
			</li>
		</ol>
	</section>

	<section class="bv-section bv-section--vendeurs">
		<div class="bv-vendeurs bv-reveal">
			<div class="bv-vendeurs__texte">
				<p class="bv-eyebrow"><?php esc_html_e( 'Pour les vendeurs', 'bayam-vitrine' ); ?></p>
				<h2><?php esc_html_e( 'Gérez votre boutique depuis WhatsApp', 'bayam-vitrine' ); ?></h2>
				<p><?php esc_html_e( 'Le Copilote WhatsApp de BAYAM travaille avec vous : écrivez-lui dans « Message à moi-même » et il s’occupe du reste.', 'bayam-vitrine' ); ?></p>
				<div class="bv-hero__cta">
					<?php if ( $url_adhesion ) : ?><a class="bv-btn bv-btn--soleil" href="<?php echo esc_url( $url_adhesion ); ?>"><?php esc_html_e( 'Devenir vendeur', 'bayam-vitrine' ); ?></a><?php endif; ?>
					<a class="bv-btn bv-btn--verre" href="<?php echo esc_url( $url_whatsapp ); ?>"><?php esc_html_e( 'Connecter WhatsApp', 'bayam-vitrine' ); ?></a>
				</div>
			</div>
			<ul class="bv-vendeurs__liste">
				<li><span><?php echo bayam_icone( 'box' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><div><strong><?php esc_html_e( 'Votre stock', 'bayam-vitrine' ); ?></strong><?php esc_html_e( 'Consultez et mettez à jour les quantités.', 'bayam-vitrine' ); ?></div></li>
				<li><span><?php echo bayam_icone( 'chart' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><div><strong><?php esc_html_e( 'Vos ventes', 'bayam-vitrine' ); ?></strong><?php esc_html_e( 'Suivez les commandes sans quitter la conversation.', 'bayam-vitrine' ); ?></div></li>
				<li><span><?php echo bayam_icone( 'link' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><div><strong><?php esc_html_e( 'Liens de paiement', 'bayam-vitrine' ); ?></strong><?php esc_html_e( 'Envoyez un lien de paiement à votre client.', 'bayam-vitrine' ); ?></div></li>
				<li><span><?php echo bayam_icone( 'send' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><div><strong><?php esc_html_e( 'Publications dans les groupes', 'bayam-vitrine' ); ?></strong><?php esc_html_e( 'Partagez vos produits dans vos groupes WhatsApp.', 'bayam-vitrine' ); ?></div></li>
			</ul>
		</div>
	</section>

</main>
<?php
get_footer();
