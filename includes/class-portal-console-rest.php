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
			register_rest_route(
				self::NS,
				'/portals/(?P<id>\d+)/records',
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'portal_records' ),
					'permission_callback' => array( __CLASS__, 'can_edit_portal' ),
				)
			);
			register_rest_route(
				self::NS,
				'/portals/(?P<id>\d+)/invites',
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_invites' ),
					'permission_callback' => array( __CLASS__, 'can_edit_portal' ),
				)
			);
			register_rest_route(
				self::NS,
				'/portals/(?P<id>\d+)/invites',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_invite' ),
					'permission_callback' => array( __CLASS__, 'can_edit_portal' ),
				)
			);
			register_rest_route(
				self::NS,
				'/portals/(?P<id>\d+)/invites/(?P<invite>[A-Za-z0-9._-]+)/resend',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'resend_invite' ),
					'permission_callback' => array( __CLASS__, 'can_edit_portal' ),
				)
			);
			register_rest_route(
				self::NS,
				'/portals/(?P<id>\d+)/invites/(?P<invite>[A-Za-z0-9._-]+)',
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'revoke_invite' ),
					'permission_callback' => array( __CLASS__, 'can_edit_portal' ),
				)
			);
			register_rest_route(
				self::NS,
				'/portals/(?P<id>\d+)/invite-candidates',
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'invite_candidates' ),
					'permission_callback' => array( __CLASS__, 'can_edit_portal' ),
				)
			);
		}

		/**
		 * Portal managers only: edit_post on the portal itself.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return true|WP_Error
		 */
		public static function can_edit_portal( $request ) {
			$portal = isset( $request['id'] ) ? (int) $request['id'] : 0;
			if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'edit_post', $portal ) ) {
				return new WP_Error( 'dg_portal_forbidden', 'You cannot manage this portal.', array( 'status' => 403 ) );
			}
			return true;
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
			if ( isset( $updated['replacementError'] ) ) {
				$error = $updated['replacementError'];
				return new WP_Error( $error['code'], $error['message'], array( 'status' => 409 ) );
			}
			return rest_ensure_response( $updated );
		}

		/**
		 * Merged records feed for the wizard Records step.
		 * Each row includes canReplace/canRecall from the packet route policy;
		 * log-only rows cannot be changed.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function portal_records( $request ) {
			$portal  = (int) $request['id'];
			$limit   = isset( $request['limit'] ) ? (int) $request['limit'] : 5;
			$data    = Portal_Records::for_portal( $portal, $limit );
			$caps    = self::caps();
			$actor   = self::actor_hash();
			$packets = array();
			foreach ( Portal_Packet_Store::for_uploads()->all( $portal ) as $packet ) {
				if ( isset( $packet['applicationId'] ) && ! isset( $packets[ (string) $packet['applicationId'] ] ) ) {
					$packets[ (string) $packet['applicationId'] ] = $packet;
				}
			}
			foreach ( $data['records'] as &$record ) {
				$packet               = isset( $packets[ $record['applicationId'] ] ) ? $packets[ $record['applicationId'] ] : null;
				$record['canReplace'] = self::authorize_actor( $caps, $actor, $packet, 'replace' );
				$record['canRecall']  = self::authorize_actor( $caps, $actor, $packet, 'mutate' );
			}
			unset( $record );
			return rest_ensure_response( $data );
		}

		/**
		 * All invitations for a portal, newest first.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function list_invites( $request ) {
			$portal = (int) $request['id'];
			return rest_ensure_response( array( 'invites' => Portal_Invites::all( $portal ) ) );
		}

		/**
		 * Invite one applicant; an existing pending invite for the same e-mail
		 * is re-sent instead of duplicated.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function create_invite( $request ) {
			$portal = (int) $request['id'];
			$email  = sanitize_text_field( (string) $request->get_param( 'email' ) );
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'dg_invite_email', 'A valid email address is required.', array( 'status' => 400 ) );
			}
			$name    = sanitize_text_field( (string) $request->get_param( 'name' ) );
			$user_id = (int) $request->get_param( 'userId' );
			if ( $user_id > 0 && ! get_userdata( $user_id ) ) {
				return new WP_Error( 'dg_invite_user', 'That WordPress user does not exist.', array( 'status' => 400 ) );
			}
			$days = $request->get_param( 'expiresInDays' );
			$days = null === $days || '' === $days ? Portal_Invites::DEFAULT_DAYS : (int) $days;
			if ( $days < 1 ) {
				$days = 1;
			}
			if ( $days > 90 ) {
				$days = 90;
			}

			$pending = self::pending_invite_for_email( $portal, $email );
			if ( null !== $pending ) {
				$report = self::send_existing_invite( $portal, $pending );
				$fresh  = Portal_Invites::find( $portal, $pending['id'] );
				$fresh  = is_array( $fresh ) ? $fresh : $pending;
				if ( is_array( $report ) && empty( $report['ok'] ) && isset( $report['error'] ) ) {
					$fresh['sendError'] = (string) $report['error'];
				}
				return rest_ensure_response(
					array(
						'invite' => $fresh,
						'resent' => true,
					)
				);
			}

			$invite = Portal_Invites::create(
				$portal,
				array(
					'email'         => $email,
					'name'          => $name,
					'userId'        => $user_id,
					'expiresInDays' => $days,
				)
			);
			if ( is_wp_error( $invite ) ) {
				return $invite;
			}
			return rest_ensure_response( array( 'invite' => $invite ) );
		}

		/**
		 * Re-send one pending invitation.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function resend_invite( $request ) {
			$portal = (int) $request['id'];
			$invite = Portal_Invites::find( $portal, (string) $request['invite'] );
			if ( ! is_array( $invite ) ) {
				return new WP_Error( 'dg_invite_missing', 'Invitation not found.', array( 'status' => 404 ) );
			}
			if ( Portal_Invites::STATUS_SENT !== Portal_Invites::status( $invite ) ) {
				return new WP_Error( 'dg_invite_terminal', 'Only a pending invitation can be re-sent.', array( 'status' => 409 ) );
			}
			$report = self::send_existing_invite( $portal, $invite );
			$fresh  = Portal_Invites::find( $portal, (string) $invite['id'] );
			$fresh  = is_array( $fresh ) ? $fresh : $invite;
			if ( is_array( $report ) && empty( $report['ok'] ) && isset( $report['error'] ) ) {
				$fresh['sendError'] = (string) $report['error'];
			}
			return rest_ensure_response( array( 'invite' => $fresh ) );
		}

		/**
		 * Revoke one invitation.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function revoke_invite( $request ) {
			$portal = (int) $request['id'];
			$invite = Portal_Invites::find( $portal, (string) $request['invite'] );
			if ( ! is_array( $invite ) ) {
				return new WP_Error( 'dg_invite_missing', 'Invitation not found.', array( 'status' => 404 ) );
			}
			Portal_Invites::revoke( $portal, (string) $invite['id'] );
			return rest_ensure_response( array( 'revoked' => true ) );
		}

		/**
		 * User picker for invitations. Needs list_users on top of edit_post;
		 * without it the candidates list is simply empty.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function invite_candidates( $request ) {
			if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'list_users' ) ) {
				return rest_ensure_response( array( 'candidates' => array() ) );
			}
			$portal = (int) $request['id'];
			$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
			$limit  = isset( $request['limit'] ) ? (int) $request['limit'] : 20;
			if ( $limit < 1 ) {
				$limit = 20;
			}
			if ( $limit > 50 ) {
				$limit = 50;
			}

			$query = array(
				'number'  => $limit,
				'orderby' => 'display_name',
				'order'   => 'ASC',
			);
			if ( '' !== $search ) {
				$query['search']         = '*' . $search . '*';
				$query['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
			}
			$users = function_exists( 'get_users' ) ? get_users( $query ) : array();

			$store      = class_exists( 'Portal_Packet_Store' ) ? Portal_Packet_Store::for_uploads() : null;
			$candidates = array();
			foreach ( $users as $user ) {
				$email = isset( $user->user_email ) ? (string) $user->user_email : '';
				$has   = false;
				if ( $store instanceof Portal_Packet_Store && '' !== $email ) {
					foreach ( $store->list_for_email( $portal, $email ) as $packet ) {
						if ( ! class_exists( 'Portal_Packet_Policy' ) || Portal_Packet_Policy::is_current( $packet ) ) {
							$has = true;
							break;
						}
					}
				}
				$candidates[] = array(
					'userId'        => isset( $user->ID ) ? (int) $user->ID : 0,
					'name'          => isset( $user->display_name ) ? (string) $user->display_name : '',
					'login'         => isset( $user->user_login ) ? (string) $user->user_login : '',
					'email'         => $email,
					'hasSubmission' => $has,
				);
			}
			return rest_ensure_response( array( 'candidates' => $candidates ) );
		}

		/**
		 * Pending (non-terminal) invite for an e-mail on a portal.
		 *
		 * @param int    $portal_id Portal post id.
		 * @param string $email     Applicant e-mail.
		 * @return array<string,mixed>|null
		 */
		private static function pending_invite_for_email( $portal_id, $email ) {
			$email = strtolower( trim( (string) $email ) );
			foreach ( Portal_Invites::all( $portal_id ) as $invite ) {
				if ( Portal_Invites::STATUS_SENT !== Portal_Invites::status( $invite ) ) {
					continue;
				}
				if ( isset( $invite['email'] ) && strtolower( trim( (string) $invite['email'] ) ) === $email ) {
					return $invite;
				}
			}
			return null;
		}

		/**
		 * Re-send an existing invite's mail and record the send.
		 *
		 * @param int                 $portal_id Portal post id.
		 * @param array<string,mixed> $invite    Invite array.
		 * @return array<string,mixed>|null Mailer report, or null without a mailer.
		 */
		private static function send_existing_invite( $portal_id, array $invite ) {
			if ( ! class_exists( 'Portal_Mailer' ) ) {
				return null;
			}
			$artifact = class_exists( 'Portal_Test_Mode' )
				? Portal_Test_Mode::artifact_dir()
				: sys_get_temp_dir();
			$mailer   = new Portal_Mailer( $artifact );
			$report   = $mailer->send_invite(
				array(
					'invite'   => $invite,
					'portalId' => $portal_id,
				)
			);
			if ( is_array( $report ) && ! empty( $report['ok'] ) ) {
				Portal_Invites::mark_sent( $portal_id, (string) $invite['id'] );
			}
			return $report;
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
