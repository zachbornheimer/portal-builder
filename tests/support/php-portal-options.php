<?php
/**
 * CLI harness: Portal_Options dual-read / compat-write without WordPress.
 *
 * Usage:
 *   php tests/support/php-portal-options.php <payload.json>
 *
 * Payload: { "action": "get"|"set", "key": "dg_login_url", "default": "", "value": "...", "options": { ... } }
 * Prints the stored bag plus the result.
 */

// phpcs:disable
$GLOBALS['dg_option_store'] = array();

function get_option( $key, $default = false ) {
	$store = $GLOBALS['dg_option_store'];
	if ( is_array( $store ) && array_key_exists( $key, $store ) ) {
		return $store[ $key ];
	}
	return $default;
}

function update_option( $key, $value ) {
	$GLOBALS['dg_option_store'][ $key ] = $value;
	return true;
}

function delete_option( $key ) {
	unset( $GLOBALS['dg_option_store'][ $key ] );
	return true;
}

$repo_root = dirname( __DIR__, 2 );
require_once $repo_root . '/includes/class-portal-options.php';
require_once $repo_root . '/includes/Definition/class-portal-definition.php';
require_once $repo_root . '/includes/Definition/class-portal-site-defaults.php';
require_once $repo_root . '/includes/adapters/class-portal-google-store.php';

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
	$GLOBALS['dg_option_store'] = $payload['options'];
}

if ( ! class_exists( 'Portal_Options' ) ) {
	fwrite( STDERR, "Portal_Options missing\n" );
	exit( 1 );
}

$action  = isset( $payload['action'] ) ? (string) $payload['action'] : 'get';
$key     = isset( $payload['key'] ) ? (string) $payload['key'] : 'dg_login_url';
$default = array_key_exists( 'default', $payload ) ? $payload['default'] : false;

if ( 'set' === $action ) {
	$value = array_key_exists( 'value', $payload ) ? $payload['value'] : null;
	Portal_Options::set( $key, $value );
	echo json_encode(
		array(
			'store' => $GLOBALS['dg_option_store'],
			'read'  => Portal_Options::get( $key, $default ),
		)
	) . "\n";
	exit( 0 );
}

if ( 'promote' === $action ) {
	$result = Portal_Options::promote();
	echo json_encode(
		array(
			'store'  => $GLOBALS['dg_option_store'],
			'read'   => Portal_Options::get( $key, $default ),
			'result' => $result,
		)
	) . "\n";
	exit( 0 );
}

if ( 'login_url' === $action ) {
	$redirect = isset( $payload['redirect'] ) ? (string) $payload['redirect'] : '';
	echo json_encode(
		array(
			'login_url' => Portal_Site_Defaults::login_url( $redirect ),
			'store'     => $GLOBALS['dg_option_store'],
		)
	) . "\n";
	exit( 0 );
}

if ( 'google_access' === $action ) {
	$const = Portal_Google_Store::OPTION_ACCESS_KEY;
	echo json_encode(
		array(
			'constant' => $const,
			'value'    => Portal_Options::get( $const, '' ),
			'store'    => $GLOBALS['dg_option_store'],
		)
	) . "\n";
	exit( 0 );
}

if ( 'google_set' === $action ) {
	$value = array_key_exists( 'value', $payload ) ? $payload['value'] : '';
	Portal_Options::set( Portal_Google_Store::OPTION_ACCESS_KEY, $value );
	echo json_encode(
		array(
			'constant' => Portal_Google_Store::OPTION_ACCESS_KEY,
			'store'    => $GLOBALS['dg_option_store'],
			'read'     => Portal_Options::get( Portal_Google_Store::OPTION_ACCESS_KEY, '' ),
		)
	) . "\n";
	exit( 0 );
}

echo json_encode(
	array(
		'value' => Portal_Options::get( $key, $default ),
		'store' => $GLOBALS['dg_option_store'],
	)
) . "\n";
exit( 0 );
