<?php
/**
 * Per-portal packets: audit trail + staff replace + applicant list/recall.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Packet_Store' ) ) {

	/**
	 * JSONL under uploads/dg-packets. Sheet/Drive remain dests of record.
	 */
	class Portal_Packet_Store {

		const DIR_NAME  = 'dg-packets';
		const EXTENSION = '.jsonl';

		/** @var string */
		private $dir;

		/** @var Portal_Files */
		private $files;

		/** @var object|null Drive-like dest with store_file(). */
		private $drive;

		/**
		 * @param string            $dir   Packet directory.
		 * @param Portal_Files|null $files FS.
		 * @param object|null       $drive Dest facade.
		 */
		public function __construct( $dir, $files = null, $drive = null ) {
			$this->dir   = rtrim( (string) $dir, '/\\' );
			$this->files = $files instanceof Portal_Files ? $files : new Portal_Files();
			$this->drive = $drive;
		}

		/**
		 * @param Portal_Files|null $files FS.
		 * @param object|null       $drive Dest.
		 * @return self
		 */
		public static function for_uploads( $files = null, $drive = null ) {
			$base = class_exists( 'Portal_Upload_Store' )
				? Portal_Upload_Store::basedir()
				: sys_get_temp_dir();
			return new self( rtrim( (string) $base, '/\\' ) . DIRECTORY_SEPARATOR . self::DIR_NAME, $files, $drive );
		}

		/**
		 * Operator console store with the live dest adapter for this application id.
		 *
		 * @param string|int        $portal_id Portal.
		 * @param string            $app_id    Application id (Drive subfolder).
		 * @param Portal_Files|null $files     FS.
		 * @return self
		 */
		public static function for_portal( $portal_id, $app_id, $files = null ) {
			return self::for_uploads( $files, self::dest_for_replace( $portal_id, $app_id ) );
		}

		/**
		 * Google dest when a definition exists; otherwise a local drive facade.
		 *
		 * @param string|int $portal_id Portal.
		 * @param string     $app_id    Application id.
		 * @return object|null
		 */
		public static function dest_for_replace( $portal_id, $app_id ) {
			if ( class_exists( 'Portal_Definition' ) && class_exists( 'Portal_Google_Store' ) ) {
				$definition = Portal_Definition::load_for_post( (int) $portal_id );
				if ( is_array( $definition ) ) {
					$file_store = Portal_Google_Store::file_store_from_options();
					$dest       = new Portal_Google_Store( $file_store, $definition );
					if ( method_exists( $dest, 'set_submission_id' ) ) {
						$dest->set_submission_id( (string) $app_id );
					}
					return $dest;
				}
			}
			if ( class_exists( 'Portal_Drive_Store' ) && class_exists( 'Portal_Test_Mode' ) ) {
				return new Portal_Drive_Store( Portal_Test_Mode::artifact_dir() );
			}
			return null;
		}

		/**
		 * @param string|int          $portal_id Portal.
		 * @param array<string,mixed> $packet    Packet.
		 * @return array<string,mixed>
		 */
		public function record( $portal_id, array $packet ) {
			if ( empty( $packet['status'] ) ) {
				$packet['status'] = Portal_Packet_Policy::STATUS_CURRENT;
			}
			if ( empty( $packet['time'] ) ) {
				$packet['time'] = gmdate( 'c' );
			}
			$this->append( $portal_id, $packet );
			return $packet;
		}

		/**
		 * @param string|int $portal_id Portal.
		 * @param string     $email     Applicant email.
		 * @return array<int,array<string,mixed>>
		 */
		public function list_for_email( $portal_id, $email ) {
			$hash = class_exists( 'Portal_Submit_Log' )
				? Portal_Submit_Log::hash_email( $email )
				: hash( 'sha256', strtolower( trim( (string) $email ) ) );
			return $this->list_for_hash( $portal_id, $hash );
		}

		/**
		 * @param string|int $portal_id Portal.
		 * @param string     $hash      Email hash.
		 * @return array<int,array<string,mixed>>
		 */
		public function list_for_hash( $portal_id, $hash ) {
			$out = array();
			foreach ( $this->read_all( $portal_id ) as $row ) {
				if ( isset( $row['emailHash'] ) && (string) $row['emailHash'] === (string) $hash ) {
					$out[] = $row;
				}
			}
			return $out;
		}

		/**
		 * @param string|int $portal_id Portal.
		 * @param string     $app_id    Application id.
		 * @return array<string,mixed>|null
		 */
		public function get( $portal_id, $app_id ) {
			foreach ( $this->read_all( $portal_id ) as $row ) {
				if ( isset( $row['applicationId'] ) && (string) $row['applicationId'] === (string) $app_id ) {
					return $row;
				}
			}
			return null;
		}

		/**
		 * @param string|int $portal_id Portal.
		 * @param string     $app_id    Application id.
		 * @return array<string,mixed>|null Recalled packet.
		 */
		public function recall( $portal_id, $app_id ) {
			$packet = $this->get( $portal_id, $app_id );
			if ( ! is_array( $packet ) ) {
				return null;
			}
			$updated = Portal_Packet_Policy::recall( $packet );
			$this->rewrite( $portal_id, $app_id, $updated );
			return $updated;
		}

		/**
		 * Staff replace: write bytes through the dest facade, then audit the packet.
		 *
		 * @param string|int $portal_id Portal.
		 * @param string     $app_id    Application id.
		 * @param string     $field_id  Field id.
		 * @param string     $buffer    New bytes.
		 * @param string     $filename  Dest name.
		 * @return array<string,mixed>|null
		 */
		public function replace_file( $portal_id, $app_id, $field_id, $buffer, $filename ) {
			$packet = $this->get( $portal_id, $app_id );
			if ( ! is_array( $packet ) || ! Portal_Packet_Policy::is_current( $packet ) ) {
				return null;
			}
			if ( is_object( $this->drive ) && method_exists( $this->drive, 'store_file' ) ) {
				$this->drive->store_file( $portal_id, $field_id, $buffer, $filename );
			}
			$packet['replacedAt']   = gmdate( 'c' );
			$packet['replacedField'] = (string) $field_id;
			$this->rewrite( $portal_id, $app_id, $packet );
			return $packet;
		}

		/**
		 * @param string|int $portal_id Portal.
		 * @return array<int,array<string,mixed>>
		 */
		public function all( $portal_id ) {
			return $this->read_all( $portal_id );
		}

		/**
		 * @param string|int $portal_id Portal.
		 * @return string
		 */
		private function path_for( $portal_id ) {
			$safe = preg_replace( '/[^A-Za-z0-9._-]+/', '_', (string) $portal_id );
			return $this->files->join( $this->dir, $safe . self::EXTENSION );
		}

		/**
		 * @param string|int          $portal_id Portal.
		 * @param array<string,mixed> $row       Row.
		 * @return void
		 */
		private function append( $portal_id, array $row ) {
			$this->files->mkdir( $this->dir );
			$path    = $this->path_for( $portal_id );
			$encoded = wp_json_encode( $row );
			if ( ! is_string( $encoded ) ) {
				$encoded = '{}';
			}
			$existing = $this->files->exists( $path ) ? (string) $this->files->read_text( $path ) : '';
			$this->files->write( $path, $existing . $encoded . "\n" );
		}

		/**
		 * @param string|int          $portal_id Portal.
		 * @param string              $app_id    Application id.
		 * @param array<string,mixed> $updated   New row.
		 * @return void
		 */
		private function rewrite( $portal_id, $app_id, array $updated ) {
			$rows = array();
			foreach ( $this->read_all( $portal_id ) as $row ) {
				if ( isset( $row['applicationId'] ) && (string) $row['applicationId'] === (string) $app_id ) {
					$rows[] = $updated;
				} else {
					$rows[] = $row;
				}
			}
			$lines = array();
			foreach ( $rows as $row ) {
				$encoded = wp_json_encode( $row );
				$lines[] = is_string( $encoded ) ? $encoded : '{}';
			}
			$this->files->mkdir( $this->dir );
			$this->files->write( $this->path_for( $portal_id ), implode( "\n", $lines ) . ( $lines ? "\n" : '' ) );
		}

		/**
		 * @param string|int $portal_id Portal.
		 * @return array<int,array<string,mixed>>
		 */
		private function read_all( $portal_id ) {
			$path = $this->path_for( $portal_id );
			if ( ! $this->files->exists( $path ) ) {
				return array();
			}
			$raw  = (string) $this->files->read_text( $path );
			$out  = array();
			foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
				if ( '' === trim( $line ) ) {
					continue;
				}
				$data = json_decode( $line, true );
				if ( is_array( $data ) ) {
					$out[] = $data;
				}
			}
			return $out;
		}
	}
}
