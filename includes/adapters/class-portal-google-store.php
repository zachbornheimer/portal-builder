<?php
/**
 * Live Sheet / Drive adapter — Zysys_FileStore behind append_row / store_file.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Google_Store' ) ) {

	/**
	 * Fans mapping.fieldDest out to Sheets and Drive via an injected FileStore.
	 */
	class Portal_Google_Store {

		const OPTION_ACCESS_KEY = 'pb_google_access_key';
		const OPTION_SECRET_KEY = 'pb_google_secret_key';
		const TEMP_DIR_NAME     = 'dg-google-store';

		/** @var object FileStore (Zysys_FileStore or test fake). */
		private $file_store;

		/** @var array */
		private $definition;

		/** @var Portal_Files */
		private $files;

		/** @var string */
		private $submission_id = '';

		/** @var array<string,string> parent folderId => subfolder id */
		private $subfolders = array();

		/** @var string Last application folder id. */
		private $last_folder_id = '';

		/**
		 * @param object            $file_store Injected FileStore.
		 * @param array             $definition Validated definition (mapping + options).
		 * @param Portal_Files|null $files      Filesystem for temp upload bytes.
		 */
		public function __construct( $file_store, array $definition, $files = null ) {
			$this->file_store = $file_store;
			$this->definition = $definition;
			$this->files      = $files instanceof Portal_Files ? $files : new Portal_Files();
		}

		/**
		 * Build FileStore from the live plugin options and persist a refreshed token.
		 *
		 * @return Zysys_FileStore
		 */
		public static function file_store_from_options() {
			$autoload = dirname( __DIR__, 2 ) . '/gsuite-filestore/vendor/autoload.php';
			if ( is_readable( $autoload ) ) {
				require_once $autoload;
			}
			$credentials = array(
				'access_key'    => function_exists( 'get_option' ) ? (string) get_option( self::OPTION_ACCESS_KEY, '' ) : '',
				'client_secret' => function_exists( 'get_option' ) ? (string) get_option( self::OPTION_SECRET_KEY, '' ) : '',
			);
			$store       = new Zysys_FileStore( $credentials );
			if ( $store->__get( 'update_access_token' ) && function_exists( 'update_option' ) ) {
				update_option( self::OPTION_ACCESS_KEY, $store->__get( 'update_access_token' ) );
			}
			return $store;
		}

		/**
		 * @param string $id File-handler app id or minted submission id.
		 * @return void
		 */
		public function set_submission_id( $id ) {
			$this->submission_id  = (string) $id;
			$this->last_folder_id = '';
		}

		/**
		 * Append the logical row to every mapped spreadsheet with a spreadsheetId.
		 *
		 * @param string|int          $portal_id Portal id (unused; mapping owns targets).
		 * @param array<string,mixed> $row       Logical row from the pipeline.
		 * @return string Comma-joined spreadsheet ids written.
		 */
		public function append_row( $portal_id, array $row ) {
			unset( $portal_id );
			$mapping = $this->mapping();
			$sheets  = isset( $mapping['sheets'] ) && is_array( $mapping['sheets'] )
				? $mapping['sheets']
				: array();
			$written = array();

			foreach ( $sheets as $sheet ) {
				if ( ! is_array( $sheet ) ) {
					continue;
				}
				$sid = isset( $sheet['spreadsheetId'] ) ? (string) $sheet['spreadsheetId'] : '';
				if ( '' === $sid ) {
					continue;
				}
				$name  = isset( $sheet['name'] ) ? (string) $sheet['name'] : '';
				$named = Portal_Submission_Destinations::named_values_for_sheet( $this->definition, $row, $name );
				if ( empty( $named ) ) {
					continue;
				}
				$this->file_store->gsheet( $sid );
				$headers = $this->ensure_headers( $named );
				$cells   = Portal_Submission_Destinations::align_to_headers( $named, $headers );
				$this->file_store->gsheet_row(
					Portal_Submission_Destinations::RAW_DATA_TAB,
					Portal_Submission_Destinations::DATA_START_CELL
				);
				$this->file_store->add_row( ...$cells );
				$written[] = $sid;
			}

			return implode( ',', $written );
		}

		/**
		 * Store one field file in each dest Drive folder; return a Drive file URL.
		 *
		 * @param string|int $portal_id Portal id (fallback subfolder name).
		 * @param string     $field_id  Definition field id.
		 * @param string     $buffer    File bytes.
		 * @param string     $filename  Original basename.
		 * @return string Drive URL or empty when no dest folderId.
		 */
		public function store_file( $portal_id, $field_id, $buffer, $filename ) {
			$mapping    = isset( $this->definition['mapping'] ) && is_array( $this->definition['mapping'] )
				? $this->definition['mapping']
				: array();
			$field_dest = isset( $mapping['fieldDest'] ) && is_array( $mapping['fieldDest'] )
				? $mapping['fieldDest']
				: array();
			$names      = Portal_Submission_Destinations::drive_names_for_field( $field_dest, $field_id );
			if ( empty( $names ) ) {
				return '';
			}

			$app_id  = '' !== $this->submission_id ? $this->submission_id : (string) $portal_id;
			$tmp     = $this->write_temp( $buffer, $filename, $field_id );
			$last_id = '';
			foreach ( $names as $name ) {
				$folder_id = Portal_Submission_Destinations::folder_id_for_name( $mapping, $name );
				if ( '' === $folder_id ) {
					continue;
				}
				$sub                  = $this->ensure_subfolder( $folder_id, $app_id );
				$this->last_folder_id = $sub;
				$this->file_store->drive_parent( $sub );
				$last_id = (string) $this->file_store->store_drive_file( $tmp );
			}

			if ( '' === $last_id ) {
				return '';
			}
			return Portal_Submission_Destinations::FILE_URL_PREFIX . $last_id;
		}

		/**
		 * Create {base}/{applicationId} so Files can be dest'd with no uploads.
		 *
		 * @param string|int $portal_id Portal id (fallback folder name).
		 * @return string Application folder id or empty.
		 */
		public function ensure_application_folder( $portal_id ) {
			if ( '' !== $this->last_folder_id ) {
				return $this->last_folder_id;
			}
			$app_id  = '' !== $this->submission_id ? $this->submission_id : (string) $portal_id;
			$mapping = $this->mapping();
			$drive   = isset( $mapping['drive'] ) && is_array( $mapping['drive'] )
				? $mapping['drive']
				: array();
			foreach ( $drive as $folder ) {
				if ( ! is_array( $folder ) ) {
					continue;
				}
				$folder_id = isset( $folder['folderId'] ) ? (string) $folder['folderId'] : '';
				if ( '' === $folder_id ) {
					continue;
				}
				$this->last_folder_id = $this->ensure_subfolder( $folder_id, $app_id );
				return $this->last_folder_id;
			}
			return '';
		}

		/**
		 * Folder URL for the last application folder, or empty.
		 *
		 * @return string
		 */
		public function folder_url() {
			if ( '' === $this->last_folder_id ) {
				return '';
			}
			return Portal_Submission_Destinations::FOLDER_URL_PREFIX . $this->last_folder_id;
		}

		/**
		 * Definition mapping block.
		 *
		 * @return array
		 */
		private function mapping() {
			return isset( $this->definition['mapping'] ) && is_array( $this->definition['mapping'] )
				? $this->definition['mapping']
				: array();
		}

		/**
		 * Read A1, append missing dest names, write the header row when it grew.
		 *
		 * @param array<string,string> $named Dest-column map.
		 * @return string[]
		 */
		private function ensure_headers( array $named ) {
			$existing = $this->read_header_row();
			$headers  = Portal_Submission_Destinations::extend_headers( $existing, $named );
			if ( count( $headers ) > count( $existing ) && method_exists( $this->file_store, 'update_values' ) ) {
				$this->file_store->update_values( Portal_Submission_Destinations::HEADER_WRITE_RANGE, $headers );
			}
			return $headers;
		}

		/**
		 * Current Raw Data A1 headers, or empty when the row is blank.
		 *
		 * @return string[]
		 */
		private function read_header_row() {
			if ( ! method_exists( $this->file_store, 'read_range' ) ) {
				return array();
			}
			$values = $this->file_store->read_range( Portal_Submission_Destinations::HEADER_RANGE );
			if ( ! is_array( $values ) || empty( $values ) || ! is_array( $values[0] ) ) {
				return array();
			}
			foreach ( $values[0] as $cell ) {
				if ( '' !== trim( (string) $cell ) ) {
					return $values[0];
				}
			}
			return array();
		}

		/**
		 * @param string $parent_id Drive folder id.
		 * @param string $app_id    Submission id.
		 * @return string Subfolder id.
		 */
		private function ensure_subfolder( $parent_id, $app_id ) {
			$key = $parent_id . "\0" . $app_id;
			if ( isset( $this->subfolders[ $key ] ) ) {
				return $this->subfolders[ $key ];
			}
			$this->file_store->drive_parent( $parent_id );
			$id                       = (string) $this->file_store->create_drive_subfolder( $app_id, true );
			$this->subfolders[ $key ] = $id;
			return $id;
		}

		/**
		 * @param string $buffer   Bytes.
		 * @param string $filename Basename.
		 * @param string $field_id Field id.
		 * @return string Temp path.
		 */
		private function write_temp( $buffer, $filename, $field_id ) {
			$safe = self::sanitize_filename( $filename );
			$dir  = $this->files->join( sys_get_temp_dir(), self::TEMP_DIR_NAME );
			$this->files->mkdir( $dir );
			$id   = '' !== $this->submission_id ? $this->submission_id : 'tmp';
			$path = $this->files->join( $dir, $id . '-' . $field_id . '-' . $safe );
			$this->files->write( $path, $buffer );
			return $path;
		}

		/**
		 * @param string $filename Raw filename.
		 * @return string
		 */
		private static function sanitize_filename( $filename ) {
			$base = basename( str_replace( '\\', '/', (string) $filename ) );
			$base = preg_replace( '/[^A-Za-z0-9._-]+/', '_', $base );
			if ( ! is_string( $base ) || '' === $base || '.' === $base || '..' === $base ) {
				return 'upload.bin';
			}
			return $base;
		}
	}
}
