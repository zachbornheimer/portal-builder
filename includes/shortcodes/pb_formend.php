<?php
function portal_application_formend_shortcode() {
	if ( defined( 'PB_APPLICATION_SUBMITTED' ) && PB_APPLICATION_SUBMITTED ) {
		return '';
	}
	if ( defined( 'PB_APPLICATION_DEADLINE_PASSED' ) && PB_APPLICATION_DEADLINE_PASSED ) {
		return '';
	}
	return '</form>';
}
add_shortcode( 'portal-application-formend', 'portal_application_formend_shortcode' );
