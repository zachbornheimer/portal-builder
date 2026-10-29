<?php
/**
 * Merged operator records feed for the wizard Records step.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Records' ) ) {

	/**
	 * One row per application, newest first, merged from the submit log
	 * and the packet store on applicationId.
	 */
	class Portal_Records {

		const DEFAULT_LIMIT = 5;
		const MAX_LIMIT     = 50;

		/**
		 * Records page for one portal.
		 *
		 * Record keys: applicationId, time (ISO-8601 UTC), name (string|null),
		 * userId (int), userLogin (string), userDisplay (string), status (packet
		 * status when known, else logged), dests (sheet/drive/anonymize/mail when
		 * known, else empty), errorCode (string|null), inviteId (string|null).
		 *
		 * Old rows without the identity keys render as unknown.
		 *
		 * @param int|string $portal_id Portal post id.
		 * @param int        $limit     Page size (default 5, capped at 50).
		 * @return array{portalId:int,total:int,records:array<int,array<string,mixed>>}
		 */
		public static function for_portal( $portal_id, $limit = self::DEFAULT_LIMIT ) {
			$limit = (int) $limit;
			if ( $limit < 1 ) {
				$limit = self::DEFAULT_LIMIT;
			}
			if ( $limit > self::MAX_LIMIT ) {
				$limit = self::MAX_LIMIT;
			}

			$merged = array();
			foreach ( self::log_rows( $portal_id ) as $row ) {
				$merged = self::merge_row( $merged, $row, 'log' );
			}
			foreach ( self::packet_rows( $portal_id ) as $row ) {
				$merged = self::merge_row( $merged, $row, 'packet' );
			}

			$records = array_values( $merged );
			usort(
				$records,
				static function ( $a, $b ) {
					$ta = (int) strtotime( (string) $a['time'] );
					$tb = (int) strtotime( (string) $b['time'] );
					if ( $ta === $tb ) {
						return strcmp( (string) $b['applicationId'], (string) $a['applicationId'] );
					}
					return ( $ta < $tb ) ? 1 : -1;
				}
			);
			$total = count( $records );

			$page = array_slice( $records, 0, $limit );
			foreach ( $page as $index => $record ) {
				$page[ $index ] = self::with_user( $record );
			}

			return array(
				'portalId' => (int) $portal_id,
				'total'    => $total,
				'records'  => $page,
			);
		}

		/**
		 * Resolve WP user login/display name for a record's userId.
		 *
		 * @param array<string,mixed> $record Record.
		 * @return array<string,mixed>
		 */
		private static function with_user( array $record ) {
			$user_id = isset( $record['userId'] ) ? (int) $record['userId'] : 0;
			if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
				return $record;
			}
			$user = get_userdata( $user_id );
			if ( $user instanceof WP_User ) {
				$record['userLogin']   = (string) $user->user_login;
				$record['userDisplay'] = (string) $user->display_name;
			}
			return $record;
		}

		/**
		 * Fold one store row into the merged map.
		 *
		 * @param array<string,array<string,mixed>> $merged Map by application id.
		 * @param array<string,mixed>               $row    Store row.
		 * @param string                            $source log|packet.
		 * @return array<string,array<string,mixed>>
		 */
		private static function merge_row( array $merged, array $row, $source ) {
			$app_id = isset( $row['applicationId'] ) ? (string) $row['applicationId'] : '';
			if ( '' === $app_id ) {
				return $merged;
			}
			if ( ! isset( $merged[ $app_id ] ) ) {
				$merged[ $app_id ] = self::blank_record( $app_id );
			}
			$record = $merged[ $app_id ];

			if ( '' === $record['time'] && isset( $row['time'] ) && is_scalar( $row['time'] ) ) {
				$record['time'] = (string) $row['time'];
			}
			if ( null === $record['name'] && isset( $row['name'] ) && is_scalar( $row['name'] ) && '' !== trim( (string) $row['name'] ) ) {
				$record['name'] = trim( (string) $row['name'] );
			}
			if ( 0 === $record['userId'] && isset( $row['userId'] ) && is_scalar( $row['userId'] ) ) {
				$record['userId'] = (int) $row['userId'];
			}
			if ( null === $record['inviteId'] && isset( $row['inviteId'] ) && is_scalar( $row['inviteId'] ) && '' !== (string) $row['inviteId'] ) {
				$record['inviteId'] = (string) $row['inviteId'];
			}
			if ( null === $record['errorCode'] && isset( $row['errorCode'] ) && is_scalar( $row['errorCode'] ) && '' !== (string) $row['errorCode'] ) {
				$record['errorCode'] = (string) $row['errorCode'];
			}
			if ( empty( $record['dests'] ) && isset( $row['dests'] ) && is_array( $row['dests'] ) && ! empty( $row['dests'] ) ) {
				$record['dests'] = self::known_dests( $row['dests'] );
			}
			if ( 'packet' === $source && isset( $row['status'] ) && is_scalar( $row['status'] ) && '' !== (string) $row['status'] ) {
				$record['status'] = (string) $row['status'];
			}

			$merged[ $app_id ] = $record;
			return $merged;
		}

		/**
		 * Empty record with exactly the documented keys.
		 *
		 * @param string $app_id Application id.
		 * @return array<string,mixed>
		 */
		private static function blank_record( $app_id ) {
			return array(
				'applicationId' => (string) $app_id,
				'time'          => '',
				'name'          => null,
				'userId'        => 0,
				'userLogin'     => '',
				'userDisplay'   => '',
				'status'        => 'logged',
				'dests'         => array(),
				'errorCode'     => null,
				'inviteId'      => null,
			);
		}

		/**
		 * Dest map with the four known keys and the ok|fail|skip|warning
		 * vocabulary, or empty when nothing is known.
		 *
		 * @param array<string,mixed> $dests Raw dest map.
		 * @return array<string,string>
		 */
		private static function known_dests( array $dests ) {
			$vocabulary = array( 'ok', 'fail', 'skip', 'warning' );
			$out        = array();
			foreach ( array( 'sheet', 'drive', 'anonymize', 'mail' ) as $key ) {
				$value       = isset( $dests[ $key ] ) && is_scalar( $dests[ $key ] ) ? (string) $dests[ $key ] : 'skip';
				$out[ $key ] = in_array( $value, $vocabulary, true ) ? $value : 'skip';
			}
			return $out;
		}

		/**
		 * Submit-log rows for the portal (the store trims to its own cap).
		 *
		 * @param int|string $portal_id Portal post id.
		 * @return array<int,array<string,mixed>>
		 */
		private static function log_rows( $portal_id ) {
			if ( ! class_exists( 'Portal_Submit_Log' ) ) {
				return array();
			}
			$log = Portal_Submit_Log::for_uploads();
			return $log->last( $portal_id, Portal_Submit_Log::MAX_ROWS );
		}

		/**
		 * Packet rows for the portal.
		 *
		 * @param int|string $portal_id Portal post id.
		 * @return array<int,array<string,mixed>>
		 */
		private static function packet_rows( $portal_id ) {
			if ( ! class_exists( 'Portal_Packet_Store' ) ) {
				return array();
			}
			return Portal_Packet_Store::for_uploads()->all( $portal_id );
		}
	}
}
