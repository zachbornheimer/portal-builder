<?php
/**
 * CLI harness: public submit failure surface, staged/tmp purge, upload roots.
 *
 * Usage:
 *   php tests/support/php-public-submit-hardening.php <scenario.json>
 *
 * scenario.entry:
 *   inspect | public_failure | purge | upload_roots | schedule
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
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $s ) {
	return (string) $s;
}
function wp_die( $message = '', $title = '', $args = array() ) {
	unset( $title, $args );
	$GLOBALS['wp_died']        = true;
	$GLOBALS['wp_die_message'] = is_scalar( $message ) ? (string) $message : '';
	throw new RuntimeException( 'wp_die:' . $GLOBALS['wp_die_message'] );
}
function wp_next_scheduled( $hook ) {
	$scheduled = isset( $GLOBALS['scheduled_hooks'] ) ? $GLOBALS['scheduled_hooks'] : array();
	return isset( $scheduled[ $hook ] ) ? $scheduled[ $hook ] : false;
}
function wp_schedule_event( $timestamp, $recurrence, $hook ) {
	$GLOBALS['scheduled_hooks'][ $hook ] = array(
		'timestamp'  => (int) $timestamp,
		'recurrence' => (string) $recurrence,
		'hook'       => (string) $hook,
	);
	return true;
}
function wp_clear_scheduled_hook( $hook ) {
	$GLOBALS['cleared_hooks'][] = (string) $hook;
	if ( isset( $GLOBALS['scheduled_hooks'][ $hook ] ) ) {
		unset( $GLOBALS['scheduled_hooks'][ $hook ] );
	}
}
function add_action() {}
function add_filter( $tag = '', $fn = null, $priority = 10, $accepted = 1 ) {
	unset( $priority, $accepted );
	$GLOBALS['added_filters'][] = array(
		'tag' => (string) $tag,
		'fn'  => $fn,
	);
}
function wp_upload_dir() {
	$basedir = isset( $GLOBALS['upload_basedir'] ) ? (string) $GLOBALS['upload_basedir'] : sys_get_temp_dir();
	return array(
		'basedir' => $basedir,
		'baseurl' => 'https://example.test/wp-content/uploads',
	);
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/dg-fake-webroot/' );
}
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
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
require_once $repo_root . '/includes/Submission/class-portal-file-signature.php';
require_once $repo_root . '/includes/Submission/class-portal-anonymizer-transport.php';
require_once $repo_root . '/includes/Submission/class-portal-anonymizer-reply.php';
require_once $repo_root . '/includes/Submission/class-portal-anonymizer-api.php';
require_once $repo_root . '/includes/Submission/class-portal-anonymizer.php';
require_once $repo_root . '/includes/Submission/class-portal-submission-pipeline.php';

$staged_unit = $repo_root . '/includes/Submission/class-portal-staged-file.php';
if ( is_readable( $staged_unit ) ) {
	require_once $staged_unit;
}
$store_unit = $repo_root . '/includes/Submission/class-portal-upload-store.php';
if ( is_readable( $store_unit ) ) {
	require_once $store_unit;
}

$scenario_path = $argv[1] ?? '';
if ( ! is_readable( $scenario_path ) ) {
	fwrite( STDERR, "unreadable scenario: $scenario_path\n" );
	exit( 2 );
}

$scenario = json_decode( file_get_contents( $scenario_path ), true );
if ( ! is_array( $scenario ) ) {
	fwrite( STDERR, "scenario must be a JSON object\n" );
	exit( 2 );
}

$entry = isset( $scenario['entry'] ) ? (string) $scenario['entry'] : 'inspect';

if ( 'inspect' === $entry ) {
	echo json_encode( inspect_public_submit_sources( $repo_root ) ) . "\n";
	exit( 0 );
}

if ( 'public_failure' === $entry ) {
	echo json_encode( run_public_failure( $scenario, $repo_root ) ) . "\n";
	exit( 0 );
}

if ( 'purge' === $entry ) {
	echo json_encode( run_purge( $scenario ) ) . "\n";
	exit( 0 );
}

if ( 'upload_roots' === $entry ) {
	echo json_encode( run_upload_roots( $scenario, $repo_root ) ) . "\n";
	exit( 0 );
}

if ( 'schedule' === $entry ) {
	echo json_encode( run_schedule() ) . "\n";
	exit( 0 );
}

if ( 'legacy_submit_failure' === $entry ) {
	echo json_encode( run_legacy_submit_failure( $scenario, $repo_root ) ) . "\n";
	exit( 0 );
}

fwrite( STDERR, "unknown entry: $entry\n" );
exit( 2 );

/**
 * @param string $repo_root Plugin root.
 * @return array<string,mixed>
 */
