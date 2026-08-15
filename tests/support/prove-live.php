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

if ( is_readable( $repo_root . '/vendor/autoload.php' ) ) {
	require_once $repo_root . '/vendor/autoload.php';
}
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

	$herb_headers = array( 'Files', 'Rec Link', 'Application ID', 'Title', 'Score Link' );
	try {
		seed_header_row( $store, $ids['spreadsheetId'], $herb_headers );
	} catch ( Exception $e ) {
		echo 'FAIL: seed headers: ' . $e->getMessage() . "\n";
		return false;
	}

	$definition = build_prove_definition( $root, $ids );
	$values     = array(
		'sub_title'              => 'Ms',
		'sub_name'               => 'Prove Live',
		'sub_email'              => 'prove.live@example.com',
		'sub_inst_affil'         => 'ISJAC',
		'sub_address_first_part' => '1 Prove Street',
		'sub_city'               => 'Raleigh',
		'sub_country'            => 'US',
		'sub_state'              => 'NC',
		'sub_zip'                => '27601',
		'sub_phone'              => '+1-919-555-0199',
		'sub_work_title'         => 'Prove Live ' . gmdate( 'Y-m-d\TH:i:s\Z' ),
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
		$named = read_named_last_row( $store, $ids['spreadsheetId'] );
		echo 'last_row=' . json_encode( $named ) . "\n";
		if ( ! isset( $named['Title'] ) || false === strpos( $named['Title'], 'Prove Live' ) ) {
			echo "FAIL: dest Title does not contain Prove Live\n";
			return false;
		}
		if ( ! cell_has_prefix( $named, 'Files', Portal_Submission_Destinations::FOLDER_URL_PREFIX ) ) {
			echo "FAIL: dest Files is not a folder URL\n";
			return false;
		}
		if ( ! cell_has_prefix( $named, 'Score Link', Portal_Submission_Destinations::FILE_URL_PREFIX ) ) {
			echo "FAIL: dest Score Link is not a file URL\n";
			return false;
		}
		if ( empty( $named['Application ID'] ) ) {
			echo "FAIL: dest Application ID is empty\n";
			return false;
		}
		echo 'files_url=' . $named['Files'] . "\n";
	} catch ( Exception $e ) {
		echo 'FAIL: read sheet: ' . $e->getMessage() . "\n";
		return false;
	}

	echo "OK google\n";

	$cfs_ok = prove_cfs( $store, $opts, $ids, $root );
	return $cfs_ok;
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
			'work_title'    => 'sheet:Housekeeping:Title',
			'score'         => 'drive:Submissions|sheet:Housekeeping:Score Link',
			'recording'     => 'drive:Submissions|sheet:Housekeeping:Rec Link',
			'files'         => 'sheet:Housekeeping:Files',
			'applicationId' => 'sheet:Housekeeping:Application ID',
		),
	);
	$validated = Portal_Definition::from_json( wp_json_encode( $base ) );
	if ( is_wp_error( $validated ) ) {
		throw new Exception( $validated->get_error_message() );
	}
	return $validated;
}

/**
 * @param Zysys_FileStore $store   FileStore.
 * @param string          $sid     Spreadsheet id.
 * @param array           $headers Dest column names.
 * @return void
 */
function seed_header_row( $store, $sid, array $headers ) {
	$store->gsheet( $sid );
	$store->update_values( Portal_Submission_Destinations::HEADER_WRITE_RANGE, $headers );
}

/**
 * Last data row keyed by dest header text.
 *
 * @param Zysys_FileStore $store FileStore.
 * @param string          $sid   Spreadsheet id.
 * @return array<string,string>
 */
