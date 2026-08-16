<?php
/**
 * Drive shipped Portal_Secret_Field + Settings secret render.
 *
 * Usage:
 *   php tests/support/php-secret-field.php '{"stored":"{...json...}"}'
 */
// phpcs:disable
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

$repo = dirname( __DIR__, 2 );
require_once $repo . '/includes/class-portal-secret-field.php';

$raw  = isset( $argv[1] ) ? $argv[1] : '{}';
$in   = json_decode( $raw, true );
$in   = is_array( $in ) ? $in : array();
$stored = isset( $in['stored'] ) ? $in['stored'] : '';
$incoming = array_key_exists( 'incoming', $in ) ? $in['incoming'] : '';

echo json_encode(
	array(
		'set'      => Portal_Secret_Field::is_set( $stored ),
		'lastFour' => Portal_Secret_Field::last_four( $stored ),
		'kept'     => Portal_Secret_Field::keep_if_blank( $incoming, $stored ),
		'html'     => Portal_Secret_Field::render_textarea( 'pb_google_secret_key', $stored, 'OAuth client JSON' ),
	)
) . "\n";
