<?php
/**
 * Signed application receipt: persist, mint HMAC URL, load by token.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Receipt' ) ) {

	/**
	 * Receipt records live in option dg_receipt_{appId} (autoload no).
	 */
	class Portal_Receipt {

		const OPTION_PREFIX = 'dg_receipt_';
		const QUERY_FLAG    = 'dg-receipt';
		const QUERY_APP     = 'app';
		const QUERY_TOKEN   = 't';
		const FILTER_SECRET = 'dg_receipt_secret';
		const HMAC_ALGO     = 'sha256';
		const DATE_FORMAT   = 'M d, Y';
		const FALLBACK_PATH = '/';

		/**
		 * In-process store when WP options are absent.
		 *
		 * @var array<string,array>
		 */
		private static $memory = array();

		/**
		 * Test or CLI secret override.
		 *
		 * @var string|null
		 */
		private static $secret_override = null;

		/**
		 * Override the HMAC secret (tests).
		 *
		 * @param string|null $secret Secret, or null to clear.
		 * @return void
		 */
		public static function use_secret( $secret ) {
			self::$secret_override = is_string( $secret ) && '' !== $secret ? $secret : null;
		}

		/**
		 * HMAC secret: filter, wp_salt('auth'), or the CLI fallback.
		 *
		 * @return string
		 */
		public static function secret() {
			if ( is_string( self::$secret_override ) && '' !== self::$secret_override ) {
				return self::$secret_override;
			}
			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( self::FILTER_SECRET, null );
				if ( is_string( $filtered ) && '' !== $filtered ) {
					return $filtered;
				}
			}
			if ( function_exists( 'wp_salt' ) ) {
				return (string) wp_salt( 'auth' );
			}
			return 'dg-receipt-dev-secret';
		}

		/**
		 * HMAC for an application id.
		 *
		 * @param string $app_id Application id.
		 * @return string
		 */
		public static function token( $app_id ) {
			return hash_hmac( self::HMAC_ALGO, (string) $app_id, self::secret() );
		}

		/**
		 * URL with only dg-receipt, app, and HMAC. Never name/email/work_title.
		 *
		 * @param int|string $portal_id Portal post id.
		 * @param string     $app_id    Application id.
		 * @return string
		 */
		public static function url( $portal_id, $app_id ) {
			$args = array(
				self::QUERY_FLAG  => '1',
				self::QUERY_APP   => (string) $app_id,
				self::QUERY_TOKEN => self::token( $app_id ),
			);
			$base = self::portal_base_url( $portal_id );
			if ( function_exists( 'add_query_arg' ) ) {
				return add_query_arg( $args, $base );
			}
			$sep = false === strpos( $base, '?' ) ? '?' : '&';
			return $base . $sep . http_build_query( $args );
		}

		/**
		 * Persist the receipt record (autoload no).
		 *
		 * @param string              $app_id Application id.
		 * @param array<string,mixed> $record Receipt fields.
		 * @return array<string,mixed>
		 */
		public static function store( $app_id, array $record ) {
			$app_id                  = (string) $app_id;
			$record['token']         = self::token( $app_id );
			$record['appId']         = $app_id;
			self::$memory[ $app_id ] = $record;
			if ( function_exists( 'update_option' ) ) {
				update_option( self::OPTION_PREFIX . $app_id, $record, false );
			}
			return $record;
		}

		/**
		 * Return the stored record when the HMAC matches; otherwise null.
		 *
		 * @param string $app_id Application id.
		 * @param string $token  HMAC from the query string.
		 * @return array<string,mixed>|null
		 */
		public static function load( $app_id, $token ) {
			$app_id = (string) $app_id;
			$token  = (string) $token;
			if ( '' === $app_id || '' === $token ) {
				return null;
			}
			$expected = self::token( $app_id );
			if ( ! hash_equals( $expected, $token ) ) {
				return null;
			}
			$record = self::fetch( $app_id );
			return is_array( $record ) ? $record : null;
		}

		/**
		 * Load a stored receipt without verifying the token.
		 *
		 * @param string $app_id Application id.
		 * @return array<string,mixed>|null
		 */
		private static function fetch( $app_id ) {
			if ( function_exists( 'get_option' ) ) {
				$stored = get_option( self::OPTION_PREFIX . $app_id, null );
				if ( is_array( $stored ) ) {
					return $stored;
				}
			}
			return isset( self::$memory[ $app_id ] ) ? self::$memory[ $app_id ] : null;
		}

		/**
		 * Permalink for the portal, or home/fallback when WP is absent.
		 *
		 * @param int|string $portal_id Portal post id.
		 * @return string
		 */
		private static function portal_base_url( $portal_id ) {
			if ( function_exists( 'get_permalink' ) ) {
				$permalink = get_permalink( $portal_id );
				if ( is_string( $permalink ) && '' !== $permalink ) {
					return $permalink;
				}
			}
			if ( function_exists( 'home_url' ) ) {
				return home_url( self::FALLBACK_PATH );
			}
			return self::FALLBACK_PATH;
		}
	}
}
