<?php
/**
 * CLI harness: Portal_View_As without WordPress.
 */

// phpcs:disable
class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
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
function is_wp_error( $t ) {
	return $t instanceof WP_Error;
}
function wp_json_encode( $d ) {
	return json_encode( $d );
}
function wp_unslash( $value ) {
	return $value;
}

require_once dirname( __DIR__, 2 ) . '/includes/Definition/class-portal-access.php';
require_once dirname( __DIR__, 2 ) . '/includes/Definition/class-portal-definition.php';
require_once dirname( __DIR__, 2 ) . '/includes/Submission/class-portal-submit-admission.php';
require_once dirname( __DIR__, 2 ) . '/includes/Definition/class-portal-view-as.php';

$path = $argv[1] ?? '';
if ( ! is_readable( $path ) ) {
	fwrite( STDERR, "unreadable $path\n" );
	exit( 2 );
}
$payload = json_decode( file_get_contents( $path ), true );
if ( ! is_array( $payload ) ) {
	fwrite( STDERR, "invalid payload\n" );
	exit( 1 );
}

$GLOBALS['dg_view_as_test'] = $payload;

if ( isset( $payload['query'] ) ) {
	$_GET['dg_view_as'] = (string) $payload['query'];
}

if ( isset( $payload['wp'] ) && is_array( $payload['wp'] ) ) {
	if ( ! function_exists( 'is_user_logged_in' ) ) {
		function is_user_logged_in() {
			return ! empty( $GLOBALS['dg_view_as_test']['wp']['logged_in'] );
		}
	}
	if ( ! function_exists( 'get_current_user_id' ) ) {
		function get_current_user_id() {
			return (int) ( $GLOBALS['dg_view_as_test']['wp']['user_id'] ?? 0 );
		}
	}
	if ( ! function_exists( 'wp_get_current_user' ) ) {
		function wp_get_current_user() {
			$wp   = $GLOBALS['dg_view_as_test']['wp'];
			$caps = isset( $wp['capabilities'] ) && is_array( $wp['capabilities'] ) ? $wp['capabilities'] : array();
			return (object) array(
				'ID'      => (int) ( $wp['user_id'] ?? 0 ),
				'roles'   => isset( $wp['roles'] ) && is_array( $wp['roles'] ) ? $wp['roles'] : array(),
				'allcaps' => $caps,
			);
		}
	}
	if ( ! function_exists( 'get_user_meta' ) ) {
		function get_user_meta( $user_id, $key, $single = false ) {
			unset( $user_id, $key, $single );
			return '';
		}
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		if ( 'dg_view_as_roles' === $key && array_key_exists( 'site_roles', $GLOBALS['dg_view_as_test'] ) ) {
			return $GLOBALS['dg_view_as_test']['site_roles'];
		}
		return $default;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		unset( $post_id, $single );
		if ( '_portal_definition' !== $key ) {
			return '';
		}
		$definition = isset( $GLOBALS['dg_view_as_test']['definition'] ) && is_array( $GLOBALS['dg_view_as_test']['definition'] )
			? $GLOBALS['dg_view_as_test']['definition']
			: null;
		return is_array( $definition ) ? wp_json_encode( $definition ) : '';
	}
}

if ( ! empty( $payload['stub_woo'] ) && ! function_exists( 'wc_memberships_get_user_active_memberships' ) ) {
	function wc_memberships_get_user_active_memberships( $user_id ) {
		unset( $user_id );
		$ids = isset( $GLOBALS['dg_view_as_test']['stub_woo_plans'] ) && is_array( $GLOBALS['dg_view_as_test']['stub_woo_plans'] )
			? $GLOBALS['dg_view_as_test']['stub_woo_plans']
			: array();
		$out = array();
		foreach ( $ids as $id ) {
			$out[] = new class( $id ) {
				private $plan_id;
				public function __construct( $plan_id ) {
					$this->plan_id = $plan_id;
				}
				public function get_plan_id() {
					return $this->plan_id;
				}
			};
		}
		return $out;
	}
}

$call      = isset( $payload['call'] ) ? (string) $payload['call'] : '';
$portal_id = isset( $payload['portal_id'] ) ? (int) $payload['portal_id'] : 1;

if ( 'decide' === $call ) {
	$applicant = Portal_View_As::persona_applicant( isset( $payload['persona'] ) ? $payload['persona'] : '' );
	$access    = isset( $payload['access'] ) && is_array( $payload['access'] ) ? $payload['access'] : array();
	$options   = isset( $payload['options'] ) && is_array( $payload['options'] ) ? $payload['options'] : array();
	echo json_encode( Portal_Access::decide( $access, $options, is_array( $applicant ) ? $applicant : array() ) ) . "\n";
	exit( 0 );
}

if ( 'applicant' === $call ) {
	echo json_encode(
		array(
			'is_active' => Portal_View_As::is_active( $portal_id ),
			'applicant' => Portal_View_As::applicant( $portal_id ),
		)
	) . "\n";
	exit( 0 );
}

if ( 'submit_decide' === $call ) {
	$err = Portal_Submit_Admission::decide(
		! empty( $payload['is_preview'] ),
		! empty( $payload['is_open'] ),
		! empty( $payload['is_view_as'] )
	);
	if ( is_wp_error( $err ) ) {
		echo json_encode(
			array(
				'code'    => $err->get_error_code(),
				'message' => $err->get_error_message(),
			)
		) . "\n";
		exit( 0 );
	}
	echo json_encode( array( 'code' => null ) ) . "\n";
	exit( 0 );
}

if ( 'sanitize_roles' === $call ) {
	echo json_encode( Portal_View_As::sanitize_roles( isset( $payload['roles'] ) ? $payload['roles'] : array() ) ) . "\n";
	exit( 0 );
}

if ( 'bar_items' === $call ) {
	$catalog = isset( $payload['catalog'] ) && is_array( $payload['catalog'] ) ? $payload['catalog'] : array();
	echo json_encode( Portal_View_As::persona_catalog( $catalog ) ) . "\n";
	exit( 0 );
}

fwrite( STDERR, "unknown call\n" );
exit( 1 );
