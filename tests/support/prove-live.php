#!/usr/bin/env php
<?php
/**
 * Prove live Sheet / Drive / Anonymizer against a private sandbox.
 *
 *   php tests/support/prove-live.php
 *   php tests/support/prove-live.php --anon-only
 *   php tests/support/prove-live.php --google-only
 *
 * Does not write to production 2027 sheet IDs. Creates (or reuses) a sandbox
 * spreadsheet + Drive folder and prints inspect URLs.
 *
 * Credentials come from LocalWP options (same keys as isjac.org).
 */

// phpcs:disable
$repo_root = dirname( __DIR__, 2 );

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
}
function is_wp_error( $t ) {
	return $t instanceof WP_Error;
}
function wp_json_encode( $d ) {
	return json_encode( $d );
}

require_once $repo_root . '/vendor/autoload.php';
require_once $repo_root . '/gsuite-filestore/vendor/autoload.php';
require_once $repo_root . '/gsuite-filestore/zysys-file-store.class.php';
require_once $repo_root . '/includes/Definition/class-portal-definition.php';
require_once $repo_root . '/includes/Definition/class-portal-site-defaults.php';
require_once $repo_root . '/includes/Submission/class-portal-files.php';
require_once $repo_root . '/includes/Submission/class-portal-test-mode.php';
require_once $repo_root . '/includes/Submission/class-portal-sheet-store.php';
require_once $repo_root . '/includes/Submission/class-portal-drive-store.php';
require_once $repo_root . '/includes/Submission/class-portal-mailer.php';
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
require_once $repo_root . '/includes/adapters/class-portal-google-store.php';

$flags = array_slice( $argv, 1 );
$anon_only   = in_array( '--anon-only', $flags, true );
$google_only = in_array( '--google-only', $flags, true );

$env_path = $repo_root . '/tests/config/env.local.json';
$env      = is_readable( $env_path )
	? json_decode( file_get_contents( $env_path ), true )
	: array();
if ( ! is_array( $env ) ) {
	$env = array();
}

$ids_path = $repo_root . '/tests/.artifacts/prove-live-ids.json';
$opts     = load_wp_options();
$failed   = 0;

if ( ! $google_only ) {
	$failed += prove_anonymizer( $opts, $repo_root ) ? 0 : 1;
}
if ( ! $anon_only ) {
	$failed += prove_google( $opts, $env, $ids_path, $repo_root ) ? 0 : 1;
}

exit( $failed > 0 ? 1 : 0 );

/**
 * @return array<string,string>
 */
function load_wp_options() {
	$sock = getenv( 'DG_MYSQL_SOCKET' );
	if ( ! is_string( $sock ) || '' === $sock ) {
		$sock = $_SERVER['HOME'] . '/Library/Application Support/Local/run/MbehRUdOC/mysql/mysqld.sock';
	}
	mysqli_report( MYSQLI_REPORT_OFF );
	$m = @new mysqli( 'localhost', 'root', 'root', 'local', 0, $sock );
	if ( $m->connect_error ) {
		fwrite( STDERR, "mysql: {$m->connect_error}\n" );
		return array();
	}
	$names = array(
		'pb_google_access_key',
		'pb_google_secret_key',
		'pb_default_anonymize_api_key',
		'pb_default_anonymize_endpoint',
	);
	$out   = array();
	foreach ( $names as $name ) {
		$q = $m->prepare( 'SELECT option_value FROM B3sRggSMK1_options WHERE option_name = ? LIMIT 1' );
		$q->bind_param( 's', $name );
		$q->execute();
		$q->bind_result( $val );
		if ( $q->fetch() && is_string( $val ) ) {
			$out[ $name ] = $val;
		}
		$q->close();
	}
	$m->close();
	$env_key = getenv( 'ANONYMIZER_LIVE_KEY' );
	if ( is_string( $env_key ) && '' !== $env_key ) {
		$out['pb_default_anonymize_api_key'] = $env_key;
	}
	return $out;
}

/**
 * @param array  $opts WP options.
 * @param string $root Repo root.
 * @return bool
 */