function inspect_public_submit_sources( $repo_root ) {
	$plugin     = (string) file_get_contents( $repo_root . '/portal-builder.php' );
	$submission = (string) file_get_contents( $repo_root . '/includes/class-portal-submission.php' );
	$store_path = $repo_root . '/includes/Submission/class-portal-upload-store.php';
	$store_src  = is_readable( $store_path ) ? (string) file_get_contents( $store_path ) : '';
	$wired      = $plugin . "\n" . $store_src;
	$catch      = extract_handle_submissions_catch( $plugin );

	$public_wp_die_raw = (bool) preg_match( '/wp_die\s*\(\s*\$e\s*->\s*getMessage\s*\(/', $catch );
	$public_rethrows   = (bool) preg_match( '/throw\s+\$e\b/', $catch );
	$public_records    = (bool) preg_match(
		'/pb_record_public_submit_failure|record_public_failure|mark_public_errors/',
		$catch
	);
	$submission_raw    = (bool) preg_match( '/wp_die\s*\(\s*\$e\s*->\s*getMessage\s*\(/', $submission );
	$abspath_tmp       = (bool) preg_match(
		'/ABSPATH\s*\.\s*(PB_RELATIVE_TMP_UPLOADS_DIR|[\'"]\/tmp-uploads\/)/',
		$plugin
	);
	$abspath_store     = (bool) preg_match(
		'/ABSPATH\s*\.\s*(PB_RELATIVE_PERMANENT_UPLOADS_DIR|[\'"]\/file-storage\/)/',
		$plugin
	);
	$schedules         = (bool) preg_match( '/wp_schedule_event\s*\(/', $wired );
	$clears            = (bool) preg_match( '/wp_clear_scheduled_hook\s*\(/', $wired );
	$cleanup_hook      = (bool) preg_match( '/purge|cleanup|dg_purge_staged/', $wired );
	$unconditional     = (bool) preg_match(
		'/process_submission\s*\([^;]+\)\s*;\s*define\s*\(\s*[\'"]PB_RECEIPT_LINK/',
		$plugin
	);

	return array(
		'ok'                      => true,
		'publicCatchDiesRaw'      => $public_wp_die_raw,
		'publicCatchRethrows'     => $public_rethrows,
		'publicCatchRecordsHuman' => $public_records,
		'submissionDiesRaw'       => $submission_raw,
		'abspathTmpConcat'        => $abspath_tmp,
		'abspathStoreConcat'      => $abspath_store,
		'schedulesCleanup'        => $schedules,
		'clearsCleanup'           => $clears,
		'mentionsCleanupHook'     => $cleanup_hook,
		'hasUploadStore'          => class_exists( 'Portal_Upload_Store' ),
		'hasRecordPublicFailure'  => class_exists( 'Portal_Submission_Pipeline' )
			&& method_exists( 'Portal_Submission_Pipeline', 'record_public_failure' ),
		'hasPurgeExpired'            => has_purge_owner(),
		'unconditionalLegacySuccess' => $unconditional,
		'hasFinishPublicSubmit'      => class_exists( 'Portal_Submission_Pipeline' )
			&& method_exists( 'Portal_Submission_Pipeline', 'finish_public_submit' ),
	);
}

/**
 * @param string $plugin portal-builder.php source.
 * @return string
 */
function extract_handle_submissions_catch( $plugin ) {
	if ( ! preg_match( '/function\s+handle_submissions\s*\(/', $plugin, $m, PREG_OFFSET_CAPTURE ) ) {
		return '';
	}
	$from  = (int) $m[0][1];
	$chunk = substr( $plugin, $from );
	if ( ! preg_match( '/\}\s*catch\s*\(\s*Exception\s+\$e\s*\)\s*\{/', $chunk, $cm, PREG_OFFSET_CAPTURE ) ) {
		return '';
	}
	$catch_at = (int) $cm[0][1];
	$rest     = substr( $chunk, $catch_at );
	$end      = strpos( $rest, "\n}" );
	return false === $end ? $rest : substr( $rest, 0, $end );
}

