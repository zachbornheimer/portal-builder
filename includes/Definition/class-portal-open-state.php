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
	 * is_open = published ∧ ¬forceClosed ∧ (no deadline ∨ now ≤ deadline)
	 */
	class Portal_Open_State {

		const DEFAULT_TIMEZONE = 'America/New_York';

		/**
		 * Pure open check from already-resolved publish config.
		 *
		 * @param array                  $publish     Keys: deadline, timezone, forceClosed.
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

			$deadline = array_key_exists( 'deadline', $publish ) ? $publish['deadline'] : null;
			if ( null === $deadline || '' === $deadline ) {
				return true;
			}

			$timezone_name = isset( $publish['timezone'] ) && is_string( $publish['timezone'] ) && '' !== $publish['timezone']
				? $publish['timezone']
				: self::DEFAULT_TIMEZONE;

			try {
				$timezone = new DateTimeZone( $timezone_name );
			} catch ( Exception $e ) {
				$timezone = new DateTimeZone( self::DEFAULT_TIMEZONE );
			}

			try {
				$deadline_dt = new DateTime( (string) $deadline, $timezone );
			} catch ( Exception $e ) {
				// Unparseable deadline: treat as no deadline (stay open).
				return true;
			}

			if ( null === $now ) {
				$now = new DateTime( 'now', $timezone );
			} elseif ( $now instanceof DateTimeImmutable ) {
				$now = DateTime::createFromImmutable( $now )->setTimezone( $timezone );
			} elseif ( $now instanceof DateTime ) {
				$now = clone $now;
				$now->setTimezone( $timezone );
			} else {
				$now = new DateTime( 'now', $timezone );
			}

			// Open when now is at or before the deadline (inclusive).
			return $now <= $deadline_dt;
		}

		/**
		 * Resolve publish config: definition publish block when valid, else legacy meta.
		 *
		 * @param int $post_id Portal post ID.
		 * @return array{deadline: mixed, timezone: string, forceClosed: bool, source: string}
		 */
		public static function resolve_publish_config( $post_id ) {
			$post_id = (int) $post_id;
			$definition = class_exists( 'Portal_Definition' )
				? Portal_Definition::load_for_post( $post_id )
				: null;

			if ( is_array( $definition ) && isset( $definition['publish'] ) && is_array( $definition['publish'] ) ) {
				$publish = $definition['publish'];
				return array(
					'deadline'    => array_key_exists( 'deadline', $publish ) ? $publish['deadline'] : null,
					'timezone'    => isset( $publish['timezone'] ) ? (string) $publish['timezone'] : self::DEFAULT_TIMEZONE,
					'forceClosed' => ! empty( $publish['forceClosed'] ),
					'source'      => 'definition',
				);
			}

			return self::legacy_publish_config( $post_id );
		}

		/**
		 * Legacy `_portal_deadline` + timezone index meta.
		 *
		 * @param int $post_id Portal post ID.
		 * @return array{deadline: mixed, timezone: string, forceClosed: bool, source: string}
		 */
		public static function legacy_publish_config( $post_id ) {
			$deadline       = get_post_meta( $post_id, '_portal_deadline', true );
			$timezone_index = get_post_meta( $post_id, '_portal_timezone', true );
			$timezones      = timezone_identifiers_list();
			$timezone_string = isset( $timezones[ $timezone_index ] )
				? $timezones[ $timezone_index ]
				: self::DEFAULT_TIMEZONE;

			return array(
				'deadline'    => ( '' === $deadline || false === $deadline ) ? null : $deadline,
				'timezone'    => $timezone_string,
				'forceClosed' => false,
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
		 * Editor preview: capability + (?preview=true|1 or WP is_preview()).
		 *
		 * @param int $post_id Portal post ID.
		 * @return bool
		 */
		public static function is_preview_request( $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
				return false;
			}

			if ( function_exists( 'is_preview' ) && is_preview() ) {
				return true;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public preview flag, gated by capability.
			if ( ! isset( $_GET['preview'] ) ) {
				return false;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$flag = strtolower( (string) wp_unslash( $_GET['preview'] ) );
			return 'true' === $flag || '1' === $flag;
		}

		/**
		 * Form UI should render when open OR editor is previewing.
		 *
		 * @param int                    $post_id Portal post ID.
		 * @param DateTimeInterface|null $now     Injectable clock.
		 * @return bool
		 */
		public static function should_show_form( $post_id, $now = null ) {
			return self::is_open( $post_id, $now ) || self::is_preview_request( $post_id );
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
			if ( ! empty( $publish['forceClosed'] ) ) {
				return 'force';
			}
			return 'deadline';
		}
	}
}
