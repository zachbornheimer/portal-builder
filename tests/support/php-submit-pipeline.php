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
require_once $repo_root . '/includes/Definition/class-portal-site-defaults.php';
require_once $repo_root . '/includes/Submission/class-portal-files.php';
require_once $repo_root . '/includes/Submission/class-portal-test-mode.php';
require_once $repo_root . '/includes/Submission/class-portal-sheet-store.php';
require_once $repo_root . '/includes/Submission/class-portal-drive-store.php';
require_once $repo_root . '/includes/Submission/class-portal-mailer.php';
require_once $repo_root . '/includes/Submission/class-portal-receipt.php';
require_once $repo_root . '/includes/Submission/class-portal-submission-field-rules.php';
require_once $repo_root . '/includes/Submission/class-portal-submission-validator.php';
require_once $repo_root . '/includes/Submission/class-portal-submission-selections.php';
require_once $repo_root . '/includes/Submission/class-portal-submit-admission.php';
require_once $repo_root . '/includes/Submission/class-portal-submission-destinations.php';
require_once $repo_root . '/includes/Submission/class-portal-submission-pipeline.php';
require_once $repo_root . '/includes/adapters/class-portal-google-store.php';

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

$append_only     = in_array( '--append', $argv, true );
$via_for_post    = in_array( '--via-for-post', $argv, true );
$live_google     = in_array( '--live-google', $argv, true );
$open_state_flag = harness_flag_value( $argv, '--open-state' );
$mapping_path    = harness_flag_value( $argv, '--mapping' );
$seed_headers    = harness_decode_headers( harness_flag_value( $argv, '--seed-headers' ) );

if ( is_string( $mapping_path ) && '' !== $mapping_path ) {
	if ( ! is_absolute_path( $mapping_path ) ) {
		$mapping_path = $repo_root . DIRECTORY_SEPARATOR . ltrim( $mapping_path, '/\\' );
	}
	if ( ! is_readable( $mapping_path ) ) {
		fwrite( STDERR, "unreadable mapping: $mapping_path\n" );
		exit( 2 );
	}
	$mapping_overlay = json_decode( file_get_contents( $mapping_path ), true );
	if ( ! is_array( $mapping_overlay ) ) {
		fwrite( STDERR, "mapping must be a JSON object\n" );
		exit( 2 );
	}
	$raw_def = json_decode( file_get_contents( $def_path ), true );
	if ( ! is_array( $raw_def ) ) {
		fwrite( STDERR, "definition must be a JSON object to overlay mapping\n" );
		exit( 2 );
	}
	$raw_def['mapping'] = $mapping_overlay;
	$definition         = Portal_Definition::validate( $raw_def );
	if ( is_wp_error( $definition ) ) {
		fwrite( STDERR, $definition->get_error_code() . ': ' . $definition->get_error_message() . "\n" );
		exit( 1 );
	}
}

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

$fake_store = null;
$open_state = null;
if ( is_string( $open_state_flag ) && '' !== $open_state_flag ) {
	$open_state = harness_open_state( $open_state_flag );
}

if ( $via_for_post ) {
	$GLOBALS['dg_harness_definition_json'] = wp_json_encode( $definition );
	if ( ! function_exists( 'get_post_meta' ) ) {
		function get_post_meta( $post_id, $key, $single = false ) {
			if ( '_portal_definition' === $key && ! empty( $GLOBALS['dg_harness_definition_json'] ) ) {
				return $GLOBALS['dg_harness_definition_json'];
			}
			return $single ? '' : array();
		}
	}
	$post_id = (int) $portal_id;
	if ( $post_id <= 0 ) {
		$post_id = 42;
	}
	$opts = array();
	if ( $live_google ) {
		$fake_store         = new Portal_Fake_File_Store( $seed_headers );
		$opts['file_store'] = $fake_store;
	}
	if ( null !== $open_state ) {
		$opts['open_state'] = $open_state;
	}
	$for_post = new ReflectionMethod( 'Portal_Submission_Pipeline', 'process_for_post' );
	if ( $for_post->getNumberOfParameters() >= 4 ) {
		$result = Portal_Submission_Pipeline::process_for_post( $post_id, $values, $files, $opts );
	} else {
		$result = Portal_Submission_Pipeline::process_for_post( $post_id, $values, $files );
	}
} elseif ( $live_google && method_exists( 'Portal_Submission_Pipeline', 'for_live' ) ) {
	$fake_store = new Portal_Fake_File_Store( $seed_headers );
	$pipeline   = Portal_Submission_Pipeline::for_live(
		$definition,
		$files_facade,
		$fixed_ms,
		$fake_store,
		$open_state
	);
	$result     = $pipeline->process( $portal_id, $definition, $values, $files );
} else {
	$pipeline = Portal_Submission_Pipeline::for_artifacts( $artifact, $files_facade, $fixed_ms );
	if ( null !== $open_state && method_exists( $pipeline, 'with_open_state' ) ) {
		$pipeline->with_open_state( $open_state );
	}
	$result = $pipeline->process( $portal_id, $definition, $values, $files );
}

