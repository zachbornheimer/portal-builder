<?php
/**
 * The template for displaying single portal posts
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header(); ?>

<main class="wp-block-group alignfull">
	<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50); margin-bottom:var(--wp--preset--spacing--40);">
		<?php echo do_shortcode( '[portal-application-title]' ); ?>
	</div>

	<div class="wp-block-group">
		<?php echo do_shortcode( '[portal-application-formstart]' ); ?>
		
		<?php if ( isset( $_POST['review_nonce'] ) ) : ?>
			<?php echo do_shortcode( '[portal-application-file-review]' ); ?>
		<?php endif; ?>

		<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--50); margin-bottom:var(--wp--preset--spacing--40);">
			<?php the_content(); ?>
		</div>

		<?php echo do_shortcode( '[portal-application-agreements]' ); ?>
		<?php echo do_shortcode( '[portal-application-upload-notes]' ); ?>
		<?php echo do_shortcode( '[portal-application-formend]' ); ?>
	</div>

	<div class="wp-block-group" style="margin-top:var(--wp--preset--spacing--40); padding-bottom:var(--wp--preset--spacing--50);">
		<div class="wp-block-group">
			<?php the_terms( get_the_ID(), 'post_tag', '<div class="is-style-pill">', '  ', '</div>' ); ?>
		</div>

		<div class="wp-block-group">
			<div style="height:var(--wp--preset--spacing--40)" aria-hidden="true" class="wp-block-spacer"></div>
			<hr class="wp-block-separator has-text-color has-contrast-3-color has-alpha-channel-opacity has-contrast-3-background-color has-background is-style-wide" style="margin-bottom:var(--wp--preset--spacing--40)"/>
			<?php comments_template(); ?>
		</div>
	</div>
</main>

<?php get_footer(); ?>
