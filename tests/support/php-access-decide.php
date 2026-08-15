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
$access    = isset( $payload['access'] ) && is_array( $payload['access'] ) ? $payload['access'] : array();
$options   = isset( $payload['options'] ) && is_array( $payload['options'] ) ? $payload['options'] : array();
$applicant = isset( $payload['applicant'] ) && is_array( $payload['applicant'] ) ? $payload['applicant'] : array();
echo json_encode( Portal_Access::decide( $access, $options, $applicant ) ) . "\n";
exit( 0 );