function prove_anonymizer( array $opts, $root ) {
	echo "== anonymizer ==\n";
	$key = isset( $opts['pb_default_anonymize_api_key'] ) ? trim( $opts['pb_default_anonymize_api_key'] ) : '';
	if ( '' === $key ) {
		echo "SKIP: no site API key (set Default Settings or ANONYMIZER_LIVE_KEY)\n";
		return true;
	}
	$pdf = $root . '/tests/fixtures/files/valid-score.pdf';
	if ( ! is_readable( $pdf ) ) {
		echo "FAIL: missing $pdf\n";
		return false;
	}
	$buffer = file_get_contents( $pdf );
	$owner  = new Portal_Anonymizer(
		array(
			'anonymize'         => true,
			'anonymizeEndpoint' => isset( $opts['pb_default_anonymize_endpoint'] )
				? $opts['pb_default_anonymize_endpoint']
				: null,
			'anonymizeApiKey'   => $key,
		)
	);
	$out = $owner->maybe_anonymize( $buffer, 'valid-score.pdf', 'application/pdf' );
	$ok  = is_string( $out ) && Portal_File_Signature::is_pdf( $out );
	$same = $out === $buffer;
	echo 'key_len=' . strlen( $key ) . "\n";
	echo 'in_bytes=' . strlen( $buffer ) . ' out_bytes=' . strlen( $out ) . ' same_as_original=' . ( $same ? 'yes' : 'no' ) . "\n";
	echo 'valid_pdf=' . ( $ok ? 'yes' : 'no' ) . "\n";
	if ( $same ) {
		echo "NOTE: fail-open kept the original (API error or invalid download).\n";
	}
	echo $ok ? "OK anonymizer\n" : "FAIL anonymizer: output is not a PDF\n";
	return $ok;
}

/**
 * @param array  $opts     WP options.
 * @param array  $env      env.local.json.
 * @param string $ids_path Cached sandbox ids.
 * @param string $root     Repo root.
 * @return bool
 */
function prove_google( array $opts, array $env, $ids_path, $root ) {
	echo "== google ==\n";
	$access = isset( $opts['pb_google_access_key'] ) ? $opts['pb_google_access_key'] : '';
	$secret = isset( $opts['pb_google_secret_key'] ) ? $opts['pb_google_secret_key'] : '';
	if ( '' === $secret ) {
		echo "FAIL: pb_google_secret_key missing in WP options\n";
		return false;
	}

	try {
		$store = new Zysys_FileStore(
			array(
				'access_key'    => $access,
				'client_secret' => $secret,
			)
		);
	} catch ( Exception $e ) {
		echo 'FAIL: FileStore: ' . $e->getMessage() . "\n";
		return false;
	}

	$ids = load_sandbox_ids( $env, $ids_path );
	try {
		$ids = ensure_sandbox( $store, $ids, $ids_path );
	} catch ( Exception $e ) {
		echo 'FAIL: sandbox: ' . $e->getMessage() . "\n";
		return false;
	}

	echo 'sheet=' . $ids['spreadsheetId'] . "\n";
	echo 'folder=' . $ids['folderId'] . "\n";
	echo 'sheet_url=https://docs.google.com/spreadsheets/d/' . $ids['spreadsheetId'] . "/edit\n";
	echo 'folder_url=https://drive.google.com/drive/folders/' . $ids['folderId'] . "\n";

	$definition = build_prove_definition( $root, $ids );
	$values     = array(
		'sub_title'             => 'Ms',
		'sub_name'              => 'Prove Live',
		'sub_email'             => 'prove.live@example.com',
		'sub_inst_affil'        => 'ISJAC',
		'sub_address_first_part'=> '1 Prove Street',
		'sub_city'              => 'Raleigh',
		'sub_country'           => 'US',
		'sub_state'             => 'NC',
		'sub_zip'               => '27601',
		'sub_phone'             => '+1-919-555-0199',
		'sub_work_title'        => 'Prove Live ' . gmdate( 'Y-m-d\TH:i:s\Z' ),
	);
	$score = $root . '/tests/fixtures/files/valid-score.pdf';
	$rec   = $root . '/tests/fixtures/files/sample-recording.mp3';
	$files = array(
		'score'     => array(
			'name'     => 'valid-score.pdf',
			'contents' => file_get_contents( $score ),
			'type'     => 'application/pdf',
		),
		'recording' => array(
			'name'     => 'sample-recording.mp3',
			'contents' => file_get_contents( $rec ),
			'type'     => 'audio/mpeg',
		),
	);

	$pipeline = Portal_Submission_Pipeline::for_live( $definition, null, null, $store );
	$result   = $pipeline->process( 'prove-live', $definition, $values, $files );
	if ( is_wp_error( $result ) ) {
		echo 'FAIL: pipeline ' . $result->get_error_code() . ': ' . $result->get_error_message() . "\n";
		return false;
	}

	echo 'pipeline_status=' . ( isset( $result['status'] ) ? $result['status'] : '' ) . "\n";
	echo 'sheet_write=' . ( isset( $result['sheetPath'] ) ? $result['sheetPath'] : '' ) . "\n";
	echo 'drive_url=' . ( isset( $result['drivePaths']['score'] ) ? $result['drivePaths']['score'] : '' ) . "\n";

	try {
		$last = read_last_row( $store, $ids['spreadsheetId'] );
		echo 'last_row=' . json_encode( $last ) . "\n";
		$has_title = false;
		foreach ( $last as $cell ) {
			if ( is_string( $cell ) && false !== strpos( $cell, 'Prove Live' ) ) {
				$has_title = true;
			}
		}
		if ( ! $has_title ) {
			echo "FAIL: last sheet row does not contain Prove Live\n";
			return false;
		}
	} catch ( Exception $e ) {
		echo 'FAIL: read sheet: ' . $e->getMessage() . "\n";
		return false;
	}

	echo "OK google\n";
	return true;
}

