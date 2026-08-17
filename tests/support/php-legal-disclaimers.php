<?php
/**
 * CLI harness: Portal_Legal_Disclaimers::parse and optional definition render.
 *
 * Usage:
 *   php tests/support/php-legal-disclaimers.php <payload.json>
 *
 * Payload:
 *   { "action": "parse", "raw": <string|array> }
 *   { "action": "render", "definition": { ... }, "legalDisclaimers": [ { "id", "text" } ] }
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
function wp_kses_post( $s ) {
	return strip_tags( (string) $s, '<p><br><em><strong><a><span><ul><ol><li>' );
}

$repo_root = dirname( __DIR__, 2 );
require_once $repo_root . '/includes/class-portal-options.php';
require_once $repo_root . '/includes/Definition/class-portal-definition.php';
require_once $repo_root . '/includes/Definition/class-portal-site-defaults.php';
require_once $repo_root . '/includes/Definition/class-portal-definition-renderer.php';
require_once $repo_root . '/includes/Definition/class-portal-legal-disclaimers.php';

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

$action = isset( $payload['action'] ) ? (string) $payload['action'] : 'parse';

if ( 'render' === $action ) {
	if ( isset( $payload['legalDisclaimers'] ) && is_array( $payload['legalDisclaimers'] ) ) {
		$GLOBALS['dg_test_legal_disclaimers'] = $payload['legalDisclaimers'];
	}
	$definition = isset( $payload['definition'] ) && is_array( $payload['definition'] )
		? $payload['definition']
		: array();
	$html       = Portal_Definition_Renderer::render( $definition );
	if ( '' === $html ) {
		fwrite( STDERR, "empty render\n" );
		exit( 1 );
	}
	echo json_encode(
		array(
			'ok'   => true,
			'html' => $html,
		),
		JSON_UNESCAPED_SLASHES
	) . "\n";
	exit( 0 );
}

$raw  = array_key_exists( 'raw', $payload ) ? $payload['raw'] : null;
$rows = Portal_Legal_Disclaimers::parse( $raw );
echo json_encode(
	array(
		'ok'   => true,
		'rows' => $rows,
	),
	JSON_UNESCAPED_SLASHES
) . "\n";
exit( 0 );
