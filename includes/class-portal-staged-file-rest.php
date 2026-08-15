<?php
/**
 * Public REST: stage a file pick and fetch staged bytes by token.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Staged_File_REST' ) ) {

	/**
	 * Thin HTTP adapter over Portal_Staged_File.
	 */
	class Portal_Staged_File_REST {

		const NS           = 'dragongate/v1';
		const NONCE_ACTION = 'dg_stage_file';
		const FILE_PARAM   = 'file';
		const FIELD_PARAM  = 'field_id';

		/**
		 * Marker key on WP_REST_Response data for binary serve (not JSON).
		 */
		const RAW_BODY_KEY = 'dg_raw_body';

		/**
		 * Hook registration.
		 */
		public static function init() {
			add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
			// WP REST always JSON-encodes response data unless this returns true.
			add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_raw_request' ), 10, 4 );
		}

		/**
		 * Register stage + read routes.
		 */
		public static function register_routes() {
			register_rest_route(
				self::NS,
				'/portals/(?P<id>\d+)/files',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'stage_file' ),
					'permission_callback' => array( __CLASS__, 'can_stage' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				)
			);
			register_rest_route(
				self::NS,
				'/files/(?P<token>[a-f0-9]{32,})',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'read_file' ),
						'permission_callback' => '__return_true',
						'args'                => array(
							'token' => array(
								'type'              => 'string',
								'required'          => true,
								'sanitize_callback' => 'sanitize_text_field',
							),
						),
					),
					array(
						'methods'             => WP_REST_Server::DELETABLE,
						'callback'            => array( __CLASS__, 'forget_file' ),
						'permission_callback' => '__return_true',
						'args'                => array(
							'token' => array(
								'type'              => 'string',
								'required'          => true,
								'sanitize_callback' => 'sanitize_text_field',
							),
						),
					),
				)
			);
		}

		/**
		 * Portal exists, form is open (or preview), nonce valid.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return bool|WP_Error
		 */
		public static function can_stage( $request ) {
			$id = (int) $request['id'];
			if ( $id <= 0 ) {
				return new WP_Error( 'dg_not_found', 'Portal not found.', array( 'status' => 404 ) );
			}
			$post = get_post( $id );
			if ( ! $post || 'portal' !== $post->post_type ) {
				return new WP_Error( 'dg_not_found', 'Portal not found.', array( 'status' => 404 ) );
			}
			if ( class_exists( 'Portal_Open_State' ) && ! Portal_Open_State::should_show_form( $id ) ) {
				return new WP_Error( 'dg_portal_closed', 'This portal is not accepting files.', array( 'status' => 403 ) );
			}
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( ! is_string( $nonce ) || '' === $nonce ) {
				$nonce = $request->get_param( '_wpnonce' );
			}
			if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
				return new WP_Error( 'dg_bad_nonce', 'Invalid staging nonce.', array( 'status' => 403 ) );
			}
			return true;
		}

		/**
		 * Multipart stage: file + field_id.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function stage_file( $request ) {
			$id         = (int) $request['id'];
			$definition = class_exists( 'Portal_Definition' )
				? Portal_Definition::load_for_post( $id )
				: null;
			if ( ! is_array( $definition ) ) {
				return new WP_Error( 'dg_no_definition', 'This portal has no form definition.', array( 'status' => 400 ) );
			}

			$field_id = $request->get_param( self::FIELD_PARAM );
			if ( ! is_string( $field_id ) || '' === $field_id ) {
				return new WP_Error( 'dg_missing_field', 'field_id is required.', array( 'status' => 400 ) );
			}

			$files = $request->get_file_params();
			$file  = null;
			if ( isset( $files[ self::FILE_PARAM ] ) && is_array( $files[ self::FILE_PARAM ] ) ) {
				$file = $files[ self::FILE_PARAM ];
			} elseif ( ! empty( $files ) ) {
				$file = reset( $files );
			}
			if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
				return new WP_Error( 'dg_missing_file', 'A file upload is required.', array( 'status' => 400 ) );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$buffer = file_get_contents( $file['tmp_name'] );
			if ( ! is_string( $buffer ) || '' === $buffer ) {
				return new WP_Error( 'dg_empty_file', 'Upload is empty.', array( 'status' => 400 ) );
			}
			$original = isset( $file['name'] ) ? (string) $file['name'] : 'upload.bin';

			$staging = new Portal_Staged_File();
			$result  = $staging->stage( $id, $field_id, $buffer, $original, $definition );
			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 400 ) );
				return $result;
			}

			$read_url = rest_url( self::NS . '/files/' . $result['token'] );

			return rest_ensure_response(
				array(
					'object'       => 'staged_file',
					'token'        => $result['token'],
					'storedName'   => $result['storedName'],
					'originalName' => $result['originalName'],
					'bytes'        => $result['bytes'],
					'anonymized'   => ! empty( $result['anonymized'] ),
					'url'          => $read_url,
				)
			);
		}

		/**
		 * Stream staged bytes. Token is the capability.
		 *
		 * Response data is a marker envelope; serve_raw_request echoes the
		 * raw buffer so Open-to-confirm gets PDF/MP3 bytes, not JSON.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function read_file( $request ) {
			$token   = (string) $request['token'];
			$staging = new Portal_Staged_File();
			$rec     = $staging->read( $token );
			if ( ! is_array( $rec ) ) {
				return new WP_Error( 'dg_staged_not_found', 'Staged file not found.', array( 'status' => 404 ) );
			}

			return self::raw_bytes_response( $rec );
		}

		/**
		 * Build a REST response that serve_raw_request will emit as binary.
		 *
		 * @param array $rec Staged record from Portal_Staged_File::read.
		 * @return WP_REST_Response|array Envelope (array when WP_REST_Response absent in CLI).
		 */
		public static function raw_bytes_response( array $rec ) {
			$payload = self::raw_payload( $rec );
			$data    = array(
				self::RAW_BODY_KEY => $payload['body'],
			);

			if ( class_exists( 'WP_REST_Response' ) ) {
				$response = new WP_REST_Response( $data, 200 );
				$response->header( 'Content-Type', $payload['content_type'] );
				$response->header(
					'Content-Disposition',
					'inline; filename="' . rawurlencode( $payload['filename'] ) . '"'
				);
				$response->header( 'Content-Length', (string) $payload['content_length'] );
				$response->header( 'Cache-Control', 'private, no-store' );
				return $response;
			}

			// CLI harness shape (no WP REST classes).
			return array(
				'data'    => $data,
				'headers' => array(
					'Content-Type'        => $payload['content_type'],
					'Content-Disposition' => 'inline; filename="' . rawurlencode( $payload['filename'] ) . '"',
					'Content-Length'      => (string) $payload['content_length'],
					'Cache-Control'       => 'private, no-store',
				),
				'status'  => 200,
			);
		}

		/**
		 * Pure content-type / body envelope for a staged record.
		 *
		 * @param array $rec Staged record.
		 * @return array{body:string,content_type:string,filename:string,content_length:int}
		 */
		public static function raw_payload( array $rec ) {
			$name = isset( $rec['storedName'] ) ? (string) $rec['storedName'] : 'file.bin';
			$body = isset( $rec['bytes'] ) ? (string) $rec['bytes'] : '';
			$type = 'application/octet-stream';
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( 'pdf' === $ext ) {
				$type = 'application/pdf';
			} elseif ( 'mp3' === $ext ) {
				$type = 'audio/mpeg';
			}
			return array(
				'body'           => $body,
				'content_type'   => $type,
				'filename'       => $name,
				'content_length' => strlen( $body ),
			);
		}

		/**
		 * Extract raw body from a REST result (or CLI envelope). Null if JSON path.
		 *
		 * @param mixed $result WP_REST_Response or CLI array envelope.
		 * @return string|null
		 */
		public static function raw_body_from_result( $result ) {
			$data = null;
			if ( is_object( $result ) && method_exists( $result, 'get_data' ) ) {
				$data = $result->get_data();
			} elseif ( is_array( $result ) && isset( $result['data'] ) && is_array( $result['data'] ) ) {
				$data = $result['data'];
			} elseif ( is_array( $result ) && array_key_exists( self::RAW_BODY_KEY, $result ) ) {
				$data = $result;
			}
			if ( ! is_array( $data ) || ! array_key_exists( self::RAW_BODY_KEY, $data ) ) {
				return null;
			}
			return (string) $data[ self::RAW_BODY_KEY ];
		}

		/**
		 * rest_pre_serve_request: emit binary body, skip wp_json_encode.
		 *
		 * @param bool             $served  Already served.
		 * @param WP_REST_Response $result  Response.
		 * @param WP_REST_Request  $request Request.
		 * @param WP_REST_Server   $server  Server.
		 * @return bool
		 */
		public static function serve_raw_request( $served, $result, $request, $server ) {
			unset( $request, $server );
			if ( true === $served ) {
				return $served;
			}
			$body = self::raw_body_from_result( $result );
			if ( null === $body ) {
				return $served;
			}

			// Headers were set on the response object; re-send them before body.
			if ( is_object( $result ) && method_exists( $result, 'get_headers' ) ) {
				foreach ( $result->get_headers() as $key => $value ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- header() may warn after output in edge cases.
					header( sprintf( '%s: %s', $key, $value ) );
				}
			}
			if ( is_object( $result ) && method_exists( $result, 'get_status' ) && function_exists( 'status_header' ) ) {
				status_header( (int) $result->get_status() );
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw binary file body.
			echo $body;
			return true;
		}

		/**
		 * Drop a staged token (Replace / abandon).
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function forget_file( $request ) {
			$token   = (string) $request['token'];
			$staging = new Portal_Staged_File();
			$staging->forget( $token );
			return rest_ensure_response(
				array(
					'object'  => 'staged_file',
					'token'   => $token,
					'deleted' => true,
				)
			);
		}
	}

	if ( function_exists( 'add_action' ) ) {
		Portal_Staged_File_REST::init();
	}
}
