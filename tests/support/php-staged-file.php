<?php
/**
 * CLI harness: Portal_Staged_File retained_name / stage / read / forget.
 *
 * Usage:
 *   php tests/support/php-staged-file.php <scenario.json>
 *
 * scenario.entry:
 *   retained_name | stage | read | forget | pipeline
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
			'method'          => isset( $request['method'] ) ? (string) $request['method'] : '',
			'url'             => $url,
			'headers'         => $headers,
			'has_multipart'   => ! empty( $request['multipart'] ),
			'authorization'   => isset( $headers['Authorization'] ) ? (string) $headers['Authorization'] : '',
			'idempotency_key' => isset( $headers['Idempotency-Key'] ) ? (string) $headers['Idempotency-Key'] : '',
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
$staged_rest = $repo_root . '/includes/class-portal-staged-file-rest.php';
if ( is_readable( $staged_rest ) ) {
	require_once $staged_rest;
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

$entry = isset( $scenario['entry'] ) ? (string) $scenario['entry'] : 'retained_name';

if ( ! class_exists( 'Portal_Staged_File' ) ) {
	echo json_encode(
		array(
			'ok'      => false,
			'code'    => 'dg_staged_missing',
			'message' => 'Portal_Staged_File not loaded',
		)
	) . "\n";
	exit( 1 );
}

if ( 'retained_name' === $entry ) {
	$original = isset( $scenario['original'] ) ? (string) $scenario['original'] : '';
	$suffix   = isset( $scenario['suffix'] ) ? (string) $scenario['suffix'] : '';
	echo json_encode(
		array(
			'ok'           => true,
			'retainedName' => Portal_Staged_File::retained_name( $original, $suffix ),
		)
	) . "\n";
	exit( 0 );
}

$artifact = isset( $scenario['artifactDir'] )
	? (string) $scenario['artifactDir']
	: ( $repo_root . '/tests/.artifacts' );
if ( ! is_absolute_path_sf( $artifact ) ) {
	$artifact = $repo_root . DIRECTORY_SEPARATOR . ltrim( $artifact, '/\\' );
}
$base_dir = $artifact . DIRECTORY_SEPARATOR . 'staged';

$files_facade = new Portal_Files();
$transport    = new Portal_Anonymizer_Fake_Transport();
$transport->script = isset( $scenario['script'] ) && is_array( $scenario['script'] )
	? $scenario['script']
	: array();

$staging = new Portal_Staged_File( $files_facade, $transport, $base_dir );

if ( 'stage' === $entry ) {
	$portal_id     = isset( $scenario['portalId'] ) ? (string) $scenario['portalId'] : 'stage-cli';
	$field_id      = isset( $scenario['fieldId'] ) ? (string) $scenario['fieldId'] : 'score';
	$original_name = isset( $scenario['originalName'] ) ? (string) $scenario['originalName'] : 'blank.pdf';
	$buffer        = isset( $scenario['buffer'] ) ? (string) $scenario['buffer'] : "%PDF-1.4\n%original\n";
	// Allow base64 for binary fixtures.
	if ( ! empty( $scenario['bufferB64'] ) ) {
		$decoded = base64_decode( (string) $scenario['bufferB64'], true );
		if ( false !== $decoded ) {
			$buffer = $decoded;
		}
	}
	$definition = isset( $scenario['definition'] ) && is_array( $scenario['definition'] )
		? $scenario['definition']
		: array(
			'version' => 1,
			'fields'  => array(
				array(
					'id'         => $field_id,
					'type'       => 'score_file',
					'label'      => 'Score',
					'required'   => true,
					'fileSuffix' => isset( $scenario['fileSuffix'] ) ? (string) $scenario['fileSuffix'] : '_SCORE',
				),
			),
			'options' => isset( $scenario['options'] ) && is_array( $scenario['options'] )
				? $scenario['options']
				: array( 'anonymize' => false ),
		);

	$result = $staging->stage( $portal_id, $field_id, $buffer, $original_name, $definition );
	if ( is_wp_error( $result ) ) {
		echo json_encode(
			array(
				'ok'      => false,
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
				'calls'   => $transport->calls,
			)
		) . "\n";
		exit( 1 );
	}

	$stored_bytes = '';
	$read         = $staging->read( $result['token'] );
	if ( is_array( $read ) && isset( $read['bytes'] ) ) {
		$stored_bytes = (string) $read['bytes'];
	} elseif ( is_array( $read ) && isset( $read['path'] ) && is_readable( $read['path'] ) ) {
		$stored_bytes = (string) file_get_contents( $read['path'] );
	}

	echo json_encode(
		array(
			'ok'               => true,
			'token'            => isset( $result['token'] ) ? $result['token'] : '',
			'storedName'       => isset( $result['storedName'] ) ? $result['storedName'] : '',
			'originalName'     => isset( $result['originalName'] ) ? $result['originalName'] : '',
			'bytes'            => isset( $result['bytes'] ) ? $result['bytes'] : 0,
			'anonymized'       => ! empty( $result['anonymized'] ),
			'storedEqualsOrig' => $stored_bytes === $buffer,
			'storedBytesLen'   => strlen( $stored_bytes ),
			'callCount'        => count( $transport->calls ),
			'calls'            => $transport->calls,
			'read'             => is_array( $read ) ? array(
				'token'        => isset( $read['token'] ) ? $read['token'] : null,
				'storedName'   => isset( $read['storedName'] ) ? $read['storedName'] : null,
				'originalName' => isset( $read['originalName'] ) ? $read['originalName'] : null,
				'anonymized'   => ! empty( $read['anonymized'] ),
				'bytesLen'     => isset( $read['bytes'] ) ? strlen( (string) $read['bytes'] ) : (
					isset( $read['path'] ) && is_readable( $read['path'] )
						? strlen( (string) file_get_contents( $read['path'] ) )
						: 0
				),
			) : null,
		)
	) . "\n";
	exit( 0 );
}

if ( 'read' === $entry ) {
	$token  = isset( $scenario['token'] ) ? (string) $scenario['token'] : '';
	$result = $staging->read( $token );
	if ( is_wp_error( $result ) ) {
		echo json_encode(
			array(
				'ok'      => false,
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			)
		) . "\n";
		exit( 1 );
	}
	if ( null === $result || false === $result ) {
		echo json_encode( array( 'ok' => false, 'code' => 'dg_staged_not_found' ) ) . "\n";
		exit( 1 );
	}
	$bytes = '';
	if ( isset( $result['bytes'] ) ) {
		$bytes = (string) $result['bytes'];
	} elseif ( isset( $result['path'] ) && is_readable( $result['path'] ) ) {
		$bytes = (string) file_get_contents( $result['path'] );
	}
	echo json_encode(
		array(
			'ok'           => true,
			'token'        => $token,
			'storedName'   => isset( $result['storedName'] ) ? $result['storedName'] : '',
			'originalName' => isset( $result['originalName'] ) ? $result['originalName'] : '',
			'anonymized'   => ! empty( $result['anonymized'] ),
			'bytesLen'     => strlen( $bytes ),
			'bytesB64'     => base64_encode( $bytes ),
		)
	) . "\n";
	exit( 0 );
}

if ( 'forget' === $entry ) {
	$token = isset( $scenario['token'] ) ? (string) $scenario['token'] : '';
	$staging->forget( $token );
	$after = $staging->read( $token );
	$gone  = is_wp_error( $after ) || null === $after || false === $after;
	echo json_encode(
		array(
			'ok'   => true,
			'gone' => $gone,
		)
	) . "\n";
	exit( 0 );
}

if ( 'serve' === $entry ) {
	// Stage then build the GET response; assert body is raw bytes, not JSON.
	if ( ! class_exists( 'Portal_Staged_File_REST' ) ) {
		echo json_encode(
			array(
				'ok'      => false,
				'code'    => 'dg_staged_rest_missing',
				'message' => 'Portal_Staged_File_REST not loaded',
			)
		) . "\n";
		exit( 1 );
	}

	$portal_id     = isset( $scenario['portalId'] ) ? (string) $scenario['portalId'] : 'stage-serve';
	$field_id      = isset( $scenario['fieldId'] ) ? (string) $scenario['fieldId'] : 'score';
	$original_name = isset( $scenario['originalName'] ) ? (string) $scenario['originalName'] : 'blank.pdf';
	$buffer        = isset( $scenario['buffer'] ) ? (string) $scenario['buffer'] : "%PDF-1.4\n%serve-raw-bytes\n";
	$definition    = array(
		'version' => 1,
		'fields'  => array(
			array(
				'id'         => $field_id,
				'type'       => 'score_file',
				'label'      => 'Score',
				'required'   => true,
				'fileSuffix' => '_SCORE',
			),
		),
		'options' => array( 'anonymize' => false ),
	);

	$stage_result = $staging->stage( $portal_id, $field_id, $buffer, $original_name, $definition );
	if ( is_wp_error( $stage_result ) ) {
		echo json_encode(
			array(
				'ok'      => false,
				'phase'   => 'stage',
				'code'    => $stage_result->get_error_code(),
				'message' => $stage_result->get_error_message(),
			)
		) . "\n";
		exit( 1 );
	}

	$token = (string) $stage_result['token'];
	$rec   = $staging->read( $token );
	if ( ! is_array( $rec ) ) {
		echo json_encode( array( 'ok' => false, 'code' => 'dg_staged_not_found' ) ) . "\n";
		exit( 1 );
	}

	$response = Portal_Staged_File_REST::raw_bytes_response( $rec );
	$raw_body = Portal_Staged_File_REST::raw_body_from_result( $response );
	if ( null === $raw_body ) {
		echo json_encode(
			array(
				'ok'      => false,
				'code'    => 'dg_serve_not_raw',
				'message' => 'raw_body_from_result returned null (would JSON-encode)',
			)
		) . "\n";
		exit( 1 );
	}

	// Simulate rest_pre_serve_request: capture echoed body.
	$served_flag = false;
	ob_start();
	$served_flag = Portal_Staged_File_REST::serve_raw_request( false, $response, null, null );
	$echoed      = ob_get_clean();

	$json_encoded = json_encode( $raw_body );
	echo json_encode(
		array(
			'ok'                 => true,
			'token'              => $token,
			'storedName'         => $stage_result['storedName'],
			'bodyEqualsStaged'   => $echoed === $buffer,
			'bodyEqualsRaw'      => $echoed === $raw_body,
			'bodyIsJsonString'   => $echoed === $json_encoded,
			'bodyStartsPdf'      => 0 === strpos( $echoed, '%PDF' ),
			'serveReturnedTrue'  => true === $served_flag,
			'contentType'        => is_array( $response ) && isset( $response['headers']['Content-Type'] )
				? $response['headers']['Content-Type']
				: ( is_object( $response ) && method_exists( $response, 'get_headers' )
					? ( isset( $response->get_headers()['Content-Type'] ) ? $response->get_headers()['Content-Type'] : '' )
					: '' ),
			'echoedLen'          => strlen( $echoed ),
			'stagedLen'          => strlen( $buffer ),
		)
	) . "\n";
	exit( 0 );
}

if ( 'pipeline' === $entry ) {
	// Stage, then submit via pipeline with token; assert anonymizer not re-called.
	$portal_id     = isset( $scenario['portalId'] ) ? (string) $scenario['portalId'] : 'stage-pipe';
	$field_id      = isset( $scenario['fieldId'] ) ? (string) $scenario['fieldId'] : 'score';
	$original_name = isset( $scenario['originalName'] ) ? (string) $scenario['originalName'] : 'blank.pdf';
	$buffer        = isset( $scenario['buffer'] ) ? (string) $scenario['buffer'] : "%PDF-1.4\n%original-bytes\n";
	if ( ! empty( $scenario['bufferB64'] ) ) {
		$decoded = base64_decode( (string) $scenario['bufferB64'], true );
		if ( false !== $decoded ) {
			$buffer = $decoded;
		}
	}
	$anon_bytes = isset( $scenario['anonBytes'] ) ? (string) $scenario['anonBytes'] : "%PDF-1.4\n%anonymized-bytes\n";
	if ( ! empty( $scenario['anonBytesB64'] ) ) {
		$decoded = base64_decode( (string) $scenario['anonBytesB64'], true );
		if ( false !== $decoded ) {
			$anon_bytes = $decoded;
		}
	}
	$anonymize = ! empty( $scenario['anonymize'] );

	$definition = array(
		'version' => 1,
		'title'   => 'Staged pipeline',
		'fields'  => array(
			array(
				'id'       => 'applicant',
				'type'     => 'applicant_pack',
				'label'    => 'Applicant',
				'required' => true,
			),
			array(
				'id'         => $field_id,
				'type'       => 'score_file',
				'label'      => 'Score',
				'required'   => true,
				'fileSuffix' => isset( $scenario['fileSuffix'] ) ? (string) $scenario['fileSuffix'] : '_SCORE',
			),
		),
		'options' => array(
			'anonymize'         => $anonymize,
			'anonymizeEndpoint' => 'https://anon.test',
			'anonymizeApiKey'   => 'test-key-not-real',
		),
	);

	if ( $anonymize ) {
		// Script a successful create + download returning different bytes.
		$transport->script = array(
			array(
				'status' => 200,
				'body'   => json_encode(
					array(
						'object' => 'anonymization',
						'data'   => array(
							'id'        => 'an_stage_pipe',
							'status'    => 'completed',
							'filename'  => 'score.pdf',
							'file_type' => 'application/pdf',
							'bytes'     => strlen( $anon_bytes ),
						),
					)
				),
			),
			array(
				'status' => 200,
				'body'   => $anon_bytes,
			),
		);
		if ( ! empty( $scenario['script'] ) && is_array( $scenario['script'] ) ) {
			$transport->script = $scenario['script'];
		}
	}

	$stage_result = $staging->stage( $portal_id, $field_id, $buffer, $original_name, $definition );
	if ( is_wp_error( $stage_result ) ) {
		echo json_encode(
			array(
				'ok'      => false,
				'phase'   => 'stage',
				'code'    => $stage_result->get_error_code(),
				'message' => $stage_result->get_error_message(),
				'calls'   => $transport->calls,
			)
		) . "\n";
		exit( 1 );
	}

	$calls_after_stage = count( $transport->calls );
	$token             = (string) $stage_result['token'];

	// Build pipeline values + staged token in values map (as process_request would see in POST).
	$values = array(
		'sub_title'                    => 'Mx',
		'sub_name'                     => 'Test Applicant',
		'sub_email'                    => 'applicant@example.com',
		'sub_address_first_part'       => '1 Test St',
		'sub_city'                     => 'Raleigh',
		'sub_country'                  => 'US',
		'sub_state'                    => 'NC',
		'sub_phone'                    => '+1-919-555-0100',
		'sub_' . $field_id . '_staged' => $token,
	);
	if ( $anonymize ) {
		$values['sub_anonymize_ack'] = '1';
	}

	$files = Portal_Submission_Pipeline::apply_staged_tokens( $values, array(), $staging );

	$sheets = new Portal_Sheet_Store( $artifact, $files_facade );
	$drive  = new Portal_Drive_Store( $artifact, $files_facade );
	$sheets->clear_portal( $portal_id );
	$drive->clear_portal( $portal_id );
	$fixed_ms = static function () {
		return 1700000000000;
	};
	$mailer   = new Portal_Mailer( $artifact, $files_facade, $fixed_ms );
	$pipeline = new Portal_Submission_Pipeline( $sheets, $drive, $mailer, $files_facade );
	if ( method_exists( $pipeline, 'with_transport' ) ) {
		$pipeline->with_transport( $transport );
	}
	$result = $pipeline->process( $portal_id, $definition, $values, $files );

	if ( is_wp_error( $result ) ) {
		echo json_encode(
			array(
				'ok'              => false,
				'phase'           => 'pipeline',
				'code'            => $result->get_error_code(),
				'message'         => $result->get_error_message(),
				'callsAfterStage' => $calls_after_stage,
				'callCount'       => count( $transport->calls ),
				'calls'           => $transport->calls,
			)
		) . "\n";
		exit( 1 );
	}

	$stored = '';
	if ( ! empty( $result['drivePaths'][ $field_id ] ) && is_readable( $result['drivePaths'][ $field_id ] ) ) {
		$stored = (string) file_get_contents( $result['drivePaths'][ $field_id ] );
	}

	$expected_drive = $anonymize ? $anon_bytes : $buffer;
	echo json_encode(
		array(
			'ok'                 => true,
			'token'              => $token,
			'storedName'         => isset( $stage_result['storedName'] ) ? $stage_result['storedName'] : '',
			'anonymized'         => ! empty( $stage_result['anonymized'] ),
			'drivePath'          => isset( $result['drivePaths'][ $field_id ] ) ? $result['drivePaths'][ $field_id ] : null,
			'driveEqualsStaged'  => $stored === $expected_drive,
			'driveEqualsOrig'    => $stored === $buffer,
			'callsAfterStage'    => $calls_after_stage,
			'callCount'          => count( $transport->calls ),
			'extraAnonCalls'     => max( 0, count( $transport->calls ) - $calls_after_stage ),
			'submitOk'           => ! empty( $result['ok'] ),
		)
	) . "\n";
	exit( 0 );
}

fwrite( STDERR, "unknown entry: $entry\n" );
exit( 2 );

/**
 * @param string $path Path.
 * @return bool
 */
function is_absolute_path_sf( $path ) {
	if ( '' === $path ) {
		return false;
	}
	if ( '/' === $path[0] || '\\' === $path[0] ) {
		return true;
	}
	return (bool) preg_match( '/^[A-Za-z]:[\\\\\\/]/', $path );
}