function read_named_last_row( $store, $sid ) {
	$store->gsheet( $sid );
	$values = $store->read_range( "'Raw Data'!A1:ZZ200" );
	if ( ! is_array( $values ) || count( $values ) < 2 || ! is_array( $values[0] ) ) {
		return array();
	}
	$headers = $values[0];
	$last    = $values[ count( $values ) - 1 ];
	$named   = array();
	foreach ( $headers as $i => $header ) {
		$label = trim( (string) $header );
		if ( '' === $label ) {
			continue;
		}
		$named[ $label ] = isset( $last[ $i ] ) ? (string) $last[ $i ] : '';
	}
	return $named;
}

/**
 * @param array  $named  Dest-keyed row.
 * @param string $column Dest header.
 * @param string $prefix Required prefix.
 * @return bool
 */
function cell_has_prefix( array $named, $column, $prefix ) {
	return isset( $named[ $column ] ) && 0 === strpos( $named[ $column ], $prefix );
}

/**
 * @param string $url    Full URL.
 * @param string $prefix Known prefix.
 * @return string
 */
function id_after_prefix( $url, $prefix ) {
	if ( 0 !== strpos( (string) $url, $prefix ) ) {
		return '';
	}
	return substr( (string) $url, strlen( $prefix ) );
}

/**
 * @param Zysys_FileStore $store     FileStore.
 * @param array           $opts      WP options.
 * @param array           $ids       Sandbox ids.
 * @param string          $root      Repo root.
 * @return bool
 */
