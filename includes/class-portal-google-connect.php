<?php
/**
 * Post-activate Google connect notice and Settings/About checklist.
 *
 * Activate records pending. The notice and checklist stay until
 * `dg_google_test_ok` is set. Only Portal_Google_Probe writes that option.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Google_Connect' ) ) {

	/**
	 * Connect-Google onboarding after activate.
	 */
	class Portal_Google_Connect {

		const PENDING_OPTION = 'dg_google_connect_pending';

		/**
		 * Same key as Portal_Google_Probe::SUCCESS_OPTION. This class never writes it.
		 */
		const TEST_OK_OPTION = 'dg_google_test_ok';

		const SCREEN_DASHBOARD = 'dashboard';
		const SCREEN_PLUGINS   = 'plugins';
		const SCREEN_PORTALS   = 'portals';
		const SCREEN_SETTINGS  = 'settings';
		const SCREEN_SETUP     = 'setup';
		const SCREEN_PUBLIC    = 'public';
		const SCREEN_ABOUT     = 'about';

		const NOTICE_SCREENS = array(
			self::SCREEN_DASHBOARD,
			self::SCREEN_PLUGINS,
			self::SCREEN_PORTALS,
			self::SCREEN_SETTINGS,
		);

		const CHECKLIST_SCREENS = array(
			self::SCREEN_ABOUT,
			self::SCREEN_SETTINGS,
		);

		const SETTINGS_PATH = 'edit.php?post_type=portal&page=portal-default-settings';

		/**
		 * Record that connect-Google is still pending. Does not touch the probe option.
		 *
		 * @return void
		 */
		public static function on_activate() {
			if ( function_exists( 'update_option' ) ) {
				update_option( self::PENDING_OPTION, '1' );
			}
		}

		/**
		 * Whether activate recorded connect-pending.
		 *
		 * @return bool
		 */
		public static function is_pending() {
			if ( ! function_exists( 'get_option' ) ) {
				return false;
			}
			return ! empty( get_option( self::PENDING_OPTION, '' ) );
		}

		/**
		 * Whether the probe wrote a successful test timestamp.
		 *
		 * @return bool
		 */
		public static function has_test_write() {
			if ( ! function_exists( 'get_option' ) ) {
				return false;
			}
			return '' !== trim( (string) get_option( self::TEST_OK_OPTION, '' ) );
		}

		/**
		 * Whether the persistent admin notice belongs on this screen.
		 *
		 * @param string $screen  Screen id from screen_from_request.
		 * @param bool   $pending Connect still pending after activate.
		 * @param bool   $test_ok Probe wrote dg_google_test_ok.
		 * @return bool
		 */
		public static function should_show_notice( $screen, $pending, $test_ok ) {
			if ( ! $pending || $test_ok ) {
				return false;
			}
			return in_array( (string) $screen, self::NOTICE_SCREENS, true );
		}

		/**
		 * Whether the in-page checklist belongs on About or Settings.
		 *
		 * @param string $screen  Screen id from screen_from_request.
		 * @param bool   $pending Connect still pending after activate.
		 * @param bool   $test_ok Probe wrote dg_google_test_ok.
		 * @return bool
		 */
		public static function should_show_checklist( $screen, $pending, $test_ok ) {
			if ( ! $pending || $test_ok ) {
				return false;
			}
			return in_array( (string) $screen, self::CHECKLIST_SCREENS, true );
		}

		/**
		 * Map a request to a screen id. Unknown admin screens return empty.
		 *
		 * @param bool   $is_admin Whether this is wp-admin.
		 * @param string $pagenow  Current admin file.
		 * @param array  $query    Request query args (page, post_type).
		 * @return string
		 */
		public static function screen_from_request( $is_admin, $pagenow, $query ) {
			if ( ! $is_admin ) {
				return self::SCREEN_PUBLIC;
			}
			$pagenow = (string) $pagenow;
			$query   = is_array( $query ) ? $query : array();
			$page    = isset( $query['page'] ) ? (string) $query['page'] : '';

			if ( 'dg-portal-setup' === $page ) {
				return self::SCREEN_SETUP;
			}
			if ( 'dgp-about' === $page ) {
				return self::SCREEN_ABOUT;
			}
			if ( 'portal-default-settings' === $page || 'portal-google-api-setup' === $page ) {
				return self::SCREEN_SETTINGS;
			}
			if ( 'index.php' === $pagenow ) {
				return self::SCREEN_DASHBOARD;
			}
			if ( 'plugins.php' === $pagenow ) {
				return self::SCREEN_PLUGINS;
			}
			$post_type = isset( $query['post_type'] ) ? (string) $query['post_type'] : '';
			if ( 'edit.php' === $pagenow && 'portal' === $post_type && '' === $page ) {
				return self::SCREEN_PORTALS;
			}
			return '';
		}

		/**
		 * Five connect steps. Copy is the checklist contract.
		 *
		 * @return string[]
		 */
		public static function checklist_items() {
			return array(
				__( 'Paste Google credentials (OAuth client JSON and access token).', 'dragongate-portals' ),
				__( 'Share folder/sheet with the Google identity that completed consent.', 'dragongate-portals' ),
				__( 'Run the Google connection test write.', 'dragongate-portals' ),
				__( 'Set the receipt From email.', 'dragongate-portals' ),
				__( 'Choose the public brand.', 'dragongate-portals' ),
			);
		}

		/**
		 * In-page checklist. Must not use the WP `.notice` class (About hides those).
		 *
		 * @return string
		 */
		public static function checklist_markup() {
			$html  = '<div id="dg-google-connect-checklist" class="dg-google-connect-checklist">';
			$html .= '<h2>' . esc_html__( 'Connect Google', 'dragongate-portals' ) . '</h2>';
			$html .= '<ol>';
			foreach ( self::checklist_items() as $item ) {
				$html .= '<li>' . esc_html( $item ) . '</li>';
			}
			$html .= '</ol></div>';
			return $html;
		}

		/**
		 * Persistent warning with a Settings link. Not dismissible.
		 *
		 * @return string
		 */
		public static function notice_markup() {
			$html  = '<div class="notice notice-warning dg-google-connect-notice">';
			$html .= '<p><strong>' . esc_html__( 'Connect Google to finish setup.', 'dragongate-portals' ) . '</strong> ';
			$html .= esc_html__( 'Finish connecting Google: credentials, share folder/sheet, test write, From email, and brand.', 'dragongate-portals' );
			$html .= ' <a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Open Default Settings', 'dragongate-portals' ) . '</a></p>';
			$html .= '</div>';
			return $html;
		}

		/**
		 * Default Settings URL.
		 *
		 * @return string
		 */
		public static function settings_url() {
			if ( function_exists( 'admin_url' ) ) {
				return admin_url( self::SETTINGS_PATH );
			}
			return self::SETTINGS_PATH;
		}

		/**
		 * Render the persistent admin notice when this screen still needs it.
		 *
		 * @return void
		 */
		public static function render_admin_notice() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen identity only.
			$request  = isset( $_GET ) && is_array( $_GET ) ? $_GET : array();
			$query    = function_exists( 'wp_unslash' ) ? wp_unslash( $request ) : $request;
			$pagenow  = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
			$is_admin = function_exists( 'is_admin' ) && is_admin();
			$screen   = self::screen_from_request( $is_admin, $pagenow, $query );
			if ( ! self::should_show_notice( $screen, self::is_pending(), self::has_test_write() ) ) {
				return;
			}
			echo self::notice_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in notice_markup.
		}

		/**
		 * Echo the in-page checklist when this screen still needs it.
		 *
		 * @param string $screen Screen id.
		 * @return void
		 */
		public static function render_checklist_if_needed( $screen ) {
			if ( ! self::should_show_checklist( $screen, self::is_pending(), self::has_test_write() ) ) {
				return;
			}
			echo self::checklist_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in checklist_markup.
		}
	}
}