if ( is_wp_error( $result ) ) {
	$payload = array(
		'ok'      => false,
		'code'    => $result->get_error_code(),
		'message' => $result->get_error_message(),
		'data'    => $result->get_error_data(),
	);
	if ( $fake_store instanceof Portal_Fake_File_Store ) {
		$payload['googleStore'] = $fake_store->record();
	}
	fwrite( STDERR, json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

$result['artifactDir'] = $artifact;
if ( $fake_store instanceof Portal_Fake_File_Store ) {
	$result['googleStore'] = $fake_store->record();
}
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

/**
 * @param array  $argv Argv.
 * @param string $name Flag name including leading dashes.
 * @return string|null
 */
function harness_flag_value( array $argv, $name ) {
	$prefix = $name . '=';
	foreach ( $argv as $arg ) {
		if ( 0 === strpos( (string) $arg, $prefix ) ) {
			return substr( (string) $arg, strlen( $prefix ) );
		}
	}
	return null;
}

/**
 * @param string $flag closed|preview|open.
 * @return array{preview:bool,open:bool}
 */
function harness_open_state( $flag ) {
	if ( 'preview' === $flag ) {
		return array(
			'preview' => true,
			'open'    => true,
		);
	}
	if ( 'closed' === $flag ) {
		return array(
			'preview' => false,
			'open'    => false,
		);
	}
	return array(
		'preview' => false,
		'open'    => true,
	);
}

/**
 * @param string|null $raw JSON array of header strings.
 * @return array
 */
function harness_decode_headers( $raw ) {
	if ( ! is_string( $raw ) || '' === $raw ) {
		return array();
	}
	$decoded = json_decode( $raw, true );
	if ( ! is_array( $decoded ) ) {
		return array();
	}
	return array_values( $decoded );
}

/**
 * Records Zysys_FileStore verbs without hitting Google.
 */
class Portal_Fake_File_Store {
	public $calls   = array();
	public $headers = array();
	private $file_seq   = 0;
	private $folder_seq = 0;

	/**
	 * @param array $headers Seeded A1 header row.
	 */
	public function __construct( $headers = array() ) {
		$this->headers = is_array( $headers ) ? array_values( $headers ) : array();
	}

	public function gsheet( $id ) {
		$this->calls[] = array(
			'op' => 'gsheet',
			'id' => (string) $id,
		);
	}

	public function gsheet_row( $sheet, $location ) {
		$this->calls[] = array(
			'op'       => 'gsheet_row',
			'sheet'    => (string) $sheet,
			'location' => (string) $location,
		);
	}

	public function add_row( ...$cells ) {
		$this->calls[] = array(
			'op'    => 'add_row',
			'cells' => array_values( $cells ),
		);
	}

	public function read_range( $range ) {
		$this->calls[] = array(
			'op'    => 'read_range',
			'range' => (string) $range,
		);
		if ( empty( $this->headers ) ) {
			return array();
		}
		return array( $this->headers );
	}

	public function update_values( $range, array $row ) {
		$this->headers = array_values( $row );
		$this->calls[] = array(
			'op'    => 'update_values',
			'range' => (string) $range,
			'row'   => $this->headers,
		);
	}

	public function drive_parent( $id ) {
		$this->calls[] = array(
			'op' => 'drive_parent',
			'id' => (string) $id,
		);
	}

	public function create_drive_subfolder( $name, $switch_to = false ) {
		++$this->folder_seq;
		$id = 'folder_fake_' . $this->folder_seq;
		$this->calls[] = array(
			'op'   => 'create_drive_subfolder',
			'name' => (string) $name,
			'id'   => $id,
		);
		return $id;
	}

	public function store_drive_file( $filepath ) {
		++$this->file_seq;
		$id = 'file_fake_' . $this->file_seq;
		$this->calls[] = array(
			'op'   => 'store_drive_file',
			'path' => (string) $filepath,
			'id'   => $id,
		);
		return $id;
	}

	public function get_drive_parent_id() {
		return 'folder_fake_parent';
	}

	public function __get( $key ) {
		return null;
	}

	/**
	 * @return array{calls:array,spreadsheetIds:array,cells:array,driveFolders:array,driveFiles:array}
	 */
	public function record() {
		$sheets  = array();
		$cells   = array();
		$folders = array();
		$files   = array();
		foreach ( $this->calls as $call ) {
			if ( 'gsheet' === $call['op'] ) {
				$sheets[] = $call['id'];
			}
			if ( 'add_row' === $call['op'] ) {
				$cells[] = $call['cells'];
			}
			if ( 'drive_parent' === $call['op'] ) {
				$folders[] = $call['id'];
			}
			if ( 'store_drive_file' === $call['op'] ) {
				$files[] = $call;
			}
		}
		return array(
			'calls'          => $this->calls,
			'spreadsheetIds' => $sheets,
			'cells'          => $cells,
			'driveFolders'   => $folders,
			'driveFiles'     => $files,
			'headers'        => $this->headers,
		);
	}
}