/**
 * @return bool
 */
function has_purge_owner() {
	if ( class_exists( 'Portal_Upload_Store' ) && method_exists( 'Portal_Upload_Store', 'purge_expired' ) ) {
		return true;
	}
	return class_exists( 'Portal_Staged_File' ) && method_exists( 'Portal_Staged_File', 'purge_expired' );
}

/**
 * @return callable|null
 */
function purge_callable() {
	if ( class_exists( 'Portal_Upload_Store' ) && method_exists( 'Portal_Upload_Store', 'purge_expired' ) ) {
		return array( 'Portal_Upload_Store', 'purge_expired' );
	}
	if ( class_exists( 'Portal_Staged_File' ) && method_exists( 'Portal_Staged_File', 'purge_expired' ) ) {
		return array( 'Portal_Staged_File', 'purge_expired' );
	}
	return null;
}

/**
 * @param array  $scenario Scenario.
 * @param string $repo_root Plugin root.
 * @return array<string,mixed>
 */
function run_public_failure( array $scenario, $repo_root ) {
	$inspect = inspect_public_submit_sources( $repo_root );
	if ( ! class_exists( 'Portal_Submission_Pipeline' )
		|| ! method_exists( 'Portal_Submission_Pipeline', 'record_public_failure' ) ) {
		return array(
			'ok'                 => false,
			'code'               => 'missing_record_public_failure',
			'message'            => 'Portal_Submission_Pipeline::record_public_failure is not available',
			'publicCatchDiesRaw' => $inspect['publicCatchDiesRaw'],
			'submissionDiesRaw'  => $inspect['submissionDiesRaw'],
			'died'               => false,
			'definedErrors'      => defined( 'DG_DEFINITION_SUBMIT_ERRORS' ),
		);
	}

	$artifact = scenario_artifact( $scenario );
	$log_path = $artifact . DIRECTORY_SEPARATOR . 'error.log';
	if ( file_exists( $log_path ) ) {
		unlink( $log_path );
	}
	ini_set( 'log_errors', '1' );
	ini_set( 'error_log', $log_path );

	$secret = isset( $scenario['secret'] )
		? (string) $scenario['secret']
		: 'Google JSON /var/www/html/wp-content/uploads/secret.json {"error":"invalid_grant"}';
	$died   = false;
	$die_message = '';

	try {
		Portal_Submission_Pipeline::record_public_failure( new Exception( $secret ) );
		if ( method_exists( 'Portal_Submission_Pipeline', 'mark_public_errors' ) ) {
			Portal_Submission_Pipeline::mark_public_errors();
		} elseif ( function_exists( 'pb_record_public_submit_failure' ) ) {
			// Already recorded; mark if the wrapper is the only define path.
		} elseif ( ! defined( 'DG_DEFINITION_SUBMIT_ERRORS' ) ) {
			// Leave undefined so the test can fail if the shipped path never defines it.
		}
		if ( function_exists( 'pb_record_public_submit_failure' ) ) {
			// Drive the plugin wrapper when present (idempotent re-record).
			pb_record_public_submit_failure( new Exception( $secret ) );
		}
	} catch ( RuntimeException $e ) {
		if ( 0 === strpos( $e->getMessage(), 'wp_die:' ) ) {
			$died        = true;
			$die_message = substr( $e->getMessage(), 7 );
		} else {
			throw $e;
		}
	}

	$errors   = Portal_Submission_Pipeline::last_errors();
	$rendered = is_array( $errors ) ? Portal_Submission_Pipeline::render_errors( $errors ) : '';
	$log      = is_readable( $log_path ) ? (string) file_get_contents( $log_path ) : '';
	$human    = '';
	if ( is_array( $errors ) && isset( $errors[0]['message'] ) ) {
		$human = (string) $errors[0]['message'];
	}

	return array(
		'ok'                 => true,
		'died'               => $died || ! empty( $GLOBALS['wp_died'] ),
		'dieMessage'         => $die_message,
		'definedErrors'      => defined( 'DG_DEFINITION_SUBMIT_ERRORS' ) && DG_DEFINITION_SUBMIT_ERRORS,
		'lastErrors'         => $errors,
		'humanMessage'       => $human,
		'rendered'           => $rendered,
		'log'                => $log,
		'secret'             => $secret,
		'publicCatchDiesRaw' => $inspect['publicCatchDiesRaw'],
		'publicCatchRethrows'=> $inspect['publicCatchRethrows'],
		'submissionDiesRaw'  => $inspect['submissionDiesRaw'],
		'publicCatchRecordsHuman' => $inspect['publicCatchRecordsHuman'],
	);
}

