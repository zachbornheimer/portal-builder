<?php
/**
 * Plugin-owned WP options: dg_* is canonical; pb_* remains a live-compat fallback.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Options' ) ) {

	/**
	 * Dual-read / compat-write for DragonGate option keys.
	 *
	 * A stored false / 0 / '' on dg_* wins. Dual-write refreshes an existing
	 * pb_* key so a 0.1.2 rollback keeps a fresh Google token.
	 */
	class Portal_Options {

		const CANONICAL_PREFIX = 'dg_';
		const LEGACY_PREFIX    = 'pb_';

		/**
		 * Read dg_* if stored (including empty), else the matching pb_* key.
		 *
		 * @param string $dg_key  Canonical option name (dg_*).
		 * @param mixed  $default When neither key is stored.
		 * @return mixed
		 */
		public static function get( $dg_key, $default = false ) {
			if ( ! function_exists( 'get_option' ) ) {
				return $default;
			}
			$sentinel = new stdClass();
			$stored   = get_option( $dg_key, $sentinel );
			if ( $stored !== $sentinel ) {
				return $stored;
			}
			$legacy = self::legacy_key( $dg_key );
			if ( $legacy !== $dg_key ) {
				return get_option( $legacy, $default );
			}
			return $default;
		}

		/**
		 * Write dg_*. Also write pb_* when that live key already exists.
		 *
		 * @param string $dg_key Canonical option name (dg_*).
		 * @param mixed  $value  Stored value.
		 * @return bool
		 */
		public static function set( $dg_key, $value ) {
			if ( ! function_exists( 'update_option' ) ) {
				return false;
			}
			$updated = (bool) update_option( $dg_key, $value );
			$legacy  = self::legacy_key( $dg_key );
			if ( $legacy === $dg_key || ! function_exists( 'get_option' ) ) {
				return $updated;
			}
			$sentinel = new stdClass();
			if ( get_option( $legacy, $sentinel ) !== $sentinel ) {
				update_option( $legacy, $value );
			}
			return $updated;
		}

		/**
		 * pb_* twin of a dg_* key. Unchanged when the name is not dg_*.
		 *
		 * @param string $dg_key Canonical option name.
		 * @return string
		 */
		public static function legacy_key( $dg_key ) {
			$dg_key = (string) $dg_key;
			if ( 0 !== strpos( $dg_key, self::CANONICAL_PREFIX ) ) {
				return $dg_key;
			}
			return self::LEGACY_PREFIX . substr( $dg_key, strlen( self::CANONICAL_PREFIX ) );
		}
	}
}
