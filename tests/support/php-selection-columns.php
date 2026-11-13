<?php
/**
 * CLI harness: Portal_Submission_Selections::columns → JSON.
 *
 * Usage:
 *   php tests/support/php-selection-columns.php <definition.json> [values.json]
 *
 * values.json is a flat object of field ids (or sub_* keys) → option ids.
 * When omitted, values are empty.
 */

// phpcs:disable
class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
}
function is_wp_error( $t ) {
	return $t instanceof WP_Error;
}
function wp_json_encode( $d ) {
	return json_encode( $d );
}

$repo_root = dirname( __DIR__, 2 );

require_once $repo_root . '/includes/Definition/class-portal-definition.php';
require_once $repo_root . '/includes/Submission/class-portal-submission-field-rules.php';
require_once $repo_root . '/includes/Submission/class-portal-submission-selections.php';

$def_path    = $argv[1] ?? '';
$values_path = $argv[2] ?? '';

if ( ! is_readable( $def_path ) ) {
	fwrite( STDERR, "unreadable definition: $def_path\n" );
	exit( 2 );
}

$definition = Portal_Definition::from_json( file_get_contents( $def_path ) );
if ( is_wp_error( $definition ) ) {
	fwrite( STDERR, $definition->get_error_code() . ': ' . $definition->get_error_message() . "\n" );
	exit( 1 );
}

$values = array();
if ( '' !== $values_path ) {
	if ( ! is_readable( $values_path ) ) {
		fwrite( STDERR, "unreadable values: $values_path\n" );
		exit( 2 );
	}
	$decoded = json_decode( file_get_contents( $values_path ), true );
	if ( ! is_array( $decoded ) ) {
		fwrite( STDERR, "values must be a JSON object\n" );
		exit( 2 );
	}
	// Accept either a bare values map or a submission envelope with "values".
	$values = isset( $decoded['values'] ) && is_array( $decoded['values'] )
		? $decoded['values']
		: $decoded;
}

$columns = Portal_Submission_Selections::columns( $definition, $values );
// Empty PHP array encodes as [] — emit {} so consumers see an object map.
$payload = empty( $columns ) ? new stdClass() : $columns;
echo json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
exit( 0 );