/**
 * Drive Portal_Submission::process_submission on a throwing path, then the
 * shipped post-submit decision. A failure must not attach the success filter.
 *
 * @param array  $scenario  Scenario.
 * @param string $repo_root Plugin root.
 * @return array<string,mixed>
 */
function run_legacy_submit_failure( array $scenario, $repo_root ) {
	$inspect = inspect_public_submit_sources( $repo_root );
	$secret  = isset( $scenario['secret'] )
		? (string) $scenario['secret']
		: 'Google JSON /var/www/html/wp-content/uploads/secret.json {"error":"invalid_grant"}';

	$GLOBALS['added_filters'] = array();
	load_portal_submission_for_cli( $repo_root );

	if ( ! class_exists( 'Portal_Submission' ) ) {
		return array(
			'ok'                         => false,
			'code'                       => 'missing_portal_submission',
			'unconditionalLegacySuccess' => $inspect['unconditionalLegacySuccess'],
			'hasFinishPublicSubmit'      => $inspect['hasFinishPublicSubmit'],
		);
	}

	$artifact = scenario_artifact( $scenario );
	$log_path = $artifact . DIRECTORY_SEPARATOR . 'legacy-error.log';
	if ( file_exists( $log_path ) ) {
		unlink( $log_path );
	}
	ini_set( 'log_errors', '1' );
	ini_set( 'error_log', $log_path );

	$_POST = array();
	$handler = new Portal_Public_Submit_Fake_Handler();
	$submission = new Portal_Submission( 'ready_to_submit_nonce', $handler );

	$died = false;
	$die_message = '';
	$completed = null;
	try {
		$completed = $submission->process_submission(
			array(
				'post_id' => 1,
				'throw'   => $secret,
			)
		);
	} catch ( Exception $e ) {
		if ( 0 === strpos( $e->getMessage(), 'wp_die:' ) ) {
			$died        = true;
			$die_message = substr( $e->getMessage(), 7 );
		} else {
			$completed = false;
			if ( class_exists( 'Portal_Submission_Pipeline' )
				&& method_exists( 'Portal_Submission_Pipeline', 'record_public_failure' ) ) {
				Portal_Submission_Pipeline::record_public_failure( $e );
				Portal_Submission_Pipeline::mark_public_errors();
			}
		}
	}

	$outcome = 'missing';
	if ( class_exists( 'Portal_Submission_Pipeline' )
		&& method_exists( 'Portal_Submission_Pipeline', 'finish_public_submit' ) ) {
		$outcome = Portal_Submission_Pipeline::finish_public_submit( (bool) $completed );
	} else {
		// Current handle_submissions treats a returned process_submission as success.
		if ( ! defined( 'PB_RECEIPT_LINK' ) ) {
			define( 'PB_RECEIPT_LINK', '' );
		}
		add_filter( 'the_content', 'pb_post_submitted_content_filter', 10, 1 );
		$outcome = 'success';
	}

	$filters = isset( $GLOBALS['added_filters'] ) ? $GLOBALS['added_filters'] : array();
	$success_fns = array();
	foreach ( $filters as $row ) {
		if ( 'the_content' !== $row['tag'] ) {
			continue;
		}
		$fn = $row['fn'];
		if ( is_string( $fn ) ) {
			$success_fns[] = $fn;
		}
	}

	$errors = class_exists( 'Portal_Submission_Pipeline' )
		? Portal_Submission_Pipeline::last_errors()
		: null;
	$human  = '';
	if ( is_array( $errors ) && isset( $errors[0]['message'] ) ) {
		$human = (string) $errors[0]['message'];
	}
	$rendered = is_array( $errors )
		? Portal_Submission_Pipeline::render_errors( $errors )
		: '';

	return array(
		'ok'                         => true,
		'completed'                  => $completed,
		'outcome'                    => $outcome,
		'died'                       => $died || ! empty( $GLOBALS['wp_died'] ),
		'dieMessage'                 => $die_message,
		'successFilterAttached'      => in_array( 'pb_post_submitted_content_filter', $success_fns, true ),
		'successFilters'             => $success_fns,
		'receiptDefined'             => defined( 'PB_RECEIPT_LINK' ),
		'definedErrors'              => defined( 'DG_DEFINITION_SUBMIT_ERRORS' ) && DG_DEFINITION_SUBMIT_ERRORS,
		'humanMessage'               => $human,
		'rendered'                   => $rendered,
		'unconditionalLegacySuccess' => $inspect['unconditionalLegacySuccess'],
		'hasFinishPublicSubmit'      => $inspect['hasFinishPublicSubmit'],
	);
}

