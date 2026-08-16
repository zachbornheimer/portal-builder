<?php
/**
 * CLI harness: shipped post-activate Google-connect notice + checklist (no WordPress boot).
 *
 * Usage:
 *   php tests/support/php-google-connect.php /path/to/cases.json
 *
 * Prints JSON from Portal_Google_Connect — not a reimplementation.
 */

// phpcs:disable
$GLOBALS['dg_options'] = array();
$GLOBALS['dg_pagenow'] = '';
$GLOBALS['dg_is_admin'] = true;

function __( $text, $domain = null ) {
	unset( $domain );
	return (string) $text;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_html__( $text, $domain = null ) {
	return esc_html( __( $text, $domain ) );
}
function esc_url( $url ) {
	return (string) $url;
}
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}
function get_option( $name, $default = false ) {
	if ( array_key_exists( $name, $GLOBALS['dg_options'] ) ) {
		return $GLOBALS['dg_options'][ $name ];
	}
	return $default;
}
function update_option( $name, $value ) {
	$GLOBALS['dg_options'][ $name ] = $value;
	return true;
}
function is_admin() {
	return ! empty( $GLOBALS['dg_is_admin'] );
}
function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function register_deactivation_hook() {}

$root = dirname( __DIR__, 2 );

$connect_file = $root . '/includes/class-portal-google-connect.php';
if ( is_readable( $connect_file ) ) {
	require_once $connect_file;
}

$probe_file = $root . '/includes/adapters/class-portal-google-probe.php';
if ( is_readable( $probe_file ) ) {
	require_once $probe_file;
}

$raw = $argv[1] ?? '';
if ( '' === $raw || ! is_readable( $raw ) ) {
	fwrite( STDERR, "unreadable $raw\n" );
	exit( 2 );
}
$cases = json_decode( file_get_contents( $raw ), true );
if ( ! is_array( $cases ) ) {
	fwrite( STDERR, "invalid cases json\n" );
	exit( 2 );
}

/**
 * Reset options, run shipped activate when present, then apply case overrides.
 *
 * @param array $case Harness case.
 * @return void
 */
function dg_harness_apply_activate( array $case ) {
	$GLOBALS['dg_options'] = array();
	if ( class_exists( 'Portal_Google_Connect' ) && method_exists( 'Portal_Google_Connect', 'on_activate' ) ) {
		Portal_Google_Connect::on_activate();
	}
	if ( array_key_exists( 'test_ok', $case ) ) {
		$key = class_exists( 'Portal_Google_Probe' )
			? Portal_Google_Probe::SUCCESS_OPTION
			: 'dg_google_test_ok';
		$GLOBALS['dg_options'][ $key ] = $case['test_ok'];
	}
}

/**
 * @param array $case Harness case.
 * @return string
 */
function dg_harness_screen( array $case ) {
	if ( class_exists( 'Portal_Google_Connect' ) && method_exists( 'Portal_Google_Connect', 'screen_from_request' ) ) {
		$is_admin = array_key_exists( 'is_admin', $case ) ? ! empty( $case['is_admin'] ) : true;
		$pagenow  = isset( $case['pagenow'] ) ? (string) $case['pagenow'] : '';
		$query    = isset( $case['query'] ) && is_array( $case['query'] ) ? $case['query'] : array();
		return (string) Portal_Google_Connect::screen_from_request( $is_admin, $pagenow, $query );
	}
	return isset( $case['screen'] ) ? (string) $case['screen'] : '';
}

/**
 * @return bool
 */
function dg_harness_pending() {
	if ( class_exists( 'Portal_Google_Connect' ) && method_exists( 'Portal_Google_Connect', 'is_pending' ) ) {
		return (bool) Portal_Google_Connect::is_pending();
	}
	return false;
}

/**
 * @return bool
 */
function dg_harness_test_write() {
	if ( class_exists( 'Portal_Google_Connect' ) && method_exists( 'Portal_Google_Connect', 'has_test_write' ) ) {
		return (bool) Portal_Google_Connect::has_test_write();
	}
	return false;
}

/**
 * @param string $screen Screen id.
 * @param bool   $pending Connect still pending.
 * @param bool   $test_ok Test write succeeded.
 * @return bool
 */
function dg_harness_notice( $screen, $pending, $test_ok ) {
	if ( class_exists( 'Portal_Google_Connect' ) && method_exists( 'Portal_Google_Connect', 'should_show_notice' ) ) {
		return (bool) Portal_Google_Connect::should_show_notice( $screen, $pending, $test_ok );
	}
	return false;
}

/**
 * @param string $screen Screen id.
 * @param bool   $pending Connect still pending.
 * @param bool   $test_ok Test write succeeded.
 * @return bool
 */
function dg_harness_checklist( $screen, $pending, $test_ok ) {
	if ( class_exists( 'Portal_Google_Connect' ) && method_exists( 'Portal_Google_Connect', 'should_show_checklist' ) ) {
		return (bool) Portal_Google_Connect::should_show_checklist( $screen, $pending, $test_ok );
	}
	return false;
}

$out = array();
foreach ( $cases as $case ) {
	$probe = isset( $case['probe'] ) ? (string) $case['probe'] : 'activate';
	$name  = isset( $case['name'] ) ? $case['name'] : '';

	if ( 'activate_source' === $probe ) {
		$src = (string) file_get_contents( $root . '/portal-builder.php' );
		$out[] = array(
			'name'             => $name,
			'probe'            => $probe,
			'calls_on_activate' => false !== strpos( $src, 'Portal_Google_Connect::on_activate' ),
		);
		continue;
	}

	if ( 'checklist_copy' === $probe ) {
		dg_harness_apply_activate( $case );
		$items  = array();
		$markup = '';
		if ( class_exists( 'Portal_Google_Connect' ) && method_exists( 'Portal_Google_Connect', 'checklist_items' ) ) {
			$items = Portal_Google_Connect::checklist_items();
		}
		if ( class_exists( 'Portal_Google_Connect' ) && method_exists( 'Portal_Google_Connect', 'checklist_markup' ) ) {
			$markup = Portal_Google_Connect::checklist_markup();
		}
		$notice_html = '';
		if ( class_exists( 'Portal_Google_Connect' ) && method_exists( 'Portal_Google_Connect', 'notice_markup' ) ) {
			$notice_html = Portal_Google_Connect::notice_markup();
		}
		$out[] = array(
			'name'        => $name,
			'probe'       => $probe,
			'items'       => $items,
			'markup'      => $markup,
			'notice_html' => $notice_html,
		);
		continue;
	}

	dg_harness_apply_activate( $case );
	$screen   = dg_harness_screen( $case );
	$pending  = dg_harness_pending();
	$test_ok  = dg_harness_test_write();
	$out[]    = array(
		'name'      => $name,
		'probe'     => $probe,
		'screen'    => $screen,
		'pending'   => $pending,
		'test_ok'   => $test_ok,
		'notice'    => dg_harness_notice( $screen, $pending, $test_ok ),
		'checklist' => dg_harness_checklist( $screen, $pending, $test_ok ),
	);
}

echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
exit( 0 );
