<?php
/**
 * Portal access and fee-waiver decisions.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Access' ) ) {

	/**
	 * Pure applicant check: membership, login, and profile rules.
	 */
	class Portal_Access {

		const AUDIENCE_ANYONE     = 'anyone';
		const AUDIENCE_LOGGED_IN  = 'logged_in';
		const AUDIENCE_MEMBERS    = 'members';
		const REASON_LOGIN        = 'login';
		const REASON_MEMBERSHIP   = 'membership';
		const REASON_PROFILE      = 'profile';
		const ROLE_ADMINISTRATOR  = 'administrator';

		/**
		 * Decide whether this applicant may apply, and whether the fee is waived.
		 *
		 * @param array $access    Validated access block.
		 * @param array $options   Definition options (freeForMembers, freeMembershipPlanIds).
		 * @param array $applicant Keys: logged_in, plan_ids, meta, membership_provider, roles, capabilities.
		 * @return array{allowed: bool, feeWaived: bool, reason: string|null}
		 */
		public static function decide( array $access, array $options, array $applicant ) {
			$logged_in = ! empty( $applicant['logged_in'] );
			$plan_ids  = isset( $applicant['plan_ids'] ) && is_array( $applicant['plan_ids'] )
				? array_map( 'strval', $applicant['plan_ids'] )
				: array();
			$meta      = isset( $applicant['meta'] ) && is_array( $applicant['meta'] )
				? $applicant['meta']
				: array();

			if ( $logged_in && self::holds_staff_visibility( $applicant ) ) {
				return array(
					'allowed'   => true,
					'feeWaived' => true,
					'reason'    => null,
				);
			}

			$audience = isset( $access['audience'] ) ? (string) $access['audience'] : self::AUDIENCE_ANYONE;
			if ( self::AUDIENCE_LOGGED_IN === $audience && ! $logged_in ) {
				return self::denied( self::REASON_LOGIN );
			}
			if ( self::AUDIENCE_MEMBERS === $audience ) {
				$denied = self::members_denial( $access, $applicant, $logged_in, $plan_ids );
				if ( null !== $denied ) {
					return $denied;
				}
			}

			$rules = isset( $access['profileRules'] ) && is_array( $access['profileRules'] )
				? $access['profileRules']
				: array();
			foreach ( $rules as $rule ) {
				if ( ! self::rule_matches( $rule, $meta ) ) {
					return self::denied( self::REASON_PROFILE );
				}
			}

			$fee_waived = false;
			if ( ! empty( $options['freeForMembers'] ) && $logged_in ) {
				$waive_plans = isset( $options['freeMembershipPlanIds'] ) && is_array( $options['freeMembershipPlanIds'] )
					? array_map( 'strval', $options['freeMembershipPlanIds'] )
					: array();
				$fee_waived = self::holds_membership( $plan_ids, $waive_plans );
			}

			return array(
				'allowed'   => true,
				'feeWaived' => $fee_waived,
				'reason'    => null,
			);
		}

		/**
		 * Snapshot of the current WP user for decide().
		 *
		 * @return array{logged_in: bool, plan_ids: string[], meta: array<string,string>, membership_provider: bool, roles: string[], capabilities: string[]}
		 */
		public static function current_applicant() {
			$provider = self::site_has_membership_provider();
			if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
				return array(
					'logged_in'           => false,
					'plan_ids'            => array(),
					'meta'                => array(),
					'membership_provider' => $provider,
					'roles'               => array(),
					'capabilities'        => array(),
				);
			}
			$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
			$grants  = self::user_grants( $user_id );
			return array(
				'logged_in'           => true,
				'plan_ids'            => self::user_plan_ids( $user_id ),
				'meta'                => self::user_profile_meta( $user_id ),
				'membership_provider' => $provider,
				'roles'               => $grants['roles'],
				'capabilities'        => $grants['capabilities'],
			);
		}

		/**
		 * Membership plans + profile keys the Publish UI can pick.
		 *
		 * @return array{membershipPlans: array<int, array{id: string, name: string}>, profileFields: array<int, array{key: string, label: string}>, roles: array<int, array{id: string, name: string}>}
		 */
		public static function catalog() {
			$plans = array();
			if ( function_exists( 'wc_memberships_get_membership_plans' ) ) {
				foreach ( wc_memberships_get_membership_plans() as $plan ) {
					if ( ! is_object( $plan ) || ! method_exists( $plan, 'get_id' ) ) {
						continue;
					}
					$plans[] = array(
						'id'   => (string) $plan->get_id(),
						'name' => method_exists( $plan, 'get_name' ) ? (string) $plan->get_name() : (string) $plan->get_id(),
					);
				}
			} elseif ( function_exists( 'get_posts' ) ) {
				$posts = get_posts(
					array(
						'post_type'      => 'wc_membership_plan',
						'post_status'    => 'publish',
						'posts_per_page' => 50,
					)
				);
				foreach ( $posts as $post ) {
					$plans[] = array(
						'id'   => (string) $post->ID,
						'name' => (string) $post->post_title,
					);
				}
			}

			$fields = array(
				array( 'key' => 'COUNTRY', 'label' => 'Country' ),
				array( 'key' => 'INSTITUTION', 'label' => 'Institution' ),
				array( 'key' => 'OCCUPATION', 'label' => 'Occupation' ),
				array( 'key' => 'billing_country', 'label' => 'Billing country' ),
			);
			return array(
				'membershipPlans' => $plans,
				'profileFields'   => $fields,
				'roles'           => self::catalog_roles(),
			);
		}

		/**
		 * @param array  $access Validated access.
		 * @param string $reason Reason code.
		 * @return string
		 */
		public static function message_for( array $access, $reason ) {
			$custom = isset( $access['denyMessage'] ) ? trim( (string) $access['denyMessage'] ) : '';
			if ( '' !== $custom ) {
				return $custom;
			}
			if ( self::REASON_LOGIN === $reason ) {
				return 'Sign in to apply to this portal.';
			}
			if ( self::REASON_MEMBERSHIP === $reason ) {
				return 'This application is for paid members.';
			}
			return 'Your profile does not match this portal’s requirements.';
		}

		/**
		 * Deny members who fail the Woo plan check or the vanilla role check.
		 *
		 * @param array    $access    Validated access block.
		 * @param array    $applicant Applicant snapshot.
		 * @param bool     $logged_in Whether the applicant is signed in.
		 * @param string[] $plan_ids  Held membership plan IDs.
		 * @return array{allowed: bool, feeWaived: bool, reason: string}|null
		 */
		private static function members_denial( array $access, array $applicant, $logged_in, array $plan_ids ) {
			if ( ! $logged_in ) {
				return self::denied( self::REASON_LOGIN );
			}
			if ( self::has_membership_provider( $applicant ) ) {
				$required = isset( $access['membershipPlanIds'] ) && is_array( $access['membershipPlanIds'] )
					? array_map( 'strval', $access['membershipPlanIds'] )
					: array();
				if ( ! self::holds_membership( $plan_ids, $required ) ) {
					return self::denied( self::REASON_MEMBERSHIP );
				}
				return null;
			}
			if ( ! self::holds_required_role( $access, $applicant ) ) {
				return self::denied( self::REASON_MEMBERSHIP );
			}
			return null;
		}

		/**
		 * Whether this snapshot was taken on a site with Woo memberships.
		 *
		 * @param array $applicant Applicant snapshot.
		 * @return bool
		 */
		private static function has_membership_provider( array $applicant ) {
			if ( array_key_exists( 'membership_provider', $applicant ) ) {
				return ! empty( $applicant['membership_provider'] );
			}
			return self::site_has_membership_provider();
		}

		/**
		 * Whether WooCommerce Memberships functions exist on this site.
		 *
		 * @return bool
		 */
		private static function site_has_membership_provider() {
			return function_exists( 'wc_memberships_get_user_active_memberships' )
				|| function_exists( 'wc_memberships_get_membership_plans' );
		}

		/**
		 * Whether a signed-in applicant holds staff visibility (skips membership and profile gates).
		 *
		 * @param array $applicant Applicant snapshot.
		 * @return bool
		 */
		private static function holds_staff_visibility( array $applicant ) {
			$roles = self::string_list( isset( $applicant['roles'] ) ? $applicant['roles'] : array() );
			return in_array( self::ROLE_ADMINISTRATOR, $roles, true );
		}

		/**
		 * Empty required list = any signed-in user. A listed slug matches a role or capability.
		 *
		 * @param array $access    Validated access block.
		 * @param array $applicant Applicant snapshot.
		 * @return bool
		 */
		private static function holds_required_role( array $access, array $applicant ) {
			$required = array_merge(
				self::string_list( isset( $access['roles'] ) ? $access['roles'] : array() ),
				self::string_list( isset( $access['capabilities'] ) ? $access['capabilities'] : array() )
			);
			if ( empty( $required ) ) {
				return true;
			}
			$held = array_merge(
				self::string_list( isset( $applicant['roles'] ) ? $applicant['roles'] : array() ),
				self::string_list( isset( $applicant['capabilities'] ) ? $applicant['capabilities'] : array() )
			);
			return count( array_intersect( $required, $held ) ) > 0;
		}

		/**
		 * Fold a raw id list to lowercase non-empty strings.
		 *
		 * @param mixed $values Raw list.
		 * @return string[]
		 */
		private static function string_list( $values ) {
			if ( ! is_array( $values ) ) {
				return array();
			}
			$out = array();
			foreach ( $values as $value ) {
				$value = strtolower( trim( (string) $value ) );
				if ( '' !== $value ) {
					$out[] = $value;
				}
			}
			return $out;
		}

		/**
		 * @param string[] $held
		 * @param string[] $required Empty required = any held plan.
		 */
		private static function holds_membership( array $held, array $required ) {
			if ( empty( $held ) ) {
				return false;
			}
			if ( empty( $required ) ) {
				return true;
			}
			return count( array_intersect( $held, $required ) ) > 0;
		}

		/**
		 * @param array                $rule
		 * @param array<string,string> $meta
		 */
		private static function rule_matches( $rule, array $meta ) {
			if ( ! is_array( $rule ) || empty( $rule['key'] ) ) {
				return true;
			}
			$key   = (string) $rule['key'];
			$op    = isset( $rule['op'] ) ? (string) $rule['op'] : 'eq';
			$want  = isset( $rule['value'] ) ? (string) $rule['value'] : '';
			$have  = self::meta_value( $meta, $key );
			$left  = self::fold( $have );
			$right = self::fold( $want );

			switch ( $op ) {
				case 'neq':
					return $left !== $right;
				case 'contains':
					return '' !== $right && false !== strpos( $left, $right );
				case 'in':
					$parts = array_map( array( __CLASS__, 'fold' ), preg_split( '/\s*,\s*/', $want ) );
					return in_array( $left, $parts, true );
				case 'gte':
					return is_numeric( $have ) && is_numeric( $want ) && (float) $have >= (float) $want;
				case 'lte':
					return is_numeric( $have ) && is_numeric( $want ) && (float) $have <= (float) $want;
				default:
					return $left === $right;
			}
		}

		/**
		 * @param array<string,string> $meta
		 * @param string               $key
		 */
		private static function meta_value( array $meta, $key ) {
			if ( isset( $meta[ $key ] ) ) {
				return (string) $meta[ $key ];
			}
			$aliases = array(
				'INSTITUTION' => array( 'INSTITUTE', 'institution' ),
				'COUNTRY'     => array( 'billing_country' ),
			);
			if ( isset( $aliases[ $key ] ) ) {
				foreach ( $aliases[ $key ] as $alias ) {
					if ( isset( $meta[ $alias ] ) ) {
						return (string) $meta[ $alias ];
					}
				}
			}
			return '';
		}

		/**
		 * @param string $value
		 */
		private static function fold( $value ) {
			return strtolower( trim( (string) $value ) );
		}

		/**
		 * @param string $reason
		 * @return array{allowed: bool, feeWaived: bool, reason: string}
		 */
		private static function denied( $reason ) {
			return array(
				'allowed'   => false,
				'feeWaived' => false,
				'reason'    => $reason,
			);
		}

		/**
		 * @param int $user_id
		 * @return string[]
		 */
		private static function user_plan_ids( $user_id ) {
			if ( $user_id <= 0 ) {
				return array();
			}
			if ( function_exists( 'wc_memberships_get_user_active_memberships' ) ) {
				$memberships = wc_memberships_get_user_active_memberships( $user_id );
				$ids         = array();
				foreach ( $memberships as $membership ) {
					if ( is_object( $membership ) && method_exists( $membership, 'get_plan_id' ) ) {
						$ids[] = (string) $membership->get_plan_id();
					}
				}
				return $ids;
			}
			return array();
		}

		/**
		 * Roles and granted capabilities for the signed-in user.
		 *
		 * @param int $user_id WordPress user id.
		 * @return array{roles: string[], capabilities: string[]}
		 */
		private static function user_grants( $user_id ) {
			$empty = array(
				'roles'        => array(),
				'capabilities' => array(),
			);
			if ( $user_id <= 0 || ! function_exists( 'wp_get_current_user' ) ) {
				return $empty;
			}
			$user = wp_get_current_user();
			if ( ! is_object( $user ) ) {
				return $empty;
			}
			$roles = isset( $user->roles ) && is_array( $user->roles )
				? array_values( array_map( 'strval', $user->roles ) )
				: array();
			$caps  = array();
			if ( isset( $user->allcaps ) && is_array( $user->allcaps ) ) {
				foreach ( $user->allcaps as $cap => $on ) {
					if ( $on ) {
						$caps[] = (string) $cap;
					}
				}
			}
			return array(
				'roles'        => $roles,
				'capabilities' => $caps,
			);
		}

		/**
		 * WordPress roles the Publish UI can pick when Woo is absent.
		 *
		 * @return array<int, array{id: string, name: string}>
		 */
		private static function catalog_roles() {
			if ( ! function_exists( 'wp_roles' ) ) {
				return array();
			}
			$wp_roles = wp_roles();
			if ( ! is_object( $wp_roles ) || ! method_exists( $wp_roles, 'get_names' ) ) {
				return array();
			}
			$out = array();
			foreach ( $wp_roles->get_names() as $slug => $name ) {
				$out[] = array(
					'id'   => (string) $slug,
					'name' => (string) $name,
				);
			}
			return $out;
		}

		/**
		 * @param int $user_id
		 * @return array<string,string>
		 */
		private static function user_profile_meta( $user_id ) {
			if ( $user_id <= 0 || ! function_exists( 'get_user_meta' ) ) {
				return array();
			}
			$keys = array( 'COUNTRY', 'INSTITUTION', 'INSTITUTE', 'institution', 'OCCUPATION', 'billing_country' );
			$out  = array();
			foreach ( $keys as $key ) {
				$val = get_user_meta( $user_id, $key, true );
				if ( is_string( $val ) && '' !== $val ) {
					$out[ $key ] = $val;
				}
			}
			return $out;
		}
	}
}