function prove_cfs( $store, array $opts, array $ids, $root ) {
	echo "== cfs scores ==\n";
	$cfs_headers = array(
		'Files',
		'Rec Link',
		'Application ID',
		'Applicant Name',
		'Application Category',
		'Title',
		'Score Link',
	);
	try {
		seed_header_row( $store, $ids['spreadsheetId'], $cfs_headers );
		$definition = build_cfs_definition( $root, $ids, $opts, true );
	} catch ( Exception $e ) {
		echo 'FAIL: cfs setup: ' . $e->getMessage() . "\n";
		return false;
	}

	$pdf_path = $root . '/tests/fixtures/files/valid-score.pdf';
	$mp3_path = $root . '/tests/fixtures/files/sample-recording.mp3';
	$pdf      = file_get_contents( $pdf_path );
	$mp3      = file_get_contents( $mp3_path );
	$scores   = json_decode( file_get_contents( $root . '/tests/fixtures/portals/call-for-scores.scores-submission.json' ), true );
	$poster   = json_decode( file_get_contents( $root . '/tests/fixtures/portals/call-for-scores.poster-submission.json' ), true );
	if ( ! is_array( $scores ) || ! is_array( $poster ) ) {
		echo "FAIL: cfs submission fixtures\n";
		return false;
	}

	$score_files = array(
		'bio'         => array(
			'name'     => 'valid-score.pdf',
			'contents' => $pdf,
			'type'     => 'application/pdf',
		),
		'large_score' => array(
			'name'     => 'valid-score.pdf',
			'contents' => $pdf,
			'type'     => 'application/pdf',
		),
		'large_rec'   => array(
			'name'     => 'sample-recording.mp3',
			'contents' => $mp3,
			'type'     => 'audio/mpeg',
		),
	);
	$pipeline    = Portal_Submission_Pipeline::for_live( $definition, null, null, $store );
	$score_res   = $pipeline->process( 'cfs-scores', $definition, $scores['values'], $score_files );
	if ( is_wp_error( $score_res ) ) {
		echo 'FAIL: cfs scores ' . $score_res->get_error_code() . ': ' . $score_res->get_error_message() . "\n";
		return false;
	}

	try {
		$named = read_named_last_row( $store, $ids['spreadsheetId'] );
		echo 'cfs_scores_row=' . json_encode( $named ) . "\n";
		if ( ! isset( $named['Applicant Name'] ) || 'John Doe' !== $named['Applicant Name'] ) {
			echo "FAIL: dest Applicant Name\n";
			return false;
		}
		if ( ! isset( $named['Application Category'] ) || 'scores' !== $named['Application Category'] ) {
			echo "FAIL: dest Application Category\n";
			return false;
		}
		if ( ! cell_has_prefix( $named, 'Files', Portal_Submission_Destinations::FOLDER_URL_PREFIX ) ) {
			echo "FAIL: dest Files is not a folder URL\n";
			return false;
		}
		if ( ! cell_has_prefix( $named, 'Score Link', Portal_Submission_Destinations::FILE_URL_PREFIX ) ) {
			echo "FAIL: dest Score Link is not a file URL\n";
			return false;
		}
		$app_folder = id_after_prefix( $named['Files'], Portal_Submission_Destinations::FOLDER_URL_PREFIX );
		$children   = list_drive_names( $store, $app_folder );
		echo 'cfs_scores_folder_children=' . json_encode( $children ) . "\n";
		if ( ! names_include_pdf( $children ) ) {
			echo "FAIL: scores folder has no score PDF\n";
			return false;
		}
		$score_id = id_after_prefix( $named['Score Link'], Portal_Submission_Destinations::FILE_URL_PREFIX );
		$stored   = drive_file_bytes( $store, $score_id );
		$same     = $stored === $pdf;
		echo 'cfs_scores_anon_same_as_original=' . ( $same ? 'yes' : 'no' ) . "\n";
		if ( '' === $stored || ! Portal_File_Signature::is_pdf( $stored ) ) {
			echo "FAIL: stored score is not a PDF\n";
			return false;
		}
		echo 'files_url=' . $named['Files'] . "\n";
	} catch ( Exception $e ) {
		echo 'FAIL: cfs scores read: ' . $e->getMessage() . "\n";
		return false;
	}
	echo "cfs_scores_ok\n";

	echo "== cfs poster ==\n";
	$poster_files = array(
		'bio'                => array(
			'name'     => 'valid-score.pdf',
			'contents' => $pdf,
			'type'     => 'application/pdf',
		),
		'poster_description' => array(
			'name'     => 'valid-score.pdf',
			'contents' => $pdf,
			'type'     => 'application/pdf',
		),
	);
	$poster_res   = $pipeline->process( 'cfs-poster', $definition, $poster['values'], $poster_files );
	if ( is_wp_error( $poster_res ) ) {
		echo 'FAIL: cfs poster ' . $poster_res->get_error_code() . ': ' . $poster_res->get_error_message() . "\n";
		return false;
	}

	try {
		$poster_named = read_named_last_row( $store, $ids['spreadsheetId'] );
		echo 'cfs_poster_row=' . json_encode( $poster_named ) . "\n";
		if ( ! cell_has_prefix( $poster_named, 'Files', Portal_Submission_Destinations::FOLDER_URL_PREFIX ) ) {
			echo "FAIL: poster dest Files is not a folder URL\n";
			return false;
		}
		if ( $poster_named['Files'] === $named['Files'] ) {
			echo "FAIL: poster Files reused the scores folder\n";
			return false;
		}
		$folders = list_drive_folders( $store, $ids['folderId'] );
		echo 'cfs_app_folders=' . count( $folders ) . "\n";
		if ( count( $folders ) < 2 ) {
			echo "FAIL: expected a second app folder, got " . count( $folders ) . "\n";
			return false;
		}
		echo 'files_url=' . $poster_named['Files'] . "\n";
	} catch ( Exception $e ) {
		echo 'FAIL: cfs poster read: ' . $e->getMessage() . "\n";
		return false;
	}
	echo "cfs_poster_ok\n";
	return true;
}

/**
 * @param string $root Repo.
 * @param array  $ids  Sandbox ids.
 * @param array  $opts WP options.
 * @param bool   $anon Anonymize scores PDFs.
 * @return array
 */
