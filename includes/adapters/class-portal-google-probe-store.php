<?php
/**
 * Probe I/O against an injected FileStore. Never calls live Google from tests.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Google_Probe_Store' ) ) {

	/**
	 * List a Drive folder and append one Sheet row through FileStore agents.
	 */
	class Portal_Google_Probe_Store {

		const DRIVE_LIST_PAGE_SIZE = 25;
		const APPEND_RANGE         = 'A1';

		/**
		 * FileStore (or test double) that exposes driveAgent / sheetsAgent.
		 *
		 * @var object
		 */
		private $file_store;

		/**
		 * Construct the probe store.
		 *
		 * @param object $file_store FileStore with __get('driveAgent'|'sheetsAgent').
		 */
		public function __construct( $file_store ) {
			$this->file_store = $file_store;
		}

		/**
		 * Options every Drive files.list must send so Shared Drives do not 403.
		 *
		 * Mirrors Zysys_FileStore::drive_write_params() plus includeItemsFromAllDrives.
		 *
		 * @param array $opts Call-specific Drive files.list options.
		 * @return array
		 */
		public static function drive_list_params( array $opts = array() ) {
			$opts['supportsAllDrives']         = true;
			$opts['includeItemsFromAllDrives'] = true;
			return $opts;
		}

		/**
		 * List children of a Drive folder.
		 *
		 * @param string $folder_id Drive folder id.
		 * @return array<int,array{id:string,name:string}>
		 * @throws Exception If the folder id is empty or Drive is not configured.
		 */
		public function list_folder( $folder_id ) {
			$folder_id = (string) $folder_id;
			if ( '' === $folder_id || false !== strpos( $folder_id, "'" ) ) {
				throw new Exception( 'Invalid Drive folder ID.' );
			}

			$drive = $this->file_store->__get( 'driveAgent' );
			if ( ! $drive || ! isset( $drive->files ) || ! method_exists( $drive->files, 'listFiles' ) ) {
				throw new Exception( 'Drive agent not configured.' );
			}

			$resp  = $drive->files->listFiles(
				self::drive_list_params(
					array(
						'q'        => sprintf( "'%s' in parents and trashed = false", $folder_id ),
						'fields'   => 'files(id,name,mimeType)',
						'pageSize' => self::DRIVE_LIST_PAGE_SIZE,
					)
				)
			);
			$files = ( $resp && method_exists( $resp, 'getFiles' ) ) ? $resp->getFiles() : array();
			$out   = array();
			foreach ( (array) $files as $file ) {
				$out[] = array(
					'id'   => self::file_attr( $file, 'id' ),
					'name' => self::file_attr( $file, 'name' ),
				);
			}
			return $out;
		}

		/**
		 * Append one RAW row to the first sheet of a spreadsheet.
		 *
		 * @param string $sheet_id Spreadsheet id.
		 * @param array  $row      Cell values.
		 * @return void
		 * @throws Exception If the spreadsheet id is empty or Sheets is not configured.
		 */
		public function append_test_row( $sheet_id, array $row ) {
			$sheet_id = (string) $sheet_id;
			if ( '' === $sheet_id ) {
				throw new Exception( 'Invalid spreadsheet ID.' );
			}

			if ( method_exists( $this->file_store, 'gsheet' ) ) {
				$this->file_store->gsheet( $sheet_id );
			}

			$sheets = $this->file_store->__get( 'sheetsAgent' );
			if ( ! $sheets || ! isset( $sheets->spreadsheets_values ) ) {
				throw new Exception( 'Sheets agent not configured.' );
			}

			$body = new Google_Service_Sheets_ValueRange( array( 'values' => array( array_values( $row ) ) ) );
			$sheets->spreadsheets_values->append(
				$sheet_id,
				self::APPEND_RANGE,
				$body,
				array( 'valueInputOption' => 'RAW' )
			);
		}

		/**
		 * Read id or name from a Drive file object.
		 *
		 * @param object $file Drive file.
		 * @param string $attr Attribute name (id or name).
		 * @return string
		 */
		private static function file_attr( $file, $attr ) {
			$getter = 'get' . ucfirst( $attr );
			if ( is_object( $file ) && method_exists( $file, $getter ) ) {
				return (string) $file->{$getter}();
			}
			if ( is_object( $file ) && isset( $file->{$attr} ) ) {
				return (string) $file->{$attr};
			}
			return '';
		}
	}
}
