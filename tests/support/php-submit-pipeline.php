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

require_once $repo_root . '/includes/class-portal-options.php';
require_once $repo_root . '/includes/Definition/class-portal-definition.php';
require_once $repo_root . '/includes/Definition/class-portal-site-defaults.php';
$legal_file = $repo_root . '/includes/Definition/class-portal-legal-disclaimers.php';
if ( is_readable( $legal_file ) ) {
	require_once $legal_file;
}
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
require_once $repo_root . '/includes/Submission/class-portal-staged-file.php';
require_once $repo_root . '/includes/Submission/class-portal-submission-pipeline.php';
require_once $repo_root . '/includes/adapters/class-portal-google-store.php';
$submit_log_file = $repo_root . '/includes/Submission/class-portal-submit-log.php';
if ( is_readable( $submit_log_file ) ) {
	require_once $submit_log_file;
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( $url ) {
		return (string) $url;
	}
}

/**
 * Mailer that throws or returns false so fail-open can be proven.
 */
class Portal_Harness_Mailer extends Portal_Mailer {
	/** @var string */
	private $fail_mode;

	/**
	 * @param string            $artifact_dir Artifact root.
	 * @param Portal_Files|null $files        Files facade.
	 * @param callable|null     $now_ms       Clock.
	 * @param string            $fail_mode    throw|false.
	 */
	public function __construct( $artifact_dir, $files, $now_ms, $fail_mode ) {
		parent::__construct( $artifact_dir, $files, $now_ms );
		$this->fail_mode = (string) $fail_mode;
	}

	/**
	 * @param array<string,mixed> $message Message.
	 * @return string|false
	 */
	public function send( array $message ) {
		if ( 'throw' === $this->fail_mode ) {
			throw new Exception( 'harness mail send failed' );
		}
		if ( 'false' === $this->fail_mode ) {
			return false;
		}
		return parent::send( $message );
	}

	/**
	 * @param array<string,mixed> $message Message.
	 * @return string|false
	 */
	public function send_operator_notify( array $message ) {
		return $this->send( $message );
	}
}

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
if ( isset( $submission['legalDisclaimers'] ) && is_array( $submission['legalDisclaimers'] ) ) {
	$GLOBALS['dg_test_legal_disclaimers'] = $submission['legalDisclaimers'];
}

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
$drive_fail      = in_array( '--drive-fail', $argv, true );
$open_state_flag = harness_flag_value( $argv, '--open-state' );
$mapping_path    = harness_flag_value( $argv, '--mapping' );
$seed_headers    = harness_decode_headers( harness_flag_value( $argv, '--seed-headers' ) );
$mail_fail       = harness_flag_value( $argv, '--mail-fail' );
$operator_email  = harness_flag_value( $argv, '--operator-email' );
$admin_email     = harness_flag_value( $argv, '--admin-email' );
$log_dir         = harness_flag_value( $argv, '--log-dir' );
if ( ! is_string( $log_dir ) || '' === $log_dir ) {
	$log_dir = $artifact . DIRECTORY_SEPARATOR . 'dg-logs';
} elseif ( ! is_absolute_path( $log_dir ) ) {
	$log_dir = $repo_root . DIRECTORY_SEPARATOR . ltrim( $log_dir, '/\\' );
}