function build_cfs_definition( $root, array $ids, array $opts, $anon ) {
	$base    = json_decode( file_get_contents( $root . '/tests/fixtures/portals/call-for-scores.definition.json' ), true );
	$mapping = json_decode( file_get_contents( $root . '/tests/fixtures/portals/call-for-scores.live-mapping.json' ), true );
	if ( ! is_array( $base ) || ! is_array( $mapping ) ) {
		throw new Exception( 'CFS fixtures are not JSON objects' );
	}
	$mapping['sheets'][0]['spreadsheetId'] = $ids['spreadsheetId'];
	$mapping['drive'][0]['folderId']       = $ids['folderId'];
	$base['mapping']                       = $mapping;
	$base['options']                       = isset( $base['options'] ) && is_array( $base['options'] )
		? $base['options']
		: array();
	$base['options']['anonymize']          = $anon;
	$key                                   = isset( $opts['pb_default_anonymize_api_key'] )
		? trim( (string) $opts['pb_default_anonymize_api_key'] )
		: '';
	if ( $anon && '' !== $key ) {
		$base['options']['anonymizeApiKey'] = $key;
		if ( ! empty( $opts['pb_default_anonymize_endpoint'] ) ) {
			$base['options']['anonymizeEndpoint'] = $opts['pb_default_anonymize_endpoint'];
		}
	}
	$validated = Portal_Definition::from_json( wp_json_encode( $base ) );
	if ( is_wp_error( $validated ) ) {
		throw new Exception( $validated->get_error_message() );
	}
	return $validated;
}

/**
 * @param Zysys_FileStore $store     FileStore.
 * @param string          $folder_id Parent folder.
 * @return string[]
 */
function list_drive_names( $store, $folder_id ) {
	$names = array();
	foreach ( list_drive_children( $store, $folder_id, false ) as $file ) {
		$names[] = $file['name'];
	}
	return $names;
}

/**
 * @param Zysys_FileStore $store     FileStore.
 * @param string          $folder_id Parent folder.
 * @return array<int,array{id:string,name:string}>
 */
function list_drive_folders( $store, $folder_id ) {
	return list_drive_children( $store, $folder_id, true );
}

/**
 * @param Zysys_FileStore $store     FileStore.
 * @param string          $folder_id Parent folder.
 * @param bool            $folders   Folders only when true.
 * @return array<int,array{id:string,name:string}>
 */
function list_drive_children( $store, $folder_id, $folders ) {
	$drive = $store->__get( 'driveAgent' );
	if ( ! $drive ) {
		throw new Exception( 'Drive agent not configured' );
	}
	$q = sprintf( "'%s' in parents and trashed = false", $folder_id );
	if ( $folders ) {
		$q .= " and mimeType = 'application/vnd.google-apps.folder'";
	}
	$resp  = $drive->files->listFiles(
		array(
			'q'        => $q,
			'fields'   => 'files(id,name,mimeType)',
			'pageSize' => 100,
		)
	);
	$out   = array();
	$files = $resp ? $resp->getFiles() : array();
	foreach ( $files as $file ) {
		$out[] = array(
			'id'   => (string) $file->getId(),
			'name' => (string) $file->getName(),
		);
	}
	return $out;
}

/**
 * @param string[] $names Drive file names.
 * @return bool
 */
function names_include_pdf( array $names ) {
	foreach ( $names as $name ) {
		if ( false !== stripos( (string) $name, '.pdf' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * @param Zysys_FileStore $store   FileStore.
 * @param string          $file_id Drive file id.
 * @return string
 */
function drive_file_bytes( $store, $file_id ) {
	$drive = $store->__get( 'driveAgent' );
	if ( ! $drive || '' === $file_id ) {
		return '';
	}
	$resp = $drive->files->get( $file_id, array( 'alt' => 'media' ) );
	if ( is_string( $resp ) ) {
		return $resp;
	}
	if ( is_object( $resp ) && method_exists( $resp, 'getBody' ) ) {
		return (string) $resp->getBody();
	}
	return '';
}
