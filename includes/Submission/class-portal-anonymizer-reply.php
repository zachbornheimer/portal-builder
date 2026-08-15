<?php
/**
 * All Intersections create/download replies → the next anonymize action.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Anonymizer_Reply' ) ) {

	/**
	 * Pure interpreter for anonymize HTTP replies. No I/O.
	 */
	class Portal_Anonymizer_Reply {

		const ACTION_OK              = 'ok';
		const ACTION_KEEP            = 'keep';
		const ACTION_RETRY_SAME      = 'retry_same';
		const ACTION_RETRY_NEW       = 'retry_new';
		const ACTION_RECREATE        = 'recreate';
		const STATUS_COMPLETED       = 'completed';
		const CODE_INTERNAL_ERROR    = 'internal_error';
		const CODE_IDEMPOTENCY       = 'idempotency_conflict';
		const CODE_NOT_FOUND         = 'not_found';
		const CODE_PAYLOAD_TOO_LARGE = 'payload_too_large';

		/**
		 * @param array $response Transport response.
		 * @return array{action:string,id?:string,file_type?:string}
		 */
		public static function interpret_create( array $response ) {
			$status = self::status( $response );
			$code   = self::problem_code( $response );
			if ( self::CODE_IDEMPOTENCY === $code ) {
				return array( 'action' => self::ACTION_RETRY_NEW );
			}
			if ( self::CODE_PAYLOAD_TOO_LARGE === $code ) {
				return array( 'action' => self::ACTION_KEEP );
			}
			if ( self::is_retryable( $status, $code ) ) {
				return array( 'action' => self::ACTION_RETRY_SAME );
			}
			if ( $status < 200 || $status >= 300 ) {
				return array( 'action' => self::ACTION_KEEP );
			}
			$parsed = self::parse_create_envelope( $response );
			if ( null === $parsed ) {
				return array( 'action' => self::ACTION_KEEP );
			}
			return array(
				'action'    => self::ACTION_OK,
				'id'        => $parsed['id'],
				'file_type' => $parsed['file_type'],
			);
		}

		/**
		 * @param array $response Transport response.
		 * @return array{action:string,bytes?:string}
		 */
		public static function interpret_download( array $response ) {
			$status = self::status( $response );
			$code   = self::problem_code( $response );
			if ( 404 === $status || self::CODE_NOT_FOUND === $code ) {
				return array( 'action' => self::ACTION_RECREATE );
			}
			if ( self::is_retryable( $status, $code ) ) {
				return array( 'action' => self::ACTION_RETRY_SAME );
			}
			if ( $status < 200 || $status >= 300 ) {
				return array( 'action' => self::ACTION_KEEP );
			}
			$bytes = isset( $response['body'] ) ? (string) $response['body'] : '';
			if ( '' === $bytes ) {
				return array( 'action' => self::ACTION_KEEP );
			}
			return array(
				'action' => self::ACTION_OK,
				'bytes'  => $bytes,
			);
		}

		/**
		 * @param array $response Transport response.
		 * @return array{id:string,file_type:string}|null
		 */
		public static function parse_create_envelope( array $response ) {
			$decoded = json_decode( isset( $response['body'] ) ? (string) $response['body'] : '', true );
			if ( ! is_array( $decoded ) || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
				return null;
			}
			$data = $decoded['data'];
			if ( empty( $data['id'] ) || ! is_string( $data['id'] ) ) {
				return null;
			}
			$status = isset( $data['status'] ) ? (string) $data['status'] : self::STATUS_COMPLETED;
			if ( self::STATUS_COMPLETED !== $status ) {
				return null;
			}
			return array(
				'id'        => $data['id'],
				'file_type' => isset( $data['file_type'] ) ? (string) $data['file_type'] : '',
			);
		}

		/**
		 * @param array $response Transport response.
		 * @return string
		 */
		public static function problem_code( array $response ) {
			$decoded = json_decode( isset( $response['body'] ) ? (string) $response['body'] : '', true );
			if ( is_array( $decoded ) && isset( $decoded['code'] ) && is_string( $decoded['code'] ) ) {
				return $decoded['code'];
			}
			return '';
		}

		/**
		 * @param int    $status HTTP status.
		 * @param string $code   Problem code.
		 * @return bool
		 */
		public static function is_retryable( $status, $code ) {
			return $status >= 500 || self::CODE_INTERNAL_ERROR === $code;
		}

		/**
		 * @param array $response Transport response.
		 * @return int
		 */
		private static function status( array $response ) {
			return isset( $response['status'] ) ? (int) $response['status'] : 0;
		}
	}
}
