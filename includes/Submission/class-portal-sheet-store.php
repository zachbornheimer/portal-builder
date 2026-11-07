<?php
/**
 * Sheet store facade — file-backed JSONL in test mode.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Sheet_Store' ) ) {

	/**
	 * Appends / reads submission rows under artifactDir/sheets/{portalId}.jsonl.
	 */
	class Portal_Sheet_Store {

		const ARTIFACT_DIR_NAME = 'sheets';
		const EXTENSION         = '.jsonl';

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
		 * Path for a portal's sheet artifact.
		 *
		 * @param string|int $portal_id Portal id.
		 * @return string
		 */
		public function path_for( $portal_id ) {
			return $this->files->join(
				$this->artifact_dir,
				self::ARTIFACT_DIR_NAME,
				(string) $portal_id . self::EXTENSION
			);
		}

		/**
		 * Append one row (JSON object) as a JSONL line.
		 *
		 * @param string|int          $portal_id Portal id.
		 * @param array<string,mixed> $row       Row payload.
		 * @return string Path written.
		 */
		public function append_row( $portal_id, array $row ) {
			$dest = $this->path_for( $portal_id );
			$this->files->mkdir( $this->files->dirname( $dest ) );
			$encoded = self::encode_row( $row );
			$this->files->append( $dest, $encoded . "\n" );
			return $dest;
		}

		/**
		 * @param array<string,mixed> $row Row payload.
		 * @return string
		 */
		private static function encode_row( array $row ) {
			if ( function_exists( 'wp_json_encode' ) ) {
				$encoded = wp_json_encode( $row );
				if ( is_string( $encoded ) && '' !== $encoded ) {
					return $encoded;
				}
			}
			$encoded = json_encode( $row );
			return is_string( $encoded ) ? $encoded : '{}';
		}

		/**
		 * Read all rows for a portal.
		 *
		 * @param string|int $portal_id Portal id.
		 * @return array<int,array<string,mixed>>
		 */
		public function read_rows( $portal_id ) {
			$dest = $this->path_for( $portal_id );
			if ( ! $this->files->exists( $dest ) ) {
				return array();
			}
			$text = trim( $this->files->read_text( $dest ) );
			if ( '' === $text ) {
				return array();
			}
			$out = array();
			foreach ( explode( "\n", $text ) as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$decoded = json_decode( $line, true );
				if ( is_array( $decoded ) ) {
					$out[] = $decoded;
				}
			}
			return $out;
		}

		/**
		 * Remove this portal's sheet artifact only.
		 *
		 * @param string|int $portal_id Portal id.
		 * @return void
		 */
		public function clear_portal( $portal_id ) {
			$dest = $this->path_for( $portal_id );
			if ( $this->files->exists( $dest ) ) {
				$this->files->remove( $dest );
			}
		}
	}
}
