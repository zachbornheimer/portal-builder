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

		const OPTION_ANONYMIZE          = 'pb_default_anonymize';
		const OPTION_ANONYMIZE_ENDPOINT = 'pb_default_anonymize_endpoint';
		const OPTION_ANONYMIZE_API_KEY  = 'pb_default_anonymize_api_key';
		const OPTION_GUIDELINES_URL     = 'pb_default_guidelines_url';
		const OPTION_FREE_FOR_MEMBERS   = 'pb_default_free_for_members';
		const OPTION_TIMEZONE           = 'pb_default_timezone';

		const BUILTIN_ANONYMIZE_ENDPOINT = 'https://api.allintersections.com';
		const BUILTIN_TIMEZONE           = 'America/New_York';

		/**
		 * Effective options: portal if set, else site, else built-in.
		 *
		 * Bools: null/missing on the portal inherits. true/false overrides.
		 * Strings: empty/null inherits.
		 *
		 * @param array $definition Definition document (options + publish).
		 * @param array $site       Site bag with the same keys as the return value.
		 * @return array{anonymize:bool,anonymizeEndpoint:string,anonymizeApiKey:string,guidelinesUrl:?string,freeForMembers:bool,timezone:string}
		 */
		public static function resolve( array $definition, array $site = array() ) {
			$options = isset( $definition['options'] ) && is_array( $definition['options'] )
				? $definition['options']
				: array();
			$publish = isset( $definition['publish'] ) && is_array( $definition['publish'] )
				? $definition['publish']
				: array();

			return array(
				'anonymize'         => self::resolve_bool( $options, 'anonymize', $site, false ),
				'anonymizeEndpoint' => self::resolve_string(
					$options,
					'anonymizeEndpoint',
					$site,
					self::BUILTIN_ANONYMIZE_ENDPOINT
				),
				'anonymizeApiKey'   => self::resolve_string( $options, 'anonymizeApiKey', $site, '' ),
				'guidelinesUrl'     => self::resolve_string( $options, 'guidelinesUrl', $site, null ),
				'freeForMembers'    => self::resolve_bool( $options, 'freeForMembers', $site, false ),
				'timezone'          => self::resolve_string( $publish, 'timezone', $site, self::BUILTIN_TIMEZONE ),
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
				'anonymize'         => ! empty( get_option( self::OPTION_ANONYMIZE, false ) ),
				'anonymizeEndpoint' => self::trim_or_null( get_option( self::OPTION_ANONYMIZE_ENDPOINT, '' ) ),
				'anonymizeApiKey'   => self::trim_or_null( get_option( self::OPTION_ANONYMIZE_API_KEY, '' ) ),
				'guidelinesUrl'     => self::trim_or_null( get_option( self::OPTION_GUIDELINES_URL, '' ) ),
				'freeForMembers'    => ! empty( get_option( self::OPTION_FREE_FOR_MEMBERS, false ) ),
				'timezone'          => self::trim_or_null( get_option( self::OPTION_TIMEZONE, '' ) ),
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
		 * Admin wizard payload. Never includes the full API key.
		 *
		 * @return array
		 */
		public static function for_wizard() {
			$site = self::read_site();
			$key  = isset( $site['anonymizeApiKey'] ) ? $site['anonymizeApiKey'] : null;
			return array(
				'anonymize'            => ! empty( $site['anonymize'] ),
				'anonymizeEndpoint'    => self::non_empty_string( $site, 'anonymizeEndpoint', self::BUILTIN_ANONYMIZE_ENDPOINT ),
				'anonymizeApiKeySet'   => is_string( $key ) && '' !== $key,
				'anonymizeApiKeyHint'  => self::key_hint( $key ),
				'guidelinesUrl'        => isset( $site['guidelinesUrl'] ) ? $site['guidelinesUrl'] : null,
				'freeForMembers'       => ! empty( $site['freeForMembers'] ),
				'timezone'             => self::non_empty_string( $site, 'timezone', self::BUILTIN_TIMEZONE ),
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
