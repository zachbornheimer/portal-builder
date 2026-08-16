<?php
/**
 * CLI harness: Portal_Access::decide without WordPress.
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

require_once dirname( __DIR__, 2 ) . '/includes/Definition/class-portal-access.php';

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

$GLOBALS['dg_access_test'] = $payload;

if ( isset( $payload['wp'] ) && is_array( $payload['wp'] ) ) {
	if ( ! function_exists( 'is_user_logged_in' ) ) {
		function is_user_logged_in() {
			return ! empty( $GLOBALS['dg_access_test']['wp']['logged_in'] );
		}
	}
	if ( ! function_exists( 'get_current_user_id' ) ) {
		function get_current_user_id() {
			return (int) ( $GLOBALS['dg_access_test']['wp']['user_id'] ?? 0 );
		}
	}
	if ( ! function_exists( 'wp_get_current_user' ) ) {
		function wp_get_current_user() {
			$wp = $GLOBALS['dg_access_test']['wp'];
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

if ( ! empty( $payload['stub_woo'] ) && ! function_exists( 'wc_memberships_get_user_active_memberships' ) ) {
	function wc_memberships_get_user_active_memberships( $user_id ) {
		unset( $user_id );
		$ids = isset( $GLOBALS['dg_access_test']['stub_woo_plans'] ) && is_array( $GLOBALS['dg_access_test']['stub_woo_plans'] )
			? $GLOBALS['dg_access_test']['stub_woo_plans']
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

if ( isset( $payload['call'] ) && 'applicant' === $payload['call'] ) {
	echo json_encode( Portal_Access::current_applicant() ) . "\n";
	exit( 0 );
}

$access    = isset( $payload['access'] ) && is_array( $payload['access'] ) ? $payload['access'] : array();
$options   = isset( $payload['options'] ) && is_array( $payload['options'] ) ? $payload['options'] : array();
$applicant = isset( $payload['applicant'] ) && is_array( $payload['applicant'] ) ? $payload['applicant'] : array();
echo json_encode( Portal_Access::decide( $access, $options, $applicant ) ) . "\n";
exit( 0 );
