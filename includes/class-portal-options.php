<?php
/**
 * Plugin-owned WP options: dg_* is canonical; pb_* remains a live-compat fallback.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Options' ) ) {

	/**
	 * Dual-read / move-then-write for DragonGate option keys.
	 *
	 * Promote moves pb_* onto dg_* and deletes the legacy key. A stored
	 * false / 0 / '' on dg_* is already set and is not overwritten. Get
	 * still dual-reads so a request that races promote works. Set writes
	 * dg_*; it also writes pb_* only when that leftover key still exists.
	 */
	class Portal_Options {

		const CANONICAL_PREFIX = 'dg_';
		const LEGACY_PREFIX    = 'pb_';
		const PROMOTE_MOVED    = 'moved';
		const PROMOTE_DROPPED  = 'dropped';

		/**
		 * Bare names after the prefix. Settings, site-defaults, mailer, Google, update, spam-gate.
		 */
		const KNOWN_KEYS = array(
			'google_secret_key',
			'google_access_key',
			'turnstile_site_key',
			'turnstile_secret',
			'county_region_script',
			'legal_disclaimers',
			'recaptcha_sitekey',
			'exiftool_path',
			'qpdf_path',
			'eyed3_path',
			'lame_path',
			'perl_path',
			'receipt_generator',
			'receipt_from_email',
			'receipt_from_name',
			'receipt_subject',
			'receipt_body',
			'receipt_alt_body',
			'operator_notify_email',
			'default_anonymize',
			'default_anonymize_fail_closed',
			'default_anonymize_endpoint',
			'default_anonymize_api_key',
			'default_anonymize_ack',
			'default_guidelines_url',
			'default_guidelines_link_label',
			'login_url',
			'join_url',
			'default_free_for_members',
			'default_timezone',
			'default_brand',
			'github_token',
		);

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
		 * Move each stored pb_* onto dg_* once. Empty / false / 0 on dg_* is already set.
		 *
		 * @return array{moved: int, dropped: int} Keys that still had a pb_* twin.
		 */
		public static function promote() {
			$empty = array(
				self::PROMOTE_MOVED   => 0,
				self::PROMOTE_DROPPED => 0,
			);
			if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) || ! function_exists( 'delete_option' ) ) {
				return $empty;
			}
			$sentinel = new stdClass();
			$moved    = 0;
			$dropped  = 0;
			foreach ( self::KNOWN_KEYS as $bare ) {
				$outcome = self::promote_key( $bare, $sentinel );
				if ( self::PROMOTE_MOVED === $outcome ) {
					++$moved;
				} elseif ( self::PROMOTE_DROPPED === $outcome ) {
					++$dropped;
				}
			}
			return array(
				self::PROMOTE_MOVED   => $moved,
				self::PROMOTE_DROPPED => $dropped,
			);
		}

		/**
		 * Move or drop one legacy twin.
		 *
		 * @param string   $bare     Name after the prefix.
		 * @param stdClass $sentinel Unset marker — same object as get().
		 * @return string self::PROMOTE_MOVED | self::PROMOTE_DROPPED | ''
		 */
		private static function promote_key( $bare, $sentinel ) {
			$dg        = self::CANONICAL_PREFIX . $bare;
			$pb        = self::LEGACY_PREFIX . $bare;
			$dg_stored = get_option( $dg, $sentinel );
			$pb_stored = get_option( $pb, $sentinel );
			$dg_set    = $dg_stored !== $sentinel;
			$pb_set    = $pb_stored !== $sentinel;
			if ( ! $dg_set && $pb_set ) {
				update_option( $dg, $pb_stored );
				delete_option( $pb );
				return self::PROMOTE_MOVED;
			}
			if ( $dg_set && $pb_set ) {
				delete_option( $pb );
				return self::PROMOTE_DROPPED;
			}
			return '';
		}

		/**
		 * Legacy pb_* twin of a dg_* key. Unchanged when the name is not dg_*.
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
