<?php
/**
 * CLI harness: dest-column named values, header extend/align, cells_for_sheet.
 *
 * Usage:
 *   php tests/support/php-sheet-headers.php named <definition.json> <row.json> <sheet>
 *   php tests/support/php-sheet-headers.php extend <headers.json> <named.json>
 *   php tests/support/php-sheet-headers.php align <named.json> <headers.json>
 *   php tests/support/php-sheet-headers.php cells <definition.json> <row.json> <sheet> [headers.json]
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
require_once $repo_root . '/includes/Submission/class-portal-submission-destinations.php';

$op = isset( $argv[1] ) ? (string) $argv[1] : '';

try {
	$result = run_sheet_headers_op( $op, array_slice( $argv, 2 ) );
} catch ( Exception $e ) {
	fwrite( STDERR, $e->getMessage() . "\n" );
	exit( 1 );
}

echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
exit( 0 );

/**
 * @param string $op   named|extend|align|cells.
 * @param array  $args Remaining argv.
 * @return mixed
 */
function run_sheet_headers_op( $op, array $args ) {
	if ( 'named' === $op ) {
		require_dest_method( 'named_values_for_sheet' );
		$definition = load_definition( isset( $args[0] ) ? $args[0] : '' );
		$row        = load_json_object( isset( $args[1] ) ? $args[1] : '', 'row' );
		$sheet      = isset( $args[2] ) ? (string) $args[2] : 'Housekeeping';
		return Portal_Submission_Destinations::named_values_for_sheet( $definition, $row, $sheet );
	}
	if ( 'extend' === $op ) {
		require_dest_method( 'extend_headers' );
		$headers = load_json_array( isset( $args[0] ) ? $args[0] : '', 'headers' );
		$named   = load_json_object( isset( $args[1] ) ? $args[1] : '', 'named' );
		return Portal_Submission_Destinations::extend_headers( $headers, $named );
	}
	if ( 'align' === $op ) {
		require_dest_method( 'align_to_headers' );
		$named   = load_json_object( isset( $args[0] ) ? $args[0] : '', 'named' );
		$headers = load_json_array( isset( $args[1] ) ? $args[1] : '', 'headers' );
		return Portal_Submission_Destinations::align_to_headers( $named, $headers );
	}
	if ( 'cells' === $op ) {
		$definition = load_definition( isset( $args[0] ) ? $args[0] : '' );
		$row        = load_json_object( isset( $args[1] ) ? $args[1] : '', 'row' );
		$sheet      = isset( $args[2] ) ? (string) $args[2] : 'Housekeeping';
		if ( isset( $args[3] ) && '' !== $args[3] ) {
			require_dest_method( 'cells_for_sheet' );
			$headers = load_json_array( $args[3], 'headers' );
			return Portal_Submission_Destinations::cells_for_sheet( $definition, $row, $sheet, $headers );
		}
		return Portal_Submission_Destinations::cells_for_sheet( $definition, $row, $sheet );
	}
	throw new Exception( 'usage: named|extend|align|cells' );
}

/**
 * @param string $method Destinations method name.
 * @return void
 */
function require_dest_method( $method ) {
	if ( ! method_exists( 'Portal_Submission_Destinations', $method ) ) {
		throw new Exception( 'missing ' . $method );
	}
}

/**
 * @param string $path Definition JSON path.
 * @return array
 */
function load_definition( $path ) {
	if ( ! is_readable( $path ) ) {
		throw new Exception( 'unreadable definition: ' . $path );
	}
	$definition = Portal_Definition::from_json( file_get_contents( $path ) );
	if ( is_wp_error( $definition ) ) {
		throw new Exception( $definition->get_error_code() . ': ' . $definition->get_error_message() );
	}
	return $definition;
}

/**
 * @param string $path Path.
 * @param string $label Error label.
 * @return array
 */
function load_json_object( $path, $label ) {
	$data = load_json_file( $path, $label );
	if ( ! is_array( $data ) ) {
		throw new Exception( $label . ' must be a JSON object' );
	}
	return $data;
}

/**
 * @param string $path Path.
 * @param string $label Error label.
 * @return array
 */
function load_json_array( $path, $label ) {
	$data = load_json_file( $path, $label );
	if ( ! is_array( $data ) ) {
		throw new Exception( $label . ' must be a JSON array' );
	}
	return array_values( $data );
}

/**
 * @param string $path Path.
 * @param string $label Error label.
 * @return mixed
 */
function load_json_file( $path, $label ) {
	if ( ! is_readable( $path ) ) {
		throw new Exception( 'unreadable ' . $label . ': ' . $path );
	}
	$decoded = json_decode( file_get_contents( $path ), true );
	if ( JSON_ERROR_NONE !== json_last_error() ) {
		throw new Exception( $label . ' is not valid JSON' );
	}
	return $decoded;
}
