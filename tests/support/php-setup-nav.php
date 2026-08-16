<?php
/**
 * CLI harness: setup submenu + edit-target / duplicate / legacy decisions (no WordPress boot).
 */

// phpcs:disable
function add_action() {}
function add_filter() {}
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}
function add_query_arg( $args, $url ) {
	$sep = false === strpos( (string) $url, '?' ) ? '?' : '&';
	return $url . $sep . http_build_query( $args );
}

$root = dirname( __DIR__, 2 );
require_once $root . '/includes/class-portal-setup-screen.php';
require_once $root . '/includes/class-portal-post-type.php';
require_once $root . '/includes/class-portal-meta.php';

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
	$probe = isset( $case['probe'] ) ? (string) $case['probe'] : 'submenu';
	$name  = isset( $case['name'] ) ? $case['name'] : '';

	if ( 'edit_target' === $probe ) {
		$pagenow   = isset( $case['pagenow'] ) ? (string) $case['pagenow'] : '';
		$query     = isset( $case['query'] ) && is_array( $case['query'] ) ? $case['query'] : array();
		$post_type = isset( $case['post_type'] ) ? (string) $case['post_type'] : '';
		$decision  = Portal_Setup_Screen::edit_target( $pagenow, $query, $post_type );
		$out[]     = array(
			'name'      => $name,
			'probe'     => $probe,
			'kind'      => isset( $decision['kind'] ) ? $decision['kind'] : '',
			'portal_id' => isset( $decision['portal_id'] ) ? (int) $decision['portal_id'] : 0,
		);
		continue;
	}

	if ( 'duplicate_next_url' === $probe ) {
		$portal_id = isset( $case['portal_id'] ) ? (int) $case['portal_id'] : 0;
		$out[]     = array(
			'name'  => $name,
			'probe' => $probe,
			'url'   => Portal_Post_Type::duplicate_next_url( $portal_id ),
		);
		continue;
	}

	if ( 'legacy_fields_label' === $probe ) {
		$out[] = array(
			'name'  => $name,
			'probe' => $probe,
			'label' => Portal_Setup_Screen::legacy_fields_label(),
		);
		continue;
	}

	if ( 'legacy_fields_url' === $probe ) {
		$portal_id = isset( $case['portal_id'] ) ? (int) $case['portal_id'] : 0;
		$out[]     = array(
			'name'  => $name,
			'probe' => $probe,
			'url'   => Portal_Setup_Screen::legacy_fields_url( $portal_id ),
			'slug'  => Portal_Setup_Screen::legacy_fields_menu_slug( $portal_id ),
		);
		continue;
	}

	if ( 'wizard_on_post_php' === $probe ) {
		$out[] = array(
			'name'       => $name,
			'probe'      => $probe,
			'registers'  => Portal_Meta::registers_wizard_on_post_editor(),
		);
		continue;
	}

	$portal_id      = isset( $case['portal_id'] ) ? (int) $case['portal_id'] : 0;
	$post_status    = isset( $case['post_status'] ) ? (string) $case['post_status'] : 'draft';
	$post_title     = isset( $case['post_title'] ) ? (string) $case['post_title'] : '';
	$has_definition = ! empty( $case['has_definition'] );
	$out[]          = array(
		'name'         => $name,
		'probe'        => 'submenu',
		'submenu_file' => Portal_Setup_Screen::setup_submenu_file(
			$portal_id,
			$post_status,
			$post_title,
			$has_definition
		),
		'is_fresh'     => Portal_Setup_Screen::is_fresh_add_new_draft(
			$post_status,
			$post_title,
			$has_definition
		),
	);
}

echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
exit( 0 );
