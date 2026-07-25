<?php
/**
 * Mail capture facade — writes messages under artifactDir/mail/*.json in test mode.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Mailer' ) ) {

	/**
	 * Captures outbound mail as JSON artifacts (real SMTP lands later).
	 */
	class Portal_Mailer {

		const ARTIFACT_DIR_NAME     = 'mail';
		const EXTENSION             = '.json';
		const UNKNOWN_RECIPIENT     = 'unknown';
		const DEFAULT_RECEIPT_SUBJECT = 'Application receipt';

		/** @var Portal_Files */
		private $files;

		/** @var string */
		private $artifact_dir;

		/** @var callable|null Returns milliseconds since epoch. */
		private $now_ms;

		/**
		 * @param string            $artifact_dir Absolute artifact root.
		 * @param Portal_Files|null $files        Injectable filesystem.
		 * @param callable|null     $now_ms       Injectable clock: () => int ms.
		 */
		public function __construct( $artifact_dir, $files = null, $now_ms = null ) {
			$this->artifact_dir = (string) $artifact_dir;
			$this->files        = $files instanceof Portal_Files ? $files : new Portal_Files();
			$this->now_ms       = is_callable( $now_ms ) ? $now_ms : null;
		}

		/**
		 * Capture a message; returns path written.
		 *
		 * @param array<string,mixed> $message Keys: to, subject, body, portalId, …
		 * @return string Absolute path written.
		 */
		public function capture( array $message ) {
			$dir = $this->files->join( $this->artifact_dir, self::ARTIFACT_DIR_NAME );
			$this->files->mkdir( $dir );

			$to       = isset( $message['to'] ) && is_string( $message['to'] ) && '' !== $message['to']
				? $message['to']
				: self::UNKNOWN_RECIPIENT;
			$safe_to  = preg_replace( '/[^A-Za-z0-9@._+-]+/', '_', $to );
			$ms       = $this->now_ms ? (int) call_user_func( $this->now_ms ) : (int) round( microtime( true ) * 1000 );
			$dest     = $this->files->join( $dir, $ms . '-' . $safe_to . self::EXTENSION );

			$payload = array_merge(
				array(
					'capturedAt' => gmdate( 'c' ),
				),
				$message
			);

			$encoded = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $encoded ) || '' === $encoded ) {
				$encoded = '{}';
			}

			$this->files->write( $dest, $encoded );
			return $dest;
		}
	}
}
