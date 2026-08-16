<?php
/**
 * CLI harness: Portal_Public_Render::render_packet_meta without WordPress.
 *
 * Usage:
 *   php tests/support/php-packet-meta.php <payload.json>
 *
 * Payload: { "definition": { options?, publish? }, "site": { ... } }
 * Prints the shipped packet-meta HTML — not a copy.
 */

// phpcs:disable
class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
}
function is_wp_error( $t ) {
	return $t instanceof WP_Error;
}
function wp_json_encode( $d ) {
	return json_encode( $d );
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_html__( $s, $domain = '' ) {
	return esc_html( $s );
}
function esc_url( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function add_action( ...$args ) {}
function add_filter( ...$args ) {}

$repo_root = dirname( __DIR__, 2 );
require_once $repo_root . '/includes/Definition/class-portal-site-defaults.php';
require_once $repo_root . '/includes/Definition/class-portal-public-render.php';

$path = $argv[1] ?? '';
if ( ! is_readable( $path ) ) {
	fwrite( STDERR, "unreadable $path\n" );
	exit( 2 );
}

$payload = json_decode( file_get_contents( $path ), true );
if ( ! is_array( $payload ) ) {
	fwrite( STDERR, "payload must be a JSON object\n" );
	exit( 2 );
}

$definition = isset( $payload['definition'] ) && is_array( $payload['definition'] )
	? $payload['definition']
	: array();
$site       = isset( $payload['site'] ) && is_array( $payload['site'] )
	? $payload['site']
	: array();

if ( ! class_exists( 'Portal_Public_Render' ) ) {
	fwrite( STDERR, "Portal_Public_Render missing\n" );
	exit( 1 );
}

echo Portal_Public_Render::render_packet_meta( $definition, $site );
exit( 0 );
