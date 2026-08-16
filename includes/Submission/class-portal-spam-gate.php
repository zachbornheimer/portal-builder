<?php
/**
 * Public-anyone spam gate (Cloudflare Turnstile).
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Spam_Gate' ) ) {

	/**
	 * Anyone-audience submits need a valid token. Logged-in / members skip.
	 */
	class Portal_Spam_Gate {

		const CODE          = 'dg_submission_captcha';
		const TOKEN_FIELD   = 'cf-turnstile-response';
		const OPTION_SITE   = 'pb_turnstile_site_key';
		const OPTION_SECRET = 'pb_turnstile_secret';
		const VERIFY_URL    = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
		const MSG_MISSING   = 'Please complete the spam check and try again.';
		const MSG_INVALID   = 'The spam check failed. Refresh the page and try again.';

		/**
		 * @param string $audience Access audience.
		 * @return bool
		 */
		public static function required_for_audience( $audience ) {
			return (string) $audience === 'anyone' || '' === (string) $audience;
		}

		/**
		 * @param array         $definition Validated definition.
		 * @param array         $values     Posted values.
		 * @param callable|null $verifier   (token, secret) => bool.
		 * @return WP_Error|null
		 */
		public static function admit( array $definition, array $values, $verifier = null ) {
			$access   = isset( $definition['access'] ) && is_array( $definition['access'] )
				? $definition['access']
				: array();
			$audience = isset( $access['audience'] ) ? (string) $access['audience'] : 'anyone';
			if ( ! self::required_for_audience( $audience ) ) {
				return null;
			}
			$secret = self::secret();
			$site   = self::site_key();
			if ( '' === $secret && '' === $site ) {
				return null;
			}
			$token = self::posted_token( $values );
			if ( '' === $token ) {
				return new WP_Error( self::CODE, self::MSG_MISSING );
			}
			if ( '' === $secret ) {
				return new WP_Error( self::CODE, self::MSG_INVALID );
			}
			$ok = is_callable( $verifier )
				? (bool) call_user_func( $verifier, $token, $secret )
				: self::verify_live( $token, $secret );
			if ( ! $ok ) {
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
			if ( ! function_exists( 'get_option' ) ) {
				return '';
			}
			$key = get_option( self::OPTION_SITE, '' );
			return is_string( $key ) ? trim( $key ) : '';
		}

		/**
		 * @return string
		 */
		public static function secret() {
			if ( ! function_exists( 'get_option' ) ) {
				return '';
			}
			$key = get_option( self::OPTION_SECRET, '' );
			return is_string( $key ) ? trim( $key ) : '';
		}

		/**
		 * @param string $token  Widget token.
		 * @param string $secret Site secret.
		 * @return bool
		 */
		public static function verify_live( $token, $secret ) {
			if ( ! function_exists( 'wp_remote_post' ) ) {
				return false;
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
				return false;
			}
			$body = function_exists( 'wp_remote_retrieve_body' )
				? wp_remote_retrieve_body( $response )
				: '';
			$data = json_decode( (string) $body, true );
			return is_array( $data ) && ! empty( $data['success'] );
		}
	}
}
