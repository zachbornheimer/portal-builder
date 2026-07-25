<?php
/**
 * CLI harness: validate a definition JSON file without bootstrapping WordPress.
 * Polyfills WP_Error and wp_json_encode only.
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

require_once dirname( __DIR__, 2 ) . '/includes/Definition/class-portal-definition.php';

$path = $argv[1] ?? '';
if ( ! is_readable( $path ) ) {
	fwrite( STDERR, "unreadable $path\n" );
	exit( 2 );
}
$raw  = file_get_contents( $path );
$result = Portal_Definition::from_json( $raw );
if ( is_wp_error( $result ) ) {
	fwrite( STDERR, $result->code . ': ' . $result->message . "\n" );
	exit( 1 );
}
echo json_encode( array( 'ok' => true, 'definition' => $result ), JSON_PRETTY_PRINT ) . "\n";
exit( 0 );
