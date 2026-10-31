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

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

$repo_root = dirname( __DIR__, 2 );
$autoload  = $repo_root . '/vendor/autoload.php';
if ( is_readable( $autoload ) ) {
	require_once $autoload;
}
if ( ! class_exists( 'Google_Client' ) ) {
	class Google_Client {
		/** @var array<string,mixed> */
		protected $token = array();

		public function __construct() {}

		public function setApplicationName( $name ) {
			unset( $name );
		}

		public function setScopes( $scopes ) {
			unset( $scopes );
		}

		public function setAccessType( $type ) {
			unset( $type );
		}

		public function setAuthConfig( $config ) {
			unset( $config );
		}

		public function setAccessToken( $token ) {
			$decoded     = is_string( $token ) ? json_decode( $token, true ) : $token;
			$this->token = is_array( $decoded ) ? $decoded : array( 'access_token' => (string) $token );
		}

		public function getAccessToken() {
			return $this->token;
		}

		public function getRefreshToken() {
			return isset( $this->token['refresh_token'] ) ? (string) $this->token['refresh_token'] : null;
		}

		public function isAccessTokenExpired() {
			return true;
		}

		public function fetchAccessTokenWithRefreshToken( $refresh_token = null ) {
			unset( $refresh_token );
			return $this->token;
		}
	}
}
if ( ! class_exists( 'Google_Service_Sheets' ) ) {
	class Google_Service_Sheets {
		const SPREADSHEETS = 'https://www.googleapis.com/auth/spreadsheets';

		public function __construct( $client = null ) {
			unset( $client );
		}
	}
}
if ( ! class_exists( 'Google_Service_Drive' ) ) {
	class Google_Service_Drive {
		const DRIVE = 'https://www.googleapis.com/auth/drive';

		public function __construct( $client = null ) {
			unset( $client );
		}
	}
}
if ( ! class_exists( 'Google_Service_Drive_DriveFile' ) ) {
	class Google_Service_Drive_DriveFile {
		/** @var array<string,mixed> */
		private $meta;

		public function __construct( array $meta = array() ) {
			$this->meta = $meta;
		}

		public function getName() {
			return isset( $this->meta['name'] ) ? (string) $this->meta['name'] : null;
		}
	}
}
require_once $repo_root . '/includes/adapters/class-zysys-file-store.php';
require_once $repo_root . '/includes/Submission/class-portal-files.php';
require_once $repo_root . '/includes/Submission/class-portal-submission-destinations.php';
require_once $repo_root . '/includes/adapters/class-portal-google-store.php';

/**
 * FileStore probe: same class, no OAuth configure().
 */
class FileStore_Write_Probe extends Zysys_FileStore {
	public function __construct() {}
}

/**
 * Records every files->create metadata and options array.
 */
class Capturing_Drive_Files {
	/** @var array<int,array> */
	public $creates = array();

	/** @var array<int,object> */
	public $metadatas = array();

