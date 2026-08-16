<?php
function portal_application_formend_shortcode() {
	if ( defined( 'PB_APPLICATION_SUBMITTED' ) && PB_APPLICATION_SUBMITTED ) {
		return '';
	}
	if ( class_exists( 'Portal_Public_Render' ) && Portal_Public_Render::form_chrome_hidden() ) {
		return '';
	}
	return '</form>';
}
add_shortcode( 'portal-application-formend', 'portal_application_formend_shortcode' );
