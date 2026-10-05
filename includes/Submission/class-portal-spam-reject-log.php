<?php
/**
 * Append-only Turnstile reject evidence (no tokens).
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Files' ) ) {
	require_once __DIR__ . '/class-portal-files.php';
}

if ( ! class_exists( 'Portal_Spam_Reject_Log' ) ) {

	/**
	 * Shared JSONL under uploads/dg-logs/spam-rejects.jsonl. Caps at MAX_ROWS.
	 */
	class Portal_Spam_Reject_Log {

		const DIR_NAME  = 'dg-logs';
		const FILE_NAME = 'spam-rejects.jsonl';
		const MAX_ROWS  = 200;
		const UA_MAX    = 512;

		/** @var string */
		private $log_dir;

		/** @var Portal_Files */
		private $files;

		/** @var callable|null */
		private $now;

		/**
		 * @param string            $log_dir Directory for spam-rejects.jsonl.
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
		 * @return string
		 */
		public function path() {
			return $this->files->join( $this->log_dir, self::FILE_NAME );
		}

		/**
		 * Append one reject row. Never stores the Turnstile token.
		 *
		 * @param array<string,mixed> $row Row fields (token keys stripped).
		 * @return array<string,mixed> Written row (with time if missing).
		 */
		public function record( array $row ) {
			unset(
				$row['token'],
				$row['cf-turnstile-response'],
				$row['contents'],
				$row['buffer'],
				$row['fileBytes'],
				$row['email']
			);
			if ( ! isset( $row['time'] ) || '' === $row['time'] ) {
				$row['time'] = $this->timestamp();
			}
			if ( isset( $row['userAgent'] ) && is_string( $row['userAgent'] ) && strlen( $row['userAgent'] ) > self::UA_MAX ) {
				$row['userAgent'] = substr( $row['userAgent'], 0, self::UA_MAX );
			}
			$line = self::encode_row( $row );
			$wrote = false;
			try {
				$this->files->mkdir( $this->log_dir );
				$this->files->append( $this->path(), $line . "\n" );
				$wrote = $this->files->exists( $this->path() );
				if ( $wrote ) {
					$this->trim_to_cap();
				}
			} catch ( Exception $e ) {
				$wrote = false;
			}
			if ( ! $wrote && function_exists( 'error_log' ) ) {
				error_log( 'dg_spam_reject ' . $line );
			}
			return $row;
		}

		/**
		 * Last N rows, oldest first.
		 *
		 * @param int $limit Max rows.
		 * @return array<int,array<string,mixed>>
		 */
		public function last( $limit = self::MAX_ROWS ) {
			$limit = (int) $limit;
			if ( $limit <= 0 ) {
				$limit = self::MAX_ROWS;
			}
			$rows = $this->read_all();
			if ( count( $rows ) <= $limit ) {
				return $rows;
			}
			return array_values( array_slice( $rows, -$limit ) );
		}

		/**
		 * @return void
		 */
		private function trim_to_cap() {
			$rows = $this->read_all();
			if ( count( $rows ) <= self::MAX_ROWS ) {
				return;
			}
			$kept = array_slice( $rows, -self::MAX_ROWS );
			$buf  = '';
			foreach ( $kept as $row ) {
				$buf .= self::encode_row( $row ) . "\n";
			}
			$this->files->write( $this->path(), $buf );
		}

		/**
		 * @return array<int,array<string,mixed>>
		 */
		private function read_all() {
			$dest = $this->path();
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
			if ( function_exists( 'wp_json_encode' ) ) {
				$encoded = wp_json_encode( $row );
				if ( is_string( $encoded ) && '' !== $encoded ) {
					return $encoded;
				}
			}
			$encoded = json_encode( $row );
			return is_string( $encoded ) ? $encoded : '{}';
		}
	}
}
