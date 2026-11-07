<?php
/**
 * The template for displaying single portal posts
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

// Ensure the loop is properly initialized
if ( have_posts() ) :
	while ( have_posts() ) :
		the_post(); ?>

		<main class="dg-public-main wp-block-group alignfull">
			<?php
			$portal_id          = (int) get_the_ID();
			$has_definition     = class_exists( 'Portal_Definition' )
				&& null !== Portal_Definition::load_for_post( $portal_id );
			?>
			<?php if ( $has_definition ) : ?>
				<?php
				$show_actions = class_exists( 'Portal_Public_Render' )
					&& Portal_Public_Render::should_show_form_actions( $portal_id );
				if ( $show_actions ) {
					echo do_shortcode( '[portal-application-formstart]' );
				}
				?>
				<article class="dg-packet" id="application">
					<?php the_content(); ?>
					<?php
					if ( $show_actions ) {
						echo Portal_Public_Render::render_submit_control();
					}
					?>
				</article>
				<?php
				if ( $show_actions ) {
					echo do_shortcode( '[portal-application-formend]' );
				}
				?>
			<?php else : ?>
			<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50); margin-bottom:var(--wp--preset--spacing--40);">
				<?php echo do_shortcode( '[portal-application-title]' ); ?>
			</div>

			<div class="wp-block-group">
				<?php echo do_shortcode( '[portal-application-formstart]' ); ?>

				<?php if ( isset( $_POST['review_nonce'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Missing ?>
					<?php echo do_shortcode( '[portal-application-file-review]' ); ?>
				<?php endif; ?>

				<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--50); margin-bottom:var(--wp--preset--spacing--40);">
					<?php the_content(); ?>
				</div>

				<?php echo do_shortcode( '[portal-application-agreements]' ); ?>
				<?php echo do_shortcode( '[portal-application-upload-notes]' ); ?>
				<?php echo do_shortcode( '[portal-application-formend]' ); ?>
			</div>
			<?php endif; ?>
		</main>

		<?php
	endwhile; // End of the loop.

else :
	// If no content, display a message
	echo '<p>No content available for this portal.</p>';
endif;

get_footer();
