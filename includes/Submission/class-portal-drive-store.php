<?php
/**
 * Drive store facade — file-backed under artifactDir/drive/{portalId}/.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Drive_Store' ) ) {

	/**
	 * Stores submission files for a portal under the artifact drive tree.
	 */
	class Portal_Drive_Store {

		const ARTIFACT_DIR_NAME = 'drive';

		/** @var Portal_Files */
		private $files;

		/** @var string */
		private $artifact_dir;

		/**
		 * @param string            $artifact_dir Absolute artifact root.
		 * @param Portal_Files|null $files        Injectable filesystem.
		 */
		public function __construct( $artifact_dir, $files = null ) {
			$this->artifact_dir = (string) $artifact_dir;
			$this->files        = $files instanceof Portal_Files ? $files : new Portal_Files();
		}

		/**
		 * Directory for a portal's drive files.
		 *
		 * @param string|int $portal_id Portal id.
		 * @return string
		 */
		public function dir_for( $portal_id ) {
			return $this->files->join(
				$this->artifact_dir,
				self::ARTIFACT_DIR_NAME,
				(string) $portal_id
			);
		}

		/**
		 * Store one field's file.
		 *
		 * @param string|int      $portal_id Portal id.
		 * @param string          $field_id  Definition field id.
		 * @param string          $buffer    File bytes.
		 * @param string          $filename  Original / destination basename.
		 * @return string Absolute path written.
		 */
		public function store_file( $portal_id, $field_id, $buffer, $filename ) {
			$dir = $this->dir_for( $portal_id );
			$this->files->mkdir( $dir );
			$safe_name = self::sanitize_filename( $filename );
			$dest      = $this->files->join( $dir, (string) $field_id . '-' . $safe_name );
			$this->files->write( $dest, $buffer );
			return $dest;
		}

		/**
		 * Remove this portal's drive folder.
		 *
		 * @param string|int $portal_id Portal id.
		 * @return void
		 */
		public function clear_portal( $portal_id ) {
			$dir = $this->dir_for( $portal_id );
			if ( $this->files->exists( $dir ) ) {
				$this->files->remove( $dir );
			}
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