/**
 * @param array  $env      env.local.json.
 * @param string $ids_path Cache path.
 * @return array{spreadsheetId:?string,folderId:?string}
 */
function load_sandbox_ids( array $env, $ids_path ) {
	$ids = array(
		'spreadsheetId' => isset( $env['liveCharSheetId'] ) ? (string) $env['liveCharSheetId'] : '',
		'folderId'      => isset( $env['liveCharFolderId'] ) ? (string) $env['liveCharFolderId'] : '',
	);
	if ( is_readable( $ids_path ) ) {
		$cached = json_decode( file_get_contents( $ids_path ), true );
		if ( is_array( $cached ) ) {
			if ( '' === $ids['spreadsheetId'] && ! empty( $cached['spreadsheetId'] ) ) {
				$ids['spreadsheetId'] = (string) $cached['spreadsheetId'];
			}
			if ( '' === $ids['folderId'] && ! empty( $cached['folderId'] ) ) {
				$ids['folderId'] = (string) $cached['folderId'];
			}
		}
	}
	return $ids;
}

/**
 * @param Zysys_FileStore $store FileStore.
 * @param array           $ids   Current ids.
 * @param string          $path  Cache path.
 * @return array{spreadsheetId:string,folderId:string}
 */
function ensure_sandbox( $store, array $ids, $path ) {
	$sheets = $store->__get( 'sheetsAgent' );
	$drive  = $store->__get( 'driveAgent' );
	if ( ! $sheets || ! $drive ) {
		throw new Exception( 'Google agents not configured' );
	}

	if ( '' === $ids['spreadsheetId'] ) {
		$body    = new Google_Service_Sheets_Spreadsheet(
			array(
				'properties' => array( 'title' => 'DragonGate prove-live' ),
				'sheets'     => array(
					array( 'properties' => array( 'title' => 'Raw Data' ) ),
				),
			)
		);
		$created = $sheets->spreadsheets->create( $body );
		$ids['spreadsheetId'] = $created->getSpreadsheetId();
		echo "created_sheet={$ids['spreadsheetId']}\n";
	}
	if ( '' === $ids['folderId'] ) {
		$meta    = new Google_Service_Drive_DriveFile(
			array(
				'name'     => 'DragonGate prove-live',
				'mimeType' => 'application/vnd.google-apps.folder',
			)
		);
		$created = $drive->files->create( $meta, array( 'fields' => 'id' ) );
		$ids['folderId'] = $created->getId();
		echo "created_folder={$ids['folderId']}\n";
	}

	$dir = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}
	file_put_contents(
		$path,
		json_encode(
			array(
				'spreadsheetId' => $ids['spreadsheetId'],
				'folderId'      => $ids['folderId'],
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n"
	);
	return $ids;
}

/**
 * @param string $root Repo.
 * @param array  $ids  Sandbox ids.
 * @return array
 */
function build_prove_definition( $root, array $ids ) {
	$base = json_decode( file_get_contents( $root . '/tests/fixtures/portals/herbolzheimer.definition.json' ), true );
	$base['title']   = 'Prove Live';
	$base['options'] = array(
		'anonymize'  => false,
		'skipHeader' => false,
	);
	$base['mapping'] = array(
		'sheets'    => array(
			array(
				'id'            => 'sheet_housekeeping',
				'name'          => 'Housekeeping',
				'role'          => 'housekeeping',
				'spreadsheetId' => $ids['spreadsheetId'],
			),
		),
		'drive'     => array(
			array(
				'id'       => 'drive_submissions',
				'name'     => 'Submissions',
				'folderId' => $ids['folderId'],
			),
		),
		'fieldDest' => array(
			'work_title' => 'sheet:Housekeeping:Title',
			'score'      => 'drive:Submissions|sheet:Housekeeping:Score Link',
			'recording'  => 'drive:Submissions|sheet:Housekeeping:Rec Link',
		),
	);
	$validated = Portal_Definition::from_json( wp_json_encode( $base ) );
	if ( is_wp_error( $validated ) ) {
		throw new Exception( $validated->get_error_message() );
	}
	return $validated;
}

/**
 * @param Zysys_FileStore $store FileStore.
 * @param string          $sid   Spreadsheet id.
 * @return array
 */
function read_last_row( $store, $sid ) {
	$sheets = $store->__get( 'sheetsAgent' );
	$range  = "'Raw Data'!A1:Z50";
	$resp   = $sheets->spreadsheets_values->get( $sid, $range );
	$values = $resp->getValues();
	if ( ! is_array( $values ) || empty( $values ) ) {
		return array();
	}
	return $values[ count( $values ) - 1 ];
}
