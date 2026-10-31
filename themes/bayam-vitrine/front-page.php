<?php
/**
 * Page d'accueil de BAYAM, sur le modèle de l'application mobile : pastilles de catégories, bannières défilantes,
 * petits prix du jour, rayons, commande par message, boutiques, nouveautés, espace vendeurs.
 * Tout vient de la base (produits, prix, remises, boutiques) : aucun chiffre écrit en dur.
 */

defined( 'ABSPATH' ) || exit;

get_header();

$url_boutique   = wc_get_page_permalink( 'shop' );
$url_boutiques  = bayam_vitrine_url_page( 'boutiques' );
$url_adhesion   = bayam_vitrine_url_page( 'vendor-membership' );
$url_nouveautes = add_query_arg( 'orderby', 'date', $url_boutique );
$boutiques      = bayam_vitrine_boutiques( 6 );
$categories     = bayam_vitrine_categories();
$promos         = wc_get_product_ids_on_sale();
$remise_max     = bayam_vitrine_remise_max( $promos );
$image          = static fn ( $fichier ) => esc_url( get_stylesheet_directory_uri() . '/assets/img/app/' . $fichier );
?>
<main id="main" class="bv-home site-main">

	<div class="bv-wrap bv-haut">
		<?php if ( $categories ) : ?>
		<nav class="bv-pastilles" aria-label="<?php esc_attr_e( 'Catégories', 'bayam-vitrine' ); ?>">
			<?php foreach ( $categories as $rang => $categorie ) : ?>
				<a class="bv-pastille bv-reveal" style="--i:<?php echo (int) $rang; ?>" href="<?php echo esc_url( get_term_link( $categorie ) ); ?>">
					<span class="bv-pastille__ico"><img src="<?php echo bayam_vitrine_picto_categorie( $categorie ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" alt="" width="64" height="64"></span>
					<span class="bv-pastille__nom"><?php echo esc_html( $categorie->name ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php endif; ?>

		<section class="bv-bannieres" aria-label="<?php esc_attr_e( 'À la une', 'bayam-vitrine' ); ?>">
			<div class="bv-defile" data-defile>
				<?php foreach ( bayam_vitrine_bannieres() as $banniere ) : ?>
					<a class="bv-banniere" href="<?php echo esc_url( $banniere['url'] ); ?>">
						<img src="<?php echo $image( $banniere['image'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" alt="<?php echo esc_attr( $banniere['texte'] ); ?>" width="1080" height="520">
					</a>
				<?php endforeach; ?>
			</div>
			<div class="bv-points" data-points></div>
		</section>
	</div>

	<?php if ( $promos ) : ?>
	<section class="bv-promo">
		<div class="bv-wrap">
			<?php bayam_vitrine_titre_section( 'Promotions du jour', '', $url_boutique, 'Tout voir' ); ?>
			<div class="bv-promo__corps">
				<div class="bv-promo__accroche bv-reveal">
					<p class="bv-promo__titre"><?php esc_html_e( 'Petits', 'bayam-vitrine' ); ?> <br><?php esc_html_e( 'prix', 'bayam-vitrine' ); ?><span><?php esc_html_e( 'du jour', 'bayam-vitrine' ); ?></span></p>
					<?php if ( $remise_max ) : ?><p class="bv-puce"><?php echo esc_html( sprintf( 'Jusqu’à -%d %%', $remise_max ) ); ?></p><?php endif; ?>
					<img src="<?php echo $image( 'sacs.webp' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" alt="" width="138" height="143" loading="lazy">
				</div>
				<div class="bv-rangee"><?php bayam_vitrine_grille_produits( array( 'post__in' => $promos, 'posts_per_page' => 10 ) ); ?></div>
			</div>
		</div>
	</section>
	<?php endif; ?>

	<?php foreach ( bayam_vitrine_rayons( $categories, 3 ) as $rayon ) : ?>
	<section class="bv-section bv-rayon">
		<?php bayam_vitrine_titre_section( $rayon->name, '', get_term_link( $rayon ), 'Tout voir' ); ?>
		<div class="bv-rangee"><?php bayam_vitrine_grille_produits( array( 'posts_per_page' => 10, 'tax_query' => array( array( 'taxonomy' => 'product_cat', 'terms' => $rayon->term_id ) ) ) ); ?></div>
	</section>
	<?php endforeach; ?>

	<div class="bv-wrap">
		<a class="bv-bandeau bv-reveal" href="<?php echo esc_url( $url_boutiques ? $url_boutiques : $url_boutique ); ?>">
			<img src="<?php echo $image( 'bandeau_message.webp' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" alt="<?php esc_attr_e( 'Commandez par message : une question ? Le vendeur vous répond sur WhatsApp.', 'bayam-vitrine' ); ?>" width="1080" height="300" loading="lazy">
		</a>
	</div>

	<?php if ( $boutiques ) : ?>
	<section class="bv-section">
		<?php bayam_vitrine_titre_section( 'Boutiques à la une', 'Des vendeurs de Douala, joignables directement.', $url_boutiques, 'Toutes les boutiques' ); ?>
		<div class="bv-shops">
			<?php foreach ( $boutiques as $rang => $boutique ) {
				bayam_vitrine_carte_boutique( $boutique, $rang );
			} ?>
		</div>
	</section>
	<?php endif; ?>

	<section class="bv-section">
		<?php bayam_vitrine_titre_section( 'Nouveautés', '', $url_nouveautes, 'Tout voir' ); ?>
		<?php bayam_vitrine_grille_produits( array( 'posts_per_page' => 8, 'orderby' => 'date', 'order' => 'DESC' ) ); ?>
	</section>

	<section class="bv-section">
		<div class="bv-vendre bv-reveal">
			<img class="bv-vendre__visuel" src="<?php echo $image( 'vendeur.webp' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" alt="" width="900" height="900" loading="lazy">
			<div class="bv-vendre__texte">
				<p class="bv-eyebrow"><?php esc_html_e( 'Pour les vendeurs', 'bayam-vitrine' ); ?></p>
				<h2><?php esc_html_e( 'Votre boutique en ligne, pilotée depuis WhatsApp', 'bayam-vitrine' ); ?></h2>
				<p><?php esc_html_e( 'Écrivez « bayam » suivi de votre demande dans votre discussion « Message à moi-même » : stock, ventes, liens de paiement et publications dans vos groupes, sans quitter WhatsApp.', 'bayam-vitrine' ); ?></p>
				<ul class="bv-vendre__liste">
					<li><?php echo bayam_icone( 'box' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Stock et ventes à jour', 'bayam-vitrine' ); ?></li>
					<li><?php echo bayam_icone( 'link' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Liens de paiement en un message', 'bayam-vitrine' ); ?></li>
					<li><?php echo bayam_icone( 'send' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Publications dans vos groupes', 'bayam-vitrine' ); ?></li>
					<li><?php echo bayam_icone( 'chart' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Bilan chaque soir', 'bayam-vitrine' ); ?></li>
				</ul>
				<?php if ( $url_adhesion ) : ?><a class="bv-btn" href="<?php echo esc_url( $url_adhesion ); ?>"><?php esc_html_e( 'Ouvrir ma boutique', 'bayam-vitrine' ); ?></a><?php endif; ?>
			</div>
		</div>
	</section>

</main>
<?php
get_footer();
