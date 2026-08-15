<?php
/**
 * HTTP transport for the All Intersections anonymize API.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Anonymizer_Transport' ) ) {

	/**
	 * Sends one HTTP request. Anonymizer owns decide/retry; this only transports.
	 */
	class Portal_Anonymizer_Transport {

		const DEFAULT_TIMEOUT_SECONDS = 120;
		const MULTIPART_PREFIX        = 'dg-anon-';

		/**
		 * @param array $request {
		 *     method: string,
		 *     url: string,
		 *     headers?: array<string,string>,
		 *     body?: string,
		 *     multipart?: array,
		 *     timeout?: int
		 * }
		 * @return array{status:int,headers:array,body:string}
		 */
		public function send( array $request ) {
			$url = isset( $request['url'] ) ? (string) $request['url'] : '';
			if ( '' === $url ) {
				throw new RuntimeException( 'anonymize transport missing url' );
			}

			$method  = isset( $request['method'] ) ? strtoupper( (string) $request['method'] ) : 'POST';
			$timeout = isset( $request['timeout'] ) ? (int) $request['timeout'] : self::DEFAULT_TIMEOUT_SECONDS;
			if ( $timeout < self::DEFAULT_TIMEOUT_SECONDS ) {
				$timeout = self::DEFAULT_TIMEOUT_SECONDS;
			}

			$headers = isset( $request['headers'] ) && is_array( $request['headers'] )
				? $request['headers']
				: array();
			$encoded = $this->encode_body( $request );
			$headers = array_merge( $headers, $encoded['headers'] );

			if ( function_exists( 'wp_remote_request' ) ) {
				return $this->send_with_wp( $url, $method, $headers, $encoded['body'], $timeout );
			}
			return $this->send_with_curl( $url, $method, $headers, $encoded['body'], $timeout );
		}

		/**
		 * @param array $request Request.
		 * @return array{body:string,headers:array<string,string>}
		 */
		private function encode_body( array $request ) {
			if ( isset( $request['multipart'] ) && is_array( $request['multipart'] ) ) {
				return $this->encode_multipart( $request['multipart'] );
			}
			$body = isset( $request['body'] ) ? (string) $request['body'] : '';
			return array(
				'body'    => $body,
				'headers' => array(),
			);
		}

		/**
		 * @param array $multipart Multipart spec (file + optional fields).
		 * @return array{body:string,headers:array<string,string>}
		 */
		private function encode_multipart( array $multipart ) {
			$boundary = self::MULTIPART_PREFIX . bin2hex( random_bytes( 16 ) );
			$parts    = '';

			if ( isset( $multipart['fields'] ) && is_array( $multipart['fields'] ) ) {
				foreach ( $multipart['fields'] as $name => $value ) {
					if ( ! is_string( $value ) || '' === $value ) {
						continue;
					}
					$parts .= $this->multipart_field( $boundary, (string) $name, $value );
				}
			}

			if ( isset( $multipart['file'] ) && is_array( $multipart['file'] ) ) {
				$parts .= $this->multipart_file( $boundary, $multipart['file'] );
			}

			$parts .= '--' . $boundary . "--\r\n";

			return array(
				'body'    => $parts,
				'headers' => array(
					'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
				),
			);
		}

		/**
		 * @param string $boundary Boundary.
		 * @param string $name     Field name.
		 * @param string $value    Field value.
		 * @return string
		 */
		private function multipart_field( $boundary, $name, $value ) {
			return '--' . $boundary . "\r\n"
				. 'Content-Disposition: form-data; name="' . $this->header_token( $name ) . "\"\r\n\r\n"
				. $value . "\r\n";
		}

		/**
		 * @param string $boundary Boundary.
		 * @param array  $file     File part.
		 * @return string
		 */
		private function multipart_file( $boundary, array $file ) {
			$filename = isset( $file['filename'] ) ? $this->header_token( (string) $file['filename'] ) : 'upload.bin';
			$contents = isset( $file['contents'] ) ? (string) $file['contents'] : '';
			$mime     = isset( $file['type'] ) ? $this->header_token( (string) $file['type'] ) : '';
			if ( '' === $mime && class_exists( 'Portal_File_Signature' ) ) {
				$mime = Portal_File_Signature::sniff( $contents, $filename, null );
			}
			if ( '' === $mime ) {
				$mime = 'application/octet-stream';
			}
			return '--' . $boundary . "\r\n"
				. 'Content-Disposition: form-data; name="file"; filename="' . $filename . "\"\r\n"
				. 'Content-Type: ' . $mime . "\r\n\r\n"
				. $contents . "\r\n";
		}

		/**
		 * @param string $value Header token.
		 * @return string
		 */
		private function header_token( $value ) {
			return str_replace( array( "\r", "\n", '"' ), '', $value );
		}

		/**
		 * @param string               $url     URL.
		 * @param string               $method  Method.
		 * @param array<string,string> $headers Headers.
		 * @param string               $body    Body.
		 * @param int                  $timeout Seconds.
		 * @return array{status:int,headers:array,body:string}
		 */
		private function send_with_wp( $url, $method, array $headers, $body, $timeout ) {
			$response = wp_remote_request(
				$url,
				array(
					'method'  => $method,
					'timeout' => $timeout,
					'headers' => $headers,
					'body'    => $body,
				)
			);
			if ( is_wp_error( $response ) ) {
				throw new RuntimeException( 'anonymize transport request failed' );
			}
			return array(
				'status'  => (int) wp_remote_retrieve_response_code( $response ),
				'headers' => array(),
				'body'    => (string) wp_remote_retrieve_body( $response ),
			);
		}

		/**
		 * @param string               $url     URL.
		 * @param string               $method  Method.
		 * @param array<string,string> $headers Headers.
		 * @param string               $body    Body.
		 * @param int                  $timeout Seconds.
		 * @return array{status:int,headers:array,body:string}
		 */
		private function send_with_curl( $url, $method, array $headers, $body, $timeout ) {
			if ( ! function_exists( 'curl_init' ) ) {
				throw new RuntimeException( 'anonymize transport unavailable' );
			}
			$lines = array();
			foreach ( $headers as $name => $value ) {
				$lines[] = $name . ': ' . $value;
			}
			$handle = curl_init( $url );
			if ( false === $handle ) {
				throw new RuntimeException( 'anonymize transport unavailable' );
			}
			curl_setopt_array(
				$handle,
				array(
					CURLOPT_CUSTOMREQUEST  => $method,
					CURLOPT_POSTFIELDS     => $body,
					CURLOPT_HTTPHEADER     => $lines,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT        => $timeout,
				)
			);
			$payload = curl_exec( $handle );
			$status  = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
			$errno   = curl_errno( $handle );
			curl_close( $handle );
			if ( false === $payload || 0 !== $errno ) {
				throw new RuntimeException( 'anonymize transport request failed' );
			}
			return array(
				'status'  => $status,
				'headers' => array(),
				'body'    => (string) $payload,
			);
		}
	}
}
