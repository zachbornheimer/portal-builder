<?php
function pb_group_shortcode( $atts, $content = null ) {
	$atts = shortcode_atts(
		array(
			'label' => '', // Legend for the fieldset
		),
		$atts
	);

	$label = $atts['label'];
	unset( $atts['label'] );
	if ( ! isset( $atts['class'] ) ) {
		$atts['class'] = '';
	}


	# add .portal-group class to the fieldset
	$atts['class'] .= ' portal-group';


	$fieldset_attrs = '';
	foreach ( $atts as $key => $value ) {
		$fieldset_attrs .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
	}

	// Process nested shortcodes
	$content = $content;

	$output = sprintf(
		'<fieldset%s><legend>%s</legend><div class="form-grid">%s</div></fieldset>',
		$fieldset_attrs,
		esc_html( $label ),
		$content
	);

	return do_shortcode( $output );
}
add_shortcode( 'pb_group', 'pb_group_shortcode' );
