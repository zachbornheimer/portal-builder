<?php
/**
 * CLI harness: definition submit anonymize path with a scripted transport.
 *
 * Usage:
 *   php tests/support/php-anonymize.php <scenario.json>
 *
 * Never contacts a live host. The production Bearer must not appear in
 * scenarios, fixtures, or this file.
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

/**
 * Scripted transport: records each send() and returns the next queued reply.
 */
class Portal_Anonymizer_Fake_Transport {
	public $script = array();
	public $calls  = array();

	public function send( array $request ) {
		$headers = isset( $request['headers'] ) && is_array( $request['headers'] )
			? $request['headers']
			: array();
		$url     = isset( $request['url'] ) ? (string) $request['url'] : '';
		$this->calls[] = array(
			'method'           => isset( $request['method'] ) ? (string) $request['method'] : '',
			'url'              => $url,
			'path'             => self::url_path( $url ),
			'headers'          => $headers,
			'timeout'          => isset( $request['timeout'] ) ? (int) $request['timeout'] : 0,
			'has_multipart'    => ! empty( $request['multipart'] ),
			'authorization'    => isset( $headers['Authorization'] ) ? (string) $headers['Authorization'] : '',
			'idempotency_key'  => isset( $headers['Idempotency-Key'] ) ? (string) $headers['Idempotency-Key'] : '',
			'url_has_key'      => self::url_leaks_secret( $url, $headers ),
		);

		if ( empty( $this->script ) ) {
			throw new RuntimeException( 'unexpected transport call' );
		}
		$next = array_shift( $this->script );
		if ( ! empty( $next['throw'] ) ) {
			throw new RuntimeException( (string) $next['throw'] );
		}
		return array(
			'status'  => isset( $next['status'] ) ? (int) $next['status'] : 0,
			'headers' => isset( $next['headers'] ) && is_array( $next['headers'] ) ? $next['headers'] : array(),
			'body'    => isset( $next['body'] ) ? (string) $next['body'] : '',
		);
	}

	private static function url_path( $url ) {
		$path = parse_url( $url, PHP_URL_PATH );
		return is_string( $path ) ? $path : '';
	}

