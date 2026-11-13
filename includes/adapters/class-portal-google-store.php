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
			$this->submission_id = (string) $id;
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
			$mapping = isset( $this->definition['mapping'] ) && is_array( $this->definition['mapping'] )
				? $this->definition['mapping']
				: array();
			$sheets  = isset( $mapping['sheets'] ) && is_array( $mapping['sheets'] )
				? $mapping['sheets']
				: array();
			$start   = $this->starting_row();
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
				$cells = Portal_Submission_Destinations::cells_for_sheet( $this->definition, $row, $name );
				if ( empty( $cells ) ) {
					continue;
				}
				$this->file_store->gsheet( $sid );
				$this->file_store->gsheet_row( Portal_Submission_Destinations::RAW_DATA_TAB, 'A' . $start );
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
				$sub = $this->ensure_subfolder( $folder_id, $app_id );
				$this->file_store->drive_parent( $sub );
				$last_id = (string) $this->file_store->store_drive_file( $tmp );
			}

			if ( '' === $last_id ) {
				return '';
			}
			return Portal_Submission_Destinations::FILE_URL_PREFIX . $last_id;
		}

		/**
		 * @return int 1-based starting row (skipHeader honored like store_records).
		 */
		private function starting_row() {
			$skip = isset( $this->definition['options']['skipHeader'] )
				? $this->definition['options']['skipHeader']
				: false;
			if ( is_numeric( $skip ) ) {
				return 1 + (int) $skip;
			}
			return $skip ? 2 : 1;
		}

		/**
		 * @param string $parent_id Drive folder id.
		 * @param string $app_id    Submission id.
		 * @return string Subfolder id.
		 */
		private function ensure_subfolder( $parent_id, $app_id ) {
			if ( isset( $this->subfolders[ $parent_id ] ) ) {
				return $this->subfolders[ $parent_id ];
			}
			$this->file_store->drive_parent( $parent_id );
			$id = (string) $this->file_store->create_drive_subfolder( $app_id, true );
			$this->subfolders[ $parent_id ] = $id;
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
