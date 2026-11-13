<?php
/**
 * CLI harness: receipt URL/HMAC + mailer interpolation (no WordPress).
 *
 *   php tests/support/php-receipt.php url <portalId> <appId>
 *   php tests/support/php-receipt.php load <appId> <token>
 *   php tests/support/php-receipt.php render <appId> <token> [name] [email]
 *   php tests/support/php-receipt.php interpolate <template.json> <tokens.json>
 *   php tests/support/php-receipt.php send <message.json> <artifactDir>
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
function esc_html__( $s, $domain = '' ) {
	unset( $domain );
	return esc_html( $s );
}
function sanitize_text_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function wp_unslash( $s ) {
	return is_string( $s ) ? stripslashes( $s ) : $s;
}
function get_the_title( $post_id ) {
	unset( $post_id );
	return '2027 Call for Scores and Papers';
}
function get_permalink( $post_id ) {
	return 'http://localhost:10033/portal/' . (int) $post_id;
}
function home_url( $path = '/' ) {
	return 'http://localhost:10033' . $path;
}
function add_query_arg( $args, $url ) {
	$sep = false === strpos( $url, '?' ) ? '?' : '&';
	return $url . $sep . http_build_query( $args );
}
function add_action() {}
function add_filter() {}
function plugins_url( $path = '', $plugin = '' ) {
	unset( $plugin );
	return 'http://localhost:10033/wp-content/plugins/portal-builder/' . ltrim( (string) $path, '/' );
}

$repo_root = dirname( __DIR__, 2 );

require_once $repo_root . '/includes/Submission/class-portal-files.php';
require_once $repo_root . '/includes/Submission/class-portal-test-mode.php';
require_once $repo_root . '/includes/Submission/class-portal-mailer.php';
$receipt_file = $repo_root . '/includes/Submission/class-portal-receipt.php';
if ( ! is_readable( $receipt_file ) ) {
	fwrite( STDERR, "missing Portal_Receipt\n" );
	exit( 1 );
}
require_once $receipt_file;
require_once $repo_root . '/includes/Definition/class-portal-public-render.php';

$op = isset( $argv[1] ) ? (string) $argv[1] : '';

try {
	$result = run_receipt_op( $op, array_slice( $argv, 2 ) );
} catch ( Exception $e ) {
	fwrite( STDERR, $e->getMessage() . "\n" );
	exit( 1 );
}

echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
exit( 0 );

/**
 * @param string $op   url|load|render|interpolate|send.
 * @param array  $args Remaining argv.
 * @return mixed
 */
function run_receipt_op( $op, array $args ) {
	if ( 'url' === $op ) {
		if ( ! class_exists( 'Portal_Receipt' ) || ! method_exists( 'Portal_Receipt', 'url' ) ) {
			throw new Exception( 'missing Portal_Receipt::url' );
		}
		$portal_id = isset( $args[0] ) ? $args[0] : '25657';
		$app_id    = isset( $args[1] ) ? $args[1] : 'dg_test_app';
		return array(
			'ok'  => true,
			'url' => Portal_Receipt::url( $portal_id, $app_id ),
		);
	}
	if ( 'load' === $op ) {
		if ( ! class_exists( 'Portal_Receipt' ) || ! method_exists( 'Portal_Receipt', 'load' ) ) {
			throw new Exception( 'missing Portal_Receipt::load' );
		}
		$app_id = isset( $args[0] ) ? (string) $args[0] : 'dg_test_app';
		$token  = isset( $args[1] ) ? (string) $args[1] : '';
		store_sample_receipt( $app_id );
		if ( 'good' === $token ) {
			$token = Portal_Receipt::token( $app_id );
		}
		$record = Portal_Receipt::load( $app_id, $token );
		return array(
			'ok'     => is_array( $record ),
			'record' => $record,
		);
	}
	if ( 'render' === $op ) {
		if ( ! method_exists( 'Portal_Public_Render', 'render_receipt_view' ) ) {
			throw new Exception( 'missing render_receipt_view' );
		}
		$app_id = isset( $args[0] ) ? (string) $args[0] : 'dg_test_app';
		$token  = isset( $args[1] ) ? (string) $args[1] : '';
		$name   = isset( $args[2] ) ? (string) $args[2] : 'Alice Secret';
		$email  = isset( $args[3] ) ? (string) $args[3] : 'alice.secret@example.com';
		store_sample_receipt( $app_id, $name, $email );
		if ( 'good' === $token && class_exists( 'Portal_Receipt' ) && method_exists( 'Portal_Receipt', 'token' ) ) {
			$token = Portal_Receipt::token( $app_id );
		} elseif ( 'bad' === $token ) {
			$token = 'deadbeef';
		}
		$_GET = array(
			'dg-receipt' => '1',
			'app'        => $app_id,
			't'          => $token,
			'name'       => $name,
			'email'      => $email,
		);
		return array(
			'ok'   => true,
			'html' => Portal_Public_Render::render_receipt_view( 25657 ),
		);
	}
	if ( 'interpolate' === $op ) {
		if ( ! method_exists( 'Portal_Mailer', 'interpolate' ) ) {
			throw new Exception( 'missing Portal_Mailer::interpolate' );
		}
		$template = load_json_value( isset( $args[0] ) ? $args[0] : '', 'template' );
		$tokens   = load_json_object( isset( $args[1] ) ? $args[1] : '', 'tokens' );
		if ( is_array( $template ) && isset( $template['template'] ) ) {
			$template = $template['template'];
		}
		return array(
			'ok'     => true,
			'result' => Portal_Mailer::interpolate( (string) $template, $tokens ),
		);
	}
	if ( 'send' === $op ) {
		if ( ! method_exists( 'Portal_Mailer', 'send' ) ) {
			throw new Exception( 'missing Portal_Mailer::send' );
		}
		$message  = load_json_object( isset( $args[0] ) ? $args[0] : '', 'message' );
		$artifact = isset( $args[1] ) ? (string) $args[1] : '';
		if ( '' === $artifact ) {
			throw new Exception( 'artifact dir required' );
		}
		$mailer = new Portal_Mailer( $artifact );
		$path   = $mailer->send( $message );
		$body   = is_readable( $path ) ? file_get_contents( $path ) : '{}';
		$payload = json_decode( $body, true );
		return array(
			'ok'      => true,
			'path'    => $path,
			'payload' => is_array( $payload ) ? $payload : array(),
		);
	}
	throw new Exception( 'usage: url|load|render|interpolate|send' );
}

/**
 * @param string $app_id Application id.
 * @param string $name   Applicant name.
 * @param string $email  Applicant email.
 * @return void
 */
function store_sample_receipt( $app_id, $name = 'Alice Secret', $email = 'alice.secret@example.com' ) {
	if ( ! method_exists( 'Portal_Receipt', 'store' ) ) {
		throw new Exception( 'missing Portal_Receipt::store' );
	}
	Portal_Receipt::store(
		$app_id,
		array(
			'applicationId' => $app_id,
			'portalId'      => '25657',
			'portalTitle'   => '2027 Call for Scores and Papers',
			'applicantName' => $name,
			'email'         => $email,
			'selection'     => 'papers',
			'submittedAt'   => 'Aug 15, 2026',
		)
	);
}

/**
 * @param string $path  Path.
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
 * @param string $path  Path.
 * @param string $label Error label.
 * @return mixed
 */
function load_json_value( $path, $label ) {
	return load_json_file( $path, $label );
}

/**
 * @param string $path  Path.
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
