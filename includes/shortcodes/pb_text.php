<?php
// Shortcode for text input
function pb_text_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'label'    => '',
			'name'     => '',
			'required' => false,
			'accept'   => '',
			'caption'  => '',
		),
		$atts
	);

	$required_attr = $atts['required'] ? 'required' : '';
	$accept_attr   = $atts['accept'] ? sprintf( 'accept="%s"', esc_attr( $atts['accept'] ) ) : '';

	$label = $atts['label'];

	if ( $atts['required'] !== false ) {
		$label .= '*';
	}

	$caption = $atts['caption'] ? sprintf( '<br /><span class="caption">%s</span>', esc_html( $atts['caption'] ) ) : '';

	$output = sprintf(
		'<div class="form-group">
    <label for="%s">%s</label>
    <input type="text" id="%s" name="%s" %s %s>
</div>',
		esc_attr( $atts['name'] ),
		esc_html( $label ) . $caption,
		esc_attr( $atts['name'] ),
		esc_attr( $atts['name'] ),
		$accept_attr,
		$required_attr
	);

	return $output;
}
add_shortcode( 'pb_text', 'pb_text_shortcode' );
