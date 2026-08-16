<?php
/**
 * CLI harness: render shipped Google API setup + field-help callbacks.
 *
 * Usage:
 *   php tests/support/php-google-api-setup.php
 *
 * Prints JSON { setup, section, secret, access } from Portal_Settings
 * callbacks — not a copy of the operator-facing strings.
 */

// phpcs:disable
function __( $text, $domain = null ) {
	unset( $domain );
	return (string) $text;
}
function _e( $text, $domain = null ) {
	echo __( $text, $domain );
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_html__( $text, $domain = null ) {
	return esc_html( __( $text, $domain ) );
}
function esc_html_e( $text, $domain = null ) {
	echo esc_html__( $text, $domain );
}
function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $url ) {
	return (string) $url;
}
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}
function get_option( $name, $default = false ) {
	unset( $name );
	return $default;
}

$repo_root = dirname( __DIR__, 2 );
require_once $repo_root . '/includes/class-portal-settings.php';

if ( ! class_exists( 'Portal_Settings' ) ) {
	fwrite( STDERR, "Portal_Settings missing\n" );
	exit( 1 );
}

$settings = new Portal_Settings();

ob_start();
$settings->google_api_setup_page_callback();
$setup = ob_get_clean();

ob_start();
$settings->google_api_section_callback();
$section = ob_get_clean();

ob_start();
$settings->render_google_secret_key_field();
$secret = ob_get_clean();

ob_start();
$settings->render_google_access_key_field();
$access = ob_get_clean();

echo json_encode(
	array(
		'setup'   => $setup,
		'section' => $section,
		'secret'  => $secret,
		'access'  => $access,
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) . "\n";
exit( 0 );
