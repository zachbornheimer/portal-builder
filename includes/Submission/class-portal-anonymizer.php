<?php
/**
 * Judge-facing anonymize owner: decide whether to call All Intersections, then execute.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Anonymize_Decision' ) ) {
	require_once __DIR__ . '/class-portal-anonymize-decision.php';
}

if ( ! class_exists( 'Portal_Anonymizer' ) ) {

	/**
	 * Replaces an upload with anonymized bytes, or keeps / refuses the original.
	 */
	class Portal_Anonymizer {

		const DEFAULT_BASE_URL      = 'https://api.allintersections.com';
		const HTTP_TIMEOUT_SECONDS  = 120;
		const MIB                   = 1048576;
		const MAX_UPLOAD_MIB        = 50;
		const MAX_CREATE_ATTEMPTS   = 3;
		const MAX_DOWNLOAD_ATTEMPTS = 3;
		const MAX_CONFLICT_REMINTS  = 1;
		const MAX_RECREATES         = 1;

		/** @var bool */
		private $enabled;

		/** @var bool */
		private $fail_closed;

		/** @var string */
		private $base_url;

		/** @var string */
		private $api_key;

		/** @var object|null Object with send(array $request). */
		private $transport;

		/** @var callable|null */
		private $uuid_factory;

		/**
		 * @param array         $options      Definition options.
		 * @param object|null   $transport    Injected transport (tests).
		 * @param callable|null $uuid_factory Injected UUID v4 factory.
		 */
		public function __construct( array $options, $transport = null, $uuid_factory = null ) {
			$endpoint           = isset( $options['anonymizeEndpoint'] ) ? $options['anonymizeEndpoint'] : null;
			$key                = isset( $options['anonymizeApiKey'] ) ? $options['anonymizeApiKey'] : null;
			$this->enabled      = ! empty( $options['anonymize'] );
			$this->fail_closed  = ! empty( $options['anonymizeFailClosed'] );
			$this->base_url     = Portal_Anonymizer_Api::normalize_base( $endpoint );
			$this->api_key      = is_string( $key ) ? trim( $key ) : '';
			$this->transport    = $transport;
			$this->uuid_factory = is_callable( $uuid_factory ) ? $uuid_factory : null;
		}

		/**
		 * Missing required config when anonymize is on, or null when ready.
		 *
		 * @param array $options Resolved options.
		 * @return string|null
		 */
		public static function missing_config( array $options ) {
			if ( empty( $options['anonymize'] ) ) {
				return null;
			}
			$key = isset( $options['anonymizeApiKey'] ) && is_string( $options['anonymizeApiKey'] )
				? trim( $options['anonymizeApiKey'] )
				: '';
			if ( '' === $key ) {
				return Portal_Anonymize_Decision::MSG_MISSING_KEY;
			}
			$base = Portal_Anonymizer_Api::normalize_base(
				isset( $options['anonymizeEndpoint'] ) ? $options['anonymizeEndpoint'] : null
			);
			if ( '' === $base ) {
				return Portal_Anonymize_Decision::MSG_MISSING_ENDPOINT;
			}
			return null;
		}

		/**
		 * Decide replace / keep / refuse / skip / block. Does not write storage.
		 *
		 * @param string      $buffer        Original file bytes.
		 * @param string      $filename      Original filename.
		 * @param string|null $declared_type Optional declared MIME.
		 * @return Portal_Anonymize_Decision
		 */
		public function decide( $buffer, $filename, $declared_type = null ) {
			$buffer  = (string) $buffer;
			$missing = self::missing_config(
				array(
					'anonymize'         => $this->enabled,
					'anonymizeApiKey'   => $this->api_key,
					'anonymizeEndpoint' => $this->base_url,
				)
			);
			if ( null !== $missing ) {
				return Portal_Anonymize_Decision::block( $missing );
			}
			if ( ! $this->should_anonymize( $buffer ) ) {
				return Portal_Anonymize_Decision::skip( $buffer );
			}
			try {
				$replaced = $this->replace_bytes( $buffer, (string) $filename, $declared_type );
			} catch ( Throwable $t ) {
				return $this->after_api_failure( $buffer, $t->getMessage() );
			}
			if ( ! is_string( $replaced ) || '' === $replaced ) {
				return $this->after_api_failure( $buffer, Portal_Anonymize_Decision::MSG_UNUSABLE );
			}
			return Portal_Anonymize_Decision::replace( $replaced );
		}

		/**
		 * Return judge-facing bytes. Fail-open wrapper for leftover callers.
		 *
		 * @param string      $buffer        Original file bytes.
		 * @param string      $filename      Original filename.
		 * @param string|null $declared_type Optional declared MIME.
		 * @return string
		 */
		public function maybe_anonymize( $buffer, $filename, $declared_type = null ) {
			$buffer   = (string) $buffer;
			$decision = $this->decide( $buffer, $filename, $declared_type );
			if ( Portal_Anonymize_Decision::ACTION_REPLACE === $decision->action ) {
				return $decision->bytes;
			}
			return $buffer;
		}

		/**
		 * @param string $buffer Original bytes.
		 * @return bool
		 */
		private function should_anonymize( $buffer ) {
			if ( ! $this->enabled || '' === $this->api_key || '' === $buffer ) {
				return false;
			}
			return strlen( $buffer ) <= ( self::MAX_UPLOAD_MIB * self::MIB );
		}

		/**
		 * @param string $buffer Original bytes.
		 * @param string $reason Failure reason.
		 * @return Portal_Anonymize_Decision
		 */
		private function after_api_failure( $buffer, $reason ) {
			if ( $this->fail_closed ) {
				return Portal_Anonymize_Decision::refuse( $reason );
			}
			return Portal_Anonymize_Decision::keep( $buffer );
		}

		/**
		 * @param string      $buffer        Original bytes.
		 * @param string      $filename      Filename.
		 * @param string|null $declared_type Declared MIME.
		 * @return string|null Replacement bytes or null to keep original.
		 */
		private function replace_bytes( $buffer, $filename, $declared_type ) {
			$key            = Portal_Anonymizer_Api::mint_uuid( $this->uuid_factory );
			$creates_left   = self::MAX_CREATE_ATTEMPTS;
			$conflict_left  = self::MAX_CONFLICT_REMINTS;
			$recreates_left = self::MAX_RECREATES;
			$api            = $this->api();

			while ( $creates_left > 0 ) {
				--$creates_left;
				$created = $api->create( $buffer, $filename, $declared_type, $key );
				$next    = $this->after_create( $created, $conflict_left );
				if ( ! empty( $next['retry'] ) ) {
					continue;
				}
				if ( isset( $next['key'] ) ) {
					$key = $next['key'];
					--$conflict_left;
					++$creates_left;
					continue;
				}
				if ( empty( $next['ok'] ) ) {
					return null;
				}

				$downloaded = $this->download_with_retries( $api, $created['id'], $key );
				if ( Portal_Anonymizer_Reply::ACTION_RECREATE === $downloaded['action'] ) {
					if ( $recreates_left <= 0 ) {
						return null;
					}
					--$recreates_left;
					++$creates_left;
					continue;
				}
				if ( Portal_Anonymizer_Reply::ACTION_KEEP === $downloaded['action'] ) {
					return null;
				}
				if ( ! $this->download_is_valid( $downloaded['bytes'], $created['file_type'], $buffer, $filename, $declared_type ) ) {
					return null;
				}
				return $downloaded['bytes'];
			}
			return null;
		}

		/**
		 * @param array $created       Create reply.
		 * @param int   $conflict_left Remints remaining.
		 * @return array{retry?:bool,key?:string,ok?:bool}
		 */
		private function after_create( array $created, $conflict_left ) {
			$action = isset( $created['action'] ) ? $created['action'] : Portal_Anonymizer_Reply::ACTION_KEEP;
			if ( Portal_Anonymizer_Reply::ACTION_RETRY_SAME === $action ) {
				return array( 'retry' => true );
			}
			if ( Portal_Anonymizer_Reply::ACTION_RETRY_NEW === $action ) {
				if ( $conflict_left <= 0 ) {
					return array();
				}
				return array( 'key' => Portal_Anonymizer_Api::mint_uuid( $this->uuid_factory ) );
			}
			if ( Portal_Anonymizer_Reply::ACTION_OK === $action ) {
				return array( 'ok' => true );
			}
			return array();
		}

		/**
		 * @param Portal_Anonymizer_Api $api API.
		 * @param string                $id  Anonymization id.
		 * @param string                $key Idempotency key.
		 * @return array{action:string,bytes?:string}
		 */
		private function download_with_retries( $api, $id, $key ) {
			$tries = self::MAX_DOWNLOAD_ATTEMPTS;
			while ( $tries > 0 ) {
				--$tries;
				$got = $api->download( $id, $key );
				if ( Portal_Anonymizer_Reply::ACTION_RETRY_SAME === $got['action'] ) {
					continue;
				}
				return $got;
			}
			return array( 'action' => Portal_Anonymizer_Reply::ACTION_KEEP );
		}

		/**
		 * @param string      $bytes         Downloaded bytes.
		 * @param string      $api_type      data.file_type.
		 * @param string      $original      Original bytes.
		 * @param string      $filename      Filename.
		 * @param string|null $declared_type Declared MIME.
		 * @return bool
		 */
		private function download_is_valid( $bytes, $api_type, $original, $filename, $declared_type ) {
			$expected = Portal_File_Signature::normalize_type( $api_type );
			if ( '' === $expected ) {
				$expected = Portal_File_Signature::sniff( $original, $filename, $declared_type );
			}
			return Portal_File_Signature::matches( $bytes, $expected );
		}

		/**
		 * @return Portal_Anonymizer_Api
		 */
		private function api() {
			return new Portal_Anonymizer_Api(
				$this->base_url,
				$this->api_key,
				$this->transport(),
				self::HTTP_TIMEOUT_SECONDS
			);
		}

		/**
		 * @return object
		 */
		private function transport() {
			if ( null === $this->transport ) {
				$this->transport = new Portal_Anonymizer_Transport();
			}
			return $this->transport;
		}

	}
}
