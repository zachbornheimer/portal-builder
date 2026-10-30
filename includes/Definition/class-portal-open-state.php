<?php
/**
 * Portal open / closed / preview gate.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Open_State' ) ) {

	/**
	 * Derives is_open from publish config + post status, and detects editor preview.
	 *
	 * is_open = published ∧ ¬forceClosed ∧ (enabled is not false)
	 *           ∧ (no launchAt ∨ now ≥ launchAt) ∧ (no deadline ∨ now ≤ deadline)
	 */
	class Portal_Open_State {

		const DEFAULT_TIMEZONE = 'America/New_York';

		const PREVIEW_TRUE   = 'true';
		const PREVIEW_OPEN   = 'open';
		const PREVIEW_CLOSED = 'closed';

		/**
		 * Pure open check from already-resolved publish config.
		 *
		 * @param array                  $publish     Keys: deadline, timezone, forceClosed, enabled, launchAt.
		 * @param string                 $post_status WP post status (e.g. publish).
		 * @param DateTimeInterface|null $now         Injectable clock; null = current time.
		 * @return bool
		 */
		public static function is_open_from_publish( array $publish, $post_status, $now = null ) {
			if ( 'publish' !== $post_status ) {
				return false;
			}
			if ( ! empty( $publish['forceClosed'] ) ) {
				return false;
			}
			if ( array_key_exists( 'enabled', $publish ) && empty( $publish['enabled'] ) ) {
				return false;
			}

			$timezone = self::publish_timezone( $publish );
			$now_dt   = self::clock_in_timezone( $now, $timezone );

			$launch_raw = array_key_exists( 'launchAt', $publish ) ? $publish['launchAt'] : null;
			$launch_dt  = self::parse_in_timezone( $launch_raw, $timezone );
			if ( $launch_dt && $now_dt < $launch_dt ) {
				return false;
			}

			$deadline    = array_key_exists( 'deadline', $publish ) ? $publish['deadline'] : null;
			$deadline_dt = self::parse_in_timezone( $deadline, $timezone );
			if ( ! $deadline_dt ) {
				return true;
			}

			return $now_dt <= $deadline_dt;
		}

		/**
		 * Dual-write enabled / forceClosed. enabled wins when both are present.
		 *
		 * @param array $publish Raw publish block.
		 * @return array{enabled: bool, forceClosed: bool}
		 */
		public static function dual_write_enabled( array $publish ) {
			$enabled = array_key_exists( 'enabled', $publish )
				? ! empty( $publish['enabled'] )
				: empty( $publish['forceClosed'] );
			return array(
				'enabled'     => $enabled,
				'forceClosed' => ! $enabled,
			);
		}

		/**
		 * Resolve publish config: definition publish block when valid, else legacy meta.
		 *
		 * @param int $post_id Portal post ID.
		 * @return array{deadline: mixed, timezone: string, forceClosed: bool, enabled: bool, launchAt: mixed, source: string}
		 */
		public static function resolve_publish_config( $post_id ) {
			$post_id    = (int) $post_id;
			$definition = class_exists( 'Portal_Definition' )
				? Portal_Definition::load_for_post( $post_id )
				: null;

			if ( is_array( $definition ) && isset( $definition['publish'] ) && is_array( $definition['publish'] ) ) {
				$publish = $definition['publish'];
				$flags   = self::dual_write_enabled( $publish );
				return array(
					'deadline'    => array_key_exists( 'deadline', $publish ) ? $publish['deadline'] : null,
					'timezone'    => isset( $publish['timezone'] ) ? (string) $publish['timezone'] : self::DEFAULT_TIMEZONE,
					'forceClosed' => $flags['forceClosed'],
					'enabled'     => $flags['enabled'],
					'launchAt'    => array_key_exists( 'launchAt', $publish ) ? $publish['launchAt'] : null,
					'source'      => 'definition',
				);
			}

			return self::legacy_publish_config( $post_id );
		}

		/**
		 * Legacy `_portal_deadline` + timezone index meta.
		 *
		 * @param int $post_id Portal post ID.
		 * @return array{deadline: mixed, timezone: string, forceClosed: bool, enabled: bool, launchAt: mixed, source: string}
		 */
		public static function legacy_publish_config( $post_id ) {
			$deadline        = get_post_meta( $post_id, '_portal_deadline', true );
			$timezone_index  = get_post_meta( $post_id, '_portal_timezone', true );
			$timezones       = timezone_identifiers_list();
			$timezone_string = isset( $timezones[ $timezone_index ] )
				? $timezones[ $timezone_index ]
				: self::DEFAULT_TIMEZONE;

			return array(
				'deadline'    => ( '' === $deadline || false === $deadline ) ? null : $deadline,
				'timezone'    => $timezone_string,
				'forceClosed' => false,
				'enabled'     => true,
				'launchAt'    => null,
				'source'      => 'legacy',
			);
		}

		/**
		 * Whether the portal is currently open for public submissions.
		 *
		 * @param int                    $post_id Portal post ID.
		 * @param DateTimeInterface|null $now     Injectable clock.
		 * @return bool
		 */
		public static function is_open( $post_id, $now = null ) {
			$post = get_post( (int) $post_id );
			if ( ! $post || 'portal' !== $post->post_type ) {
				return false;
			}
			$publish = self::resolve_publish_config( $post_id );
			return self::is_open_from_publish( $publish, $post->post_status, $now );
		}

		/**
		 * Editor preview mode for this request: 'true' | 'open' | 'closed' | null.
		 *
		 * ?preview=true|1 keeps its historical meaning (render the form, refuse
		 * submission). ?preview=open forces the open rendering; ?preview=closed
		 * forces the closed rendering. Every variant is capability-gated.
		 *
		 * @param int $post_id Portal post ID.
		 * @return string|null
		 */
		public static function preview_mode( $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
				return null;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public preview flag, gated by capability.
			if ( isset( $_GET['preview'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$flag = strtolower( trim( (string) wp_unslash( $_GET['preview'] ) ) );
				if ( 'true' === $flag || '1' === $flag ) {
					return self::PREVIEW_TRUE;
				}
				if ( self::PREVIEW_OPEN === $flag ) {
					return self::PREVIEW_OPEN;
				}
				if ( self::PREVIEW_CLOSED === $flag ) {
					return self::PREVIEW_CLOSED;
				}
			}

			// WP's is_preview() is truthy for any non-empty preview value, so it
			// only backs values without explicit meaning above.
			if ( function_exists( 'is_preview' ) && is_preview() ) {
				return self::PREVIEW_TRUE;
			}
			return null;
		}

		/**
		 * Any editor preview rendering. Preview never accepts submissions.
		 *
		 * @param int $post_id Portal post ID.
		 * @return bool
		 */
		public static function is_preview_request( $post_id ) {
			return null !== self::preview_mode( $post_id );
		}

		/**
		 * A valid, unexpired, unused invitation admits a specific person to
		 * submit past the closed gate. No-op until Portal_Invites lands.
		 *
		 * @param int $post_id Portal post ID.
		 * @return bool
		 */
		public static function invite_admission( $post_id ) {
			return class_exists( 'Portal_Invites' )
				&& Portal_Invites::grants_admission( (int) $post_id );
		}

		/**
		 * The invite behind ?dg_invite=... on this request, or null.
		 *
		 * @param int $post_id Portal post ID.
		 * @return array|null
		 */
		public static function invite_for_request( $post_id ) {
			if ( ! class_exists( 'Portal_Invites' ) ) {
				return null;
			}
			$invite = Portal_Invites::for_current_request( (int) $post_id );
			return is_array( $invite ) ? $invite : null;
		}

		/**
		 * Whether the request carries a dg_invite token at all (used to explain
		 * an unusable token instead of failing silently).
		 *
		 * @return bool
		 */
		public static function invite_token_present() {
			if ( ! class_exists( 'Portal_Invites' ) ) {
				return false;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag.
			if ( ! isset( $_GET[ Portal_Invites::QUERY_KEY ] ) ) {
				return false;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = wp_unslash( $_GET[ Portal_Invites::QUERY_KEY ] );
			return '' !== trim( (string) $raw );
		}

		/**
		 * Publish instant for public copy: the definition's publish block is the
		 * source of truth; the legacy deadline meta backstops a definition that
		 * leaves deadline unset (this codebase's established fallback shape).
		 *
		 * @param int    $post_id Portal post ID.
		 * @param string $which   'open' (launchAt) or 'close' (deadline).
		 * @return array{value: string|null, timezone: string}
		 */
		public static function publish_instant( $post_id, $which ) {
			$post_id = (int) $post_id;
			$publish = self::resolve_publish_config( $post_id );
			$key     = 'close' === $which ? 'deadline' : 'launchAt';
			$value   = array_key_exists( $key, $publish ) ? $publish[ $key ] : null;

			if ( ( null === $value || '' === $value ) && 'deadline' === $key && 'definition' === $publish['source'] ) {
				$legacy = get_post_meta( $post_id, '_portal_deadline', true );
				if ( is_string( $legacy ) && '' !== $legacy ) {
					$value = $legacy;
				}
			}

			return array(
				'value'    => ( null === $value || '' === $value ) ? null : (string) $value,
				'timezone' => (string) $publish['timezone'],
			);
		}

		/**
		 * Form UI renders when open, when an editor previews the open form
		 * (?preview=true|open), or when a valid invitation admits this visitor.
		 *
		 * @param int                    $post_id Portal post ID.
		 * @param DateTimeInterface|null $now     Injectable clock.
		 * @return bool
		 */
		public static function should_show_form( $post_id, $now = null ) {
			if ( self::is_open( $post_id, $now ) ) {
				return true;
			}
			$mode = self::preview_mode( $post_id );
			if ( self::PREVIEW_TRUE === $mode || self::PREVIEW_OPEN === $mode ) {
				return true;
			}
			return self::invite_admission( $post_id );
		}

		/**
		 * Closed reason for messaging (deadline | force | status).
		 *
		 * @param int                    $post_id Portal post ID.
		 * @param DateTimeInterface|null $now     Injectable clock.
		 * @return string|null Null when open.
		 */
		public static function closed_reason( $post_id, $now = null ) {
			if ( self::is_open( $post_id, $now ) ) {
				return null;
			}
			$post = get_post( (int) $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				return 'status';
			}
			$publish = self::resolve_publish_config( $post_id );
			if ( ! empty( $publish['forceClosed'] ) || ( array_key_exists( 'enabled', $publish ) && empty( $publish['enabled'] ) ) ) {
				return 'force';
			}
			$timezone = self::publish_timezone( $publish );
			$now_dt   = self::clock_in_timezone( $now, $timezone );
			$launch   = self::parse_in_timezone(
				array_key_exists( 'launchAt', $publish ) ? $publish['launchAt'] : null,
				$timezone
			);
			if ( $launch && $now_dt < $launch ) {
				return 'launch';
			}
			return 'deadline';
		}

		/**
		 * @param array $publish Publish block.
		 * @return DateTimeZone
		 */
		private static function publish_timezone( array $publish ) {
			$name = isset( $publish['timezone'] ) && is_string( $publish['timezone'] ) && '' !== $publish['timezone']
				? $publish['timezone']
				: self::DEFAULT_TIMEZONE;
			try {
				return new DateTimeZone( $name );
			} catch ( Exception $e ) {
				return new DateTimeZone( self::DEFAULT_TIMEZONE );
			}
		}

		/**
		 * @param DateTimeInterface|null $now       Injectable clock.
		 * @param DateTimeZone           $timezone  Portal timezone.
		 * @return DateTime
		 */
		private static function clock_in_timezone( $now, DateTimeZone $timezone ) {
			if ( $now instanceof DateTimeImmutable ) {
				return DateTime::createFromImmutable( $now )->setTimezone( $timezone );
			}
			if ( $now instanceof DateTime ) {
				$clone = clone $now;
				$clone->setTimezone( $timezone );
				return $clone;
			}
			return new DateTime( 'now', $timezone );
		}

		/**
		 * @param mixed        $value    Datetime string or empty.
		 * @param DateTimeZone $timezone Portal timezone.
		 * @return DateTime|null
		 */
		private static function parse_in_timezone( $value, DateTimeZone $timezone ) {
			if ( null === $value || '' === $value ) {
				return null;
			}
			try {
				return new DateTime( (string) $value, $timezone );
			} catch ( Exception $e ) {
				return null;
			}
		}
	}
}
