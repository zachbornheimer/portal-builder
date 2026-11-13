<?php
/**
 * Drive shipped Portal_Spam_Gate::admit.
 *
 * Usage:
 *   php tests/support/php-spam-gate.php '{"audience":"anyone","token":"","secret":"s"}'
 */
// phpcs:disable
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code, $message ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}

$GLOBALS['dg_options'] = array();
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['dg_options'] )
		? $GLOBALS['dg_options'][ $name ]
		: $default;
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) {}
}

$repo = dirname( __DIR__, 2 );
require_once $repo . '/includes/Definition/class-portal-definition.php';
require_once $repo . '/includes/Submission/class-portal-spam-gate.php';
require_once $repo . '/includes/Definition/class-portal-public-render.php';

$in = json_decode( isset( $argv[1] ) ? $argv[1] : '{}', true );
$in = is_array( $in ) ? $in : array();
if ( array_key_exists( 'secret', $in ) ) {
	$GLOBALS['dg_options'][ Portal_Spam_Gate::OPTION_SECRET ] = $in['secret'];
} else {
	$GLOBALS['dg_options'][ Portal_Spam_Gate::OPTION_SECRET ] = 'test-secret';
}
if ( array_key_exists( 'site', $in ) ) {
	$GLOBALS['dg_options'][ Portal_Spam_Gate::OPTION_SITE ] = $in['site'];
}

$definition = array(
	'access' => array(
		'audience' => isset( $in['audience'] ) ? $in['audience'] : 'anyone',
	),
);
if ( ! empty( $in['testMode'] ) ) {
	$definition['publish'] = array( 'testMode' => true );
}
$values = array();
if ( isset( $in['token'] ) ) {
	$values[ Portal_Spam_Gate::TOKEN_FIELD ] = $in['token'];
}
$accept = ! empty( $in['accept'] );
$err    = Portal_Spam_Gate::admit(
	$definition,
	$values,
	function ( $token, $secret ) use ( $accept ) {
		unset( $secret );
		return $accept && '' !== $token;
	}
);

$keys = Portal_Spam_Gate::resolve_keys( $definition );

echo json_encode(
	array(
		'required' => Portal_Spam_Gate::required_for_audience( $definition['access']['audience'], $definition ),
		'ok'       => ! is_wp_error( $err ) && null === $err,
		'code'     => is_wp_error( $err ) ? $err->get_error_code() : null,
		'message'  => is_wp_error( $err ) ? $err->get_error_message() : null,
		'siteKey'  => $keys['site'],
		'widget'   => Portal_Public_Render::render_spam_widget( $definition ),
	)
) . "\n";

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
