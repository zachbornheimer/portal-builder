#!/usr/bin/env php
<?php
/**
 * Patch portal 25657 fieldDest extras for receipt / date / email columns.
 *
 *   php tests/support/php-25657-dests.php
 *
 * Adds (idempotent) dest tokens on All Submissions:
 *   email → Email
 *   dateReceived → Date Received
 *   receiptUrl → Application Receipt Link
 * Keeps applicationId / files on both sheets and applicant → Applicant.
 */

// phpcs:disable
$sock = getenv( 'DG_MYSQL_SOCKET' );
if ( ! is_string( $sock ) || '' === $sock ) {
	$sock = $_SERVER['HOME'] . '/Library/Application Support/Local/run/MbehRUdOC/mysql/mysqld.sock';
}

const PORTAL_ID  = 25657;
const META_KEY   = '_portal_definition';
const TABLE      = 'B3sRggSMK1_postmeta';
const ALL_SHEET  = 'All Submissions';
const ADJ_SHEET  = 'For Adjudication';

$required = array(
	'applicationId' => 'sheet:' . ALL_SHEET . ':Application ID|sheet:' . ADJ_SHEET . ':Application ID',
	'files'         => 'sheet:' . ALL_SHEET . ':Files|sheet:' . ADJ_SHEET . ':Files',
	'email'         => 'sheet:' . ALL_SHEET . ':Email',
	'dateReceived'  => 'sheet:' . ALL_SHEET . ':Date Received',
	'receiptUrl'    => 'sheet:' . ALL_SHEET . ':Application Receipt Link',
);

mysqli_report( MYSQLI_REPORT_OFF );
$m = @new mysqli( 'localhost', 'root', 'root', 'local', 0, $sock );
if ( $m->connect_error ) {
	fwrite( STDERR, "mysql: {$m->connect_error}\n" );
	exit( 1 );
}

$q = $m->prepare( 'SELECT meta_id, meta_value FROM ' . TABLE . ' WHERE post_id = ? AND meta_key = ? LIMIT 1' );
$portal = PORTAL_ID;
$key    = META_KEY;
$q->bind_param( 'is', $portal, $key );
$q->execute();
$q->bind_result( $meta_id, $raw );
if ( ! $q->fetch() || ! is_string( $raw ) ) {
	fwrite( STDERR, "no definition for " . PORTAL_ID . "\n" );
	exit( 1 );
}
$q->close();

$def = json_decode( $raw, true );
if ( ! is_array( $def ) ) {
	$maybe = @unserialize( $raw );
	$def   = is_array( $maybe ) ? $maybe : null;
}
if ( ! is_array( $def ) ) {
	fwrite( STDERR, "definition is not JSON\n" );
	exit( 1 );
}

if ( ! isset( $def['mapping'] ) || ! is_array( $def['mapping'] ) ) {
	$def['mapping'] = array();
}
if ( ! isset( $def['mapping']['fieldDest'] ) || ! is_array( $def['mapping']['fieldDest'] ) ) {
	$def['mapping']['fieldDest'] = array();
}

$dest    = $def['mapping']['fieldDest'];
$changed = array();
foreach ( $required as $fid => $token ) {
	$current = isset( $dest[ $fid ] ) ? (string) $dest[ $fid ] : '';
	if ( $current === $token ) {
		continue;
	}
	$dest[ $fid ] = $token;
	$changed[ $fid ] = array(
		'from' => $current,
		'to'   => $token,
	);
}

if ( empty( $changed ) ) {
	echo json_encode(
		array(
			'ok'      => true,
			'changed' => false,
			'dest'    => array_intersect_key( $dest, $required ),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n";
	exit( 0 );
}

$def['mapping']['fieldDest'] = $dest;
$encoded = json_encode( $def, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
if ( ! is_string( $encoded ) || '' === $encoded ) {
	fwrite( STDERR, "failed to encode definition\n" );
	exit( 1 );
}

$u = $m->prepare( 'UPDATE ' . TABLE . ' SET meta_value = ? WHERE meta_id = ?' );
$u->bind_param( 'si', $encoded, $meta_id );
if ( ! $u->execute() ) {
	fwrite( STDERR, "update failed: {$m->error}\n" );
	exit( 1 );
}
$u->close();
$m->close();

echo json_encode(
	array(
		'ok'      => true,
		'changed' => true,
		'updates' => $changed,
		'dest'    => array_intersect_key( $dest, $required ),
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";
exit( 0 );