/**
 * Load Portal_Submission with CLI stubs (no Google, no Composer).
 *
 * @param string $repo_root Plugin root.
 * @return void
 */
function load_portal_submission_for_cli( $repo_root ) {
	if ( class_exists( 'Portal_Submission' ) ) {
		return;
	}
	if ( ! function_exists( 'plugin_dir_path' ) ) {
		function plugin_dir_path( $file ) {
			return dirname( $file ) . '/';
		}
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $key, $default = false ) {
			unset( $key );
			return $default;
		}
	}
	if ( ! function_exists( 'update_option' ) ) {
		function update_option() {}
	}
	if ( ! function_exists( 'wp_verify_nonce' ) ) {
		function wp_verify_nonce() {
			return false;
		}
	}
	if ( ! class_exists( 'Zysys_FileStore' ) ) {
		class Zysys_FileStore {
			public function __construct( $credentials ) {
				unset( $credentials );
			}
			public function __get( $key ) {
				unset( $key );
				return null;
			}
		}
	}
	if ( ! class_exists( 'PHPMailer\\PHPMailer\\Exception' ) ) {
		class_alias( 'Exception', 'PHPMailer\\PHPMailer\\Exception' );
	}
	$autoload = $repo_root . '/vendor/autoload.php';
	if ( ! is_readable( $autoload ) ) {
		if ( ! is_dir( $repo_root . '/vendor' ) ) {
			mkdir( $repo_root . '/vendor', 0755, true );
		}
		file_put_contents( $autoload, "<?php\n" );
	}
	require_once $repo_root . '/includes/class-portal-submission.php';
}

/**
 * File handler stand-in so Portal_Submission can be constructed.
 */
class Portal_Public_Submit_Fake_Handler {
	public $stored_file_paths = array();
	public function get_appId() {
		return 'test-app';
	}
	public function get_temp_file_dir() {
		return sys_get_temp_dir();
	}
	public function permanently_store_temp() {
		return true;
	}
}

/**
 * @param array $scenario Scenario.
 * @return array<string,mixed>
 */