	private static function url_leaks_secret( $url, array $headers ) {
		$auth = isset( $headers['Authorization'] ) ? (string) $headers['Authorization'] : '';
		if ( '' === $auth ) {
			return false;
		}
		$parts = preg_split( '/\s+/', $auth, 2 );
		$secret = isset( $parts[1] ) ? $parts[1] : '';
		if ( '' === $secret ) {
			return false;
		}
		return false !== strpos( $url, $secret );
	}
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
require_once $repo_root . '/includes/Submission/class-portal-submission-selections.php';
require_once $repo_root . '/includes/Submission/class-portal-submit-admission.php';
require_once $repo_root . '/includes/Submission/class-portal-submission-destinations.php';
require_once $repo_root . '/includes/Submission/class-portal-submission-pipeline.php';

$anon_units = array(
	$repo_root . '/includes/Submission/class-portal-file-signature.php',
	$repo_root . '/includes/Submission/class-portal-anonymizer-transport.php',
	$repo_root . '/includes/Submission/class-portal-anonymizer-reply.php',
	$repo_root . '/includes/Submission/class-portal-anonymizer-api.php',
	$repo_root . '/includes/Submission/class-portal-anonymizer.php',
);
foreach ( $anon_units as $unit ) {
	if ( is_readable( $unit ) ) {
		require_once $unit;
	}
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

$entry      = isset( $scenario['entry'] ) ? (string) $scenario['entry'] : 'pipeline';
$options    = isset( $scenario['options'] ) && is_array( $scenario['options'] ) ? $scenario['options'] : array();
$original   = isset( $scenario['original'] ) ? (string) $scenario['original'] : '';
$filename   = isset( $scenario['filename'] ) ? (string) $scenario['filename'] : 'score.pdf';
$declared   = isset( $scenario['declared_type'] ) ? $scenario['declared_type'] : null;
$download   = isset( $scenario['download'] ) ? (string) $scenario['download'] : '';
$artifact   = isset( $scenario['artifactDir'] ) ? (string) $scenario['artifactDir'] : ( $repo_root . '/tests/.artifacts/anonymize' );
$portal_id  = isset( $scenario['portalId'] ) ? (string) $scenario['portalId'] : 'anon-cli';

$transport         = new Portal_Anonymizer_Fake_Transport();
$transport->script = isset( $scenario['script'] ) && is_array( $scenario['script'] )
	? $scenario['script']
	: array();

$anonymizer_loaded = class_exists( 'Portal_Anonymizer' );

if ( 'anonymizer' === $entry ) {
	if ( ! $anonymizer_loaded ) {
		echo json_encode( fail_open_payload( $original, $download, $transport, false ) ) . "\n";
		exit( 0 );
	}
	$anonymizer = new Portal_Anonymizer( $options, $transport );
	$result     = $anonymizer->maybe_anonymize( $original, $filename, $declared );
	echo json_encode(
		result_payload( $result, $original, $download, $transport, true, true )
	) . "\n";
	exit( 0 );
}

$definition = Portal_Definition::validate(
	array(
		'version' => 1,
		'title'   => 'Anonymize harness',
		'fields'  => array(
			array(
				'id'       => 'applicant',
				'type'     => 'applicant_pack',
				'label'    => 'Applicant',
				'required' => true,
			),
			array(
				'id'       => 'score',
				'type'     => 'score_file',
				'label'    => 'Score',
				'required' => true,
			),
		),
		'options' => $options,
	)
);
if ( is_wp_error( $definition ) ) {
	fwrite( STDERR, $definition->get_error_code() . ': ' . $definition->get_error_message() . "\n" );
	exit( 1 );
}

$values = array(
	'sub_title'              => 'Mx',
	'sub_name'               => 'Test Applicant',
	'sub_email'              => 'applicant@example.com',
	'sub_address_first_part' => '1 Test St',
	'sub_city'               => 'Raleigh',
	'sub_country'            => 'US',
	'sub_state'              => 'NC',
	'sub_phone'              => '+1-919-555-0100',
);
$files  = array(
	'score' => array(
		'name'     => $filename,
		'contents' => $original,
	),
);

$files_facade = new Portal_Files();
$sheets       = new Portal_Sheet_Store( $artifact, $files_facade );
$drive        = new Portal_Drive_Store( $artifact, $files_facade );
$sheets->clear_portal( $portal_id );
$drive->clear_portal( $portal_id );

$fixed_ms = static function () {
	return 1700000000000;
};

$pipeline = pipeline_with_transport( $sheets, $drive, $files_facade, $fixed_ms, $artifact, $transport );
$result   = $pipeline->process( $portal_id, $definition, $values, $files );

if ( is_wp_error( $result ) ) {
	echo json_encode(
		array(
			'ok'                      => false,
			'submit_ok'               => false,
			'anonymizer_loaded'       => $anonymizer_loaded,
			'code'                    => $result->get_error_code(),
			'message'                 => $result->get_error_message(),
			'stored_equals_original'  => null,
			'stored_equals_download'  => false,
			'calls'                   => $transport->calls,
		)
	) . "\n";
	exit( 0 );
}

$stored = '';
if ( ! empty( $result['drivePaths']['score'] ) && is_readable( $result['drivePaths']['score'] ) ) {
	$stored = (string) file_get_contents( $result['drivePaths']['score'] );
}

echo json_encode(
	result_payload( $stored, $original, $download, $transport, true, $anonymizer_loaded )
) . "\n";
exit( 0 );

/**
 * @param Portal_Sheet_Store                  $sheets     Sheets.
 * @param Portal_Drive_Store                  $drive      Drive.
 * @param Portal_Files                        $files      FS.
 * @param callable                            $now_ms     Clock.
 * @param string                              $artifact   Artifact dir.
 * @param Portal_Anonymizer_Fake_Transport    $transport  Fake transport.
 * @return Portal_Submission_Pipeline
 */
function pipeline_with_transport( $sheets, $drive, $files, $now_ms, $artifact, $transport ) {
	$mailer   = new Portal_Mailer( $artifact, $files, $now_ms );
	$pipeline = new Portal_Submission_Pipeline( $sheets, $drive, $mailer, $files );
	if ( method_exists( $pipeline, 'with_transport' ) ) {
		$pipeline->with_transport( $transport );
	}
	return $pipeline;
}

/**
 * @param string                           $original  Original bytes.
 * @param string                           $download  Expected download.
 * @param Portal_Anonymizer_Fake_Transport $transport Transport.
 * @param bool                             $loaded    Whether Anonymizer exists.
 * @return array
 */
function fail_open_payload( $original, $download, $transport, $loaded ) {
	return result_payload( $original, $original, $download, $transport, true, $loaded );
}

/**
 * @param string                           $result    Result bytes.
 * @param string                           $original  Original bytes.
 * @param string                           $download  Download bytes.
 * @param Portal_Anonymizer_Fake_Transport $transport Transport.
 * @param bool                             $submit_ok Submission completed.
 * @param bool                             $loaded    Whether Anonymizer exists.
 * @return array
 */
function result_payload( $result, $original, $download, $transport, $submit_ok, $loaded ) {
	return array(
		'ok'                     => true,
		'submit_ok'              => $submit_ok,
		'anonymizer_loaded'      => $loaded,
		'stored_equals_original' => $result === $original,
		'stored_equals_download' => ( '' !== $download && $result === $download ),
		'call_count'             => count( $transport->calls ),
		'calls'                  => $transport->calls,
	);
}
