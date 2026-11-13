<?php
/**
 * Full-page DragonGate portal setup (create / edit).
 *
 * Replaces the WordPress post editor for the portal CPT.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Setup_Screen' ) ) {

	/**
	 * Dedicated admin product surface for portal authoring.
	 */
	class Portal_Setup_Screen {

		const PAGE_SLUG                = 'dg-portal-setup';
		const SUBMENU_LABEL_MAX        = 36;
		const DEFAULT_NEW_PORTAL_TITLE = 'New portal';
		const AUTO_DRAFT_TITLE         = 'Auto Draft';

		/**
		 * Hook redirects, menu, and assets.
		 */
		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
			add_action( 'admin_menu', array( __CLASS__, 'hide_setup_submenu_item' ), 999 );
			add_action( 'admin_menu', array( __CLASS__, 'insert_current_portal_submenu' ), 1000 );
			add_action( 'admin_init', array( __CLASS__, 'redirect_post_screens' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybe_create_draft' ) );
			add_filter( 'get_edit_post_link', array( __CLASS__, 'filter_edit_link' ), 10, 3 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
			add_action( 'admin_head', array( __CLASS__, 'hide_admin_notices' ) );
			add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
			// Keep Portals top-level open and highlight the right child on setup.
			add_filter( 'parent_file', array( __CLASS__, 'filter_parent_file' ) );
			add_filter( 'submenu_file', array( __CLASS__, 'filter_submenu_file' ) );
		}

		/**
		 * CPT menu slug (Portals in the admin sidebar).
		 *
		 * @return string
		 */
		public static function parent_menu_slug() {
			return 'edit.php?post_type=portal';
		}

		/**
		 * When setup is open, expand the Portals top-level menu.
		 *
		 * @param string $parent_file Current parent file.
		 * @return string
		 */
		public static function filter_parent_file( $parent_file ) {
			if ( self::is_setup_screen() ) {
				return self::parent_menu_slug();
			}
			return $parent_file;
		}

		/**
		 * Empty Add New draft (default title, no definition) vs an existing portal.
		 *
		 * @param string $post_status    Post status.
		 * @param string $post_title     Raw post title.
		 * @param bool   $has_definition Whether a definition document exists.
		 * @return bool
		 */
		public static function is_fresh_add_new_draft( $post_status, $post_title, $has_definition ) {
			if ( $has_definition ) {
				return false;
			}
			if ( 'draft' !== $post_status ) {
				return false;
			}
			$title = trim( (string) $post_title );
			return in_array( $title, array( '', self::DEFAULT_NEW_PORTAL_TITLE, self::AUTO_DRAFT_TITLE ), true );
		}

		/**
		 * Unique submenu slug for the portal currently being edited.
		 *
		 * @param int $portal_id Portal post ID.
		 * @return string
		 */
		public static function current_portal_submenu_slug( $portal_id ) {
			return self::PAGE_SLUG . '&portal_id=' . (int) $portal_id;
		}

		/**
		 * Which Portals child is current on the setup screen.
		 *
		 * Fresh empty drafts → Add New. Existing portals → this portal's row,
		 * never the All Portals list slug.
		 *
		 * @param int    $portal_id      Portal post ID.
		 * @param string $post_status    Post status.
		 * @param string $post_title     Raw post title.
		 * @param bool   $has_definition Whether a definition document exists.
		 * @return string
		 */
		public static function setup_submenu_file( $portal_id, $post_status, $post_title, $has_definition ) {
			if ( $portal_id < 1 || self::is_fresh_add_new_draft( $post_status, $post_title, $has_definition ) ) {
				return self::PAGE_SLUG;
			}
			return self::current_portal_submenu_slug( $portal_id );
		}

		/**
		 * @param string $title Portal title.
		 * @return string
		 */
		public static function excerpt_submenu_label( $title ) {
			$title = trim( (string) $title );
			if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
				if ( mb_strlen( $title ) > self::SUBMENU_LABEL_MAX ) {
					return mb_substr( $title, 0, self::SUBMENU_LABEL_MAX ) . '…';
				}
				return $title;
			}
			if ( strlen( $title ) > self::SUBMENU_LABEL_MAX ) {
				return substr( $title, 0, self::SUBMENU_LABEL_MAX ) . '…';
			}
			return $title;
		}

		/**
		 * Highlight Add New or the named portal row — never All Portals while editing.
		 *
		 * @param string $submenu_file Current submenu file.
		 * @return string
		 */
		public static function filter_submenu_file( $submenu_file ) {
			if ( ! self::is_setup_screen() ) {
				return $submenu_file;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$portal_id = isset( $_GET['portal_id'] ) ? (int) $_GET['portal_id'] : 0;
			$post      = $portal_id > 0 ? get_post( $portal_id ) : null;
			if ( ! $post || 'portal' !== $post->post_type ) {
				return self::PAGE_SLUG;
			}

			$def = class_exists( 'Portal_Definition' )
				? Portal_Definition::load_for_post( $portal_id )
				: null;

			return self::setup_submenu_file(
				$portal_id,
				(string) $post->post_status,
				(string) $post->post_title,
				null !== $def
			);
		}

		/**
		 * Setup-only: name the portal being edited under All Portals.
		 */
		public static function insert_current_portal_submenu() {
			if ( ! self::is_setup_screen() ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$portal_id = isset( $_GET['portal_id'] ) ? (int) $_GET['portal_id'] : 0;
			$post      = $portal_id > 0 ? get_post( $portal_id ) : null;
			if ( ! $post || 'portal' !== $post->post_type ) {
				return;
			}

			$def = class_exists( 'Portal_Definition' )
				? Portal_Definition::load_for_post( $portal_id )
				: null;
			if ( self::is_fresh_add_new_draft( (string) $post->post_status, (string) $post->post_title, null !== $def ) ) {
				return;
			}

			global $submenu;
			$parent = self::parent_menu_slug();
			if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
				return;
			}

			$slug  = self::current_portal_submenu_slug( $portal_id );
			$label = self::excerpt_submenu_label(
				get_the_title( $portal_id ) ? get_the_title( $portal_id ) : $post->post_title
			);
			if ( '' === $label ) {
				$label = __( 'Portal', 'dragongate-portals' );
			}

			$row      = array( $label, 'edit_posts', $slug );
			$inserted = false;
			$out      = array();
			foreach ( $submenu[ $parent ] as $item ) {
				$out[] = $item;
				if ( ! $inserted && isset( $item[2] ) && $parent === $item[2] ) {
					$out[]     = $row;
					$inserted = true;
				}
			}
			if ( ! $inserted ) {
				array_splice( $out, 1, 0, array( $row ) );
			}
			$submenu[ $parent ] = $out;
		}

		/**
		 * @param string $classes Admin body classes.
		 * @return string
		 */
		public static function body_class( $classes ) {
			if ( self::is_setup_screen() ) {
				$classes .= ' dg-portal-setup';
			}
			return $classes;
		}

		/**
		 * Setup URL for a portal id (0 = create flow).
		 *
		 * @param int $portal_id Portal post ID.
		 * @return string
		 */
		public static function url( $portal_id = 0 ) {
			$args = array( 'page' => self::PAGE_SLUG );
			if ( $portal_id > 0 ) {
				$args['portal_id'] = (int) $portal_id;
			}
			return add_query_arg( $args, admin_url( 'admin.php' ) );
		}

		/**
		 * Register setup under the Portals CPT menu (so WP can highlight it).
		 */
		public static function register_menu() {
			add_submenu_page(
				self::parent_menu_slug(),
				__( 'Portal setup', 'dragongate-portals' ),
				__( 'Portal setup', 'dragongate-portals' ),
				'edit_posts',
				self::PAGE_SLUG,
				array( __CLASS__, 'render' )
			);

			// Point “Add New Portal” at our screen (replace default post-new link).
			global $submenu;
			$parent = self::parent_menu_slug();
			if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
				return;
			}
			foreach ( $submenu[ $parent ] as $i => $item ) {
				if ( empty( $item[2] ) ) {
					continue;
				}
				if ( 'post-new.php?post_type=portal' === $item[2] ) {
					// Keep a stable submenu slug WP can match for current-menu highlighting.
					$submenu[ $parent ][ $i ][2] = self::PAGE_SLUG;
					$submenu[ $parent ][ $i ][0] = __( 'Add New Portal', 'dragongate-portals' );
				}
			}
		}

		/**
		 * Drop the duplicate "Portal setup" row only — keep Add New (same page slug).
		 */
		public static function hide_setup_submenu_item() {
			global $submenu;
			$parent = self::parent_menu_slug();
			if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
				return;
			}
			foreach ( $submenu[ $parent ] as $i => $item ) {
				// Remove the auto-added setup row; leave "Add New Portal" which also uses PAGE_SLUG.
				if ( isset( $item[2], $item[0] ) && self::PAGE_SLUG === $item[2] ) {
					$label = wp_strip_all_tags( (string) $item[0] );
					if ( __( 'Portal setup', 'dragongate-portals' ) === $label || 'Portal setup' === $label ) {
						unset( $submenu[ $parent ][ $i ] );
					}
				}
			}
			// Re-index so WP menu walker stays happy.
			$submenu[ $parent ] = array_values( $submenu[ $parent ] );
		}

		/**
		 * Send CPT edit / new screens to the product setup page.
		 */
		public static function redirect_post_screens() {
			global $pagenow;

			if ( ! is_admin() || ! current_user_can( 'edit_posts' ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';

			if ( 'post-new.php' === $pagenow && 'portal' === $post_type ) {
				wp_safe_redirect( self::url( 0 ) );
				exit;
			}

			if ( 'post.php' === $pagenow ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
				if ( $post_id > 0 && 'edit' === $action && 'portal' === get_post_type( $post_id ) ) {
					wp_safe_redirect( self::url( $post_id ) );
					exit;
				}
			}
		}

		/**
		 * Ensure setup has a portal id in this same request (no second admin boot).
		 *
		 * Reuses this user's newest empty draft so a refresh of Add New does not
		 * mint another post.
		 */
		public static function maybe_create_draft() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $_GET['portal_id'] ) ) {
				return;
			}
			if ( ! current_user_can( 'edit_posts' ) ) {
				return;
			}

			$portal_id = self::reuse_or_create_empty_draft();
			if ( $portal_id < 1 ) {
				return;
			}

			// Same request as render() — JS replaceState adds portal_id to the URL.
			$_GET['portal_id'] = $portal_id;
		}

		/**
		 * Newest unused draft for this user, or a new one.
		 *
		 * @return int Portal post ID, or 0.
		 */
		private static function reuse_or_create_empty_draft() {
			$existing = get_posts(
				array(
					'post_type'      => 'portal',
					'post_status'    => 'draft',
					'author'         => get_current_user_id(),
					'posts_per_page' => 8,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);
			foreach ( $existing as $post ) {
				if ( class_exists( 'Portal_Definition' ) && null !== Portal_Definition::load_for_post( $post->ID ) ) {
					continue;
				}
				return (int) $post->ID;
			}

			$created = wp_insert_post(
				array(
					'post_type'   => 'portal',
					'post_status' => 'draft',
					'post_title'  => __( 'New portal', 'dragongate-portals' ),
				),
				true
			);
			if ( is_wp_error( $created ) || ! $created ) {
				return 0;
			}
			return (int) $created;
		}

		/**
		 * Row actions and list “Edit” go to setup.
		 *
		 * @param string $link    Default edit link.
		 * @param int    $post_id Post ID.
		 * @param string $context Link context.
		 * @return string
		 */
		public static function filter_edit_link( $link, $post_id, $context ) {
			unset( $context );
			if ( 'portal' === get_post_type( $post_id ) ) {
				return self::url( (int) $post_id );
			}
			return $link;
		}

		/**
		 * @return bool
		 */
		public static function is_setup_screen() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return is_admin() && isset( $_GET['page'] ) && self::PAGE_SLUG === $_GET['page'];
		}

		/**
		 * Enqueue wizard assets only on setup screen.
		 *
		 * @param string $hook_suffix Admin page hook.
		 */
		public static function enqueue_assets( $hook_suffix = '' ) {
			unset( $hook_suffix );
			if ( ! self::is_setup_screen() ) {
				return;
			}

			$plugin_file = dirname( __DIR__ ) . '/portal-builder.php';

			$asset_ver = static function ( $rel ) use ( $plugin_file ) {
				$path = dirname( $plugin_file ) . '/' . $rel;
				return is_readable( $path ) ? (string) filemtime( $path ) : PB_VERSION;
			};

			wp_enqueue_style(
				'dg-tokens',
				plugins_url( 'src/tokens.css', $plugin_file ),
				array(),
				$asset_ver( 'src/tokens.css' )
			);
			wp_enqueue_style(
				'dragongate-portal-css',
				plugins_url( 'assets/dist/dragongate-portal.css', $plugin_file ),
				array( 'dg-tokens' ),
				$asset_ver( 'assets/dist/dragongate-portal.css' )
			);
			wp_enqueue_style(
				'dg-portal-admin',
				plugins_url( 'assets/portal-admin.css', $plugin_file ),
				array( 'dragongate-portal-css' ),
				$asset_ver( 'assets/portal-admin.css' )
			);
			wp_enqueue_script(
				'dragongate-portal-js',
				plugins_url( 'assets/dist/dragongate-portal.js', $plugin_file ),
				array(),
				$asset_ver( 'assets/dist/dragongate-portal.js' ),
				true
			);
			add_filter(
				'script_loader_tag',
				static function ( $tag, $handle ) {
					if ( 'dragongate-portal-js' === $handle ) {
						return str_replace( '<script ', '<script type="module" ', $tag );
					}
					return $tag;
				},
				10,
				2
			);
		}

		/**
		 * Suppress third-party admin notices on the product surface.
		 */
		public static function hide_admin_notices() {
			if ( ! self::is_setup_screen() ) {
				return;
			}
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
		}

		/**
		 * Render full-page setup app.
		 */
		public static function render() {
			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_die( esc_html__( 'You do not have permission to set up portals.', 'dragongate-portals' ) );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$portal_id = isset( $_GET['portal_id'] ) ? (int) $_GET['portal_id'] : 0;
			$post      = $portal_id > 0 ? get_post( $portal_id ) : null;

			if ( ! $post || 'portal' !== $post->post_type ) {
				echo '<div class="wrap"><p>' . esc_html__( 'Portal not found.', 'dragongate-portals' ) . '</p></div>';
				return;
			}

			if ( ! current_user_can( 'edit_post', $portal_id ) ) {
				wp_die( esc_html__( 'You cannot edit this portal.', 'dragongate-portals' ) );
			}

			$title       = get_the_title( $portal_id );
			$rest_root   = esc_url_raw( rest_url( 'dragongate/v1' ) );
			$wp_rest     = esc_url_raw( rest_url() );
			$nonce       = wp_create_nonce( 'wp_rest' );
			$public_url  = get_permalink( $portal_id );
			$status      = get_post_status( $portal_id );
			$list_url    = admin_url( 'edit.php?post_type=portal' );
			$plugin_file = dirname( __DIR__ ) . '/portal-builder.php';
			$logo_file   = dirname( $plugin_file ) . '/assets/icon.svg';
			$logo_ver    = is_readable( $logo_file ) ? (string) filemtime( $logo_file ) : PB_VERSION;
			$logo_url    = esc_url_raw(
				add_query_arg( 'ver', $logo_ver, plugins_url( 'assets/icon.svg', $plugin_file ) )
			);
			$definition  = class_exists( 'Portal_Definition' )
				? Portal_Definition::load_for_post( $portal_id )
				: null;

			// No .wrap card — flat page: full-bleed header + open paper content.
			echo '<div class="dg-setup-page" data-dg-setup="1">';
			echo '<h1 class="screen-reader-text">' . esc_html__( 'Portal setup', 'dragongate-portals' ) . '</h1>';

			printf(
				'<div data-portal-wizard class="dg-wizard-root dg-wizard-root--product" data-portal-id="%1$d" data-rest-root="%2$s" data-rest-nonce="%3$s" data-portal-title="%4$s" data-wp-rest-root="%5$s" data-public-url="%6$s" data-post-status="%7$s" data-product-mode="1" data-list-url="%8$s" data-logo-url="%9$s">',
				(int) $portal_id,
				esc_attr( $rest_root ),
				esc_attr( $nonce ),
				esc_attr( $title ),
				esc_attr( $wp_rest ),
				esc_attr( $public_url ? $public_url : '' ),
				esc_attr( $status ? $status : 'draft' ),
				esc_attr( $list_url ),
				esc_attr( $logo_url )
			);
			echo '<script type="application/json" data-dg-definition>';
			echo wp_json_encode( $definition, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
			echo '</script>';
			$catalog = class_exists( 'Portal_Access' ) ? Portal_Access::catalog() : array(
				'membershipPlans' => array(),
				'profileFields'   => array(),
			);
			echo '<script type="application/json" data-dg-access-catalog>';
			echo wp_json_encode( $catalog, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
			echo '</script>';
			$site_defaults = class_exists( 'Portal_Site_Defaults' )
				? Portal_Site_Defaults::for_wizard()
				: array();
			echo '<script type="application/json" data-dg-site-defaults>';
			echo wp_json_encode( $site_defaults, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
			echo '</script>';
			echo '</div>';

			echo '</div>';
		}
	}

	Portal_Setup_Screen::init();
}
