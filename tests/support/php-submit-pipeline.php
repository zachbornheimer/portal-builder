<?php
/**
 * CLI harness: definition-aware submit → mock Sheet / Drive / Mail artifacts.
 *
 * Usage:
 *   php tests/support/php-submit-pipeline.php \
 *     <definition.json> <submission.json> [artifactDir] [portalId]
 *
 * submission.json shape:
 * {
 *   "portalId": "herbolzheimer",
 *   "values": { "sub_name": "...", "sub_work_title": "..." },
 *   "files": {
 *     "score": { "name": "sample-score.pdf", "path": "tests/fixtures/files/sample-score.pdf" }
 *   }
 * }
 *
 * Paths in files.*.path are resolved relative to the repo root when not absolute.
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
require_once $repo_root . '/includes/Submission/class-portal-files.php';
require_once $repo_root . '/includes/Submission/class-portal-test-mode.php';
require_once $repo_root . '/includes/Submission/class-portal-sheet-store.php';
require_once $repo_root . '/includes/Submission/class-portal-drive-store.php';
require_once $repo_root . '/includes/Submission/class-portal-mailer.php';
require_once $repo_root . '/includes/Submission/class-portal-submission-field-rules.php';
require_once $repo_root . '/includes/Submission/class-portal-submission-validator.php';
require_once $repo_root . '/includes/Submission/class-portal-submission-pipeline.php';

$def_path  = $argv[1] ?? '';
$sub_path  = $argv[2] ?? '';
$artifact  = $argv[3] ?? null;
$portal_id = $argv[4] ?? null;

if ( ! is_readable( $def_path ) ) {
	fwrite( STDERR, "unreadable definition: $def_path\n" );
	exit( 2 );
}
if ( ! is_readable( $sub_path ) ) {
	fwrite( STDERR, "unreadable submission: $sub_path\n" );
	exit( 2 );
}

$definition = Portal_Definition::from_json( file_get_contents( $def_path ) );
if ( is_wp_error( $definition ) ) {
	fwrite( STDERR, $definition->get_error_code() . ': ' . $definition->get_error_message() . "\n" );
	exit( 1 );
}

$submission = json_decode( file_get_contents( $sub_path ), true );
if ( ! is_array( $submission ) ) {
	fwrite( STDERR, "submission must be a JSON object\n" );
	exit( 2 );
}

$values = isset( $submission['values'] ) && is_array( $submission['values'] )
	? $submission['values']
	: array();
$files_in = isset( $submission['files'] ) && is_array( $submission['files'] )
	? $submission['files']
	: array();

// Resolve relative file paths against repo root.
$files = array();
foreach ( $files_in as $field_id => $meta ) {
	if ( ! is_array( $meta ) ) {
		continue;
	}
	if ( isset( $meta['path'] ) && is_string( $meta['path'] ) && ! is_absolute_path( $meta['path'] ) ) {
		$meta['path'] = $repo_root . DIRECTORY_SEPARATOR . ltrim( $meta['path'], '/\\' );
	}
	if ( empty( $meta['name'] ) && isset( $meta['path'] ) ) {
		$meta['name'] = basename( $meta['path'] );
	}
	$files[ $field_id ] = $meta;
}

if ( null === $artifact || '' === $artifact ) {
	$artifact = Portal_Test_Mode::artifact_dir( $repo_root );
} elseif ( ! is_absolute_path( $artifact ) ) {
	$artifact = $repo_root . DIRECTORY_SEPARATOR . ltrim( $artifact, '/\\' );
}

if ( null === $portal_id || '' === $portal_id ) {
	$portal_id = isset( $submission['portalId'] ) ? (string) $submission['portalId'] : 'cli-submit';
}

$append_only = in_array( '--append', $argv, true );

// Isolate this portal's prior artifacts for a clean run (unless --append).
$files_facade = new Portal_Files();
$sheets       = new Portal_Sheet_Store( $artifact, $files_facade );
$drive        = new Portal_Drive_Store( $artifact, $files_facade );
if ( ! $append_only ) {
	$sheets->clear_portal( $portal_id );
	$drive->clear_portal( $portal_id );
}

// Deterministic mail filename for assertions.
$fixed_ms = static function () {
	return 1700000000000;
};

$pipeline = Portal_Submission_Pipeline::for_artifacts( $artifact, $files_facade, $fixed_ms );
$result   = $pipeline->process( $portal_id, $definition, $values, $files );

if ( is_wp_error( $result ) ) {
	$payload = array(
		'ok'      => false,
		'code'    => $result->get_error_code(),
		'message' => $result->get_error_message(),
		'data'    => $result->get_error_data(),
	);
	fwrite( STDERR, json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

$result['artifactDir'] = $artifact;
echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( 0 );

/**
 * @param string $path Path.
 * @return bool
 */
function is_absolute_path( $path ) {
	if ( '' === $path ) {
		return false;
	}
	if ( '/' === $path[0] || '\\' === $path[0] ) {
		return true;
	}
	return (bool) preg_match( '/^[A-Za-z]:[\\\\\\/]/', $path );
}
