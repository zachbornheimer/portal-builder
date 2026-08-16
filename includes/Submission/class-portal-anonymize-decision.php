<?php
/**
 * Outcome of one judge-facing anonymize attempt.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Anonymize_Decision' ) ) {

	/**
	 * Replace, keep-original, refuse-original, skip, or block-missing-config.
	 */
	class Portal_Anonymize_Decision {

		const ACTION_REPLACE = 'replace';
		const ACTION_KEEP    = 'keep';
		const ACTION_REFUSE  = 'refuse';
		const ACTION_SKIP    = 'skip';
		const ACTION_BLOCK   = 'block';

		const CODE_CONFIG  = 'dg_anonymize_config';
		const CODE_REFUSED = 'dg_anonymize_refused';

		const MSG_MISSING_KEY      = 'Anonymize is on but the API key is missing.';
		const MSG_MISSING_ENDPOINT = 'Anonymize is on but the API URL is missing.';
		const MSG_UNUSABLE         = 'Anonymize returned unusable bytes.';

		/** @var string One of ACTION_*. */
		public $action;

		/** @var string Bytes to store, or empty when refused. */
		public $bytes;

		/** @var string Operator dest: ok, skip, or fail. */
		public $dest;

		/** @var string Error code when dest is fail. */
		public $code;

		/** @var string Operator-facing reason. */
		public $message;

		/**
		 * @param string $action  One of ACTION_*.
		 * @param string $bytes   Bytes to store, or empty when storage is refused.
		 * @param string $dest    Operator dest: ok|skip|fail.
		 * @param string $code    Error code when dest is fail.
		 * @param string $message Operator-facing reason.
		 */
		private function __construct( $action, $bytes, $dest, $code = '', $message = '' ) {
			$this->action  = (string) $action;
			$this->bytes   = (string) $bytes;
			$this->dest    = (string) $dest;
			$this->code    = (string) $code;
			$this->message = (string) $message;
		}

		/**
		 * Successful replacement.
		 *
		 * @param string $bytes Anonymized bytes.
		 * @return self
		 */
		public static function replace( $bytes ) {
			return new self( self::ACTION_REPLACE, $bytes, 'ok' );
		}

		/**
		 * Fail-open: keep identifying bytes; dest is fail, not ok.
		 *
		 * @param string $bytes Original bytes.
		 * @return self
		 */
		public static function keep( $bytes ) {
			return new self( self::ACTION_KEEP, $bytes, 'fail' );
		}

		/**
		 * Fail-closed: do not store identifying bytes.
		 *
		 * @param string $message Reason.
		 * @return self
		 */
		public static function refuse( $message ) {
			return new self(
				self::ACTION_REFUSE,
				'',
				'fail',
				self::CODE_REFUSED,
				(string) $message
			);
		}

		/**
		 * Anonymize was not required (off, empty, or oversized).
		 *
		 * @param string $bytes Original bytes.
		 * @return self
		 */
		public static function skip( $bytes ) {
			return new self( self::ACTION_SKIP, $bytes, 'skip' );
		}

		/**
		 * Required config is missing. Never store originals.
		 *
		 * @param string $message Reason.
		 * @return self
		 */
		public static function block( $message ) {
			return new self(
				self::ACTION_BLOCK,
				'',
				'fail',
				self::CODE_CONFIG,
				(string) $message
			);
		}

		/**
		 * Whether callers may write $bytes.
		 *
		 * @return bool
		 */
		public function stores() {
			return self::ACTION_REPLACE === $this->action || self::ACTION_KEEP === $this->action || self::ACTION_SKIP === $this->action;
		}

		/**
		 * Whether callers must not write identifying bytes.
		 *
		 * @return bool
		 */
		public function blocks_store() {
			return self::ACTION_BLOCK === $this->action || self::ACTION_REFUSE === $this->action;
		}
	}
}
