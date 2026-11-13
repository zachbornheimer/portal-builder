<?php
/**
 * CLI harness: Portal_Site_Defaults::resolve without WordPress.
 *
 * Usage:
 *   php tests/support/php-site-defaults.php <payload.json>
 *
 * Payload: { "definition": { options?, publish? }, "site": { ... } }
 * Prints the resolved bag from the shipped resolve() — not a copy.
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

$GLOBALS['dg_site_option'] = array();

function get_option( $key, $default = false ) {
	$opts = $GLOBALS['dg_site_option'];
	if ( is_array( $opts ) && array_key_exists( $key, $opts ) ) {
		return $opts[ $key ];
	}
	return $default;
}

function home_url( $path = '/' ) {
	$path = (string) $path;
	if ( '' === $path ) {
		$path = '/';
	}
	if ( '/' !== $path[0] ) {
		$path = '/' . $path;
	}
	return 'https://example.test' . $path;
}

$repo_root = dirname( __DIR__, 2 );
require_once $repo_root . '/includes/class-portal-options.php';
require_once $repo_root . '/includes/Definition/class-portal-definition.php';
require_once $repo_root . '/includes/Definition/class-portal-site-defaults.php';

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

if ( isset( $payload['options'] ) && is_array( $payload['options'] ) ) {
	$GLOBALS['dg_site_option'] = $payload['options'];
}

if ( ! class_exists( 'Portal_Site_Defaults' ) ) {
	fwrite( STDERR, "Portal_Site_Defaults missing\n" );
	exit( 1 );
}

$action = isset( $payload['action'] ) ? (string) $payload['action'] : 'resolve';
if ( 'login_url' === $action ) {
	$redirect = isset( $payload['redirect'] ) ? (string) $payload['redirect'] : '';
	echo json_encode(
		array(
			'login_url'     => Portal_Site_Defaults::login_url( $redirect ),
			'builtin_login' => Portal_Site_Defaults::BUILTIN_LOGIN_PATH,
		)
	) . "\n";
	exit( 0 );
}
if ( 'join_url' === $action ) {
	echo json_encode(
		array(
			'join_url'     => Portal_Site_Defaults::join_url(),
			'builtin_join' => Portal_Site_Defaults::BUILTIN_JOIN_PATH,
		)
	) . "\n";
	exit( 0 );
}

$definition = isset( $payload['definition'] ) && is_array( $payload['definition'] )
	? $payload['definition']
	: array();
$site       = isset( $payload['site'] ) && is_array( $payload['site'] )
	? $payload['site']
	: array();

echo json_encode( Portal_Site_Defaults::resolve( $definition, $site ) ) . "\n";
exit( 0 );
