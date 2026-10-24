<?php
/**
 * Public spam gate (Cloudflare Turnstile).
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Options' ) ) {
	require_once dirname( __DIR__ ) . '/class-portal-options.php';
}

if ( ! class_exists( 'Portal_Spam_Reject_Log' ) ) {
	require_once __DIR__ . '/class-portal-spam-reject-log.php';
}

if ( ! class_exists( 'Portal_Spam_Gate' ) ) {

	/**
	 * When both Turnstile keys are set, every public submit needs a valid token.
	 */
	class Portal_Spam_Gate {

		const CODE          = 'dg_submission_captcha';
		const TOKEN_FIELD   = 'cf-turnstile-response';
		const OPTION_SITE   = 'dg_turnstile_site_key';
		const OPTION_SECRET = 'dg_turnstile_secret';
		const VERIFY_URL    = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
		const MSG_MISSING   = 'Please complete the spam check and try again.';
		const MSG_INVALID   = 'The spam check failed. Refresh the page and try again.';

		/** @var Portal_Spam_Reject_Log|null Injectable reject log (tests). */
		private static $reject_log = null;

		/**
		 * Both site key and secret must be present before the gate is enforced.
		 * A site key alone used to reject every submit with MSG_INVALID.
		 *
		 * @return bool
		 */
		public static function keys_complete() {
			return '' !== self::site_key() && '' !== self::secret();
		}

		/**
		 * Exactly one of site/secret is set — admin should finish configuration.
		 *
		 * @return bool
		 */
		public static function keys_incomplete() {
			$site   = self::site_key();
			$secret = self::secret();
			return ( '' !== $site ) xor ( '' !== $secret );
		}

		/**
		 * @param string $audience Access audience (unused when keys are complete).
		 * @return bool
		 */
		public static function required_for_audience( $audience ) {
			unset( $audience );
			return self::keys_complete();
		}

		/**
		 * Override reject log store (unit harness). Pass null to clear.
		 *
		 * @param Portal_Spam_Reject_Log|null $log Log store.
		 * @return void
		 */
		public static function set_reject_log( $log ) {
			self::$reject_log = ( $log instanceof Portal_Spam_Reject_Log ) ? $log : null;
		}

		/**
		 * @param array         $definition Validated definition.
		 * @param array         $values     Posted values.
		 * @param callable|null $verifier   (token, secret) => bool.
		 * @param string|int    $portal_id  Portal id for reject evidence.
		 * @return WP_Error|null
		 */
		public static function admit( array $definition, array $values, $verifier = null, $portal_id = '' ) {
			$access   = isset( $definition['access'] ) && is_array( $definition['access'] )
				? $definition['access']
				: array();
			$audience = isset( $access['audience'] ) ? (string) $access['audience'] : 'anyone';
			if ( ! self::required_for_audience( $audience ) ) {
				return null;
			}
			$secret = self::secret();
			$token  = self::posted_token( $values );
			if ( '' === $token ) {
				self::log_reject(
					$portal_id,
					$audience,
					$values,
					true,
					'skipped',
					array(),
					self::MSG_MISSING
				);
				return new WP_Error( self::CODE, self::MSG_MISSING );
			}
			if ( is_callable( $verifier ) ) {
				$ok = (bool) call_user_func( $verifier, $token, $secret );
				if ( ! $ok ) {
					self::log_reject(
						$portal_id,
						$audience,
						$values,
						false,
						'verifier_false',
						array(),
						self::MSG_INVALID
					);
					return new WP_Error( self::CODE, self::MSG_INVALID );
				}
				return null;
			}
			$detail = self::verify_live_detail( $token, $secret );
			if ( empty( $detail['ok'] ) ) {
				$outcome = isset( $detail['outcome'] ) ? (string) $detail['outcome'] : 'success_false';
				$codes   = isset( $detail['errorCodes'] ) && is_array( $detail['errorCodes'] )
					? $detail['errorCodes']
					: array();
				self::log_reject(
					$portal_id,
					$audience,
					$values,
					false,
					$outcome,
					$codes,
					self::MSG_INVALID
				);
				return new WP_Error( self::CODE, self::MSG_INVALID );
			}
			return null;
		}

		/**
		 * @param array $values Posted values.
		 * @return string
		 */
		public static function posted_token( array $values ) {
			if ( isset( $values[ self::TOKEN_FIELD ] ) && is_string( $values[ self::TOKEN_FIELD ] ) ) {
				return trim( $values[ self::TOKEN_FIELD ] );
			}
			return '';
		}

		/**
		 * @return string
		 */
		public static function site_key() {
			$key = class_exists( 'Portal_Options' )
				? Portal_Options::get( self::OPTION_SITE, '' )
				: '';
			return is_string( $key ) ? trim( $key ) : '';
		}

		/**
		 * @return string
		 */
		public static function secret() {
			$key = class_exists( 'Portal_Options' )
				? Portal_Options::get( self::OPTION_SECRET, '' )
				: '';
			return is_string( $key ) ? trim( $key ) : '';
		}

		/**
		 * Live siteverify with outcome detail for reject logging.
		 *
		 * @param string $token  Widget token.
		 * @param string $secret Site secret.
		 * @return array{ok:bool,outcome:string,errorCodes?:array}
		 */
		public static function verify_live_detail( $token, $secret ) {
			if ( ! function_exists( 'wp_remote_post' ) ) {
				return array(
					'ok'      => false,
					'outcome' => 'http_error',
				);
			}
			$response = wp_remote_post(
				self::VERIFY_URL,
				array(
					'timeout' => 8,
					'body'    => array(
						'secret'   => $secret,
						'response' => $token,
					),
				)
			);
			if ( is_wp_error( $response ) ) {
				$code = method_exists( $response, 'get_error_code' ) ? (string) $response->get_error_code() : '';
				$msg  = method_exists( $response, 'get_error_message' ) ? (string) $response->get_error_message() : '';
				$blob = strtolower( $code . ' ' . $msg );
				$is_timeout = ( false !== strpos( $blob, 'timed out' ) )
					|| ( false !== strpos( $blob, 'timeout' ) )
					|| ( 'http_request_failed' === $code && false !== strpos( $blob, 'curl error 28' ) );
				return array(
					'ok'      => false,
					'outcome' => $is_timeout ? 'timeout' : 'http_error',
				);
			}
			$body = function_exists( 'wp_remote_retrieve_body' )
				? wp_remote_retrieve_body( $response )
				: '';
			$data = json_decode( (string) $body, true );
			if ( ! is_array( $data ) ) {
				return array(
					'ok'      => false,
					'outcome' => 'http_error',
				);
			}
			if ( ! empty( $data['success'] ) ) {
				return array(
					'ok'      => true,
					'outcome' => 'success',
				);
			}
			$codes = array();
			if ( isset( $data['error-codes'] ) && is_array( $data['error-codes'] ) ) {
				foreach ( $data['error-codes'] as $code ) {
					if ( is_string( $code ) || is_int( $code ) ) {
						$codes[] = (string) $code;
					}
				}
			} elseif ( isset( $data['error_codes'] ) && is_array( $data['error_codes'] ) ) {
				foreach ( $data['error_codes'] as $code ) {
					if ( is_string( $code ) || is_int( $code ) ) {
						$codes[] = (string) $code;
					}
				}
			}
			$out = array(
				'ok'      => false,
				'outcome' => 'success_false',
			);
			if ( ! empty( $codes ) ) {
				$out['errorCodes'] = $codes;
			}
			return $out;
		}

		/**
		 * @param string $token  Widget token.
		 * @param string $secret Site secret.
		 * @return bool
		 */
		public static function verify_live( $token, $secret ) {
			$detail = self::verify_live_detail( $token, $secret );
			return ! empty( $detail['ok'] );
		}

		/**
		 * Write reject evidence. Never stores the Turnstile token.
		 *
		 * @param string|int          $portal_id Portal id.
		 * @param string              $audience  Access audience.
		 * @param array<string,mixed> $values    Posted values (email keys only hashed).
		 * @param bool                $token_empty Whether token was empty.
		 * @param string              $outcome   siteverify / verifier outcome.
		 * @param array               $error_codes Cloudflare error-codes when present.
		 * @param string              $reason    MSG_MISSING / MSG_INVALID text (not PII).
		 * @return void
		 */
		private static function log_reject( $portal_id, $audience, array $values, $token_empty, $outcome, array $error_codes, $reason ) {
			if ( '' === (string) $portal_id && function_exists( 'get_the_ID' ) ) {
				$maybe = (int) get_the_ID();
				if ( $maybe > 0 ) {
					$portal_id = (string) $maybe;
				}
			}
			$row = array(
				'time'        => gmdate( 'c' ),
				'portalId'    => (string) $portal_id,
				'audience'    => (string) $audience,
				'wpUserId'    => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				'tokenEmpty'  => (bool) $token_empty,
				'tokenPresent'=> ! $token_empty,
				'outcome'     => (string) $outcome,
				'reason'      => (string) $reason,
			);
			$email = self::email_from_values( $values );
			if ( '' !== $email ) {
				$hash = class_exists( 'Portal_Submit_Log' )
					? Portal_Submit_Log::hash_email( $email )
					: hash( 'sha256', strtolower( trim( $email ) ) );
				if ( '' !== $hash ) {
					$row['emailHash'] = $hash;
				}
			}
			if ( ! empty( $error_codes ) ) {
				$row['errorCodes'] = array_values( $error_codes );
			}
			if ( isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) && '' !== $_SERVER['REMOTE_ADDR'] ) {
				$row['remoteIp'] = $_SERVER['REMOTE_ADDR'];
			}
			if ( isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ) {
				$row['userAgent'] = $_SERVER['HTTP_USER_AGENT'];
			}
			$log = self::$reject_log instanceof Portal_Spam_Reject_Log
				? self::$reject_log
				: ( class_exists( 'Portal_Spam_Reject_Log' ) ? Portal_Spam_Reject_Log::for_uploads() : null );
			if ( $log instanceof Portal_Spam_Reject_Log ) {
				$log->record( $row );
				return;
			}
			$line = function_exists( 'wp_json_encode' ) ? wp_json_encode( $row ) : json_encode( $row );
			if ( function_exists( 'error_log' ) && is_string( $line ) ) {
				error_log( 'dg_spam_reject ' . $line );
			}
		}

		/**
		 * Pull applicant email from common posted keys without inventing storage.
		 *
		 * @param array<string,mixed> $values Posted values.
		 * @return string
		 */
		private static function email_from_values( array $values ) {
			$keys = array( 'sub_email', 'email', 'applicant_email', 'applicantEmail' );
			foreach ( $keys as $key ) {
				if ( isset( $values[ $key ] ) && is_string( $values[ $key ] ) && '' !== trim( $values[ $key ] ) ) {
					return trim( $values[ $key ] );
				}
			}
			foreach ( $values as $key => $val ) {
				if ( ! is_string( $val ) || '' === trim( $val ) ) {
					continue;
				}
				$key_s = is_string( $key ) ? strtolower( $key ) : '';
				if ( false !== strpos( $key_s, 'email' ) && false !== strpos( $val, '@' ) ) {
					return trim( $val );
				}
			}
			return '';
		}
	}
}
