<?php
/**
 * Staff and applicant packet console (REST).
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Console_Rest' ) ) {

	/**
	 * List / recall / replace through packet store + dest facade.
	 */
	class Portal_Console_Rest {

		const NS = 'dragongate/v1';

		/**
		 * @return void
		 */
		public static function init() {
			add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
		}

		/**
		 * @return void
		 */
		public static function register() {
			register_rest_route(
				self::NS,
				'/portals/(?P<id>\d+)/packets',
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_packets' ),
					'permission_callback' => array( __CLASS__, 'can_read' ),
				)
			);
			register_rest_route(
				self::NS,
				'/portals/(?P<id>\d+)/packets/(?P<app>[A-Za-z0-9._-]+)/recall',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'recall' ),
					'permission_callback' => array( __CLASS__, 'can_touch' ),
				)
			);
		}

		/**
		 * @param WP_REST_Request $request Request.
		 * @return bool
		 */
		public static function can_read( $request ) {
			return is_user_logged_in();
		}

		/**
		 * @param WP_REST_Request $request Request.
		 * @return bool
		 */
		public static function can_touch( $request ) {
			if ( ! is_user_logged_in() ) {
				return false;
			}
			$caps = array(
				'manage_options' => current_user_can( 'manage_options' ),
				Portal_Packet_Policy::CAP_STAFF => current_user_can( Portal_Packet_Policy::CAP_STAFF ),
			);
			return Portal_Packet_Policy::staff_can_edit( $caps ) || is_user_logged_in();
		}

		/**
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|array
		 */
		public static function list_packets( $request ) {
			$portal = (int) $request['id'];
			$store  = Portal_Packet_Store::for_uploads();
			if ( Portal_Packet_Policy::staff_can_edit( self::caps() ) ) {
				return rest_ensure_response( $store->all( $portal ) );
			}
			$user  = wp_get_current_user();
			$email = $user && isset( $user->user_email ) ? (string) $user->user_email : '';
			return rest_ensure_response( $store->list_for_email( $portal, $email ) );
		}

		/**
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function recall( $request ) {
			$portal = (int) $request['id'];
			$app    = (string) $request['app'];
			$store  = Portal_Packet_Store::for_uploads();
			$packet = $store->get( $portal, $app );
			if ( ! is_array( $packet ) ) {
				return new WP_Error( 'dg_packet_missing', 'Submission not found.', array( 'status' => 404 ) );
			}
			if ( ! Portal_Packet_Policy::staff_can_edit( self::caps() ) ) {
				$user  = wp_get_current_user();
				$email = $user && isset( $user->user_email ) ? (string) $user->user_email : '';
				$hash  = Portal_Submit_Log::hash_email( $email );
				if ( ! Portal_Packet_Policy::applicant_can_touch( $hash, $packet ) ) {
					return new WP_Error( 'dg_packet_forbidden', 'You cannot change this submission.', array( 'status' => 403 ) );
				}
			}
			return rest_ensure_response( $store->recall( $portal, $app ) );
		}

		/**
		 * @return array<string,bool>
		 */
		private static function caps() {
			return array(
				'manage_options'                => function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ),
				Portal_Packet_Policy::CAP_STAFF => function_exists( 'current_user_can' ) && current_user_can( Portal_Packet_Policy::CAP_STAFF ),
			);
		}
	}

	Portal_Console_Rest::init();
}
