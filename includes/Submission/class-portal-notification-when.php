<?php
/**
 * Applicant-facing “when you hear back” clause.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Notification_When' ) ) {

	/**
	 * One owner for the phrase after “will be made ”.
	 */
	class Portal_Notification_When {

		const META_DATE   = '_portal_applicant_notification_date';
		const META_KIND   = '_portal_applicant_notification_kind';
		const META_WINDOW = '_portal_applicant_notification_window';

		const KIND_WINDOW = 'window';

		const DATE_FORMAT     = 'l, F j, Y';
		const ON_OR_BEFORE    = 'on or before ';
		const IN_PREFIX       = 'in ';
		const WINDOW_HAS_PREP = '/^(in|by|around|on|before)\b/i';
		const SUCCESS_LEAD    = 'Your application has been submitted successfully! Submissions will be reviewed shortly';
		const SUCCESS_MADE    = ' and official notification of acceptance will be made ';

		/**
		 * Clause after “will be made ”, with no trailing period.
		 *
		 * @param int|string $portal_id Portal post ID.
		 * @return string
		 */
		public static function phrase( $portal_id ) {
			if ( ! function_exists( 'get_post_meta' ) ) {
				return '';
			}
			$id     = (int) $portal_id;
			$kind   = strtolower( self::meta_string( $id, self::META_KIND ) );
			$date   = self::meta_string( $id, self::META_DATE );
			$window = self::meta_string( $id, self::META_WINDOW );
			if ( self::KIND_WINDOW === $kind && '' !== $window ) {
				return self::window_phrase( $window );
			}
			if ( '' !== $date ) {
				return self::date_phrase( $date );
			}
			return '';
		}

		/**
		 * Public success copy. Drops the “will be made …” clause when empty.
		 *
		 * @param string $phrase Clause from phrase().
		 * @return string
		 */
		public static function success_copy( $phrase ) {
			$phrase = is_string( $phrase ) ? trim( $phrase ) : '';
			if ( '' === $phrase ) {
				return self::SUCCESS_LEAD . '.';
			}
			return self::SUCCESS_LEAD . self::SUCCESS_MADE . $phrase . '.';
		}

		/**
		 * Trimmed string meta, or empty when missing.
		 *
		 * @param int    $portal_id Portal post ID.
		 * @param string $key       Meta key.
		 * @return string
		 */
		private static function meta_string( $portal_id, $key ) {
			$raw = get_post_meta( $portal_id, $key, true );
			if ( ! is_string( $raw ) ) {
				return '';
			}
			return trim( $raw );
		}

		/**
		 * Window clause: keep a leading preposition, otherwise prefix “in ”.
		 *
		 * @param string $window Host-written window text.
		 * @return string
		 */
		private static function window_phrase( $window ) {
			if ( 1 === preg_match( self::WINDOW_HAS_PREP, $window ) ) {
				return $window;
			}
			return self::IN_PREFIX . $window;
		}

		/**
		 * Date clause: “on or before {weekday date}”, or the raw value if unparseable.
		 *
		 * @param string $raw Stored date meta.
		 * @return string
		 */
		private static function date_phrase( $raw ) {
			$formatted = self::format_calendar_day( $raw );
			if ( '' === $formatted ) {
				return self::ON_OR_BEFORE . $raw;
			}
			return self::ON_OR_BEFORE . $formatted;
		}

		/**
		 * Format a calendar day in UTC so the weekday does not shift with server TZ.
		 *
		 * @param string $raw Stored date meta.
		 * @return string Empty when unparseable.
		 */
		private static function format_calendar_day( $raw ) {
			$utc = new DateTimeZone( 'UTC' );
			$day = DateTimeImmutable::createFromFormat( '!Y-m-d', $raw, $utc );
			if ( $day instanceof DateTimeImmutable && $day->format( 'Y-m-d' ) === $raw ) {
				return $day->format( self::DATE_FORMAT );
			}
			try {
				$parsed = new DateTimeImmutable( $raw, $utc );
			} catch ( Exception $e ) {
				return '';
			}
			return $parsed->format( self::DATE_FORMAT );
		}
	}
}
