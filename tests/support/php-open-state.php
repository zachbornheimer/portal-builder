<?php
/**
 * CLI harness: exercise Portal_Open_State pure logic without WordPress.
 *
 * Usage:
 *   php tests/support/php-open-state.php <cases.json>
 *
 * cases.json: array of { name, publish, post_status, now, expect_open }
 * now is ISO-8601 string interpreted in publish.timezone.
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

require_once dirname( __DIR__, 2 ) . '/includes/Definition/class-portal-open-state.php';

$path = $argv[1] ?? '';
if ( ! is_readable( $path ) ) {
	fwrite( STDERR, "unreadable $path\n" );
	exit( 2 );
}

$cases = json_decode( file_get_contents( $path ), true );
if ( ! is_array( $cases ) ) {
	fwrite( STDERR, "cases must be a JSON array\n" );
	exit( 2 );
}

$results = array();
$failed  = 0;

foreach ( $cases as $i => $case ) {
	$name        = isset( $case['name'] ) ? $case['name'] : "case_$i";
	$publish     = isset( $case['publish'] ) && is_array( $case['publish'] ) ? $case['publish'] : array();
	$post_status = isset( $case['post_status'] ) ? $case['post_status'] : 'publish';
	$expect      = ! empty( $case['expect_open'] );
	$now         = null;

	if ( ! empty( $case['now'] ) ) {
		$tz_name = isset( $publish['timezone'] ) ? $publish['timezone'] : Portal_Open_State::DEFAULT_TIMEZONE;
		try {
			$tz  = new DateTimeZone( $tz_name );
			$now = new DateTime( $case['now'], $tz );
		} catch ( Exception $e ) {
			fwrite( STDERR, "$name: bad now: {$e->getMessage()}\n" );
			exit( 2 );
		}
	}

	$got = Portal_Open_State::is_open_from_publish( $publish, $post_status, $now );
	$ok  = ( $got === $expect );
	if ( ! $ok ) {
		++$failed;
	}
	$results[] = array(
		'name'        => $name,
		'ok'          => $ok,
		'got'         => $got,
		'expect_open' => $expect,
	);
}

echo json_encode(
	array(
		'ok'      => 0 === $failed,
		'failed'  => $failed,
		'results' => $results,
	),
	JSON_PRETTY_PRINT
) . "\n";

exit( 0 === $failed ? 0 : 1 );