function run_purge( array $scenario ) {
	$callable = purge_callable();
	if ( null === $callable ) {
		return array(
			'ok'      => false,
			'code'    => 'missing_purge',
			'message' => 'No purge_expired owner is loaded',
		);
	}

	$artifact = scenario_artifact( $scenario );
	$now      = isset( $scenario['now'] ) ? (int) $scenario['now'] : time();
	$ttl      = isset( $scenario['ttl'] ) ? (int) $scenario['ttl'] : 86400;

	if ( class_exists( 'Portal_Upload_Store' ) && method_exists( 'Portal_Upload_Store', 'set_basedir' ) ) {
		Portal_Upload_Store::set_basedir( $artifact );
	}
	if ( class_exists( 'Portal_Upload_Store' ) && method_exists( 'Portal_Upload_Store', 'set_clock' ) ) {
		Portal_Upload_Store::set_clock(
			static function () use ( $now ) {
				return $now;
			}
		);
	}

	$staged = $artifact . DIRECTORY_SEPARATOR . 'dg-staged';
	$tmp    = $artifact . DIRECTORY_SEPARATOR . 'dg-tmp';
	$store  = $artifact . DIRECTORY_SEPARATOR . 'dg-store';
	$legacy = $artifact . DIRECTORY_SEPARATOR . 'legacy-tmp';
	foreach ( array( $staged, $tmp, $store, $legacy ) as $dir ) {
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
	}

	$old_staged  = $staged . DIRECTORY_SEPARATOR . 'old-token' . DIRECTORY_SEPARATOR . 'content';
	$fresh_staged = $staged . DIRECTORY_SEPARATOR . 'fresh-token' . DIRECTORY_SEPARATOR . 'content';
	$old_tmp     = $tmp . DIRECTORY_SEPARATOR . 'old.bin';
	$fresh_tmp   = $tmp . DIRECTORY_SEPARATOR . 'fresh.bin';
	$old_legacy  = $legacy . DIRECTORY_SEPARATOR . 'legacy-old.bin';
	$accepted    = $store . DIRECTORY_SEPARATOR . 'accepted.bin';

	plant_file( $old_staged, 'old-staged', $now - $ttl - 120 );
	plant_file( $fresh_staged, 'fresh-staged', $now - 60 );
	plant_file( $old_tmp, 'old-tmp', $now - $ttl - 120 );
	plant_file( $fresh_tmp, 'fresh-tmp', $now - 60 );
	plant_file( $old_legacy, 'legacy-old', $now - $ttl - 120 );
	plant_file( $accepted, 'accepted-keep', $now - $ttl - 120 );

	$roots = array( $staged, $tmp, $legacy );
	call_user_func( $callable, $now, $ttl, $roots );

	return array(
		'ok'            => true,
		'oldStagedGone' => ! file_exists( $old_staged ),
		'freshStaged'   => file_exists( $fresh_staged ),
		'oldTmpGone'    => ! file_exists( $old_tmp ),
		'freshTmp'      => file_exists( $fresh_tmp ),
		'legacyGone'    => ! file_exists( $old_legacy ),
		'acceptedKept'  => file_exists( $accepted ),
	);
}

/**
 * @param array  $scenario Scenario.
 * @param string $repo_root Plugin root.
 * @return array<string,mixed>
 */
function run_upload_roots( array $scenario, $repo_root ) {
	$inspect  = inspect_public_submit_sources( $repo_root );
	$artifact = scenario_artifact( $scenario );
	$webroot  = $artifact . DIRECTORY_SEPARATOR . 'webroot';
	$uploads  = $webroot . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'uploads';
	if ( ! is_dir( $uploads ) ) {
		mkdir( $uploads, 0755, true );
	}
	$GLOBALS['upload_basedir'] = $uploads;

	if ( ! class_exists( 'Portal_Upload_Store' ) ) {
		return array(
			'ok'                 => false,
			'code'               => 'missing_upload_store',
			'message'            => 'Portal_Upload_Store is not loaded',
			'abspathTmpConcat'   => $inspect['abspathTmpConcat'],
			'abspathStoreConcat' => $inspect['abspathStoreConcat'],
			'abspath'            => $webroot,
		);
	}

	if ( method_exists( 'Portal_Upload_Store', 'set_basedir' ) ) {
		Portal_Upload_Store::set_basedir( $uploads );
	}

	$tmp    = Portal_Upload_Store::tmp_dir();
	$store  = Portal_Upload_Store::store_dir();
	$staged = method_exists( 'Portal_Upload_Store', 'staged_dir' )
		? Portal_Upload_Store::staged_dir()
		: $uploads . DIRECTORY_SEPARATOR . 'dg-staged';

	$deny_tmp    = deny_path_beside( $tmp );
	$deny_store  = deny_path_beside( $store );
	$deny_staged = deny_path_beside( $staged );

	$legacy_tmp   = $webroot . DIRECTORY_SEPARATOR . 'tmp-uploads';
	$legacy_store = $webroot . DIRECTORY_SEPARATOR . 'file-storage';
	$staged_name  = 'aabbccddeeff00112233445566778899' . DIRECTORY_SEPARATOR . 'content';

	return array(
		'ok'                 => true,
		'tmpDir'             => $tmp,
		'storeDir'           => $store,
		'stagedDir'          => $staged,
		'uploadsBasedir'     => $uploads,
		'abspath'            => $webroot,
		'legacyTmp'          => $legacy_tmp,
		'legacyStore'        => $legacy_store,
		'tmpIsLegacy'        => realpath_or_self( $tmp ) === realpath_or_self( $legacy_tmp ),
		'storeIsLegacy'      => realpath_or_self( $store ) === realpath_or_self( $legacy_store ),
		'tmpUnderUploads'    => path_is_under( $tmp, $uploads ),
		'storeUnderUploads'  => path_is_under( $store, $uploads ),
		'stagedUnderUploads' => path_is_under( $staged, $uploads ),
		'denyTmp'            => $deny_tmp,
		'denyStore'          => $deny_store,
		'denyStaged'         => $deny_staged,
		'denyTmpExists'      => $deny_tmp && is_readable( $deny_tmp ),
		'denyStoreExists'    => $deny_store && is_readable( $deny_store ),
		'denyStagedExists'   => $deny_staged && is_readable( $deny_staged ),
		'stagedPublicUrl'    => '/tmp-uploads/' . str_replace( DIRECTORY_SEPARATOR, '/', $staged_name ),
		'stagedAbsPath'      => $staged . DIRECTORY_SEPARATOR . $staged_name,
		'legacyPublicPath'   => $legacy_tmp . DIRECTORY_SEPARATOR . $staged_name,
		'abspathTmpConcat'   => $inspect['abspathTmpConcat'],
		'abspathStoreConcat' => $inspect['abspathStoreConcat'],
	);
}

