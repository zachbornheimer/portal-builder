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
			register_rest_route(
				self::NS,
				'/portals/(?P<id>\d+)/packets/(?P<app>[A-Za-z0-9._-]+)/replace',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'replace' ),
					'permission_callback' => array( __CLASS__, 'can_replace' ),
				)
			);
		}

		/**
		 * @param WP_REST_Request $request Request.
		 * @return bool
		 */
		public static function can_read( $request ) {
			return function_exists( 'is_user_logged_in' ) && is_user_logged_in();
		}

		/**
		 * @param WP_REST_Request $request Request.
		 * @return bool
		 */
		public static function can_touch( $request ) {
			return self::authorize( $request, 'mutate' );
		}

		/**
		 * @param WP_REST_Request $request Request.
		 * @return bool
		 */
		public static function can_replace( $request ) {
			return self::authorize( $request, 'replace' );
		}

		/**
		 * Staff or owning applicant. Pure policy; packet is the source of truth.
		 *
		 * @param array<string,bool>       $caps       Capability map.
		 * @param string                   $actor_hash Email hash.
		 * @param array<string,mixed>|null $packet     Stored packet.
		 * @param string                   $action     mutate|replace.
		 * @return bool
		 */
		public static function authorize_actor( array $caps, $actor_hash, $packet, $action = 'mutate' ) {
			if ( ! is_array( $packet ) ) {
				return false;
			}
			if ( 'replace' === $action ) {
				return Portal_Packet_Policy::can_replace( $caps, $actor_hash, $packet );
			}
			return Portal_Packet_Policy::can_mutate( $caps, $actor_hash, $packet );
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
			if ( ! self::authorize_actor( self::caps(), self::actor_hash(), $packet, 'mutate' ) ) {
				return new WP_Error( 'dg_packet_forbidden', 'You cannot change this submission.', array( 'status' => 403 ) );
			}
			return rest_ensure_response( $store->recall( $portal, $app ) );
		}

		/**
		 * Replace a file on a current packet through the dest adapter.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function replace( $request ) {
			$portal = (int) $request['id'];
			$app    = (string) $request['app'];
			$field  = sanitize_text_field( (string) $request->get_param( 'field_id' ) );
			if ( '' === $field ) {
				return new WP_Error( 'dg_packet_field', 'Field id is required.', array( 'status' => 400 ) );
			}
			$files = $request->get_file_params();
			$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : array();
			$tmp   = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
			if ( '' === $tmp || ! is_readable( $tmp ) ) {
				return new WP_Error( 'dg_packet_file', 'Choose a replacement file.', array( 'status' => 400 ) );
			}
			$buffer = file_get_contents( $tmp );
			if ( ! is_string( $buffer ) ) {
				return new WP_Error( 'dg_packet_file', 'Could not read the replacement file.', array( 'status' => 400 ) );
			}
			$filename = isset( $file['name'] ) ? (string) $file['name'] : ( $field . '.bin' );
			$store    = Portal_Packet_Store::for_portal( $portal, $app );
			$packet   = $store->get( $portal, $app );
			if ( ! self::authorize_actor( self::caps(), self::actor_hash(), $packet, 'replace' ) ) {
				return new WP_Error( 'dg_packet_forbidden', 'You cannot replace this file.', array( 'status' => 403 ) );
			}
			$updated = $store->replace_file( $portal, $app, $field, $buffer, $filename );
			if ( ! is_array( $updated ) ) {
				return new WP_Error( 'dg_packet_replace', 'Could not replace that file.', array( 'status' => 409 ) );
			}
			return rest_ensure_response( $updated );
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

		/**
		 * @return string
		 */
		private static function actor_hash() {
			if ( ! function_exists( 'wp_get_current_user' ) || ! class_exists( 'Portal_Submit_Log' ) ) {
				return '';
			}
			$user  = wp_get_current_user();
			$email = $user && isset( $user->user_email ) ? (string) $user->user_email : '';
			return Portal_Submit_Log::hash_email( $email );
		}

		/**
		 * @param WP_REST_Request $request Request.
		 * @param string          $action  mutate|replace.
		 * @return bool
		 */
		private static function authorize( $request, $action ) {
			if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
				return false;
			}
			$portal = isset( $request['id'] ) ? $request['id'] : 0;
			$app    = isset( $request['app'] ) ? (string) $request['app'] : '';
			$packet = Portal_Packet_Store::for_uploads()->get( $portal, $app );
			return self::authorize_actor( self::caps(), self::actor_hash(), $packet, $action );
		}
	}

	Portal_Console_Rest::init();
}
