<?php
/**
 * All Intersections create + content POSTs for one upload.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Anonymizer_Api' ) ) {

	/**
	 * Builds the two POSTs. Retry policy stays on Portal_Anonymizer.
	 */
	class Portal_Anonymizer_Api {

		const CREATE_PATH  = '/v1/anonymizations';
		const CONTENT_PATH = '/v1/anonymizations/{id}/content';

		/** @var string */
		private $base_url;

		/** @var string */
		private $api_key;

		/** @var object */
		private $transport;

		/** @var int */
		private $timeout;

		/**
		 * @param string $base_url  API base (no trailing slash issues).
		 * @param string $api_key   Bearer secret; never logged.
		 * @param object $transport Object with send(array $request).
		 * @param int    $timeout   HTTP timeout seconds.
		 */
		public function __construct( $base_url, $api_key, $transport, $timeout ) {
			$this->base_url  = (string) $base_url;
			$this->api_key   = (string) $api_key;
			$this->transport = $transport;
			$this->timeout   = (int) $timeout;
		}

		/**
		 * @param string      $buffer        File bytes.
		 * @param string      $filename      Filename.
		 * @param string|null $declared_type Optional MIME.
		 * @param string      $key           Idempotency-Key.
		 * @return array{action:string,id?:string,file_type?:string}
		 */
		public function create( $buffer, $filename, $declared_type, $key ) {
			$response = $this->transport->send(
				array(
					'method'    => 'POST',
					'url'       => self::join_url( $this->base_url, self::CREATE_PATH ),
					'headers'   => $this->headers( $key ),
					'timeout'   => $this->timeout,
					'multipart' => $this->multipart( $buffer, $filename, $declared_type ),
				)
			);
			return Portal_Anonymizer_Reply::interpret_create( $response );
		}

		/**
		 * @param string $id  Anonymization id.
		 * @param string $key Idempotency-Key.
		 * @return array{action:string,bytes?:string}
		 */
		public function download( $id, $key ) {
			$path     = str_replace( '{id}', rawurlencode( (string) $id ), self::CONTENT_PATH );
			$response = $this->transport->send(
				array(
					'method'  => 'POST',
					'url'     => self::join_url( $this->base_url, $path ),
					'headers' => $this->headers( $key ),
					'timeout' => $this->timeout,
					'body'    => '',
				)
			);
			return Portal_Anonymizer_Reply::interpret_download( $response );
		}

		/**
		 * @param string $base Base URL.
		 * @param string $path Path.
		 * @return string
		 */
		public static function join_url( $base, $path ) {
			return rtrim( (string) $base, '/' ) . '/' . ltrim( (string) $path, '/' );
		}

		/**
		 * Empty/null endpoint uses the default All Intersections base.
		 *
		 * @param mixed $endpoint Raw option.
		 * @return string
		 */
		public static function normalize_base( $endpoint ) {
			if ( ! is_string( $endpoint ) ) {
				return Portal_Anonymizer::DEFAULT_BASE_URL;
			}
			$trimmed = trim( $endpoint );
			return '' === $trimmed ? Portal_Anonymizer::DEFAULT_BASE_URL : rtrim( $trimmed, '/' );
		}

		/**
		 * UUID v4 via WP when loaded; otherwise random_bytes.
		 *
		 * @param callable|null $factory Optional factory.
		 * @return string
		 */
		public static function mint_uuid( $factory = null ) {
			if ( is_callable( $factory ) ) {
				return (string) call_user_func( $factory );
			}
			if ( function_exists( 'wp_generate_uuid4' ) ) {
				return wp_generate_uuid4();
			}
			$bytes    = random_bytes( 16 );
			$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
			$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
			$hex      = bin2hex( $bytes );
			return sprintf(
				'%s-%s-%s-%s-%s',
				substr( $hex, 0, 8 ),
				substr( $hex, 8, 4 ),
				substr( $hex, 12, 4 ),
				substr( $hex, 16, 4 ),
				substr( $hex, 20, 12 )
			);
		}

		/**
		 * @param string $key Idempotency-Key.
		 * @return array<string,string>
		 */
		private function headers( $key ) {
			return array(
				'Authorization'   => 'Bearer ' . $this->api_key,
				'Idempotency-Key' => $key,
			);
		}

		/**
		 * @param string      $buffer        Bytes.
		 * @param string      $filename      Name.
		 * @param string|null $declared_type MIME.
		 * @return array
		 */
		private function multipart( $buffer, $filename, $declared_type ) {
			$multipart = array(
				'file'   => array(
					'filename' => $filename,
					'contents' => $buffer,
					'type'     => Portal_File_Signature::sniff( $buffer, $filename, $declared_type ),
				),
				'fields' => array(),
			);
			$type      = $this->optional_type( $buffer, $filename, $declared_type );
			if ( null !== $type ) {
				$multipart['fields']['type'] = $type;
			}
			return $multipart;
		}

		/**
		 * Optional `type` only when declared MIME disagrees with sniffed bytes.
		 *
		 * @param string      $buffer        Bytes.
		 * @param string      $filename      Name.
		 * @param string|null $declared_type MIME.
		 * @return string|null
		 */
		private function optional_type( $buffer, $filename, $declared_type ) {
			$declared = Portal_File_Signature::normalize_type( $declared_type );
			if ( '' === $declared ) {
				return null;
			}
			$sniffed = Portal_File_Signature::sniff( $buffer, $filename, null );
			return $declared !== $sniffed ? $declared : null;
		}
	}
}
