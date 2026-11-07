<?php
/**
 * REST API for portal definitions.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Definition_REST' ) ) {

	class Portal_Definition_REST {

		const NS = 'dragongate/v1';

		public static function init() {
			add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		}

		public static function register_routes() {
			register_rest_route(
				self::NS,
				'/portals/(?P<id>\d+)/definition',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'get_definition' ),
						'permission_callback' => array( __CLASS__, 'can_edit' ),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( __CLASS__, 'put_definition' ),
						'permission_callback' => array( __CLASS__, 'can_edit' ),
					),
				)
			);
		}

		/**
		 * @param WP_REST_Request $request Request.
		 * @return bool
		 */
		public static function can_edit( $request ) {
			$id = (int) $request['id'];
			return $id > 0 && current_user_can( 'edit_post', $id );
		}

		/**
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function get_definition( $request ) {
			$id = (int) $request['id'];
			$post = get_post( $id );
			if ( ! $post || 'portal' !== $post->post_type ) {
				return new WP_Error( 'dg_not_found', 'Portal not found.', array( 'status' => 404 ) );
			}
			$raw = get_post_meta( $id, Portal_Definition::META_KEY, true );
			if ( ! is_string( $raw ) || '' === $raw ) {
				return rest_ensure_response(
					array(
						'object' => 'portal_definition',
						'portal_id' => $id,
						'definition' => null,
					)
				);
			}
			$validated = Portal_Definition::from_json( $raw );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
			return rest_ensure_response(
				array(
					'object'     => 'portal_definition',
					'portal_id'  => $id,
					'definition' => $validated,
				)
			);
		}

		/**
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function put_definition( $request ) {
			$id = (int) $request['id'];
			$post = get_post( $id );
			if ( ! $post || 'portal' !== $post->post_type ) {
				return new WP_Error( 'dg_not_found', 'Portal not found.', array( 'status' => 404 ) );
			}

			$body = $request->get_json_params();
			if ( ! is_array( $body ) ) {
				return new WP_Error( 'dg_bad_body', 'JSON body required.', array( 'status' => 400 ) );
			}
			// Accept either { definition: {...} } or bare definition.
			$data = isset( $body['definition'] ) && is_array( $body['definition'] ) ? $body['definition'] : $body;
			$validated = Portal_Definition::validate( $data );
			if ( is_wp_error( $validated ) ) {
				$validated->add_data( array( 'status' => 400 ) );
				return $validated;
			}

			$json = Portal_Definition::to_json( $validated );
			update_post_meta( $id, Portal_Definition::META_KEY, $json );

			return rest_ensure_response(
				array(
					'object'     => 'portal_definition',
					'portal_id'  => $id,
					'definition' => $validated,
				)
			);
		}
	}

	Portal_Definition_REST::init();
}
