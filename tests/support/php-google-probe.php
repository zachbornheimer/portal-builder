<?php
/**
 * CLI harness: shipped Settings Google probe (no WordPress boot, no live Google).
 *
 * Usage:
 *   php tests/support/php-google-probe.php '{"op":"render"}'
 *
 * Prints JSON from Portal_Settings + Portal_Google_Probe — not a reimplementation.
 */

// phpcs:disable
$GLOBALS['dg_wp_die_calls']    = array();
$GLOBALS['dg_can_manage']      = true;
$GLOBALS['dg_nonce_valid']     = true;
$GLOBALS['dg_options']         = array();
$GLOBALS['dg_updated_options'] = array();

function __( $text, $domain = null ) {
	unset( $domain );
	return (string) $text;
}
function _e( $text, $domain = null ) {
	echo __( $text, $domain );
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_html__( $text, $domain = null ) {
	return esc_html( __( $text, $domain ) );
}
function esc_html_e( $text, $domain = null ) {
	echo esc_html__( $text, $domain );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $url ) {
	return (string) $url;
}
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}
function get_option( $name, $default = false ) {
	if ( array_key_exists( $name, $GLOBALS['dg_options'] ) ) {
		return $GLOBALS['dg_options'][ $name ];
	}
	return $default;
}
function update_option( $name, $value ) {
	$GLOBALS['dg_options'][ $name ]         = $value;
	$GLOBALS['dg_updated_options'][ $name ] = $value;
	return true;
}
function current_user_can( $cap, $post_id = 0 ) {
	unset( $post_id );
	return $GLOBALS['dg_can_manage'] && 'manage_options' === $cap;
}
function wp_verify_nonce( $nonce, $action ) {
	unset( $action );
	return $GLOBALS['dg_nonce_valid'] && '' !== (string) $nonce && '0' !== (string) $nonce;
}
function wp_create_nonce( $action ) {
	unset( $action );
	return 'test-nonce';
}
function wp_nonce_field( $action, $name, $referer = true, $echo = true ) {
	unset( $action, $referer );
	$html = '<input type="hidden" name="' . esc_attr( $name ) . '" value="test-nonce" />';
	if ( $echo ) {
		echo $html;
	}
	return $html;
}
function wp_die( $message = '', $title = '', $args = array() ) {
	$GLOBALS['dg_wp_die_calls'][] = array(
		'message' => $message,
		'title'   => $title,
		'args'    => $args,
	);
}
function wp_unslash( $value ) {
	return $value;
}
function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}
function checked( $checked, $current = true, $echo = true ) {
	unset( $checked, $current, $echo );
	return '';
}
function selected( $selected, $current = true, $echo = true ) {
	unset( $selected, $current, $echo );
	return '';
}

/**
 * In-memory options bag the probe can be injected with.
 */
class Harness_Probe_Options {
	/** @var array<string,mixed> */
	public $values;

	/**
	 * @param array<string,mixed> $values Seed.
	 */
	public function __construct( array $values = array() ) {
		$this->values = $values;
	}

	/**
	 * @param string $name    Option name.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( $name, $default = '' ) {
		return array_key_exists( $name, $this->values ) ? $this->values[ $name ] : $default;
	}

	/**
	 * @param string $name  Option name.
	 * @param mixed  $value Stored value.
	 * @return void
	 */
	public function update( $name, $value ) {
		$this->values[ $name ]                  = $value;
		$GLOBALS['dg_updated_options'][ $name ] = $value;
	}
}

/**
 * Fake Drive/Sheets store. Never talks to Google.
 */
class Harness_Probe_Store {
	/** @var string|null */
	public $listed = null;

	/** @var array{sheet_id:string,row:array}|null */
	public $appended = null;

	/** @var \Throwable|null */
	public $list_error = null;

	/** @var \Throwable|null */
	public $append_error = null;

	/**
	 * @param string $folder_id Drive folder id.
	 * @return array<int,array{id:string,name:string}>
	 */
	public function list_folder( $folder_id ) {
		if ( $this->list_error instanceof \Throwable ) {
			throw $this->list_error;
		}
		$this->listed = (string) $folder_id;
		return array(
			array(
				'id'   => 'file-1',
				'name' => 'child',
			),
		);
	}

	/**
	 * @param string $sheet_id Spreadsheet id.
	 * @param array  $row      Cells.
	 * @return void
	 */
	public function append_test_row( $sheet_id, array $row ) {
		if ( $this->append_error instanceof \Throwable ) {
			throw $this->append_error;
		}
		$this->appended = array(
			'sheet_id' => (string) $sheet_id,
			'row'      => $row,
		);
	}
}

