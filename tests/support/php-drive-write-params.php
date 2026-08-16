<?php
/**
 * CLI harness: capture option arrays FileStore passes to Drive files->create.
 *
 *   php tests/support/php-drive-write-params.php
 *
 * Instantiates the shipped FileStore (constructor skipped so Google auth is
 * not required) and drives create_drive_subfolder + store_drive_file against
 * a capturing Drive agent.
 */

$repo_root = dirname( __DIR__, 2 );
require_once $repo_root . '/gsuite-filestore/vendor/autoload.php';
require_once $repo_root . '/gsuite-filestore/zysys-file-store.class.php';

/**
 * FileStore probe: same class, no OAuth configure().
 */
class FileStore_Write_Probe extends Zysys_FileStore {
	public function __construct() {}
}

/**
 * Records every files->create options array.
 */
class Capturing_Drive_Files {
	/** @var array<int,array> */
	public $creates = array();

	/**
	 * @param mixed $metadata Unused Drive file metadata.
	 * @param array $opts     Options the live client would send.
	 * @return object
	 */
	public function create( $metadata, $opts = array() ) {
		unset( $metadata );
		$this->creates[] = is_array( $opts ) ? $opts : array();
		$file            = new stdClass();
		$file->id        = 'probe-file-id';
		return $file;
	}
}

class Capturing_Drive_Agent {
	/** @var Capturing_Drive_Files */
	public $files;

	public function __construct() {
		$this->files = new Capturing_Drive_Files();
	}
}

/**
 * @param array $opts Raw Drive files->create options.
 * @return array{supportsAllDrives:mixed,hasSupportsAllDrives:bool,keys:string[],fields:?string,uploadType:?string}
 */
function summarize_create_opts( array $opts ) {
	return array(
		'supportsAllDrives'    => array_key_exists( 'supportsAllDrives', $opts )
			? $opts['supportsAllDrives']
			: null,
		'hasSupportsAllDrives' => array_key_exists( 'supportsAllDrives', $opts ),
		'keys'                 => array_keys( $opts ),
		'fields'               => isset( $opts['fields'] ) ? (string) $opts['fields'] : null,
		'uploadType'           => isset( $opts['uploadType'] ) ? (string) $opts['uploadType'] : null,
	);
}

$store = new FileStore_Write_Probe();
$agent = new Capturing_Drive_Agent();
$store->__set( 'driveAgent', $agent );
$store->__set( 'drive_parent_id', 'parent-folder-id' );

$store->create_drive_subfolder( '25657' );

$tmp = tempnam( sys_get_temp_dir(), 'dg-drive-write-' );
file_put_contents( $tmp, "%PDF-1.4\n%probe\n" );
$store->store_drive_file( $tmp );
unlink( $tmp );

$folder = isset( $agent->files->creates[0] ) && is_array( $agent->files->creates[0] )
	? summarize_create_opts( $agent->files->creates[0] )
	: null;
$upload = isset( $agent->files->creates[1] ) && is_array( $agent->files->creates[1] )
	? summarize_create_opts( $agent->files->creates[1] )
	: null;

echo json_encode(
	array(
		'ok'          => true,
		'createCount' => count( $agent->files->creates ),
		'folder'      => $folder,
		'upload'      => $upload,
	)
) . "\n";
