#!/usr/bin/env php
<?php
/**
 * Read the last Raw Data row (by header) or list Drive folder children.
 *
 *   php tests/support/read-google-last-row.php --sheet=SPREADSHEET_ID
 *   php tests/support/read-google-last-row.php --drive-children=FOLDER_ID
 */

$repo_root = dirname( __DIR__, 2 );
require_once $repo_root . '/vendor/autoload.php';
require_once $repo_root . '/gsuite-filestore/vendor/autoload.php';
require_once $repo_root . '/gsuite-filestore/zysys-file-store.class.php';

$sheet_id = '';
$folder_id = '';
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--sheet=' ) ) {
		$sheet_id = substr( $arg, strlen( '--sheet=' ) );
	}
	if ( 0 === strpos( $arg, '--drive-children=' ) ) {
		$folder_id = substr( $arg, strlen( '--drive-children=' ) );
	}
}

$store = file_store_from_wp();
if ( '' !== $folder_id ) {
	echo wp_json_encode( drive_children( $store, $folder_id ), JSON_UNESCAPED_SLASHES ) . "\n";
	exit( 0 );
}
if ( '' === $sheet_id ) {
	fwrite( STDERR, "usage: --sheet=ID or --drive-children=ID\n" );
	exit( 2 );
}
echo wp_json_encode( last_named_row( $store, $sheet_id ), JSON_UNESCAPED_SLASHES ) . "\n";

/**
 * @return Zysys_FileStore
 */
function file_store_from_wp() {
	$sock = getenv( 'DG_MYSQL_SOCKET' );
	if ( ! is_string( $sock ) || '' === $sock ) {
		$sock = $_SERVER['HOME'] . '/Library/Application Support/Local/run/MbehRUdOC/mysql/mysqld.sock';
	}
	$m = new mysqli( 'localhost', 'root', 'root', 'local', 0, $sock );
	if ( $m->connect_error ) {
		throw new Exception( $m->connect_error );
	}
	$access = '';
	$secret = '';
	foreach ( array( 'pb_google_access_key' => &$access, 'pb_google_secret_key' => &$secret ) as $name => &$val ) {
		$q = $m->prepare( 'SELECT option_value FROM B3sRggSMK1_options WHERE option_name = ? LIMIT 1' );
		$q->bind_param( 's', $name );
		$q->execute();
		$q->bind_result( $got );
		if ( $q->fetch() && is_string( $got ) ) {
			$val = $got;
		}
		$q->close();
	}
	unset( $val );
	$m->close();
	if ( '' === $secret ) {
		throw new Exception( 'pb_google_secret_key missing' );
	}
	return new Zysys_FileStore(
		array(
			'access_key'    => $access,
			'client_secret' => $secret,
		)
	);
}

/**
 * @param Zysys_FileStore $store FileStore.
 * @param string          $sid   Spreadsheet id.
 * @return array{headers:string[],last:string[],named:array<string,string>}
 */
function last_named_row( $store, $sid ) {
	$sheets = $store->__get( 'sheetsAgent' );
	$resp   = $sheets->spreadsheets_values->get( $sid, "'Raw Data'!A1:ZZ200" );
	$rows   = $resp->getValues();
	if ( ! is_array( $rows ) || count( $rows ) < 2 ) {
		return array(
			'headers' => isset( $rows[0] ) && is_array( $rows[0] ) ? $rows[0] : array(),
			'last'    => array(),
			'named'   => array(),
		);
	}
	$headers = array_map( 'strval', $rows[0] );
	$last    = array_map( 'strval', $rows[ count( $rows ) - 1 ] );
	$named   = array();
	foreach ( $headers as $i => $header ) {
		$label = trim( (string) $header );
		if ( '' === $label ) {
			continue;
		}
		$named[ $label ] = isset( $last[ $i ] ) ? (string) $last[ $i ] : '';
	}
	return array(
		'headers' => $headers,
		'last'    => $last,
		'named'   => $named,
	);
}

/**
 * @param Zysys_FileStore $store FileStore.
 * @param string          $id    Folder id.
 * @return array{id:string,names:string[],folders:string[],files:string[]}
 */
function drive_children( $store, $id ) {
	$drive = $store->__get( 'driveAgent' );
	$resp  = $drive->files->listFiles(
		array(
			'q'                         => sprintf( '"%s" in parents and trashed = false', $id ),
			'fields'                    => 'files(id,name,mimeType)',
			'pageSize'                  => 100,
			'supportsAllDrives'         => true,
			'includeItemsFromAllDrives' => true,
		)
	);
	$items   = $resp->getFiles() ? $resp->getFiles() : array();
	$names   = array();
	$folders = array();
	$files   = array();
	foreach ( $items as $item ) {
		$name = (string) $item->getName();
		$names[] = $name;
		if ( 'application/vnd.google-apps.folder' === $item->getMimeType() ) {
			$folders[] = $name;
		} else {
			$files[] = $name;
		}
	}
	return array(
		'id'      => $id,
		'names'   => $names,
		'folders' => $folders,
		'files'   => $files,
	);
}

function wp_json_encode( $d ) {
	return json_encode( $d );
}
