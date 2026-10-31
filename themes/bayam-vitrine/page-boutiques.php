<?php
/**
 * « Nos boutiques » : annuaire des vendeurs avec les cartes de l'accueil et une recherche instantanée,
 * à la place de la liste WCFM (carte géographique lourde à charger, filtres sans contenu).
 */

defined( 'ABSPATH' ) || exit;

get_header();
$boutiques = bayam_vitrine_boutiques( 500 );
?>
<main id="main" class="site-main bv-annuaire">
	<header class="bv-annuaire__tete">
		<h1 class="entry-title"><?php esc_html_e( 'Nos boutiques', 'bayam-vitrine' ); ?></h1>
		<p><?php esc_html_e( 'Les vendeurs de BAYAM à Douala. Commandez sur le site, ou écrivez-leur directement sur WhatsApp.', 'bayam-vitrine' ); ?></p>
		<label class="bv-recherche-boutique">
			<?php echo bayam_icone( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<span class="screen-reader-text"><?php esc_html_e( 'Rechercher une boutique', 'bayam-vitrine' ); ?></span>
			<input type="search" data-filtre-boutiques placeholder="<?php esc_attr_e( 'Rechercher une boutique ou un quartier', 'bayam-vitrine' ); ?>" autocomplete="off">
		</label>
	</header>
	<?php if ( $boutiques ) : ?>
		<div class="bv-shops" data-boutiques>
			<?php foreach ( $boutiques as $rang => $boutique ) {
				bayam_vitrine_carte_boutique( $boutique, $rang );
			} ?>
		</div>
	<?php endif; ?>
	<p class="bv-annuaire__vide" data-aucune<?php echo $boutiques ? ' hidden' : ''; ?>><?php esc_html_e( 'Aucune boutique ne correspond à votre recherche.', 'bayam-vitrine' ); ?></p>
</main>
<?php
get_footer();
