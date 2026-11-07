<?php
/**
 * View-as: staff permission, persona overlay, admin-bar, query parse.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_View_As' ) ) {

	/**
	 * Overlay the applicant snapshot for staff preview. View only.
	 */
	class Portal_View_As {

		const QUERY_KEY     = 'dg_view_as';
		const OPTION_ROLES  = 'dg_view_as_roles';
		const DEFAULT_ROLE  = 'administrator';
		const PERSONA_OUT   = 'logged_out';
		const PERSONA_IN    = 'logged_in';
		const PREFIX_PLAN   = 'plan:';
		const PREFIX_ROLE   = 'role:';
		const BAR_ID        = 'dg-view-as';
		const BAR_TITLE     = 'View Portal as';
		const LABEL_YOURS   = 'Your access';
		const LABEL_OUT     = 'Logged Out';
		const LABEL_IN      = 'Signed in, no membership';
		const BANNER_FORMAT = 'Viewing as %s. Submissions are off.';

		/**
		 * Wire the admin-bar dropdown.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'admin_bar_menu', array( __CLASS__, 'register_admin_bar' ), 80 );
		}

		/**
		 * Fold a query value to a known persona id, or null.
		 *
		 * @param mixed $raw Query value.
		 * @return string|null
		 */
		public static function parse_query( $raw ) {
			$value = strtolower( trim( (string) $raw ) );
			if ( '' === $value ) {
				return null;
			}
			if ( self::PERSONA_OUT === $value || self::PERSONA_IN === $value ) {
				return $value;
			}
			if ( 0 === strpos( $value, self::PREFIX_PLAN ) ) {
				$id = trim( substr( $value, strlen( self::PREFIX_PLAN ) ) );
				return '' !== $id ? self::PREFIX_PLAN . $id : null;
			}
			if ( 0 === strpos( $value, self::PREFIX_ROLE ) ) {
				$slug = trim( substr( $value, strlen( self::PREFIX_ROLE ) ) );
				return '' !== $slug ? self::PREFIX_ROLE . $slug : null;
			}
			return null;
		}

		/**
		 * Applicant snapshot for a persona. Unknown persona → null.
		 *
		 * @param mixed $persona Persona id.
		 * @return array{logged_in: bool, plan_ids: string[], meta: array, membership_provider: bool, roles: string[], capabilities: string[]}|null
		 */
		public static function persona_applicant( $persona ) {
			$id = self::parse_query( $persona );
			if ( null === $id ) {
				return null;
			}
			$empty = array(
				'logged_in'           => false,
				'plan_ids'            => array(),
				'meta'                => array(),
				'membership_provider' => false,
				'roles'               => array(),
				'capabilities'        => array(),
			);
			if ( self::PERSONA_OUT === $id ) {
				return $empty;
			}
			$empty['logged_in'] = true;
			if ( self::PERSONA_IN === $id ) {
				$empty['membership_provider'] = true;
				return $empty;
			}
			if ( 0 === strpos( $id, self::PREFIX_PLAN ) ) {
				$empty['plan_ids']            = array( substr( $id, strlen( self::PREFIX_PLAN ) ) );
				$empty['membership_provider'] = true;
				return $empty;
			}
			$empty['roles'] = array( substr( $id, strlen( self::PREFIX_ROLE ) ) );
			return $empty;
		}

		/**
		 * Empty list becomes administrator so a saved blank never opens the door.
		 *
		 * @param mixed $raw Role slugs.
		 * @return string[]
		 */
		public static function sanitize_roles( $raw ) {
			$roles = self::fold_roles( $raw );
			return empty( $roles ) ? array( self::DEFAULT_ROLE ) : $roles;
		}

		/**
		 * Portal override if set, else the site option (default administrator).
		 *
		 * @param int $portal_id Portal post id.
		 * @return string[]
		 */
		public static function allowed_roles( $portal_id ) {
			$override = self::definition_roles( $portal_id );
			if ( ! empty( $override ) ) {
				return $override;
			}
			$stored = function_exists( 'get_option' )
				? get_option( self::OPTION_ROLES, array() )
				: array();
			return self::sanitize_roles( $stored );
		}

		/**
		 * Logged in and holds at least one allowed role.
		 *
		 * @param int $portal_id Portal post id.
		 * @return bool
		 */
		public static function current_user_may( $portal_id ) {
			if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
				return false;
			}
			$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
			$held = ( is_object( $user ) && isset( $user->roles ) && is_array( $user->roles ) )
				? self::fold_roles( $user->roles )
				: array();
			return count( array_intersect( $held, self::allowed_roles( $portal_id ) ) ) > 0;
		}

		/**
		 * Capability-gated: an unprivileged query is not active.
		 *
		 * @param int $portal_id Portal post id.
		 * @return bool
		 */
		public static function is_active( $portal_id ) {
			return self::current_user_may( $portal_id ) && null !== self::requested_persona();
		}

		/**
		 * Overlay when active, else the real applicant.
		 *
		 * @param int $post_id Portal post id.
		 * @return array
		 */
		public static function applicant( $post_id ) {
			if ( self::is_active( $post_id ) ) {
				$overlay = self::persona_applicant( self::requested_persona() );
				if ( is_array( $overlay ) ) {
					return $overlay;
				}
			}
			return class_exists( 'Portal_Access' )
				? Portal_Access::current_applicant()
				: self::persona_applicant( self::PERSONA_OUT );
		}

		/**
		 * Admin-bar children, in display order.
		 *
		 * @param array $catalog Portal_Access::catalog() shape.
		 * @return array<int, array{id: string, label: string}>
		 */
		public static function persona_catalog( array $catalog ) {
			$items = array(
				array(
					'id'    => '',
					'label' => self::LABEL_YOURS,
				),
				array(
					'id'    => self::PERSONA_OUT,
					'label' => self::LABEL_OUT,
				),
				array(
					'id'    => self::PERSONA_IN,
					'label' => self::LABEL_IN,
				),
			);
			$plans = isset( $catalog['membershipPlans'] ) && is_array( $catalog['membershipPlans'] )
				? $catalog['membershipPlans']
				: array();
			if ( ! empty( $plans ) ) {
				foreach ( $plans as $plan ) {
					if ( ! is_array( $plan ) || empty( $plan['id'] ) ) {
						continue;
					}
					$name    = isset( $plan['name'] ) && '' !== (string) $plan['name']
						? (string) $plan['name']
						: (string) $plan['id'];
					$items[] = array(
						'id'    => self::PREFIX_PLAN . (string) $plan['id'],
						'label' => $name,
					);
				}
				return $items;
			}
			$roles = isset( $catalog['roles'] ) && is_array( $catalog['roles'] ) ? $catalog['roles'] : array();
			foreach ( $roles as $role ) {
				if ( ! is_array( $role ) || empty( $role['id'] ) ) {
					continue;
				}
				$name    = isset( $role['name'] ) && '' !== (string) $role['name']
					? (string) $role['name']
					: (string) $role['id'];
				$items[] = array(
					'id'    => self::PREFIX_ROLE . (string) $role['id'],
					'label' => $name,
				);
			}
			return $items;
		}

		/**
		 * Banner copy for the active persona.
		 *
		 * @param string $persona Persona id.
		 * @param array  $catalog Portal_Access::catalog() shape.
		 * @return string
		 */
		public static function banner_text( $persona, array $catalog = array() ) {
			$label = (string) $persona;
			foreach ( self::persona_catalog( $catalog ) as $item ) {
				if ( $item['id'] === $persona ) {
					$label = $item['label'];
					break;
				}
			}
			return sprintf( self::BANNER_FORMAT, $label );
		}

		/**
		 * Parsed query persona, or null when absent/unknown.
		 *
		 * @return string|null
		 */
		public static function requested_persona() {
			if ( ! isset( $_GET[ self::QUERY_KEY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return null;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = $_GET[ self::QUERY_KEY ];
			if ( function_exists( 'wp_unslash' ) ) {
				$raw = wp_unslash( $raw );
			}
			return self::parse_query( $raw );
		}

		/**
		 * Add View Portal as when the user may overlay this portal.
		 *
		 * @param mixed $wp_admin_bar WP_Admin_Bar.
		 * @return void
		 */
		public static function register_admin_bar( $wp_admin_bar ) {
			if ( ! is_object( $wp_admin_bar ) || ! method_exists( $wp_admin_bar, 'add_node' ) ) {
				return;
			}
			$portal_id = self::current_portal_id();
			if ( $portal_id <= 0 || ! self::current_user_may( $portal_id ) ) {
				return;
			}
			$catalog = class_exists( 'Portal_Access' ) ? Portal_Access::catalog() : array();
			$wp_admin_bar->add_node(
				array(
					'id'    => self::BAR_ID,
					'title' => self::BAR_TITLE,
					'href'  => false,
				)
			);
			foreach ( self::persona_catalog( $catalog ) as $item ) {
				$wp_admin_bar->add_node(
					array(
						'id'     => self::bar_child_id( $item['id'] ),
						'parent' => self::BAR_ID,
						'title'  => $item['label'],
						'href'   => self::persona_url( $item['id'] ),
					)
				);
			}
		}

		/**
		 * Front singular portal, or the portal being edited in admin.
		 *
		 * @return int
		 */
		public static function current_portal_id() {
			if ( function_exists( 'is_singular' ) && is_singular( 'portal' ) && function_exists( 'get_queried_object_id' ) ) {
				return (int) get_queried_object_id();
			}
			if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
				return 0;
			}
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! is_object( $screen ) || ! isset( $screen->post_type ) || 'portal' !== $screen->post_type ) {
				return 0;
			}
			if ( function_exists( 'get_the_ID' ) ) {
				$id = (int) get_the_ID();
				if ( $id > 0 ) {
					return $id;
				}
			}
			return isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		/**
		 * Per-portal viewAsRoles override, or empty to use the site option.
		 *
		 * @param int $portal_id Portal post id.
		 * @return string[]
		 */
		private static function definition_roles( $portal_id ) {
			if ( ! class_exists( 'Portal_Definition' ) ) {
				return array();
			}
			$definition = Portal_Definition::load_for_post( (int) $portal_id );
			if ( ! is_array( $definition ) ) {
				return array();
			}
			$access = isset( $definition['access'] ) && is_array( $definition['access'] )
				? $definition['access']
				: array();
			return self::fold_roles( isset( $access['viewAsRoles'] ) ? $access['viewAsRoles'] : array() );
		}

		/**
		 * Lowercase non-empty role slugs.
		 *
		 * @param mixed $raw Role slugs.
		 * @return string[]
		 */
		private static function fold_roles( $raw ) {
			if ( ! is_array( $raw ) ) {
				return array();
			}
			$out = array();
			foreach ( $raw as $role ) {
				$role = strtolower( trim( (string) $role ) );
				if ( '' !== $role ) {
					$out[] = $role;
				}
			}
			return array_values( array_unique( $out ) );
		}

		/**
		 * Stable admin-bar child id for a persona (or Your access).
		 *
		 * @param string $persona Persona id or empty for Your access.
		 * @return string
		 */
		private static function bar_child_id( $persona ) {
			$suffix = '' === $persona ? 'your-access' : str_replace( array( ':', '/' ), '-', $persona );
			return self::BAR_ID . '-' . $suffix;
		}

		/**
		 * Current URL with or without the view-as query.
		 *
		 * @param string $persona Persona id or empty to clear.
		 * @return string
		 */
		private static function persona_url( $persona ) {
			if ( '' === $persona ) {
				return function_exists( 'remove_query_arg' )
					? remove_query_arg( self::QUERY_KEY )
					: '';
			}
			if ( function_exists( 'add_query_arg' ) ) {
				return add_query_arg( self::QUERY_KEY, $persona );
			}
			return '?' . self::QUERY_KEY . '=' . rawurlencode( $persona );
		}
	}
}