/**
 * @return array<string,mixed>
 */
function run_schedule() {
	if ( ! class_exists( 'Portal_Upload_Store' )
		|| ! method_exists( 'Portal_Upload_Store', 'schedule_cleanup' )
		|| ! method_exists( 'Portal_Upload_Store', 'unschedule_cleanup' ) ) {
		return array(
			'ok'      => false,
			'code'    => 'missing_schedule',
			'message' => 'Portal_Upload_Store schedule/unschedule is not available',
		);
	}

	$GLOBALS['scheduled_hooks'] = array();
	$GLOBALS['cleared_hooks']   = array();
	Portal_Upload_Store::schedule_cleanup();
	$scheduled = $GLOBALS['scheduled_hooks'];
	Portal_Upload_Store::unschedule_cleanup();

	$hook = '';
	if ( defined( 'Portal_Upload_Store::CLEANUP_HOOK' ) ) {
		$hook = (string) Portal_Upload_Store::CLEANUP_HOOK;
	} elseif ( ! empty( $scheduled ) ) {
		$hook = (string) array_key_first( $scheduled );
	}

	return array(
		'ok'        => true,
		'hook'      => $hook,
		'scheduled' => $scheduled,
		'cleared'   => isset( $GLOBALS['cleared_hooks'] ) ? $GLOBALS['cleared_hooks'] : array(),
	);
}

/**
 * @param array $scenario Scenario.
 * @return string
 */
function scenario_artifact( array $scenario ) {
	$artifact = isset( $scenario['artifactDir'] ) ? (string) $scenario['artifactDir'] : sys_get_temp_dir();
	if ( ! is_dir( $artifact ) ) {
		mkdir( $artifact, 0755, true );
	}
	return rtrim( $artifact, "/\\" );
}

/**
 * @param string $path    File path.
 * @param string $contents Bytes.
 * @param int    $mtime   Unix mtime.
 * @return void
 */
function plant_file( $path, $contents, $mtime ) {
	$dir = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}
	file_put_contents( $path, $contents );
	touch( $path, $mtime );
}

/**
 * @param string $dir Directory.
 * @return string|null
 */
function deny_path_beside( $dir ) {
	if ( ! is_string( $dir ) || '' === $dir ) {
		return null;
	}
	$candidates = array(
		rtrim( $dir, "/\\" ) . DIRECTORY_SEPARATOR . '.htaccess',
		rtrim( $dir, "/\\" ) . DIRECTORY_SEPARATOR . 'index.php',
	);
	foreach ( $candidates as $candidate ) {
		if ( is_readable( $candidate ) ) {
			return $candidate;
		}
	}
	return $candidates[0];
}

/**
 * @param string $path Path.
 * @return string
 */
function realpath_or_self( $path ) {
	$real = realpath( $path );
	return false === $real ? rtrim( str_replace( '\\', '/', (string) $path ), '/' ) : $real;
}

/**
 * @param string $path   Candidate.
 * @param string $parent Parent directory.
 * @return bool
 */
function path_is_under( $path, $parent ) {
	$p = realpath_or_self( $path );
	$r = realpath_or_self( $parent );
	return $p === $r || 0 === strpos( $p, $r . '/' ) || 0 === strpos( $p, $r . DIRECTORY_SEPARATOR );
}