$repo_root = dirname( __DIR__, 2 );
require_once $repo_root . '/includes/class-portal-settings.php';

$failure_file = $repo_root . '/includes/adapters/class-portal-google-probe-failure.php';
if ( is_readable( $failure_file ) ) {
	require_once $failure_file;
}
$store_adapter = $repo_root . '/includes/adapters/class-portal-google-probe-store.php';
if ( is_readable( $store_adapter ) ) {
	require_once $store_adapter;
}
$probe_file = $repo_root . '/includes/adapters/class-portal-google-probe.php';
if ( is_readable( $probe_file ) ) {
	require_once $probe_file;
}

$store_file = $repo_root . '/includes/adapters/class-portal-google-store.php';
if ( is_readable( $store_file ) ) {
	require_once $store_file;
}

$raw = isset( $argv[1] ) ? (string) $argv[1] : '';
if ( '' === $raw || '-' === $raw ) {
	$raw = (string) stream_get_contents( STDIN );
} elseif ( is_readable( $raw ) ) {
	$raw = (string) file_get_contents( $raw );
}
$input = json_decode( $raw, true );
if ( ! is_array( $input ) ) {
	fwrite( STDERR, "invalid json\n" );
	exit( 2 );
}

$op = isset( $input['op'] ) ? (string) $input['op'] : '';

if ( ! class_exists( 'Portal_Settings' ) ) {
	echo json_encode( array( 'ok' => false, 'missing' => 'Portal_Settings' ) ) . "\n";
	exit( 0 );
}

$settings = new Portal_Settings();

if ( 'render' === $op ) {
	ob_start();
	$settings->google_api_section_callback();
	$settings->render_google_secret_key_field();
	$settings->render_google_access_key_field();
	if ( method_exists( $settings, 'render_google_probe_field' ) ) {
		$settings->render_google_probe_field();
	}
	$html = (string) ob_get_clean();
	echo json_encode(
		array(
			'ok'             => true,
			'html'           => $html,
			'hasProbeMethod' => method_exists( $settings, 'render_google_probe_field' ),
			'hasProbeClass'  => class_exists( 'Portal_Google_Probe' ),
			'wpDie'          => $GLOBALS['dg_wp_die_calls'],
		),
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	) . "\n";
	exit( 0 );
}

if ( 'list_params' === $op ) {
	if ( ! class_exists( 'Portal_Google_Probe_Store' ) ) {
		echo json_encode( array( 'ok' => false, 'missing' => 'list_folder' ) ) . "\n";
		exit( 0 );
	}

	class Capturing_Drive_List_Files {
		/** @var array<int,array> */
		public $lists = array();

		/**
		 * @param array $opts Options the live client would send.
		 * @return object
		 */
		public function listFiles( $opts = array() ) {
			$this->lists[] = is_array( $opts ) ? $opts : array();
			$resp          = new class() {
				public function getFiles() {
					return array();
				}
			};
			return $resp;
		}
	}

	class Capturing_Drive_List_Agent {
		/** @var Capturing_Drive_List_Files */
		public $files;

		public function __construct() {
			$this->files = new Capturing_Drive_List_Files();
		}
	}

	class Capturing_File_Store {
		/** @var array<string,mixed> */
		private $vars = array();

		/**
		 * @param string $key   Variable name.
		 * @param mixed  $value Value.
		 * @return void
		 */
		public function __set( $key, $value ) {
			$this->vars[ $key ] = $value;
		}

		/**
		 * @param string $key Variable name.
		 * @return mixed
		 */
		public function __get( $key = '' ) {
			return array_key_exists( $key, $this->vars ) ? $this->vars[ $key ] : null;
		}
	}

	$inner = new Capturing_File_Store();
	$agent = new Capturing_Drive_List_Agent();
	$inner->__set( 'driveAgent', $agent );
	$store = new Portal_Google_Probe_Store( $inner );
	$store->list_folder( 'folder-probe-id' );
	$opts = isset( $agent->files->lists[0] ) && is_array( $agent->files->lists[0] )
		? $agent->files->lists[0]
		: array();
	echo json_encode(
		array(
			'ok'                   => true,
			'supportsAllDrives'    => array_key_exists( 'supportsAllDrives', $opts ) ? $opts['supportsAllDrives'] : null,
			'hasSupportsAllDrives' => array_key_exists( 'supportsAllDrives', $opts ),
			'keys'                 => array_keys( $opts ),
		)
	) . "\n";
	exit( 0 );
}

