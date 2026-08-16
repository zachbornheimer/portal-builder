<?php
/**
 * CLI harness: Portal_Brand preset / sanitize / css without WordPress.
 *
 * Usage:
 *   php tests/support/php-brand.php <payload.json>
 *
 * Payload: { "action": "preset-css"|"sanitize", "preset"?: "isjac", "raw"?: {} }
 * Prints JSON from the shipped class — not a copy.
 */

// phpcs:disable
function esc_url_raw( $s ) {
	$s = trim( (string) $s );
	if ( '' === $s ) {
		return '';
	}
	if ( ! preg_match( '#^https?://#i', $s ) ) {
		return '';
	}
	$clean = filter_var( $s, FILTER_SANITIZE_URL );
	return is_string( $clean ) ? $clean : '';
}

$repo_root = dirname( __DIR__, 2 );
require_once $repo_root . '/includes/Definition/class-portal-brand.php';

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

if ( ! class_exists( 'Portal_Brand' ) ) {
	fwrite( STDERR, "Portal_Brand missing\n" );
	exit( 1 );
}

$action = isset( $payload['action'] ) ? (string) $payload['action'] : 'preset-css';
$preset = isset( $payload['preset'] ) ? (string) $payload['preset'] : Portal_Brand::PRESET_ISJAC;

if ( 'sanitize' === $action ) {
	$raw    = isset( $payload['raw'] ) && is_array( $payload['raw'] ) ? $payload['raw'] : array();
	$brand  = Portal_Brand::sanitize( $raw );
	$css    = Portal_Brand::css( $brand );
	echo json_encode(
		array(
			'brand'   => $brand,
			'css'     => $css,
			'is_host' => Portal_Brand::is_host( $brand ),
		)
	) . "\n";
	exit( 0 );
}

$brand = Portal_Brand::preset( $preset );
$css   = Portal_Brand::css( $brand );
echo json_encode(
	array(
		'preset'        => $preset,
		'brand'         => $brand,
		'css'           => $css,
		'is_host'       => Portal_Brand::is_host( $brand ),
		'fonts_url'     => Portal_Brand::fonts_url( $brand ),
		'has_ink'       => false !== strpos( $css, '#020726' ),
		'has_paper'     => false !== strpos( $css, '#FFFAFC' ),
		'has_accent'    => false !== strpos( $css, '#15526F' ),
		'has_dm_sans'   => false !== strpos( $css, 'DM Sans' ),
		'has_isjac_cls' => false !== strpos( $css, '.isjac-' ),
	)
) . "\n";
exit( 0 );
