<?php
/**
 * CLI harness: drive Portal_Notification_When with stubbed get_post_meta.
 *
 * Usage:
 *   php tests/support/php-notification-when.php <payload.json>
 *
 * Payload: { "portal_id": 1, "meta": { "<key>": "<value>" } }
 * Prints { phrase, sentence } from the shipped class.
 */

// phpcs:disable
function get_post_meta( $post_id, $key, $single = true ) {
	unset( $post_id, $single );
	$store = isset( $GLOBALS['dg_notification_meta'] ) && is_array( $GLOBALS['dg_notification_meta'] )
		? $GLOBALS['dg_notification_meta']
		: array();
	if ( ! array_key_exists( $key, $store ) ) {
		return '';
	}
	return $store[ $key ];
}

$repo_root = dirname( __DIR__, 2 );
$when_file = $repo_root . '/includes/Submission/class-portal-notification-when.php';
if ( ! is_readable( $when_file ) ) {
	fwrite( STDERR, "missing Portal_Notification_When\n" );
	exit( 1 );
}
require_once $when_file;

$path = $argv[1] ?? '';
if ( ! is_readable( $path ) ) {
	fwrite( STDERR, "unreadable $path\n" );
	exit( 2 );
}

$payload = json_decode( file_get_contents( $path ), true );
if ( ! is_array( $payload ) ) {
	fwrite( STDERR, "payload must be a JSON object\n" );
	exit( 2 );
}

if ( ! class_exists( 'Portal_Notification_When' ) ) {
	fwrite( STDERR, "Portal_Notification_When missing\n" );
	exit( 1 );
}

$GLOBALS['dg_notification_meta'] = isset( $payload['meta'] ) && is_array( $payload['meta'] )
	? $payload['meta']
	: array();
$portal_id = isset( $payload['portal_id'] ) ? $payload['portal_id'] : 1;

$phrase   = Portal_Notification_When::phrase( $portal_id );
$sentence = Portal_Notification_When::success_copy( $phrase );

echo json_encode(
	array(
		'phrase'   => $phrase,
		'sentence' => $sentence,
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) . "\n";
exit( 0 );
