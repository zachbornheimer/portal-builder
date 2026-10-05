<?php
/**
 * Drive shipped Portal_Spam_Gate::admit.
 *
 * Usage:
 *   php tests/support/php-spam-gate.php '{"audience":"anyone","token":"","secret":"s","site":"site"}'
 */
// phpcs:disable
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code, $message ) {
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
}

$GLOBALS['dg_options'] = array();
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['dg_options'] )
		? $GLOBALS['dg_options'][ $name ]
		: $default;
}

function get_current_user_id() {
	return isset( $GLOBALS['dg_wp_user_id'] ) ? (int) $GLOBALS['dg_wp_user_id'] : 0;
}

$repo = dirname( __DIR__, 2 );
require_once $repo . '/includes/class-portal-options.php';
require_once $repo . '/includes/Submission/class-portal-files.php';
require_once $repo . '/includes/Submission/class-portal-submit-log.php';
require_once $repo . '/includes/Submission/class-portal-spam-reject-log.php';
require_once $repo . '/includes/Submission/class-portal-spam-gate.php';

$in = json_decode( isset( $argv[1] ) ? $argv[1] : '{}', true );
$in = is_array( $in ) ? $in : array();

$has_secret = array_key_exists( 'secret', $in );
$has_site   = array_key_exists( 'site', $in );
$GLOBALS['dg_options'][ Portal_Spam_Gate::OPTION_SECRET ] = $has_secret ? $in['secret'] : 'test-secret';
$GLOBALS['dg_options'][ Portal_Spam_Gate::OPTION_SITE ]   = $has_site ? $in['site'] : 'test-site';
$GLOBALS['dg_wp_user_id'] = isset( $in['wpUserId'] ) ? (int) $in['wpUserId'] : 0;

if ( ! empty( $in['remoteIp'] ) && is_string( $in['remoteIp'] ) ) {
	$_SERVER['REMOTE_ADDR'] = $in['remoteIp'];
}
if ( ! empty( $in['userAgent'] ) && is_string( $in['userAgent'] ) ) {
	$_SERVER['HTTP_USER_AGENT'] = $in['userAgent'];
}

$log_dir = isset( $in['logDir'] ) && is_string( $in['logDir'] ) && '' !== $in['logDir']
	? $in['logDir']
	: sys_get_temp_dir() . '/dg-spam-reject-' . getmypid();
if ( ! is_dir( $log_dir ) ) {
	mkdir( $log_dir, 0755, true );
}
$reject_log = new Portal_Spam_Reject_Log( $log_dir );
Portal_Spam_Gate::set_reject_log( $reject_log );

$definition = array(
	'access' => array(
		'audience' => isset( $in['audience'] ) ? $in['audience'] : 'anyone',
	),
);
$values = array();
if ( isset( $in['token'] ) ) {
	$values[ Portal_Spam_Gate::TOKEN_FIELD ] = $in['token'];
}
if ( isset( $in['email'] ) && is_string( $in['email'] ) ) {
	$values['sub_email'] = $in['email'];
}
foreach ( array( 'sub_email', 'email', 'applicant_email' ) as $ek ) {
	if ( isset( $in[ $ek ] ) && is_string( $in[ $ek ] ) ) {
		$values[ $ek ] = $in[ $ek ];
	}
}

$portal_id = isset( $in['portalId'] ) ? $in['portalId'] : 'test-portal';
$accept    = ! empty( $in['accept'] );
$err       = Portal_Spam_Gate::admit(
	$definition,
	$values,
	function ( $token, $secret ) use ( $accept ) {
		unset( $secret );
		return $accept && '' !== $token;
	},
	$portal_id
);

$rejects = $reject_log->last( 50 );
$log_path = $reject_log->path();
$raw_log  = is_file( $log_path ) ? file_get_contents( $log_path ) : '';

echo json_encode(
	array(
		'required'   => Portal_Spam_Gate::required_for_audience( $definition['access']['audience'] ),
		'complete'   => Portal_Spam_Gate::keys_complete(),
		'incomplete' => Portal_Spam_Gate::keys_incomplete(),
		'ok'         => ! is_wp_error( $err ) && null === $err,
		'code'       => is_wp_error( $err ) ? $err->get_error_code() : null,
		'message'    => is_wp_error( $err ) ? $err->get_error_message() : null,
		'rejects'    => $rejects,
		'logPath'    => $log_path,
		'logRaw'     => is_string( $raw_log ) ? $raw_log : '',
	)
) . "\n";

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