	/**
	 * @param mixed $metadata Drive file metadata.
	 * @param array $opts     Options the live client would send.
	 * @return object
	 */
	public function create( $metadata, $opts = array() ) {
		$this->metadatas[] = is_object( $metadata ) ? $metadata : new stdClass();
		$this->creates[]   = is_array( $opts ) ? $opts : array();
		$file              = new stdClass();
		$file->id          = 'probe-file-id-' . count( $this->creates );
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
 * Filesystem spy that counts temp-dir mkdir and file writes.
 */
class Counting_Portal_Files extends Portal_Files {
	/** @var int */
	public $writes = 0;

	/** @var int */
	public $mkdirs = 0;

	public function mkdir( $dir ) {
		++$this->mkdirs;
		return true;
	}

	public function write( $path, $bytes ) {
		unset( $path, $bytes );
		++$this->writes;
		return true;
	}
}

/**
 * Stub Google_Client that simulates expired/fresh OAuth tokens without network I/O.
 */
class Stub_Google_Client extends Google_Client {
	/** @var bool */
	public $stub_expired = true;

	/** @var string|null */
	public $fetched_with_refresh_token = null;

	public function __construct( $expired = true ) {
		parent::__construct();
		$this->stub_expired = (bool) $expired;
	}

	public function isAccessTokenExpired() {
		return $this->stub_expired;
	}

	public function fetchAccessTokenWithRefreshToken( $refresh_token = null ) {
		$this->fetched_with_refresh_token = $refresh_token;
		$creds                            = array(
			'access_token'  => 'refreshed-access-token-xyz',
			'refresh_token' => (string) $refresh_token,
			'expires_in'    => 3600,
			'created'       => 1700000000,
		);
		$this->setAccessToken( $creds );
		return $creds;
	}
}

/**
 * FileStore subclass that injects Stub_Google_Client into configure().
 */
class Token_Refresh_FileStore_Probe extends Zysys_FileStore {
	/** @var Stub_Google_Client */
	private $stub_client;

	public function __construct( array $credentials, Stub_Google_Client $stub_client ) {
		$this->stub_client = $stub_client;
		parent::__construct( $credentials );
	}

	protected function create_google_client() {
		return $this->stub_client;
	}
}

/**
 * @param array       $opts     Raw Drive files->create options.
 * @param object|null $metadata Captured DriveFile metadata.
 * @return array<string,mixed>
 */
function summarize_create_opts( array $opts, $metadata = null ) {
	$name = null;
	if ( is_object( $metadata ) && method_exists( $metadata, 'getName' ) ) {
		$name = $metadata->getName();
	}
	return array(
		'supportsAllDrives'    => array_key_exists( 'supportsAllDrives', $opts )
			? $opts['supportsAllDrives']
			: null,
		'hasSupportsAllDrives' => array_key_exists( 'supportsAllDrives', $opts ),
		'keys'                 => array_keys( $opts ),
		'fields'               => isset( $opts['fields'] ) ? (string) $opts['fields'] : null,
		'uploadType'           => isset( $opts['uploadType'] ) ? (string) $opts['uploadType'] : null,
		'mimeType'             => isset( $opts['mimeType'] ) ? (string) $opts['mimeType'] : null,
		'data'                 => isset( $opts['data'] ) ? (string) $opts['data'] : null,
		'name'                 => $name,
	);
}

$store = new FileStore_Write_Probe();
$agent = new Capturing_Drive_Agent();
$store->__set( 'driveAgent', $agent );
$store->__set( 'drive_parent_id', 'parent-folder-id' );

$store->create_drive_subfolder( '25657' );

$store->store_drive_file( $repo_root . '/composer.json' );

$folder = isset( $agent->files->creates[0] ) && is_array( $agent->files->creates[0] )
	? summarize_create_opts( $agent->files->creates[0], $agent->files->metadatas[0] ?? null )
	: null;
$upload = isset( $agent->files->creates[1] ) && is_array( $agent->files->creates[1] )
	? summarize_create_opts( $agent->files->creates[1], $agent->files->metadatas[1] ?? null )
	: null;

$bytes_store = new FileStore_Write_Probe();
$bytes_agent = new Capturing_Drive_Agent();
$bytes_store->__set( 'driveAgent', $bytes_agent );
$bytes_store->__set( 'drive_parent_id', 'parent-folder-id' );
$bytes_id     = $bytes_store->store_drive_bytes( '../unsafe/score.pdf', "%PDF-1.4\n%in-memory\n", 'application/pdf' );
$bytes_upload = isset( $bytes_agent->files->creates[0] )
	? summarize_create_opts( $bytes_agent->files->creates[0], $bytes_agent->files->metadatas[0] ?? null )
	: null;

$gs_probe = new FileStore_Write_Probe();
$gs_agent = new Capturing_Drive_Agent();
$gs_probe->__set( 'driveAgent', $gs_agent );
$counting_files = new Counting_Portal_Files();
$definition     = array(
	'mapping' => array(
		'drive'     => array(
			array(
				'name'     => 'Primary Drive',
				'folderId' => 'shared-drive-root',
			),
		),
		'fieldDest' => array(
			'score_pdf' => 'drive:Primary Drive',
		),
	),
);
$google_store   = new Portal_Google_Store( $gs_probe, $definition, $counting_files );
$google_store->set_submission_id( 'app-99' );
$drive_url = $google_store->store_file( 'portal-1', 'score_pdf', "%PDF-1.4\n%direct-bytes\n", 'nested/path/score.pdf' );

$client_secret_json = json_encode(
	array(
		'web' => array(
			'client_id'     => 'test-client-id.apps.googleusercontent.com',
			'client_secret' => 'test-client-secret',
			'redirect_uris' => array( 'https://example.org/oauth' ),
		),
	)
);
$expired_token_json = json_encode(
	array(
		'access_token'  => 'expired-access-token',
		'refresh_token' => 'refresh-token-123',
		'expires_in'    => 3600,
		'created'       => 1000,
	)
);

$expired_client = new Stub_Google_Client( true );
$expired_store  = new Token_Refresh_FileStore_Probe(
	array(
		'access_key'    => $expired_token_json,
		'client_secret' => $client_secret_json,
	),
	$expired_client
);

$fresh_client = new Stub_Google_Client( false );
$fresh_store  = new Token_Refresh_FileStore_Probe(
	array(
		'access_key'    => $expired_token_json,
		'client_secret' => $client_secret_json,
	),
	$fresh_client
);

echo json_encode(
	array(
		'ok'           => true,
		'createCount'  => count( $agent->files->creates ),
		'folder'       => $folder,
		'upload'       => $upload,
		'bytesUpload'  => array(
			'id'     => $bytes_id,
			'create' => $bytes_upload,
		),
		'googleStore'  => array(
			'url'         => $drive_url,
			'tempWrites'  => $counting_files->writes,
			'tempMkdirs'  => $counting_files->mkdirs,
			'createCount' => count( $gs_agent->files->creates ),
			'subfolder'   => isset( $gs_agent->files->creates[0] )
				? summarize_create_opts( $gs_agent->files->creates[0], $gs_agent->files->metadatas[0] ?? null )
				: null,
			'file'        => isset( $gs_agent->files->creates[1] )
				? summarize_create_opts( $gs_agent->files->creates[1], $gs_agent->files->metadatas[1] ?? null )
				: null,
		),
		'tokenRefresh' => array(
			'fetchedWith'        => $expired_client->fetched_with_refresh_token,
			'updateAccessToken'  => $expired_store->__get( 'update_access_token' ),
			'updatedAccessToken' => $expired_store->__get( 'updated_access_token' ),
			'freshUpdateToken'   => $fresh_store->__get( 'update_access_token' ),
			'freshUpdatedToken'  => $fresh_store->__get( 'updated_access_token' ),
		),
	)
) . "\n";
