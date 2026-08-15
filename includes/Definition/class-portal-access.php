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

		/**
		 * Decide whether this applicant may apply, and whether the fee is waived.
		 *
		 * @param array $access    Validated access block.
		 * @param array $options   Definition options (freeForMembers, freeMembershipPlanIds).
		 * @param array $applicant Keys: logged_in (bool), plan_ids (string[]), meta (string[]).
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

			$audience = isset( $access['audience'] ) ? (string) $access['audience'] : self::AUDIENCE_ANYONE;
			if ( self::AUDIENCE_LOGGED_IN === $audience && ! $logged_in ) {
				return self::denied( self::REASON_LOGIN );
			}
			if ( self::AUDIENCE_MEMBERS === $audience ) {
				if ( ! $logged_in ) {
					return self::denied( self::REASON_LOGIN );
				}
				$required = isset( $access['membershipPlanIds'] ) && is_array( $access['membershipPlanIds'] )
					? array_map( 'strval', $access['membershipPlanIds'] )
					: array();
				if ( ! self::holds_membership( $plan_ids, $required ) ) {
					return self::denied( self::REASON_MEMBERSHIP );
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
		 * @return array{logged_in: bool, plan_ids: string[], meta: array<string,string>}
		 */
		public static function current_applicant() {
			if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
				return array(
					'logged_in' => false,
					'plan_ids'  => array(),
					'meta'      => array(),
				);
			}
			$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
			return array(
				'logged_in' => true,
				'plan_ids'  => self::user_plan_ids( $user_id ),
				'meta'      => self::user_profile_meta( $user_id ),
			);
		}

		/**
		 * Membership plans + profile keys the Publish UI can pick.
		 *
		 * @return array{membershipPlans: array<int, array{id: string, name: string}>, profileFields: array<int, array{key: string, label: string}>}
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
				return 'This portal is for members.';
			}
			return 'Your profile does not match this portal’s requirements.';
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
