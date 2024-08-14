<?php
// Shortcode for full_span input
function pb_full_span_shortcode( $atts, $content ) {
	# should be wrapped in a span that's 100% cols
	$output  = '<span class="full-span">';
	$output .= do_shortcode( $content );
	$output .= '</span>';
	return $output;
}
add_shortcode( 'pb_full_span', 'pb_full_span_shortcode' );
