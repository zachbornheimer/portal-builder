<?php

function portal_application_title_shortcode( $atts ) {
	$guidelines_url = get_post_meta( get_the_ID(), '_portal_guidelines_url', true );

	$output  = '<div class="entry-content alignfull wp-block-post-content has-global-padding is-layout-constrained wp-block-post-content-is-layout-constrained">';
	$output .= '<p><em>Application Form for:</em></p>';
	$output .= '<h2 style="text-align: center;">' . esc_html( get_the_title() ) . '</h2>';

	if ( ! empty( $guidelines_url ) ) {
		$output .= '<div style="text-align: center; margin:0px;padding:0px;">';
		$output .= '<em><span style="font-size: 12px;">Guidelines available at: ';
		$output .= '<a style="text-decoration: underline;" href="' . esc_url( $guidelines_url ) . '" target="_blank">' . esc_html( $guidelines_url ) . '</a>';
		$output .= '</span></em></div>';
	}

	$output .= '</div>';

	return $output;
}
add_shortcode( 'portal-application-title', 'portal_application_title_shortcode' );
