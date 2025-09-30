<?php
function pb_group_shortcode( $atts, $content = null ) {
	$atts = shortcode_atts(
		array(
			'label' => '', // Legend for the fieldset
			'track-completion' => '', // Field name to track completion
			'completion-value' => 'YES', // Value when group is complete
			'incomplete-value' => 'NO', // Value when group is incomplete
		),
		$atts
	);

	$label = $atts['label'];
	$track_completion = $atts['track-completion'];
	$completion_value = $atts['completion-value'];
	$incomplete_value = $atts['incomplete-value'];
	
	unset( $atts['label'], $atts['track-completion'], $atts['completion-value'], $atts['incomplete-value'] );
	
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

	// Add hidden completion tracking field if track-completion is specified
	if ( ! empty( $track_completion ) ) {
		$content .= sprintf(
			'<input type="hidden" name="%s" value="%s" class="completion-tracker" data-complete-value="%s" data-incomplete-value="%s" />',
			esc_attr( $track_completion ),
			esc_attr( $incomplete_value ), // Start as incomplete
			esc_attr( $completion_value ),
			esc_attr( $incomplete_value )
		);
	}

	$output = sprintf(
		'<fieldset%s><legend>%s</legend><div class="form-grid">%s</div></fieldset>',
		$fieldset_attrs,
		esc_html( $label ),
		$content
	);

	return do_shortcode( $output );
}
add_shortcode( 'pb_group', 'pb_group_shortcode' );
