<?php
/**
 * Zysys_FileStore adapter for Google Drive and Google Sheets via google/apiclient.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Zysys_FileStore' ) && ! class_exists( 'Zysys_File_Store' ) ) {

	/**
	 * Interacts with Google Drive and Google Sheets using Google APIs.
	 */
	class Zysys_File_Store {

		/**
		 * Stored dynamic variables (agents, sheet/drive IDs, refreshed tokens).
		 *
		 * @var array<string,mixed>
		 */
		protected $variables = array();

		/**
		 * Serialized OAuth access token or token array.
		 *
		 * @var string|array|null
		 */
		private $access_key;

		/**
		 * Serialized OAuth client secret JSON or array.
		 *
		 * @var string|array|null
		 */
		private $client_secret;

		/**
		 * Initialize the Zysys_FileStore object from an associative credentials array or JSON file paths.
		 *
		 * @param string|array $access_key_cred_path_or_credentials_array Path to an access key credentials JSON file
		 *                                                                or an associative array with 'access_key' and 'client_secret'.
		 * @param string|null  $client_secret_path                        Optional path to the client secret JSON file.
		 * @throws Exception If invalid data is provided or required keys are missing.
		 */
		public function __construct( $access_key_cred_path_or_credentials_array, $client_secret_path = null ) {
			if ( is_array( $access_key_cred_path_or_credentials_array ) ) {
				$this->access_key    = $access_key_cred_path_or_credentials_array['access_key'] ?? null;
				$this->client_secret = $access_key_cred_path_or_credentials_array['client_secret'] ?? null;

				if ( is_null( $this->access_key ) || is_null( $this->client_secret ) ) {
					throw new Exception( "The array provided must contain 'access_key' and 'client_secret' keys." );
				}
			} else {
				if ( is_null( $client_secret_path ) ) {
					$raw_script         = isset( $_SERVER['SCRIPT_FILENAME'] )
						? ( function_exists( 'wp_unslash' ) ? wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) : $_SERVER['SCRIPT_FILENAME'] )
						: 'index.php';
					$script_filename    = function_exists( 'sanitize_file_name' )
						? sanitize_file_name( $raw_script )
						: basename( (string) $raw_script );
					$client_secret_path = __DIR__ . '/.credentials/' . basename( $script_filename, '.php' ) . '.json';
				}

				if ( ! file_exists( $access_key_cred_path_or_credentials_array ) ) {
					throw new Exception( 'Invalid access key credentials file path provided.' );
				}
				$access_key_data  = $this->_load_json( $access_key_cred_path_or_credentials_array );
				$this->access_key = $access_key_data['access_key'] ?? null;

				if ( ! file_exists( $client_secret_path ) ) {
					throw new Exception( 'Invalid client secret file path provided.' );
				}
				$client_secret_data  = $this->_load_json( $client_secret_path );
				$this->client_secret = $client_secret_data['client_secret'] ?? null;

				if ( is_null( $this->access_key ) || is_null( $this->client_secret ) ) {
					throw new Exception( 'Required keys are missing in the provided files.' );
				}
			}

			$this->_use_gsuite();
			$this->configure();
		}

		/**
		 * Load Google API libraries via root Composer autoloader.
		 *
		 * @return void
		 */
		protected function _use_gsuite() {
			$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
			if ( is_readable( $autoload ) ) {
				require_once $autoload;
			}
		}

		/**
		 * Read and decode a JSON file into an associative array.
		 *
		 * @param string $path File path.
		 * @return array
		 * @throws Exception If the file is missing or contains invalid JSON.
		 */
		protected function _load_json( $path ) {
			if ( ! file_exists( $path ) ) {
				throw new Exception( 'Credentials JSON file not found.' );
			}
			$json = file_get_contents( $path );
			$data = json_decode( (string) $json, true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
				throw new Exception( 'Invalid JSON in credentials file.' );
			}
			return $data;
		}

		/**
		 * Instantiate a Google_Client instance.
		 *
		 * @return Google_Client
		 */
		protected function create_google_client() {
			return new Google_Client();
		}

		/**
		 * Configure the Google Client with the provided access key and client secret.
		 *
		 * @return void
		 * @throws Exception If the Google Client configuration fails.
		 */
		public function configure() {
			$client = $this->create_google_client();
			$client->setApplicationName( 'Zysys FileStore' );

			$client->setScopes(
				array(
					Google_Service_Sheets::SPREADSHEETS,
					Google_Service_Drive::DRIVE,
				)
			);

			$client->setAccessType( 'offline' );

			if ( $this->__get( 'credentials_path' ) ) {
				$cred_path = (string) $this->__get( 'credentials_path' );
				if ( file_exists( $cred_path ) ) {
					$access_token = json_decode( (string) file_get_contents( $cred_path ), true );
					$client->setAccessToken( $access_token );
					$this->refresh_access_token_if_expired( $client, false );
				} else {
					throw new Exception( 'Credentials path is invalid or not set.' );
				}
			} elseif ( $this->client_secret ) {
				$keys = is_array( $this->client_secret )
					? $this->client_secret
					: json_decode( (string) $this->client_secret, true );
				if ( ! is_array( $keys ) || empty( $keys ) ) {
					throw new Exception( 'Client secret is invalid or not configured.' );
				}
				if ( 1 === count( $keys ) ) {
					$first_key = array_key_first( $keys );
					if ( null !== $first_key && is_array( $keys[ $first_key ] ) ) {
						$keys = $keys[ $first_key ];
					}
				}

				$client->setAuthConfig( $keys );
			} else {
				throw new Exception( 'Client secret is missing or not configured.' );
			}

			$this->_var( 'gsuiteAgent', $client );
			$this->_var( 'sheetsAgent', new Google_Service_Sheets( $client ) );
			$this->_var( 'driveAgent', new Google_Service_Drive( $client ) );

			if ( $this->access_key ) {
				$client->setAccessToken( $this->access_key );
				$this->refresh_access_token_if_expired( $client, is_string( $this->access_key ) );
			}
		}

		/**
		 * Refresh an expired access token when a refresh token is available and record the updated token.
		 *
		 * @param Google_Client $client         Configured Google client.
		 * @param bool          $encode_as_json Whether to serialize the refreshed token array as JSON.
		 * @return void
		 */
		protected function refresh_access_token_if_expired( $client, $encode_as_json ) {
			if ( $client->isAccessTokenExpired() && $client->getRefreshToken() ) {
				$client->fetchAccessTokenWithRefreshToken( $client->getRefreshToken() );
				$token     = $client->getAccessToken();
				$refreshed = ( $encode_as_json && is_array( $token ) )
					? (string) ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $token ) : json_encode( $token ) )
					: $token;
				$this->__set( 'updated_access_token', $refreshed );
				$this->__set( 'update_access_token', $refreshed );
			} else {
				$this->__set( 'updated_access_token', false );
				$this->__set( 'update_access_token', false );
			}
		}

		/**
		 * Set a variable in the class store.
		 *
		 * @param string $key   Variable name.
		 * @param mixed  $value Value to set.
		 * @return void
		 */
		public function __set( $key, $value ) {
			$this->variables[ $key ] = $value;
		}

		/**
		 * Get a variable from the class store.
		 *
		 * @param string $key Variable name.
		 * @return mixed
		 */
		public function __get( $key = '' ) {
			if ( array_key_exists( $key, $this->variables ) ) {
				return $this->variables[ $key ];
			}
			return null;
		}

		/**
		 * Set a variable and ensure it is stored.
		 *
		 * @param string $var_name Variable name.
		 * @param mixed  $value    Variable value.
		 * @return int
		 * @throws Exception If the variable could not be set.
		 */
		protected function _var( $var_name, $value ) {
			$this->__set( $var_name, $value );
			if ( $this->__get( $var_name ) !== $value ) {
				throw new Exception( 'Critical error: unable to set FileStore variable.' );
			}
			return 1;
		}

		/**
		 * Set Google Sheets ID.
		 *
		 * @param string $id Google Sheets ID.
		 * @return void
		 */
		public function gsheet( $id ) {
			$this->_var( 'gsheet_id', $id );
		}

		/**
		 * Set Google Sheets row identifier.
		 *
		 * @param string $sheet_identifier    Google Sheets sheet name.
		 * @param string $location_identifier Row identifier.
		 * @return void
		 */
		public function gsheet_row( $sheet_identifier, $location_identifier ) {
			$this->_var( 'gsheet_sheet_name', $sheet_identifier );
			$this->_var( 'gsheet_row_identifier', $location_identifier );
		}

		/**
		 * Append a row to the Google Sheet.
		 *
		 * @param mixed ...$args Values to append.
		 * @return void
		 * @throws Exception If sheet targeting variables are not set.
		 */
		public function add_row( ...$args ) {
			if ( ! $this->__get( 'gsheet_id' ) ) {
				throw new Exception( 'Error: gsheet_id not set.' );
			}
			if ( ! $this->__get( 'gsheet_sheet_name' ) ) {
				throw new Exception( 'Error: gsheet_sheet_name not set.' );
			}
			if ( ! $this->__get( 'gsheet_row_identifier' ) ) {
				throw new Exception( 'Error: gsheet_row_identifier not set.' );
			}

			$body   = new Google_Service_Sheets_ValueRange( array( 'values' => array( $args ) ) );
			$params = array( 'valueInputOption' => 'RAW' );
			$range  = sprintf( "'%s'!%s", $this->__get( 'gsheet_sheet_name' ), $this->__get( 'gsheet_row_identifier' ) );

			$this->__get( 'sheetsAgent' )->spreadsheets_values->append( $this->__get( 'gsheet_id' ), $range, $body, $params );
		}

		/**
		 * Read values from a range on the current spreadsheet.
		 *
		 * @param string $range A1 range, including sheet name.
		 * @return array
		 * @throws Exception If gsheet_id is not set.
		 */
		public function read_range( $range ) {
			if ( ! $this->__get( 'gsheet_id' ) ) {
				throw new Exception( 'Error: gsheet_id not set.' );
			}

			$resp   = $this->__get( 'sheetsAgent' )->spreadsheets_values->get( $this->__get( 'gsheet_id' ), $range );
			$values = $resp ? $resp->getValues() : null;
			return is_array( $values ) ? $values : array();
		}

		/**
		 * Overwrite a range with one RAW row on the current spreadsheet.
		 *
		 * @param string $range A1 range, including sheet name.
		 * @param array  $row   Cell values.
		 * @return void
		 * @throws Exception If gsheet_id is not set.
		 */
		public function update_values( $range, array $row ) {
			if ( ! $this->__get( 'gsheet_id' ) ) {
				throw new Exception( 'Error: gsheet_id not set.' );
			}

			$body   = new Google_Service_Sheets_ValueRange( array( 'values' => array( array_values( $row ) ) ) );
			$params = array( 'valueInputOption' => 'RAW' );
			$this->__get( 'sheetsAgent' )->spreadsheets_values->update( $this->__get( 'gsheet_id' ), $range, $body, $params );
		}

		/**
		 * Set Google Drive parent folder ID.
		 *
		 * @param string $folder_id Google Drive folder ID.
		 * @return void
		 */
		public function drive_parent( $folder_id ) {
			$this->_var( 'drive_parent_id', $folder_id );
		}

		/**
		 * Options every Drive files write must send.
		 *
		 * Shared Drive parents 403 unless supportsAllDrives is true.
		 *
		 * @param array $opts Call-specific Drive files options.
		 * @return array
		 */
		protected function drive_write_params( array $opts = array() ) {
			$opts['supportsAllDrives'] = true;
			return $opts;
		}

		/**
		 * Set the local storage path.
		 *
		 * @param string $path Directory path.
		 * @return void
		 * @throws Exception If the path does not exist or is not writable.
		 */
		public function local_store( $path ) {
			if ( ! is_dir( $path ) ) {
				throw new Exception( 'Local store path does not exist.' );
			}

			if ( ! is_writable( $path ) ) {
				throw new Exception( 'Local store path is not writable.' );
			}

			$this->_var( 'local_store_path', $path );
		}

		/**
		 * Create a subfolder in both Google Drive and local storage.
		 *
		 * @param string $name      Subfolder name.
		 * @param bool   $switch_to Switch to the created subfolder after creation.
		 * @return void
		 */
		public function create_subfolder( $name, $switch_to = false ) {
			$drive_id = $this->create_drive_subfolder( $name );
			$this->create_local_subfolder( $name );

			if ( $switch_to ) {
				$this->drive_parent( $drive_id );
				$this->local_store( $this->__get( 'local_store_path' ) . '/' . $name );
			}
		}

		/**
		 * Get the current Google Drive parent folder ID.
		 *
		 * @return string|null
		 */
		public function get_drive_parent_id() {
			return $this->__get( 'drive_parent_id' );
		}

		/**
		 * Create a subfolder in local storage.
		 *
		 * @param string $name Subfolder name.
		 * @return string Created folder path.
		 * @throws Exception If the path is not set, or if the folder exists and is not empty.
		 */
		public function create_local_subfolder( $name ) {
			if ( ! $this->__get( 'local_store_path' ) ) {
				throw new Exception( 'Error: Local Store Path not set.' );
			}

			$folder_to_create = $this->__get( 'local_store_path' ) . '/' . $name;

			if ( ! is_dir( $folder_to_create ) ) {
				if ( ! mkdir( $folder_to_create ) ) {
					throw new Exception( 'Error: Failed to create local subfolder.' );
				}
			} elseif ( ! $this->_is_dir_empty( $folder_to_create ) ) {
				throw new Exception( 'Error: Subfolder exists and is not empty.' );
			}

			return $folder_to_create;
		}

		/**
		 * Check if a directory is empty.
		 *
		 * @param string $dir Directory path.
		 * @return bool|null Returns null if unreadable, true if empty, false otherwise.
		 */
		protected function _is_dir_empty( $dir ) {
			if ( ! is_readable( $dir ) ) {
				return null;
			}

			$entries = scandir( $dir );
			if ( ! is_array( $entries ) ) {
				return null;
			}
			foreach ( $entries as $entry ) {
				if ( '.' !== $entry && '..' !== $entry ) {
					return false;
				}
			}
			return true;
		}

		/**
		 * Create a subfolder in Google Drive.
		 *
		 * @param string $name      Subfolder name.
		 * @param bool   $switch_to Switch to the created subfolder after creation.
		 * @return string Created Google Drive folder ID.
		 * @throws Exception If the parent folder ID is not set.
		 */
		public function create_drive_subfolder( $name, $switch_to = false ) {
			if ( ! $this->__get( 'drive_parent_id' ) ) {
				throw new Exception( 'Error: Drive Parent Folder not set.' );
			}

			$file_metadata = new Google_Service_Drive_DriveFile(
				array(
					'name'     => $name,
					'parents'  => array( $this->__get( 'drive_parent_id' ) ),
					'mimeType' => 'application/vnd.google-apps.folder',
				)
			);

			$file = $this->__get( 'driveAgent' )->files->create(
				$file_metadata,
				$this->drive_write_params(
					array(
						'fields' => 'id',
					)
				)
			);

			if ( $switch_to ) {
				$this->drive_parent( $file->id );
			}

			return $file->id;
		}

		/**
		 * Store a file in both Google Drive and local storage.
		 *
		 * @param string $filepath File path to store.
		 * @return array IDs of stored files.
		 * @throws Exception If the file does not exist.
		 */
		public function store_file( $filepath ) {
			if ( ! file_exists( $filepath ) ) {
				throw new Exception( 'Error: File does not exist.' );
			}

			$drive = $this->store_drive_file( $filepath );
			$local = $this->store_local_file( $filepath );

			return array( $drive, $local );
		}

		/**
		 * Store in-memory bytes as a file in Google Drive.
		 *
		 * @param string $filename  Target filename in Google Drive.
		 * @param string $buffer    Raw file bytes.
		 * @param string $mime_type MIME type for upload.
		 * @return string Google Drive file ID.
		 */
		public function store_drive_bytes( $filename, $buffer, $mime_type = 'application/octet-stream' ) {
			$mime          = is_string( $mime_type ) && '' !== trim( $mime_type )
				? $mime_type
				: 'application/octet-stream';
			$file_metadata = new Google_Service_Drive_DriveFile(
				array(
					'name'    => basename( (string) $filename ),
					'parents' => array( $this->__get( 'drive_parent_id' ) ),
				)
			);

			$file = $this->__get( 'driveAgent' )->files->create(
				$file_metadata,
				$this->drive_write_params(
					array(
						'data'       => (string) $buffer,
						'mimeType'   => $mime,
						'uploadType' => 'multipart',
						'fields'     => 'id',
					)
				)
			);

			return $file->id;
		}

		/**
		 * Store a file in Google Drive.
		 *
		 * @param string $filepath File path to store.
		 * @return string Google Drive file ID.
		 * @throws Exception If the file does not exist.
		 */
		public function store_drive_file( $filepath ) {
			if ( ! file_exists( $filepath ) ) {
				throw new Exception( 'Error: File does not exist.' );
			}

			$detected_mime = function_exists( 'mime_content_type' ) ? mime_content_type( $filepath ) : false;
			$mime_type     = is_string( $detected_mime ) && '' !== $detected_mime
				? $detected_mime
				: 'application/octet-stream';

			return $this->store_drive_bytes( basename( $filepath ), (string) file_get_contents( $filepath ), $mime_type );
		}

		/**
		 * Store a file in local storage.
		 *
		 * @param string $filepath File path to store.
		 * @return void
		 * @throws Exception If the file does not exist or if copying fails.
		 */
		public function store_local_file( $filepath ) {
			if ( ! file_exists( $filepath ) ) {
				throw new Exception( 'Error: File does not exist.' );
			}

			$destination = $this->__get( 'local_store_path' ) . '/' . basename( $filepath );
			$attempts    = 0;

			do {
				copy( $filepath, $destination );
			} while ( file_get_contents( $filepath ) !== file_get_contents( $destination ) && ++$attempts < 15 );

			if ( $attempts >= 15 ) {
				throw new Exception( 'Error: Failed to store file locally after multiple attempts.' );
			}
		}
	}

	class_alias( 'Zysys_File_Store', 'Zysys_FileStore' );
}
