<?php
function portal_application_formstart_shortcode() {
	if ( defined( 'PB_APPLICATION_SUBMITTED' ) && PB_APPLICATION_SUBMITTED ) {
		return '';
	}
	if ( defined( 'PB_APPLICATION_DEADLINE_PASSED' ) && PB_APPLICATION_DEADLINE_PASSED ) {
		return '';
	}

	$nonceName = isset( $_POST['review_nonce'] ) ? 'ready_to_submit_nonce' : 'review_nonce';

	ob_start();
	echo '<form action="" enctype="multipart/form-data" method="post">';
	echo '<input type="hidden" name="post_id" value="' . get_the_ID() . '" />';
	wp_nonce_field( $nonceName, $nonceName );
	return ob_get_clean();
}
add_shortcode( 'portal-application-formstart', 'portal_application_formstart_shortcode' );
