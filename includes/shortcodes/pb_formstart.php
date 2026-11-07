<?php
/**
 * Portal form open tag + nonce.
 *
 * Definition portals use a single-step submit nonce (Phase 3).
 * Legacy shortcode portals keep the two-step review → ready_to_submit flow.
 *
 * @package DragonGate
 */

/**
 * @return string
 */
function portal_application_formstart_shortcode() {
	if ( defined( 'DG_APPLICATION_SUBMITTED' ) && DG_APPLICATION_SUBMITTED ) {
		return '';
	}
	if ( class_exists( 'Portal_Public_Render' ) && Portal_Public_Render::form_chrome_hidden() ) {
		return '';
	}

	$post_id    = (int) get_the_ID();
	$definition = class_exists( 'Portal_Definition' ) ? Portal_Definition::load_for_post( $post_id ) : null;
	$is_def     = is_array( $definition );

	ob_start();
	printf(
		'<form action="" enctype="multipart/form-data" method="post" class="dg-portal-submit-form" data-dg-form="%s">',
		esc_attr( $is_def ? 'definition' : 'legacy' )
	);
	printf( '<input type="hidden" name="post_id" value="%s" />', esc_attr( (string) $post_id ) );

	if ( $is_def ) {
		wp_nonce_field( Portal_Submission_Pipeline::NONCE_ACTION, Portal_Submission_Pipeline::NONCE_FIELD );
	} else {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- chooses next nonce name only.
		$nonce_name = isset( $_POST['review_nonce'] ) ? 'ready_to_submit_nonce' : 'review_nonce';
		wp_nonce_field( $nonce_name, $nonce_name );
	}

	return ob_get_clean();
}
add_shortcode( 'portal-application-formstart', 'portal_application_formstart_shortcode' );
