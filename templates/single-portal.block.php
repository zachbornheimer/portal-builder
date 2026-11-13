<?php
/**
 * Block-theme single portal: site chrome + application, no leftover shortcode shell.
 *
 * @package DragonGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'dg-public-portal' ); ?>>
<?php wp_body_open(); ?>
<?php if ( function_exists( 'block_header_area' ) ) : ?>
	<header class="wp-block-template-part">
		<?php block_header_area(); ?>
	</header>
<?php endif; ?>

<main class="dg-public-main">
	<?php
	if ( have_posts() ) :
		while ( have_posts() ) :
			the_post();
			$portal_id      = (int) get_the_ID();
			$has_definition = class_exists( 'Portal_Definition' )
				&& null !== Portal_Definition::load_for_post( $portal_id );
			if ( $has_definition ) {
				$show_actions = class_exists( 'Portal_Public_Render' )
					&& Portal_Public_Render::should_show_form_actions( $portal_id );
				if ( $show_actions ) {
					echo do_shortcode( '[portal-application-formstart]' );
				}
				echo '<article class="dg-packet" id="application">';
				the_content();
				if ( $show_actions ) {
					echo Portal_Public_Render::render_submit_control();
				}
				echo '</article>';
				if ( $show_actions ) {
					echo do_shortcode( '[portal-application-formend]' );
				}
			} else {
				echo do_shortcode( '[portal-application-title]' );
				echo do_shortcode( '[portal-application-formstart]' );
				the_content();
				echo do_shortcode( '[portal-application-agreements]' );
				echo do_shortcode( '[portal-application-upload-notes]' );
				echo do_shortcode( '[portal-application-formend]' );
			}
		endwhile;
	endif;
	?>
</main>

<?php if ( function_exists( 'block_footer_area' ) ) : ?>
	<footer class="wp-block-template-part">
		<?php block_footer_area(); ?>
	</footer>
<?php endif; ?>
<?php wp_footer(); ?>
</body>
</html>
