<?php
// Shortcode for file input
function pb_file_shortcode( $atts ) {
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

	$caption = $atts['caption'] ? sprintf( '<br /><span class="caption">%s</span>', esc_html( $atts['caption'] ) ) : '';

	if ( $atts['required'] !== false ) {
		$label .= '*';
	}

	$output = sprintf(
		'<div class="form-group">
    <label for="%s">%s</label>
    <input type="file" id="%s" name="%s" %s %s>
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
add_shortcode( 'pb_file', 'pb_file_shortcode' );
