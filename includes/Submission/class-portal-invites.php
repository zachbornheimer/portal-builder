<?php
/**
 * One-off applicant invitations: personal link that works past the deadline.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Invites' ) ) {

	/**
	 * Per-portal invite store (option dg_portal_invites_{portalId}, autoload off).
	 *
	 * The URL token is {inviteId}.{hmac} where the hmac covers the invite id,
	 * the portal id, and the expiry; only the token's hash is ever stored.
	 */
	class Portal_Invites {

		const QUERY_KEY      = 'dg_invite';
		const STATUS_SENT    = 'sent';
		const STATUS_USED    = 'used';
		const STATUS_EXPIRED = 'expired';
		const STATUS_REVOKED = 'revoked';
		const DEFAULT_DAYS   = 14;
		const MAX_PER_PORTAL = 200;

		/**
		 * In-process store when WP options are absent.
		 *
		 * @var array<string,array>
		 */
		private static $memory = array();

		/**
		 * Memoized per-portal resolve() for the current request.
		 *
		 * @var array<string,array|null>
		 */
		private static $current_request = array();

		/**
		 * Create an invite, e-mail it, and record the send.
		 *
		 * A mail failure never loses the invite: the returned array carries a
		 * `sendError` key instead of failing creation.
		 *
		 * @param int|string          $portal_id Portal post id.
		 * @param array<string,mixed> $args      Keys: email, name?, userId?, expiresInDays?.
		 * @return array<string,mixed>|WP_Error Invite array, or an error.
		 */
		public static function create( $portal_id, array $args ) {
			$email = isset( $args['email'] ) ? self::valid_email( trim( (string) $args['email'] ) ) : false;
			if ( false === $email ) {
				return new WP_Error( 'dg_invite_email', 'A valid email address is required.' );
			}
			$name    = isset( $args['name'] ) ? trim( (string) $args['name'] ) : '';
			$user_id = isset( $args['userId'] ) ? (int) $args['userId'] : 0;
			if ( $user_id > 0 && function_exists( 'get_userdata' ) && ! get_userdata( $user_id ) ) {
				return new WP_Error( 'dg_invite_user', 'That WordPress user does not exist.' );
			}
			$days = isset( $args['expiresInDays'] ) ? (int) $args['expiresInDays'] : self::DEFAULT_DAYS;
			if ( $days < 1 ) {
				$days = 1;
			}
			if ( $days > 90 ) {
				$days = 90;
			}

			$map = self::load( $portal_id );
			self::evict_terminal( $map );
			if ( count( $map ) >= self::MAX_PER_PORTAL ) {
				return new WP_Error( 'dg_invite_cap', 'This portal already has the maximum number of invitations.' );
			}

			$now        = time();
			$expires    = gmdate( 'c', $now + ( $days * 86400 ) );
			$id         = 'inv_' . self::random_hex( 16 );
			$invite     = array(
				'id'            => $id,
				'email'         => $email,
				'name'          => $name,
				'userId'        => $user_id,
				'status'        => self::STATUS_SENT,
				'createdAt'     => gmdate( 'c', $now ),
				'expiresAt'     => $expires,
				'sentAt'        => null,
				'usedAt'        => null,
				'applicationId' => null,
				'tokenHash'     => hash( 'sha256', self::token_for( $id, $portal_id, $expires ) ),
			);
			$map[ $id ] = $invite;
			self::save( $portal_id, $map );

			$report = self::dispatch( $portal_id, $invite );
			if ( is_array( $report ) && ! empty( $report['ok'] ) ) {
				self::mark_sent( $portal_id, $id );
				$stored = self::find( $portal_id, $id );
				return is_array( $stored ) ? $stored : self::hydrate( $portal_id, $invite );
			}
			$out = self::hydrate( $portal_id, $invite );
			if ( is_array( $report ) && isset( $report['error'] ) && '' !== (string) $report['error'] ) {
				$out['sendError'] = (string) $report['error'];
			} else {
				$out['sendError'] = 'The invitation e-mail could not be sent.';
			}
			return $out;
		}

		/**
		 * All invites for a portal, newest first.
		 *
		 * @param int|string $portal_id Portal post id.
		 * @return array<int,array<string,mixed>>
		 */
		public static function all( $portal_id ) {
			$out = array();
			foreach ( self::load( $portal_id ) as $stored ) {
				$out[] = self::hydrate( $portal_id, $stored );
			}
			usort(
				$out,
				static function ( $a, $b ) {
					$ta = isset( $a['createdAt'] ) ? (int) strtotime( (string) $a['createdAt'] ) : 0;
					$tb = isset( $b['createdAt'] ) ? (int) strtotime( (string) $b['createdAt'] ) : 0;
					if ( $ta === $tb ) {
						return strcmp( (string) $b['id'], (string) $a['id'] );
					}
					return ( $ta < $tb ) ? 1 : -1;
				}
			);
			return $out;
		}

		/**
		 * One invite for a portal, or null.
		 *
		 * @param int|string $portal_id Portal post id.
		 * @param string     $invite_id Invite id.
		 * @return array<string,mixed>|null
		 */
		public static function find( $portal_id, $invite_id ) {
			$invite_id = (string) $invite_id;
			$map       = self::load( $portal_id );
			if ( ! isset( $map[ $invite_id ] ) || ! is_array( $map[ $invite_id ] ) ) {
				return null;
			}
			return self::hydrate( $portal_id, $map[ $invite_id ] );
		}

		/**
		 * Revoke an invite. True when it existed and is now revoked.
		 *
		 * @param int|string $portal_id Portal post id.
		 * @param string     $invite_id Invite id.
		 * @return bool
		 */
		public static function revoke( $portal_id, $invite_id ) {
			$updated = self::update( $portal_id, $invite_id, array( 'status' => self::STATUS_REVOKED ) );
			return is_array( $updated );
		}

		/**
		 * Record that the invitation mail went out.
		 *
		 * @param int|string $portal_id Portal post id.
		 * @param string     $invite_id Invite id.
		 * @return void
		 */
		public static function mark_sent( $portal_id, $invite_id ) {
			self::update( $portal_id, $invite_id, array( 'sentAt' => gmdate( 'c' ) ) );
		}

		/**
		 * Record that an invited application was submitted.
		 *
		 * @param int|string $portal_id      Portal post id.
		 * @param string     $invite_id      Invite id.
		 * @param string     $application_id Application id.
		 * @return void
		 */
		public static function mark_used( $portal_id, $invite_id, $application_id ) {
			self::update(
				$portal_id,
				$invite_id,
				array(
					'usedAt'        => gmdate( 'c' ),
					'applicationId' => (string) $application_id,
					'status'        => self::STATUS_USED,
				)
			);
		}

		/**
		 * Absolute personal invitation link for this invite on this portal.
		 *
		 * The token is deterministic (id + portal + stored expiry), so the same
		 * link is re-mintable without ever storing it.
		 *
		 * @param int|string          $portal_id Portal post id.
		 * @param array<string,mixed> $invite    Invite array.
		 * @return string
		 */
		public static function url( $portal_id, array $invite ) {
			$id      = isset( $invite['id'] ) ? (string) $invite['id'] : '';
			$expires = isset( $invite['expiresAt'] ) ? (string) $invite['expiresAt'] : '';
			if ( '' === $id || '' === $expires ) {
				return '';
			}
			$base = self::portal_base_url( $portal_id );
			$args = array( self::QUERY_KEY => self::token_for( $id, $portal_id, $expires ) );
			if ( function_exists( 'add_query_arg' ) ) {
				return (string) add_query_arg( $args, $base );
			}
			$sep = false === strpos( $base, '?' ) ? '?' : '&';
			return $base . $sep . http_build_query( $args );
		}

		/**
		 * Resolve a raw token to its invite.
		 *
		 * Null when no token was supplied, the invite array when the token is
		 * valid for this portal, or a WP_Error naming why it is not usable.
		 *
		 * @param int|string  $portal_id Portal post id.
		 * @param string|null $raw       Raw token; null reads dg_invite from the request.
		 * @return array<string,mixed>|WP_Error|null
		 */
		public static function resolve( $portal_id, $raw = null ) {
			if ( null === $raw ) {
				$raw = self::request_token();
				if ( null === $raw ) {
					return null;
				}
			}
			if ( ! is_string( $raw ) ) {
				return new WP_Error( 'dg_invite_invalid', 'This invitation link is not valid.' );
			}
			$raw = trim( $raw );
			if ( '' === $raw ) {
				return null;
			}
			$parts = explode( '.', $raw, 2 );
			if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
				return new WP_Error( 'dg_invite_invalid', 'This invitation link is not valid.' );
			}
			$invite = self::find( $portal_id, $parts[0] );
			if ( ! is_array( $invite ) ) {
				return new WP_Error( 'dg_invite_invalid', 'This invitation link is not valid.' );
			}
			if ( ! hash_equals( self::hmac_for( $invite['id'], $portal_id, (string) $invite['expiresAt'] ), $parts[1] ) ) {
				return new WP_Error( 'dg_invite_invalid', 'This invitation link is not valid.' );
			}
			$status = self::status( $invite );
			if ( self::STATUS_USED === $status ) {
				return new WP_Error( 'dg_invite_used', 'This invitation has already been used.' );
			}
			if ( self::STATUS_REVOKED === $status ) {
				return new WP_Error( 'dg_invite_revoked', 'This invitation has been revoked.' );
			}
			if ( self::STATUS_EXPIRED === $status ) {
				return new WP_Error( 'dg_invite_expired', 'This invitation has expired.' );
			}
			return $invite;
		}

		/**
		 * Resolved invite for this request, memoized per portal.
		 *
		 * @param int|string $portal_id Portal post id.
		 * @return array<string,mixed>|null
		 */
		public static function for_current_request( $portal_id ) {
			$key = (string) $portal_id;
			if ( ! array_key_exists( $key, self::$current_request ) ) {
				$resolved                      = self::resolve( $portal_id );
				self::$current_request[ $key ] = is_array( $resolved ) ? $resolved : null;
			}
			return self::$current_request[ $key ];
		}

		/**
		 * Whether the current request carries a usable invitation.
		 *
		 * Never throws, and never true for another portal's invite.
		 *
		 * @param int|string $portal_id Portal post id.
		 * @return bool
		 */
		public static function grants_admission( $portal_id ) {
			try {
				$resolved = self::resolve( $portal_id );
				if ( ! is_array( $resolved ) ) {
					return false;
				}
				return self::STATUS_SENT === self::status( $resolved );
			} catch ( Exception $e ) {
				return false;
			} catch ( Throwable $e ) {
				return false;
			}
		}

		/**
		 * Effective status: used / revoked / expired / sent.
		 *
		 * @param array<string,mixed> $invite Invite array.
		 * @return string
		 */
		public static function status( array $invite ) {
			$stored = isset( $invite['status'] ) ? (string) $invite['status'] : '';
			if ( in_array( $stored, array( self::STATUS_USED, self::STATUS_REVOKED, self::STATUS_EXPIRED ), true ) ) {
				return $stored;
			}
			$expires = isset( $invite['expiresAt'] ) ? strtotime( (string) $invite['expiresAt'] ) : false;
			if ( is_int( $expires ) && $expires < time() ) {
				return self::STATUS_EXPIRED;
			}
			return self::STATUS_SENT;
		}

		/**
		 * Build the invite mail and dispatch it through Portal_Mailer.
		 *
		 * @param int|string          $portal_id Portal post id.
		 * @param array<string,mixed> $invite    Stored invite.
		 * @return array<string,mixed>|null Mailer report, or null without a mailer.
		 */
		private static function dispatch( $portal_id, array $invite ) {
			if ( ! class_exists( 'Portal_Mailer' ) ) {
				return null;
			}
			$artifact = class_exists( 'Portal_Test_Mode' )
				? Portal_Test_Mode::artifact_dir()
				: sys_get_temp_dir();
			$mailer   = new Portal_Mailer( $artifact );
			return $mailer->send_invite(
				array(
					'invite'   => $invite,
					'portalId' => $portal_id,
				)
			);
		}

		/**
		 * Apply changes to a stored invite; returns the updated row or null.
		 *
		 * @param int|string          $portal_id Portal post id.
		 * @param string              $invite_id Invite id.
		 * @param array<string,mixed> $changes   Changed keys.
		 * @return array<string,mixed>|null
		 */
		private static function update( $portal_id, $invite_id, array $changes ) {
			$invite_id = (string) $invite_id;
			$map       = self::load( $portal_id );
			if ( ! isset( $map[ $invite_id ] ) || ! is_array( $map[ $invite_id ] ) ) {
				return null;
			}
			foreach ( $changes as $key => $value ) {
				$map[ $invite_id ][ $key ] = $value;
			}
			self::save( $portal_id, $map );
			self::$current_request = array();
			return $map[ $invite_id ];
		}

		/**
		 * Evict the oldest terminal invites so a live one is never dropped.
		 *
		 * @param array<string,array> $map Invite map by id (by reference).
		 * @return void
		 */
		private static function evict_terminal( array &$map ) {
			if ( count( $map ) < self::MAX_PER_PORTAL ) {
				return;
			}
			$entries = array_values( $map );
			usort(
				$entries,
				static function ( $a, $b ) {
					$ta = isset( $a['createdAt'] ) ? (int) strtotime( (string) $a['createdAt'] ) : 0;
					$tb = isset( $b['createdAt'] ) ? (int) strtotime( (string) $b['createdAt'] ) : 0;
					if ( $ta === $tb ) {
						return strcmp( (string) $a['id'], (string) $b['id'] );
					}
					return ( $ta > $tb ) ? 1 : -1;
				}
			);
			foreach ( $entries as $entry ) {
				if ( count( $map ) < self::MAX_PER_PORTAL ) {
					break;
				}
				if ( isset( $entry['id'] ) && self::STATUS_SENT !== self::status( $entry ) ) {
					unset( $map[ $entry['id'] ] );
				}
			}
		}

		/**
		 * Hydrate a stored invite with its computed status and URL.
		 *
		 * @param int|string          $portal_id Portal post id.
		 * @param array<string,mixed> $stored    Stored invite.
		 * @return array<string,mixed>
		 */
		private static function hydrate( $portal_id, array $stored ) {
			$invite                  = $stored;
			$invite['userId']        = isset( $stored['userId'] ) ? (int) $stored['userId'] : 0;
			$invite['name']          = isset( $stored['name'] ) ? (string) $stored['name'] : '';
			$invite['sentAt']        = isset( $stored['sentAt'] ) ? $stored['sentAt'] : null;
			$invite['usedAt']        = isset( $stored['usedAt'] ) ? $stored['usedAt'] : null;
			$invite['applicationId'] = isset( $stored['applicationId'] ) ? $stored['applicationId'] : null;
			$invite['status']        = self::status( $invite );
			$invite['url']           = self::url( $portal_id, $invite );
			return $invite;
		}

		/**
		 * HMAC over {inviteId}|{portalId}|{expiresAt} with the auth salt.
		 *
		 * @param string     $invite_id  Invite id.
		 * @param int|string $portal_id  Portal post id.
		 * @param string     $expires_at Stored expiry string.
		 * @return string
		 */
		private static function hmac_for( $invite_id, $portal_id, $expires_at ) {
			$secret = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : 'dg-invite-dev-secret';
			return hash_hmac( 'sha256', (string) $invite_id . '|' . (string) $portal_id . '|' . (string) $expires_at, $secret );
		}

		/**
		 * Full URL token: {inviteId}.{hmac}.
		 *
		 * @param string     $invite_id  Invite id.
		 * @param int|string $portal_id  Portal post id.
		 * @param string     $expires_at Stored expiry string.
		 * @return string
		 */
		private static function token_for( $invite_id, $portal_id, $expires_at ) {
			return (string) $invite_id . '.' . self::hmac_for( $invite_id, $portal_id, $expires_at );
		}

		/**
		 * Raw dg_invite query token for the current request, or null.
		 *
		 * Read from the request's GET data; the raw query string is the
		 * fallback for SAPIs where filter_input cannot see the request
		 * (CLI and test harnesses).
		 *
		 * @return mixed Token string, the raw value for malformed input, or null.
		 */
		private static function request_token() {
			$raw = function_exists( 'filter_input' ) ? filter_input( INPUT_GET, self::QUERY_KEY ) : null;
			if ( false === $raw || null === $raw ) {
				$query  = isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '';
				$parsed = array();
				parse_str( $query, $parsed );
				if ( isset( $parsed[ self::QUERY_KEY ] ) ) {
					$raw = $parsed[ self::QUERY_KEY ];
				}
			}
			if ( is_string( $raw ) ) {
				$raw = trim( $raw );
				return '' !== $raw ? $raw : null;
			}
			return is_array( $raw ) ? $raw : null;
		}

		/**
		 * Permalink for the portal, or home/fallback when WP is absent.
		 *
		 * @param int|string $portal_id Portal post id.
		 * @return string
		 */
		private static function portal_base_url( $portal_id ) {
			if ( function_exists( 'get_permalink' ) ) {
				$permalink = get_permalink( $portal_id );
				if ( is_string( $permalink ) && '' !== $permalink ) {
					return $permalink;
				}
			}
			if ( function_exists( 'home_url' ) ) {
				return (string) home_url( '/' );
			}
			return '/';
		}

		/**
		 * Option name holding one portal's invite map.
		 *
		 * @param int|string $portal_id Portal post id.
		 * @return string
		 */
		private static function option_name( $portal_id ) {
			$id = preg_replace( '/[^A-Za-z0-9_-]/', '_', (string) $portal_id );
			return 'dg_portal_invites_' . ( is_string( $id ) && '' !== $id ? $id : 'portal' );
		}

		/**
		 * Load the stored invite map for a portal.
		 *
		 * @param int|string $portal_id Portal post id.
		 * @return array<string,array>
		 */
		private static function load( $portal_id ) {
			$key = self::option_name( $portal_id );
			if ( function_exists( 'get_option' ) ) {
				$stored = get_option( $key, null );
				if ( is_array( $stored ) ) {
					return $stored;
				}
			}
			return isset( self::$memory[ $key ] ) && is_array( self::$memory[ $key ] ) ? self::$memory[ $key ] : array();
		}

		/**
		 * Persist the invite map (autoload off).
		 *
		 * @param int|string          $portal_id Portal post id.
		 * @param array<string,array> $map       Invite map by id.
		 * @return void
		 */
		private static function save( $portal_id, array $map ) {
			$key                  = self::option_name( $portal_id );
			self::$memory[ $key ] = $map;
			if ( function_exists( 'update_option' ) ) {
				update_option( $key, $map, false );
			}
		}

		/**
		 * Valid e-mail address or false (WP is_email when available).
		 *
		 * @param string $email Candidate.
		 * @return string|false
		 */
		private static function valid_email( $email ) {
			$email = (string) $email;
			if ( function_exists( 'is_email' ) ) {
				$valid = is_email( $email );
				return is_string( $valid ) && '' !== $valid ? $valid : false;
			}
			return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
		}

		/**
		 * Lowercase hex id fragment of the requested length.
		 *
		 * @param int $length Hex characters.
		 * @return string
		 */
		private static function random_hex( $length ) {
			$length = (int) $length;
			if ( function_exists( 'random_bytes' ) ) {
				return substr( bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ), 0, $length );
			}
			return substr( md5( uniqid( 'dg-invite-', true ) ), 0, $length );
		}
	}
}
