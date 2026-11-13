<?php
/**
 * CLI harness: setup submenu highlight predicates (no WordPress boot).
 */

// phpcs:disable
function add_action() {}
function add_filter() {}

require_once dirname( __DIR__, 2 ) . '/includes/class-portal-setup-screen.php';

$raw = $argv[1] ?? '';
if ( '' === $raw || ! is_readable( $raw ) ) {
	fwrite( STDERR, "unreadable $raw\n" );
	exit( 2 );
}
$cases = json_decode( file_get_contents( $raw ), true );
if ( ! is_array( $cases ) ) {
	fwrite( STDERR, "invalid cases json\n" );
	exit( 2 );
}

$out = array();
foreach ( $cases as $case ) {
	$portal_id      = isset( $case['portal_id'] ) ? (int) $case['portal_id'] : 0;
	$post_status    = isset( $case['post_status'] ) ? (string) $case['post_status'] : 'draft';
	$post_title     = isset( $case['post_title'] ) ? (string) $case['post_title'] : '';
	$has_definition = ! empty( $case['has_definition'] );
	$out[]          = array(
		'name'          => isset( $case['name'] ) ? $case['name'] : '',
		'submenu_file'  => Portal_Setup_Screen::setup_submenu_file(
			$portal_id,
			$post_status,
			$post_title,
			$has_definition
		),
		'is_fresh'      => Portal_Setup_Screen::is_fresh_add_new_draft(
			$post_status,
			$post_title,
			$has_definition
		),
	);
}

echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
exit( 0 );