harness_stub_mail_options( $operator_email, $admin_email );

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
	harness_clear_submit_log( $log_dir, $portal_id, $files_facade );
}
if ( $drive_fail ) {
	$drive = new Portal_Harness_Drive();
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
	if ( class_exists( 'Portal_Submit_Log' ) ) {
		$opts['log'] = new Portal_Submit_Log( $log_dir, $files_facade );
	}
	$for_post = new ReflectionMethod( 'Portal_Submission_Pipeline', 'process_for_post' );
	if ( $for_post->getNumberOfParameters() >= 4 ) {
		$result = harness_run_process(
			static function () use ( $post_id, $values, $files, $opts ) {
				return Portal_Submission_Pipeline::process_for_post( $post_id, $values, $files, $opts );
			}
		);
	} else {
		$result = harness_run_process(
			static function () use ( $post_id, $values, $files ) {
				return Portal_Submission_Pipeline::process_for_post( $post_id, $values, $files );
			}
		);
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
	$pipeline = harness_attach_log( $pipeline, $log_dir, $files_facade );
	$result   = harness_run_process(
		static function () use ( $pipeline, $portal_id, $definition, $values, $files ) {
			return $pipeline->process( $portal_id, $definition, $values, $files );
		}
	);
} else {
	$pipeline = harness_artifact_pipeline( $artifact, $files_facade, $fixed_ms, $sheets, $drive, $mail_fail, $drive_fail );
	$pipeline = harness_attach_log( $pipeline, $log_dir, $files_facade );
	if ( null !== $open_state && method_exists( $pipeline, 'with_open_state' ) ) {
		$pipeline->with_open_state( $open_state );
	}
	$result = harness_run_process(
		static function () use ( $pipeline, $portal_id, $definition, $values, $files ) {
			return $pipeline->process( $portal_id, $definition, $values, $files );
		}
	);
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
	harness_attach_log_view( $payload, $portal_id, $log_dir, $files_facade );
	fwrite( STDERR, json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

$result['artifactDir'] = $artifact;
if ( $fake_store instanceof Portal_Fake_File_Store ) {
	$result['googleStore'] = $fake_store->record();
}
harness_attach_log_view( $result, $portal_id, $log_dir, $files_facade );
echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( 0 );

/**
 * Stub get_option for operator notify resolution tests.
 *
 * @param string|null $operator_email pb_operator_notify_email, or null to leave unset.
 * @param string|null $admin_email    admin_email, or null to leave unset.
 * @return void
 */
function harness_stub_mail_options( $operator_email, $admin_email ) {
	if ( function_exists( 'get_option' ) ) {
		return;
	}
	if ( null === $operator_email && null === $admin_email ) {
		return;
	}
	$GLOBALS['dg_harness_options'] = array(
		'pb_operator_notify_email' => is_string( $operator_email ) ? $operator_email : '',
		'admin_email'              => is_string( $admin_email ) ? $admin_email : '',
	);
	/**
	 * @param string $key     Option name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $key, $default = false ) {
		$opts = isset( $GLOBALS['dg_harness_options'] ) && is_array( $GLOBALS['dg_harness_options'] )
			? $GLOBALS['dg_harness_options']
			: array();
		return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
	}
}

/**
 * Artifact pipeline, optionally wrapped in a mailer that throws or returns false.
 *
 * @param string            $artifact     Artifact root.
 * @param Portal_Files      $files        Files facade.
 * @param callable          $now_ms       Clock.
 * @param Portal_Sheet_Store $sheets      Sheet store.
 * @param object            $drive        Drive port.
 * @param string|null       $mail_fail    throw|false|null.
 * @param bool              $drive_fail   Use the injected failing Drive port.
 * @return Portal_Submission_Pipeline
 */
function harness_artifact_pipeline( $artifact, $files, $now_ms, $sheets, $drive, $mail_fail, $drive_fail = false ) {
	if ( $drive_fail || ( is_string( $mail_fail ) && '' !== $mail_fail ) ) {
		$mailer = ( is_string( $mail_fail ) && '' !== $mail_fail )
			? new Portal_Harness_Mailer( $artifact, $files, $now_ms, $mail_fail )
			: new Portal_Mailer( $artifact, $files, $now_ms );
		return new Portal_Submission_Pipeline( $sheets, $drive, $mailer, $files );
	}
	return Portal_Submission_Pipeline::for_artifacts( $artifact, $files, $now_ms );
}

/**
 * Run process(); dest throws become WP_Error after public-failure record.
 *
 * @param callable $runner Pipeline call.
 * @return mixed
 */
function harness_run_process( $runner ) {
	try {
		return $runner();
	} catch ( Exception $e ) {
		if ( class_exists( 'Portal_Submission_Pipeline' ) && method_exists( 'Portal_Submission_Pipeline', 'record_public_failure' ) ) {
			Portal_Submission_Pipeline::record_public_failure( $e );
		}
		return new WP_Error( 'dg_submission_uncaught', $e->getMessage() );
	}
}

/**
 * @param object      $pipeline Pipeline.
 * @param string      $log_dir  Log directory.
 * @param Portal_Files $files   Files facade.
 * @return object
 */
function harness_attach_log( $pipeline, $log_dir, $files ) {
	if ( ! is_object( $pipeline ) || ! method_exists( $pipeline, 'with_log' ) ) {
		return $pipeline;
	}
	if ( ! class_exists( 'Portal_Submit_Log' ) ) {
		return $pipeline;
	}
	return $pipeline->with_log( new Portal_Submit_Log( $log_dir, $files ) );
}

/**
 * @param string       $log_dir   Log directory.
 * @param string       $portal_id Portal id.
 * @param Portal_Files $files     Files facade.
 * @return void
 */
function harness_clear_submit_log( $log_dir, $portal_id, $files ) {
	if ( class_exists( 'Portal_Submit_Log' ) ) {
		$log = new Portal_Submit_Log( $log_dir, $files );
		if ( method_exists( $log, 'clear_portal' ) ) {
			$log->clear_portal( $portal_id );
			return;
		}
	}
	$path = rtrim( (string) $log_dir, '/\\' ) . DIRECTORY_SEPARATOR . $portal_id . '.jsonl';
	if ( $files->exists( $path ) ) {
		$files->remove( $path );
	}
}

/**
 * Surface last-N log rows and public error HTML on the harness payload.
 *
 * @param array        $payload   Payload.
 * @param string       $portal_id Portal id.
 * @param string       $log_dir   Log directory.
 * @param Portal_Files $files     Files facade.
 * @return void
 */
function harness_attach_log_view( array &$payload, $portal_id, $log_dir, $files ) {
	$payload['logRows']    = array();
	$payload['adminRows']  = array();
	$payload['adminHtml']  = '';
	$payload['publicHtml'] = '';
	$payload['lastErrors'] = null;
	$payload['logPath']    = rtrim( (string) $log_dir, '/\\' ) . DIRECTORY_SEPARATOR . $portal_id . '.jsonl';
	if ( class_exists( 'Portal_Submit_Log' ) ) {
		$log                   = new Portal_Submit_Log( $log_dir, $files );
		$payload['logPath']    = $log->path_for( $portal_id );
		$payload['logRows']    = $log->last( $portal_id, 20 );
		$payload['adminRows']  = $payload['logRows'];
		$payload['adminHtml']  = $log->render_admin( $portal_id );
	} elseif ( $files->exists( $payload['logPath'] ) ) {
		$text = trim( $files->read_text( $payload['logPath'] ) );
		if ( '' !== $text ) {
			foreach ( explode( "\n", $text ) as $line ) {
				$decoded = json_decode( trim( $line ), true );
				if ( is_array( $decoded ) ) {
					$payload['logRows'][] = $decoded;
				}
			}
			$payload['adminRows'] = $payload['logRows'];
		}
	}
	if ( class_exists( 'Portal_Submission_Pipeline' ) && method_exists( 'Portal_Submission_Pipeline', 'last_errors' ) ) {
		$errors = Portal_Submission_Pipeline::last_errors();
		if ( is_array( $errors ) ) {
			$payload['lastErrors'] = $errors;
			$payload['publicHtml'] = Portal_Submission_Pipeline::render_errors( $errors );
		}
	}
}

/**
 * Drive port that throws a Google-shaped error (no retry).
 */
class Portal_Harness_Drive {
	/**
	 * @param string $id Submission id.
	 * @return void
	 */
	public function set_submission_id( $id ) {
	}

	/**
	 * @param string|int $portal_id Portal id.
	 * @return void
	 */
	public function ensure_application_folder( $portal_id ) {
	}

	/**
	 * @return string
	 */
	public function folder_url() {
		return '';
	}

	/**
	 * @param string|int $portal_id Portal id.
	 * @param string     $field_id  Field id.
	 * @param string     $buffer    Bytes.
	 * @param string     $filename  Filename.
	 * @return string
	 */
	public function store_file( $portal_id, $field_id, $buffer, $filename ) {
		throw new Exception( 'Google Drive write failed: {"error":"invalid_grant","error_description":"Token has been expired or revoked."}' );
	}
}

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
