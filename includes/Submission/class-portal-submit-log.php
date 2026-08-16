<?php
/**
 * Append-only operator log of definition-submit dest outcomes.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Submit_Log' ) ) {

	/**
	 * One JSONL file per portal under uploads/dg-logs. No file bytes, no CRM.
	 */
	class Portal_Submit_Log {

		const DIR_NAME        = 'dg-logs';
		const EXTENSION       = '.jsonl';
		const MAX_ROWS        = 200;
		const DEFAULT_LAST_N  = 20;
		const DEST_SHEET      = 'sheet';
		const DEST_DRIVE      = 'drive';
		const DEST_ANONYMIZE  = 'anonymize';
		const DEST_MAIL       = 'mail';
		const DEST_OK         = 'ok';
		const DEST_FAIL       = 'fail';
		const DEST_SKIP       = 'skip';
		const DEST_WARNING    = 'warning';
		const CODE_OK         = 'ok';
		const CODE_DRIVE      = 'drive_fail';
		const CODE_SHEET      = 'sheet_fail';
		const CODE_MAIL       = 'mail_fail';
		const EMAIL_HASH_ALGO = 'sha256';

		/** @var string */
		private $log_dir;

		/** @var Portal_Files */
		private $files;

		/** @var callable|null */
		private $now;

		/**
		 * @param string            $log_dir Directory for {portalId}.jsonl.
		 * @param Portal_Files|null $files   Injectable FS.
		 * @param callable|null     $now     Clock returning unix seconds or ISO time.
		 */
		public function __construct( $log_dir, $files = null, $now = null ) {
			$this->log_dir = rtrim( (string) $log_dir, '/\\' );
			$this->files   = $files instanceof Portal_Files ? $files : new Portal_Files();
			$this->now     = is_callable( $now ) ? $now : null;
		}

		/**
		 * Live store under wp_upload_dir()/dg-logs.
		 *
		 * @param Portal_Files|null $files Injectable FS.
		 * @param callable|null     $now   Clock.
		 * @return self
		 */
		public static function for_uploads( $files = null, $now = null ) {
			$base = class_exists( 'Portal_Upload_Store' )
				? Portal_Upload_Store::basedir()
				: sys_get_temp_dir();
			return new self( rtrim( (string) $base, '/\\' ) . DIRECTORY_SEPARATOR . self::DIR_NAME, $files, $now );
		}

		/**
		 * Closed dest map. Skip is a result, not an omitted key.
		 *
		 * @return array<string,string>
		 */
		public static function empty_dests() {
			return array(
				self::DEST_SHEET     => self::DEST_SKIP,
				self::DEST_DRIVE     => self::DEST_SKIP,
				self::DEST_ANONYMIZE => self::DEST_SKIP,
				self::DEST_MAIL      => self::DEST_SKIP,
			);
		}

		/**
		 * @param string|int $portal_id Portal id.
		 * @return string
		 */
		public function path_for( $portal_id ) {
			return $this->files->join( $this->log_dir, self::safe_id( $portal_id ) . self::EXTENSION );
		}

		/**
		 * Append one dest-result row. Never stores file bytes or raw email.
		 *
		 * @param string|int           $portal_id Portal id.
		 * @param string               $app_id    Application id.
		 * @param array<string,string> $dests     Dest => ok|fail|skip|warning.
		 * @param string               $code      Error code (`ok` on happy path).
		 * @param string               $email     Optional applicant email (hashed only).
		 * @param bool                 $test      Per-portal testMode submit.
		 * @return array<string,mixed> Written row.
		 */
		public function record( $portal_id, $app_id, array $dests, $code, $email = '', $test = false ) {
			$row = array(
				'applicationId' => (string) $app_id,
				'time'          => $this->timestamp(),
				'dests'         => self::normalize_dests( $dests ),
				'errorCode'     => (string) $code,
			);
			$hash = self::hash_email( $email );
			if ( '' !== $hash ) {
				$row['emailHash'] = $hash;
			}
			if ( $test ) {
				$row['test'] = true;
			}
			$this->append( $portal_id, $row );
			return $row;
		}

		/**
		 * Last N rows, oldest first.
		 *
		 * @param string|int $portal_id Portal id.
		 * @param int        $limit     Max rows.
		 * @return array<int,array<string,mixed>>
		 */
		public function last( $portal_id, $limit = self::DEFAULT_LAST_N ) {
			$limit = (int) $limit;
			if ( $limit <= 0 ) {
				$limit = self::DEFAULT_LAST_N;
			}
			$rows = $this->read_all( $portal_id );
			if ( count( $rows ) <= $limit ) {
				return $rows;
			}
			return array_values( array_slice( $rows, -$limit ) );
		}

		/**
		 * Tiny last-N table for the portal setup page.
		 *
		 * @param string|int $portal_id Portal id.
		 * @param int        $limit     Max rows.
		 * @return string
		 */
		public function render_admin( $portal_id, $limit = self::DEFAULT_LAST_N ) {
			$rows = $this->last( $portal_id, $limit );
			$head = '<section class="dg-submit-log" data-dg-submit-log><h2>'
				. self::escape( 'Recent submissions' )
				. '</h2>';
			if ( empty( $rows ) ) {
				return $head . '<p>' . self::escape( 'No submissions recorded yet.' ) . '</p></section>';
			}
			$body = '';
			foreach ( $rows as $row ) {
				$dests = isset( $row['dests'] ) && is_array( $row['dests'] ) ? $row['dests'] : array();
				$body .= '<tr>';
				$body .= '<td>' . self::escape( isset( $row['time'] ) ? $row['time'] : '' ) . '</td>';
				$body .= '<td>' . self::escape( isset( $row['applicationId'] ) ? $row['applicationId'] : '' ) . '</td>';
				foreach ( array( self::DEST_SHEET, self::DEST_DRIVE, self::DEST_ANONYMIZE, self::DEST_MAIL ) as $dest ) {
					$body .= '<td>' . self::escape( isset( $dests[ $dest ] ) ? $dests[ $dest ] : self::DEST_SKIP ) . '</td>';
				}
				$body .= '<td>' . self::escape( isset( $row['errorCode'] ) ? $row['errorCode'] : '' ) . '</td>';
				$body .= '</tr>';
			}
			return $head
				. '<table><thead><tr><th>Time</th><th>Application</th><th>Sheet</th><th>Drive</th><th>Anonymize</th><th>Mail</th><th>Status</th></tr></thead><tbody>'
				. $body
				. '</tbody></table></section>';
		}

		/**
		 * @param string|int $portal_id Portal id.
		 * @return void
		 */
		public function clear_portal( $portal_id ) {
			$dest = $this->path_for( $portal_id );
			if ( $this->files->exists( $dest ) ) {
				$this->files->remove( $dest );
			}
		}

		/**
		 * @param string $email Applicant email.
		 * @return string
		 */
		public static function hash_email( $email ) {
			$email = strtolower( trim( (string) $email ) );
			if ( '' === $email ) {
				return '';
			}
			return hash( self::EMAIL_HASH_ALGO, $email );
		}

		/**
		 * @param array<string,string> $dests Dest map.
		 * @return array<string,string>
		 */
		public static function normalize_dests( array $dests ) {
			$out = self::empty_dests();
			foreach ( $out as $key => $default ) {
				if ( ! isset( $dests[ $key ] ) ) {
					continue;
				}
				$value = (string) $dests[ $key ];
				if ( '' !== $value ) {
					$out[ $key ] = $value;
				}
			}
			return $out;
		}

		/**
		 * @param string|int          $portal_id Portal id.
		 * @param array<string,mixed> $row       Row.
		 * @return void
		 */
		private function append( $portal_id, array $row ) {
			$dest = $this->path_for( $portal_id );
			try {
				$this->files->mkdir( $this->files->dirname( $dest ) );
				$this->files->append( $dest, self::encode_row( $row ) . "\n" );
				$this->trim_to_cap( $portal_id );
			} catch ( Exception $e ) {
				return;
			}
		}

		/**
		 * @param string|int $portal_id Portal id.
		 * @return void
		 */
		private function trim_to_cap( $portal_id ) {
			$rows = $this->read_all( $portal_id );
			if ( count( $rows ) <= self::MAX_ROWS ) {
				return;
			}
			$kept = array_slice( $rows, -self::MAX_ROWS );
			$buf  = '';
			foreach ( $kept as $row ) {
				$buf .= self::encode_row( $row ) . "\n";
			}
			$this->files->write( $this->path_for( $portal_id ), $buf );
		}

		/**
		 * @param string|int $portal_id Portal id.
		 * @return array<int,array<string,mixed>>
		 */
		private function read_all( $portal_id ) {
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
		 * @return string
		 */
		private function timestamp() {
			if ( null !== $this->now ) {
				$stamp = call_user_func( $this->now );
				if ( is_string( $stamp ) && '' !== $stamp ) {
					return $stamp;
				}
				if ( is_int( $stamp ) || is_float( $stamp ) ) {
					return gmdate( 'c', (int) $stamp );
				}
			}
			return gmdate( 'c' );
		}

		/**
		 * @param array<string,mixed> $row Row.
		 * @return string
		 */
		private static function encode_row( array $row ) {
			unset( $row['contents'], $row['buffer'], $row['fileBytes'], $row['email'] );
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
		 * @param string|int $portal_id Portal id.
		 * @return string
		 */
		private static function safe_id( $portal_id ) {
			$id = preg_replace( '/[^A-Za-z0-9_-]/', '_', (string) $portal_id );
			return is_string( $id ) && '' !== $id ? $id : 'portal';
		}

		/**
		 * @param mixed $text Text.
		 * @return string
		 */
		private static function escape( $text ) {
			$text = (string) $text;
			if ( function_exists( 'esc_html' ) ) {
				return esc_html( $text );
			}
			return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
		}
	}
}