if ( 'row' === $op ) {
	if ( ! class_exists( 'Portal_Google_Probe' ) || ! method_exists( 'Portal_Google_Probe', 'test_row' ) ) {
		echo json_encode( array( 'ok' => false, 'missing' => 'test_row', 'row' => null ) ) . "\n";
		exit( 0 );
	}
	$now = isset( $input['now'] ) ? (string) $input['now'] : '2026-08-16T14:32:01Z';
	$row = Portal_Google_Probe::test_row( $now );
	echo json_encode(
		array(
			'ok'    => true,
			'row'   => $row,
			'wpDie' => $GLOBALS['dg_wp_die_calls'],
		)
	) . "\n";
	exit( 0 );
}

if ( 'probe' === $op || 'handle' === $op ) {
	$GLOBALS['dg_can_manage']  = ! array_key_exists( 'can', $input ) || ! empty( $input['can'] );
	$GLOBALS['dg_nonce_valid'] = ! array_key_exists( 'nonceValid', $input ) || ! empty( $input['nonceValid'] );

	$secret = array_key_exists( 'secret', $input ) ? (string) $input['secret'] : 'oauth-client-json';
	$token  = array_key_exists( 'token', $input ) ? (string) $input['token'] : 'oauth-access-token';
	$now    = isset( $input['now'] ) ? (string) $input['now'] : '2026-08-16T14:32:01Z';
	$folder = isset( $input['folderId'] ) ? (string) $input['folderId'] : '';
	$sheet  = isset( $input['sheetId'] ) ? (string) $input['sheetId'] : '';
	$nonce  = array_key_exists( 'nonce', $input ) ? (string) $input['nonce'] : 'test-nonce';

	$secret_key = class_exists( 'Portal_Google_Store' ) ? Portal_Google_Store::OPTION_SECRET_KEY : 'pb_google_secret_key';
	$token_key  = class_exists( 'Portal_Google_Store' ) ? Portal_Google_Store::OPTION_ACCESS_KEY : 'pb_google_access_key';

	$options = new Harness_Probe_Options(
		array(
			$secret_key => $secret,
			$token_key  => $token,
		)
	);
	$GLOBALS['dg_options'][ $secret_key ] = $secret;
	$GLOBALS['dg_options'][ $token_key ]  = $token;

	$store = new Harness_Probe_Store();
	if ( ! empty( $input['listError'] ) ) {
		$code              = isset( $input['listErrorCode'] ) ? (int) $input['listErrorCode'] : 0;
		$store->list_error = new Exception( (string) $input['listError'], $code );
	}
	if ( ! empty( $input['appendError'] ) ) {
		$code                = isset( $input['appendErrorCode'] ) ? (int) $input['appendErrorCode'] : 0;
		$store->append_error = new Exception( (string) $input['appendError'], $code );
	}

	$probe = null;
	if ( class_exists( 'Portal_Google_Probe' ) ) {
		$probe = new Portal_Google_Probe(
			$store,
			function () use ( $now ) {
				return $now;
			},
			$options
		);
	}

	if ( 'probe' === $op ) {
		if ( ! $probe || ! method_exists( $probe, 'run' ) ) {
			echo json_encode( array( 'ok' => false, 'missing' => 'run', 'wpDie' => $GLOBALS['dg_wp_die_calls'] ) ) . "\n";
			exit( 0 );
		}
		$result = $probe->run( $folder, $sheet );
	} else {
		if ( ! method_exists( $settings, 'handle_google_probe' ) ) {
			echo json_encode( array( 'ok' => false, 'missing' => 'handle_google_probe', 'wpDie' => $GLOBALS['dg_wp_die_calls'] ) ) . "\n";
			exit( 0 );
		}
		$request = array(
			'dg_google_probe_folder_id' => $folder,
			'dg_google_probe_sheet_id'  => $sheet,
			'dg_google_probe_nonce'     => $nonce,
		);
		$result  = $settings->handle_google_probe( $request, $probe );
	}

	if ( ! is_array( $result ) ) {
		$result = array( 'ok' => false, 'message' => (string) $result );
	}
	$result['wpDie']    = $GLOBALS['dg_wp_die_calls'];
	$result['listed']   = $store->listed;
	$result['appended'] = $store->appended;
	$result['updated']  = $GLOBALS['dg_updated_options'];
	echo json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	exit( 0 );
}

fwrite( STDERR, "unknown op\n" );
exit( 2 );
