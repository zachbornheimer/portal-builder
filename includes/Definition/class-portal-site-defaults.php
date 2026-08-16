<?php
/**
 * Site-wide portal defaults: portal override, else site option, else built-in.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Site_Defaults' ) ) {

	/**
	 * Resolves effective publish/anonymize options. Pure merge; WP lives in read_site().
	 */
	class Portal_Site_Defaults {

		const OPTION_ANONYMIZE             = 'pb_default_anonymize';
		const OPTION_ANONYMIZE_ENDPOINT    = 'pb_default_anonymize_endpoint';
		const OPTION_ANONYMIZE_API_KEY     = 'pb_default_anonymize_api_key';
		const OPTION_ANONYMIZE_ACK         = 'pb_default_anonymize_ack';
		const OPTION_ANONYMIZE_FAIL_CLOSED = 'pb_default_anonymize_fail_closed';
		const OPTION_GUIDELINES_URL        = 'pb_default_guidelines_url';
		const OPTION_GUIDELINES_LINK_LABEL = 'pb_default_guidelines_link_label';
		const OPTION_FREE_FOR_MEMBERS      = 'pb_default_free_for_members';
		const OPTION_TIMEZONE              = 'pb_default_timezone';
		const OPTION_BRAND                 = 'pb_default_brand';
		const OPTION_LOGIN_URL             = 'pb_login_url';
		const OPTION_JOIN_URL              = 'pb_join_url';

		const BUILTIN_ANONYMIZE_ENDPOINT    = 'https://api.allintersections.com';
		const BUILTIN_ANONYMIZE_ACK         = 'I certify that my scores and recordings exclude any information that might identify the composer but do include title of work, instrumentation, and duration.';
		const BUILTIN_TIMEZONE              = 'America/New_York';
		const BUILTIN_GUIDELINES_LINK_LABEL = 'Link to Guidelines';
		const BUILTIN_LOGIN_PATH            = '/login';
		const BUILTIN_JOIN_PATH             = '/membership';

		/**
		 * Effective options: portal if set, else site, else built-in.
		 *
		 * Bools: null/missing on the portal inherits. true/false overrides.
		 * Strings: empty/null inherits.
		 *
		 * @param array $definition Definition document (options + publish).
		 * @param array $site       Site bag with the same keys as the return value.
		 * @return array{anonymize:bool,anonymizeFailClosed:bool,anonymizeEndpoint:string,anonymizeApiKey:string,anonymizeAck:string,guidelinesUrl:?string,guidelinesLinkLabel:string,freeForMembers:bool,timezone:string,brand:?array}
		 */
		public static function resolve( array $definition, array $site = array() ) {
			$options = isset( $definition['options'] ) && is_array( $definition['options'] )
				? $definition['options']
				: array();
			$publish = isset( $definition['publish'] ) && is_array( $definition['publish'] )
				? $definition['publish']
				: array();

			return array(
				'anonymize'           => self::resolve_bool( $options, 'anonymize', $site, false ),
				'anonymizeFailClosed' => self::resolve_bool( $options, 'anonymizeFailClosed', $site, false ),
				'anonymizeEndpoint'   => self::resolve_string(
					$options,
					'anonymizeEndpoint',
					$site,
					self::BUILTIN_ANONYMIZE_ENDPOINT
				),
				'anonymizeApiKey'     => self::resolve_string( $options, 'anonymizeApiKey', $site, '' ),
				'anonymizeAck'        => self::resolve_string(
					$options,
					'anonymizeAck',
					$site,
					self::BUILTIN_ANONYMIZE_ACK
				),
				'guidelinesUrl'       => self::resolve_string( $options, 'guidelinesUrl', $site, null ),
				'guidelinesLinkLabel' => self::resolve_string(
					$options,
					'guidelinesLinkLabel',
					$site,
					self::BUILTIN_GUIDELINES_LINK_LABEL
				),
				'freeForMembers'      => self::resolve_bool( $options, 'freeForMembers', $site, false ),
				'timezone'            => self::resolve_string( $publish, 'timezone', $site, self::BUILTIN_TIMEZONE ),
				'brand'               => self::resolve_brand( $options, $site ),
			);
		}

		/**
		 * WP options as a site bag. Not used by unit tests.
		 *
		 * @return array
		 */
		public static function read_site() {
			if ( ! function_exists( 'get_option' ) ) {
				return array();
			}
			return array(
				'anonymize'           => ! empty( get_option( self::OPTION_ANONYMIZE, false ) ),
				'anonymizeFailClosed' => ! empty( get_option( self::OPTION_ANONYMIZE_FAIL_CLOSED, false ) ),
				'anonymizeEndpoint'   => self::trim_or_null( get_option( self::OPTION_ANONYMIZE_ENDPOINT, '' ) ),
				'anonymizeApiKey'     => self::trim_or_null( get_option( self::OPTION_ANONYMIZE_API_KEY, '' ) ),
				'anonymizeAck'        => self::trim_or_null( get_option( self::OPTION_ANONYMIZE_ACK, '' ) ),
				'guidelinesUrl'       => self::trim_or_null( get_option( self::OPTION_GUIDELINES_URL, '' ) ),
				'guidelinesLinkLabel' => self::trim_or_null( get_option( self::OPTION_GUIDELINES_LINK_LABEL, '' ) ),
				'freeForMembers'      => ! empty( get_option( self::OPTION_FREE_FOR_MEMBERS, false ) ),
				'timezone'            => self::trim_or_null( get_option( self::OPTION_TIMEZONE, '' ) ),
				'brand'               => self::read_brand_option(),
			);
		}

		/**
		 * Resolve using live WP options when present.
		 *
		 * @param array $definition Definition document.
		 * @return array
		 */
		public static function resolve_for_site( array $definition ) {
			return self::resolve( $definition, self::read_site() );
		}

		/**
		 * Public sign-in URL. Never wp-login.php. Optional redirect_to.
		 *
		 * @param string $redirect Absolute or site-relative return URL.
		 * @return string
		 */
		public static function login_url( $redirect = '' ) {
			$url = self::absolute_site_url( self::stored_or_builtin( self::OPTION_LOGIN_URL, self::BUILTIN_LOGIN_PATH ) );
			$redirect = trim( (string) $redirect );
			if ( '' === $redirect || '' === $url ) {
				return $url;
			}
			$sep = false === strpos( $url, '?' ) ? '?' : '&';
			return $url . $sep . 'redirect_to=' . rawurlencode( $redirect );
		}

		/**
		 * Public join / upgrade URL.
		 *
		 * @return string
		 */
		public static function join_url() {
			return self::absolute_site_url( self::stored_or_builtin( self::OPTION_JOIN_URL, self::BUILTIN_JOIN_PATH ) );
		}

		/**
		 * @param string $option   Option name.
		 * @param string $fallback Built-in path.
		 * @return string
		 */
		private static function stored_or_builtin( $option, $fallback ) {
			if ( function_exists( 'get_option' ) ) {
				$stored = self::trim_or_null( get_option( $option, '' ) );
				if ( null !== $stored ) {
					return $stored;
				}
			}
			return $fallback;
		}

		/**
		 * @param string $path_or_url Path (/login) or absolute URL.
		 * @return string
		 */
		private static function absolute_site_url( $path_or_url ) {
			$path_or_url = trim( (string) $path_or_url );
			if ( '' === $path_or_url ) {
				return '';
			}
			if ( 0 === strpos( $path_or_url, 'http://' ) || 0 === strpos( $path_or_url, 'https://' ) ) {
				return $path_or_url;
			}
			if ( '/' !== $path_or_url[0] ) {
				$path_or_url = '/' . $path_or_url;
			}
			if ( function_exists( 'home_url' ) ) {
				return home_url( $path_or_url );
			}
			return $path_or_url;
		}

		/**
		 * Admin wizard payload. Never includes the full API key.
		 *
		 * @return array
		 */
		public static function for_wizard() {
			$site = self::read_site();
			$key  = isset( $site['anonymizeApiKey'] ) ? $site['anonymizeApiKey'] : null;
			return array(
				'anonymize'           => ! empty( $site['anonymize'] ),
				'anonymizeFailClosed' => ! empty( $site['anonymizeFailClosed'] ),
				'anonymizeEndpoint'   => self::non_empty_string( $site, 'anonymizeEndpoint', self::BUILTIN_ANONYMIZE_ENDPOINT ),
				'anonymizeApiKeySet'  => is_string( $key ) && '' !== $key,
				'anonymizeApiKeyHint' => self::key_hint( $key ),
				'anonymizeAck'        => self::non_empty_string( $site, 'anonymizeAck', self::BUILTIN_ANONYMIZE_ACK ),
				'guidelinesUrl'       => isset( $site['guidelinesUrl'] ) ? $site['guidelinesUrl'] : null,
				'guidelinesLinkLabel' => isset( $site['guidelinesLinkLabel'] ) ? $site['guidelinesLinkLabel'] : null,
				'freeForMembers'      => ! empty( $site['freeForMembers'] ),
				'timezone'            => self::non_empty_string( $site, 'timezone', self::BUILTIN_TIMEZONE ),
			);
		}

		/**
		 * Last-four hint for a stored secret. Empty when nothing is saved.
		 *
		 * @param mixed $secret Stored key.
		 * @return string
		 */
		public static function key_hint( $secret ) {
			if ( ! is_string( $secret ) ) {
				return '';
			}
			$trimmed = trim( $secret );
			if ( '' === $trimmed ) {
				return '';
			}
			$tail = strlen( $trimmed ) >= 4 ? substr( $trimmed, -4 ) : $trimmed;
			return '••••' . $tail;
		}

		/**
		 * Empty/null inherits. A stored bag (including product) is a value.
		 *
		 * @param array $portal Portal options.
		 * @param array $site   Site bag.
		 * @return array|null
		 */
		private static function resolve_brand( array $portal, array $site ) {
			$from_portal = self::brand_or_null( array_key_exists( 'brand', $portal ) ? $portal['brand'] : null );
			if ( null !== $from_portal ) {
				return $from_portal;
			}
			return self::brand_or_null( array_key_exists( 'brand', $site ) ? $site['brand'] : null );
		}

		/**
		 * @param mixed $value Raw brand.
		 * @return array|null
		 */
		private static function brand_or_null( $value ) {
			return is_array( $value ) && array() !== $value ? $value : null;
		}

		/**
		 * @return array|null
		 */
		private static function read_brand_option() {
			$raw = get_option( self::OPTION_BRAND, null );
			return self::brand_or_null( $raw );
		}

		/**
		 * @param array  $portal Portal options or publish.
		 * @param string $key    Field name.
		 * @param array  $site   Site bag.
		 * @param bool   $fallback Built-in.
		 * @return bool
		 */
		private static function resolve_bool( array $portal, $key, array $site, $fallback ) {
			if ( array_key_exists( $key, $portal ) && is_bool( $portal[ $key ] ) ) {
				return $portal[ $key ];
			}
			if ( array_key_exists( $key, $site ) && is_bool( $site[ $key ] ) ) {
				return $site[ $key ];
			}
			return (bool) $fallback;
		}

		/**
		 * @param array       $portal   Portal options or publish.
		 * @param string      $key      Field name.
		 * @param array       $site     Site bag.
		 * @param string|null $fallback Built-in.
		 * @return string|null
		 */
		private static function resolve_string( array $portal, $key, array $site, $fallback ) {
			$from_portal = self::trim_or_null( array_key_exists( $key, $portal ) ? $portal[ $key ] : null );
			if ( null !== $from_portal ) {
				return $from_portal;
			}
			$from_site = self::trim_or_null( array_key_exists( $key, $site ) ? $site[ $key ] : null );
			if ( null !== $from_site ) {
				return $from_site;
			}
			return $fallback;
		}

		/**
		 * @param mixed $value Raw.
		 * @return string|null
		 */
		private static function trim_or_null( $value ) {
			if ( ! is_string( $value ) ) {
				return null;
			}
			$trimmed = trim( $value );
			return '' === $trimmed ? null : $trimmed;
		}

		/**
		 * @param array  $bag      Source.
		 * @param string $key      Key.
		 * @param string $fallback Built-in.
		 * @return string
		 */
		private static function non_empty_string( array $bag, $key, $fallback ) {
			$value = self::trim_or_null( array_key_exists( $key, $bag ) ? $bag[ $key ] : null );
			return null !== $value ? $value : $fallback;
		}
	}
}
