<?php
/**
 * Public portal content: definition-first form render + closed/preview gate.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Public_Render' ) ) {

	/**
	 * Filters the_content for singular portals.
	 */
	class Portal_Public_Render {

		const ATTR_RENDER_DEFINITION = 'definition';
		const ATTR_RENDER_LEGACY     = 'legacy';

		const PREVIEW_BANNER_TEXT = 'Preview — not a live submission';
		const MSG_DEADLINE        = 'The application deadline has passed.';
		const MSG_CLOSED          = 'This portal is closed.';
		const MSG_NOT_ACCEPTING   = 'This portal is not currently accepting applications.';

		const CONTENT_FILTER_PRIORITY = 9;

		/**
		 * Wire the_content filter + definition form assets.
		 */
		public static function init() {
			add_filter( 'the_content', array( __CLASS__, 'filter_content' ), self::CONTENT_FILTER_PRIORITY );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		}

		/**
		 * Definition-first content filter with open/closed/preview behavior.
		 *
		 * @param string $content Post content.
		 * @return string
		 */
		public static function filter_content( $content ) {
			if ( ! is_singular( 'portal' ) ) {
				return $content;
			}

			$post_id = (int) get_the_ID();
			if ( $post_id <= 0 ) {
				return $content;
			}

			// Submission success / review flows own the content.
			if ( defined( 'PB_APPLICATION_SUBMITTED' ) && PB_APPLICATION_SUBMITTED ) {
				return $content;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- review step reuses shortcode body.
			if ( isset( $_POST['review_nonce'] ) ) {
				return $content;
			}

			$is_preview = Portal_Open_State::is_preview_request( $post_id );
			$is_open    = Portal_Open_State::is_open( $post_id );
			$show_form  = $is_open || $is_preview;

			if ( ! $show_form ) {
				if ( ! defined( 'PB_APPLICATION_DEADLINE_PASSED' ) ) {
					// Reused by formstart/formend shortcodes to hide form chrome.
					define( 'PB_APPLICATION_DEADLINE_PASSED', true );
				}
				return self::render_closed_message( $post_id );
			}

			$banner     = $is_preview ? self::render_preview_banner() : '';
			$definition = Portal_Definition::load_for_post( $post_id );

			if ( is_array( $definition ) ) {
				return $banner . Portal_Definition_Renderer::render( $definition );
			}

			return $banner . self::wrap_legacy( $content );
		}

		/**
		 * Closed message (no form fields).
		 *
		 * @param int $post_id Portal ID.
		 * @return string
		 */
		public static function render_closed_message( $post_id ) {
			$reason  = Portal_Open_State::closed_reason( $post_id );
			$message = self::MSG_NOT_ACCEPTING;
			if ( 'deadline' === $reason ) {
				$message = self::MSG_DEADLINE;
			} elseif ( 'force' === $reason ) {
				$message = self::MSG_CLOSED;
			}

			return sprintf(
				'<div class="dg-portal-closed" data-dg-portal-state="closed" data-dg-closed-reason="%s"><p>%s</p></div>',
				esc_attr( (string) $reason ),
				esc_html( $message )
			);
		}

		/**
		 * Preview banner for editors.
		 *
		 * @return string
		 */
		public static function render_preview_banner() {
			return sprintf(
				'<div class="dg-preview-banner" data-dg-preview="true" role="status">%s</div>',
				esc_html( self::PREVIEW_BANNER_TEXT )
			);
		}

		/**
		 * Wrap shortcode-era content with legacy render marker.
		 *
		 * @param string $content Original content.
		 * @return string
		 */
		public static function wrap_legacy( $content ) {
			return sprintf(
				'<div class="dg-portal-form" data-dg-render="%s">%s</div>',
				esc_attr( self::ATTR_RENDER_LEGACY ),
				$content
			);
		}

		/**
		 * Enqueue tokens + definition form styles when a definition is present.
		 */
		public static function enqueue_assets() {
			if ( ! is_singular( 'portal' ) ) {
				return;
			}
			$post_id = (int) get_the_ID();
			if ( null === Portal_Definition::load_for_post( $post_id ) ) {
				return;
			}

			$plugin_file = dirname( __DIR__, 2 ) . '/portal-builder.php';

			wp_enqueue_style(
				'dg-tokens',
				plugins_url( 'src/tokens.css', $plugin_file ),
				array(),
				PB_VERSION
			);
			wp_enqueue_style(
				'dg-definition-form',
				plugins_url( 'assets/definition-form.css', $plugin_file ),
				array( 'dg-tokens', 'portal-styles' ),
				PB_VERSION
			);
		}
	}

	Portal_Public_Render::init();
}
