<?php
/**
 * Who may edit or recall a submitted packet.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Packet_Policy' ) ) {

	/**
	 * Staff replace vs applicant recall. Pure; no I/O.
	 */
	class Portal_Packet_Policy {

		const CAP_STAFF       = 'dg_edit_submissions';
		const STATUS_CURRENT  = 'current';
		const STATUS_RECALLED = 'recalled';

		/**
		 * @param array<string,bool> $caps Capability map.
		 * @return bool
		 */
		public static function staff_can_edit( array $caps ) {
			if ( ! empty( $caps['manage_options'] ) ) {
				return true;
			}
			return ! empty( $caps[ self::CAP_STAFF ] );
		}

		/**
		 * Applicant may touch only their own current packet on this portal.
		 *
		 * @param string              $actor_hash Email hash of the logged-in applicant.
		 * @param array<string,mixed> $packet     Stored packet.
		 * @return bool
		 */
		public static function applicant_can_touch( $actor_hash, array $packet ) {
			$hash = isset( $packet['emailHash'] ) ? (string) $packet['emailHash'] : '';
			if ( '' === $actor_hash || $hash !== (string) $actor_hash ) {
				return false;
			}
			$status = isset( $packet['status'] ) ? (string) $packet['status'] : self::STATUS_CURRENT;
			return self::STATUS_CURRENT === $status;
		}

		/**
		 * @param array<string,mixed> $packet Packet.
		 * @return array<string,mixed>
		 */
		public static function recall( array $packet ) {
			$packet['status']     = self::STATUS_RECALLED;
			$packet['recalledAt'] = isset( $packet['recalledAt'] ) ? $packet['recalledAt'] : gmdate( 'c' );
			return $packet;
		}

		/**
		 * @param array<string,mixed> $packet Packet.
		 * @return bool
		 */
		public static function is_current( array $packet ) {
			$status = isset( $packet['status'] ) ? (string) $packet['status'] : self::STATUS_CURRENT;
			return self::STATUS_CURRENT === $status;
		}
	}
}
