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
		const MSG_RECEIPT_INVALID = 'This receipt link is invalid or has expired.';

		const PUBLIC_FONTS_URL = 'https://fonts.bunny.net/css?family=fraunces:500,600,700|ibm-plex-mono:400,500|inter:400,500,600&display=swap';

		const CONTENT_FILTER_PRIORITY = 12;

		/**
		 * Wire early closed flag, the_content filter, and definition form assets.
		 */
		public static function init() {
			// Prime before template shortcodes (formstart) so chrome hides when closed.
			add_action( 'wp', array( __CLASS__, 'prime_request_flags' ) );
			add_filter( 'the_content', array( __CLASS__, 'filter_content' ), self::CONTENT_FILTER_PRIORITY );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
			add_filter( 'wp_resource_hints', array( __CLASS__, 'font_preconnect' ), 10, 2 );
		}

		/**
		 * Set PB_APPLICATION_DEADLINE_PASSED early when the form must not render.
		 */
		public static function prime_request_flags() {
			if ( ! is_singular( 'portal' ) ) {
				return;
			}
			$post_id = (int) get_queried_object_id();
			if ( $post_id <= 0 ) {
				return;
			}
			if ( ! Portal_Open_State::should_show_form( $post_id ) ) {
				if ( ! defined( 'PB_APPLICATION_DEADLINE_PASSED' ) ) {
					// Reused by formstart/formend shortcodes to hide form chrome.
					define( 'PB_APPLICATION_DEADLINE_PASSED', true );
				}
			}
		}

		/**
		 * Definition-first content filter with open/closed/preview behavior.
		 *
		 * @param string $content Post content.
		 * @return string
		 */
		public static function filter_content( $content ) {
			if ( ! is_singular( 'portal' ) || ! in_the_loop() || ! is_main_query() ) {
				return $content;
			}

			$post_id = (int) get_the_ID();
			if ( $post_id <= 0 ) {
				return $content;
			}

			// Definition pipeline success owns the content.
			if ( defined( 'DG_DEFINITION_SUBMIT_OK' ) && DG_DEFINITION_SUBMIT_OK ) {
				$success = Portal_Submission_Pipeline::last_success();
				if ( is_array( $success ) ) {
					return self::wrap_packet(
						self::render_packet_head( get_the_title( $post_id ) )
						. Portal_Submission_Pipeline::render_success( $success )
					);
				}
			}

			// Lightweight receipt view from email link (query arg).
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['dg-receipt'] ) ) {
				return self::render_receipt_view( $post_id );
			}

			// Legacy submission success / review flows own the content.
			if ( defined( 'PB_APPLICATION_SUBMITTED' ) && PB_APPLICATION_SUBMITTED ) {
				return $content;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- review step reuses shortcode body.
			if ( isset( $_POST['review_nonce'] ) ) {
				return $content;
			}

			$is_preview = Portal_Open_State::is_preview_request( $post_id );
			$show_form  = Portal_Open_State::should_show_form( $post_id );

			if ( ! $show_form ) {
				if ( ! defined( 'PB_APPLICATION_DEADLINE_PASSED' ) ) {
					// Reused by formstart/formend shortcodes to hide form chrome.
					define( 'PB_APPLICATION_DEADLINE_PASSED', true );
				}
				return self::render_closed_message( $post_id );
			}

			$definition = Portal_Definition::load_for_post( $post_id );
			if ( is_array( $definition ) && ! $is_preview && class_exists( 'Portal_Access' ) ) {
				$access   = isset( $definition['access'] ) && is_array( $definition['access'] )
					? $definition['access']
					: array();
				$options  = isset( $definition['options'] ) && is_array( $definition['options'] )
					? $definition['options']
					: array();
				$decision = Portal_Access::decide( $access, $options, Portal_Access::current_applicant() );
				if ( empty( $decision['allowed'] ) ) {
					return self::render_restricted_message( $post_id, $definition, $decision );
				}
			}

			$banner = $is_preview ? self::render_preview_banner() : '';

			if ( is_array( $definition ) ) {
				$errors_html = '';
				if ( defined( 'DG_DEFINITION_SUBMIT_ERRORS' ) && DG_DEFINITION_SUBMIT_ERRORS ) {
					$errs = Portal_Submission_Pipeline::last_errors();
					if ( is_array( $errs ) && ! empty( $errs ) ) {
						$errors_html = Portal_Submission_Pipeline::render_errors( $errs );
					}
				}
				$title = get_the_title( $post_id );
				$site  = class_exists( 'Portal_Site_Defaults' )
					? Portal_Site_Defaults::read_site()
					: array();
				return self::wrap_packet(
					$banner
					. self::render_packet_head( $title )
					. self::render_packet_meta( $definition )
					. $errors_html
					. Portal_Definition_Renderer::render( $definition, $site )
				);
			}

			return $banner . self::wrap_legacy( $content );
		}

		/**
		 * Minimal receipt page for definition submissions (email link target).
		 *
		 * @param int $post_id Portal ID.
		 * @return string
		 */
		public static function render_receipt_view( $post_id ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$app_id = isset( $_GET['app'] ) ? sanitize_text_field( wp_unslash( $_GET['app'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$token = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';

			$record = class_exists( 'Portal_Receipt' )
				? Portal_Receipt::load( $app_id, $token )
				: null;

			if ( ! is_array( $record ) ) {
				$missing = sprintf(
					'<div class="dg-receipt" data-dg-receipt="missing"><p>%s</p></div>',
					esc_html__( 'This receipt link is invalid or has expired.', 'dragongate-portals' )
				);
				return self::wrap_packet(
					self::render_packet_head( get_the_title( $post_id ) ) . $missing
				);
			}

			$stored_id   = isset( $record['applicationId'] ) ? (string) $record['applicationId'] : $app_id;
			$stored_name = isset( $record['applicantName'] ) ? (string) $record['applicantName'] : '';
			$stored_mail = isset( $record['email'] ) ? (string) $record['email'] : '';
			$portal      = isset( $record['portalTitle'] ) ? (string) $record['portalTitle'] : get_the_title( $post_id );

			$receipt = sprintf(
				'<div class="dg-receipt" data-dg-receipt="1" data-dg-app-id="%1$s"><h2>%2$s</h2><p>Application ID: <code>%1$s</code></p><p>Portal: %3$s</p>%4$s%5$s</div>',
				esc_attr( $stored_id ),
				esc_html__( 'Application receipt', 'dragongate-portals' ),
				esc_html( $portal ),
				$stored_name ? '<p>Name: ' . esc_html( $stored_name ) . '</p>' : '',
				$stored_mail ? '<p>Email: ' . esc_html( $stored_mail ) . '</p>' : ''
			);

			return self::wrap_packet(
				self::render_packet_head( get_the_title( $post_id ) ) . $receipt
			);
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

			$closed = sprintf(
				'<div class="dg-portal-closed" data-dg-portal-state="closed" data-dg-closed-reason="%s"><p>%s</p></div>',
				esc_attr( (string) $reason ),
				esc_html( $message )
			);

			$definition = class_exists( 'Portal_Definition' )
				? Portal_Definition::load_for_post( $post_id )
				: null;

			return self::wrap_packet(
				self::render_packet_head( get_the_title( $post_id ) )
				. self::render_packet_meta( $definition )
				. $closed
			);
		}

		/**
		 * Applicant does not meet access rules (login / membership / profile).
		 *
		 * @param int   $post_id    Portal ID.
		 * @param array $definition Validated definition.
		 * @param array $decision   Portal_Access::decide result.
		 * @return string
		 */
		public static function render_restricted_message( $post_id, array $definition, array $decision ) {
			$access  = isset( $definition['access'] ) && is_array( $definition['access'] )
				? $definition['access']
				: array();
			$reason  = isset( $decision['reason'] ) ? (string) $decision['reason'] : 'profile';
			$message = class_exists( 'Portal_Access' )
				? Portal_Access::message_for( $access, $reason )
				: 'You cannot apply to this portal.';
			$login   = '';
			if ( 'login' === $reason && function_exists( 'wp_login_url' ) ) {
				$target = get_permalink( $post_id );
				$login  = sprintf(
					' <a href="%s">%s</a>',
					esc_url( wp_login_url( $target ? $target : '' ) ),
					esc_html__( 'Sign in', 'dragongate-portals' )
				);
			}
			$body = sprintf(
				'<div class="dg-portal-closed" data-dg-portal-state="restricted" data-dg-closed-reason="%s"><p>%s%s</p></div>',
				esc_attr( $reason ),
				esc_html( $message ),
				$login
			);
			return self::wrap_packet(
				self::render_packet_head( get_the_title( $post_id ) )
				. self::render_packet_meta( $definition )
				. $body
			);
		}

		/**
		 * Preview banner for editors.
		 *
		 * @return string
		 */
		public static function render_preview_banner() {
			return sprintf(
				'<div class="dg-preview-banner" data-dg-preview="true" role="status"><strong>%s</strong></div>',
				esc_html( self::PREVIEW_BANNER_TEXT )
			);
		}

		/**
		 * Whether the definition path should emit formstart, submit, and privacy.
		 *
		 * @param int $post_id Portal post ID.
		 * @return bool
		 */
		public static function should_show_form_actions( $post_id ) {
			if ( defined( 'PB_APPLICATION_DEADLINE_PASSED' ) && PB_APPLICATION_DEADLINE_PASSED ) {
				return false;
			}
			if ( defined( 'DG_DEFINITION_SUBMIT_OK' ) && DG_DEFINITION_SUBMIT_OK ) {
				return false;
			}
			if ( defined( 'PB_APPLICATION_SUBMITTED' ) && PB_APPLICATION_SUBMITTED ) {
				return false;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- receipt view flag.
			if ( isset( $_GET['dg-receipt'] ) ) {
				return false;
			}
			if ( ! class_exists( 'Portal_Open_State' ) ) {
				return false;
			}
			if ( ! Portal_Open_State::should_show_form( (int) $post_id ) ) {
				return false;
			}
			if ( Portal_Open_State::is_preview_request( (int) $post_id ) ) {
				return true;
			}
			if ( ! class_exists( 'Portal_Access' ) || ! class_exists( 'Portal_Definition' ) ) {
				return true;
			}
			$definition = Portal_Definition::load_for_post( (int) $post_id );
			if ( ! is_array( $definition ) ) {
				return true;
			}
			$access   = isset( $definition['access'] ) && is_array( $definition['access'] )
				? $definition['access']
				: array();
			$options  = isset( $definition['options'] ) && is_array( $definition['options'] )
				? $definition['options']
				: array();
			$decision = Portal_Access::decide( $access, $options, Portal_Access::current_applicant() );
			return ! empty( $decision['allowed'] );
		}

		/**
		 * Primary submit control. Name and label are public contracts.
		 *
		 * @return string
		 */
		public static function render_submit_control() {
			return sprintf(
				'<p class="dg-actions sub_submit_container"><button class="dg-public-submit" name="sub_submit" type="submit">%s</button></p>',
				esc_html__( 'Submit application', 'dragongate-portals' )
			);
		}

		/**
		 * Privacy lock line under submit.
		 *
		 * @return string
		 */
		public static function render_privacy_line() {
			$lock = '<svg class="dg-privacy-lock" width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><rect x="3" y="6.2" width="8" height="6" rx="1.1" stroke="currentColor" stroke-width="1.2"/><path d="M4.6 6.2V4.5a2.4 2.4 0 0 1 4.8 0v1.7" stroke="currentColor" stroke-width="1.2"/></svg>';
			return sprintf(
				'<p class="dg-privacy">%1$s<span>%2$s</span></p>',
				$lock,
				esc_html__( 'Your information is used to process this application. Files and answers go to the host’s Google Drive and Sheets. We do not sell your data.', 'dragongate-portals' )
			);
		}

		/**
		 * Seal + Application eyebrow + title. Used for open, closed, and receipt.
		 *
		 * @param string $title Portal title.
		 * @return string
		 */
		public static function render_packet_head( $title ) {
			$seal = '<svg class="dg-seal" viewBox="0 0 80 80" fill="none" aria-hidden="true"><circle cx="40" cy="40" r="37.2" stroke="currentColor" stroke-width="1.15"/><circle cx="40" cy="40" r="33.4" stroke="currentColor" stroke-width="0.6" opacity="0.45"/><path d="M26 30.5c0-8.4 6.2-13.6 14-13.6s14 5.2 14 13.6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><rect x="25.4" y="28" width="5.2" height="30" rx="0.6" fill="currentColor"/><rect x="49.4" y="28" width="5.2" height="30" rx="0.6" fill="currentColor"/><path d="M40 31.2c3.4 5.1 6.1 8.4 6.1 13.1 0 3.9-2.6 6.7-6.1 6.7s-6.1-2.8-6.1-6.7c0-4.7 2.7-8 6.1-13.1Z" fill="currentColor"/></svg>';
			$rule = '<div class="dg-rule-ornament" aria-hidden="true"><svg width="10" height="10" viewBox="0 0 10 10"><path d="M5 0.6 9.2 5 5 9.4 0.8 5Z" fill="currentColor"/></svg></div>';

			return sprintf(
				'<header class="dg-packet-head dg-public-application-head">%1$s<div><p class="dg-public-eyebrow">%2$s</p><h1 class="dg-packet-title">%3$s</h1></div></header>%4$s',
				$seal,
				esc_html__( 'Application', 'dragongate-portals' ),
				esc_html( $title ),
				$rule
			);
		}

		/**
		 * Scored meta row from definition publish/options. Omits empty items.
		 *
		 * @param array|null $definition Definition document or null.
		 * @return string
		 */
		public static function render_packet_meta( $definition ) {
			if ( ! is_array( $definition ) ) {
				return '';
			}

			$items   = array();
			$publish = isset( $definition['publish'] ) && is_array( $definition['publish'] ) ? $definition['publish'] : array();
			$options = isset( $definition['options'] ) && is_array( $definition['options'] ) ? $definition['options'] : array();

			$deadline_item = self::meta_deadline_item( $publish );
			if ( '' !== $deadline_item ) {
				$items[] = $deadline_item;
			}

			$fee_item = self::meta_fee_item( $publish, $options );
			if ( '' !== $fee_item ) {
				$items[] = $fee_item;
			}

			$guidelines = isset( $options['guidelinesUrl'] ) ? (string) $options['guidelinesUrl'] : '';
			if ( '' !== $guidelines ) {
				$items[] = sprintf(
					'<a class="dg-meta-link" href="%1$s">%2$s</a>',
					esc_url( $guidelines ),
					esc_html__( 'Read the call →', 'dragongate-portals' )
				);
			}

			if ( empty( $items ) ) {
				return '';
			}

			return '<div class="dg-meta">' . implode( '<span class="dg-meta-split" aria-hidden="true"></span>', $items ) . '</div>';
		}

		/**
		 * @param array $publish Publish block.
		 * @return string
		 */
		private static function meta_deadline_item( $publish ) {
			$deadline = array_key_exists( 'deadline', $publish ) ? $publish['deadline'] : null;
			if ( null === $deadline || '' === $deadline ) {
				return '';
			}

			$timezone = isset( $publish['timezone'] ) && is_string( $publish['timezone'] ) && '' !== $publish['timezone']
				? $publish['timezone']
				: 'America/New_York';

			$formatted = self::format_deadline( $deadline, $timezone );
			if ( '' === $formatted ) {
				return '';
			}

			$when = sprintf(
				/* translators: %s: formatted deadline datetime */
				esc_html__( 'Closes %s', 'dragongate-portals' ),
				$formatted
			);

			$icon = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="2" y="3.2" width="12" height="10.3" rx="1.4" stroke="currentColor" stroke-width="1.2"/><path d="M2 6.4h12M5.2 2.2v2.2M10.8 2.2v2.2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>';

			return sprintf(
				'<div class="dg-meta-item">%1$s<div><span class="dg-meta-when">%2$s</span><span class="dg-meta-tz">%3$s</span></div></div>',
				$icon,
				esc_html( $when ),
				esc_html( $timezone )
			);
		}

		/**
		 * @param array $publish Publish block.
		 * @param array $options Options block.
		 * @return string
		 */
		private static function meta_fee_item( $publish, $options ) {
			$lines = array();
			$fee   = array_key_exists( 'applicationFee', $publish ) ? $publish['applicationFee'] : null;
			if ( null !== $fee && '' !== $fee ) {
				$lines[] = sprintf(
					/* translators: %s: fee amount */
					esc_html__( 'Application fee $%s', 'dragongate-portals' ),
					$fee
				);
			}
			if ( ! empty( $options['freeForMembers'] ) ) {
				$lines[] = esc_html__( 'Free for members', 'dragongate-portals' );
			}
			if ( empty( $lines ) ) {
				return '';
			}

			$icon = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3.2 4.2h6.4c.9 0 1.4.4 1.8 1.1L14 10.2c.3.6 0 1.3-.7 1.3H6.4c-.8 0-1.3-.4-1.7-1.1L2.5 5.5c-.3-.6 0-1.3.7-1.3Z" stroke="currentColor" stroke-width="1.2"/></svg>';

			return sprintf(
				'<div class="dg-meta-item">%1$s<div>%2$s</div></div>',
				$icon,
				implode( '<br />', array_map( 'esc_html', $lines ) )
			);
		}

		/**
		 * @param mixed  $deadline Deadline string.
		 * @param string $timezone IANA timezone.
		 * @return string Empty when unparseable.
		 */
		private static function format_deadline( $deadline, $timezone ) {
			try {
				$tz = new DateTimeZone( $timezone );
			} catch ( Exception $e ) {
				$tz = new DateTimeZone( 'America/New_York' );
			}
			try {
				$dt = new DateTime( (string) $deadline, $tz );
			} catch ( Exception $e ) {
				return '';
			}
			return $dt->format( 'j F Y, g:i a' );
		}

		/**
		 * Paper packet wrapper. Owner of the card — templates may add a second
		 * .dg-packet; nested cards collapse via CSS.
		 *
		 * @param string $inner Packet contents.
		 * @return string
		 */
		public static function wrap_packet( $inner ) {
			return '<div class="dg-packet">' . $inner . '</div>';
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
			$plugin_file = dirname( __DIR__, 2 ) . '/portal-builder.php';
			$plugin_dir  = dirname( $plugin_file );

			wp_enqueue_style(
				'dg-public-fonts',
				self::PUBLIC_FONTS_URL,
				array(),
				null
			);
			wp_enqueue_style(
				'dg-tokens',
				plugins_url( 'assets/tokens.css', $plugin_file ),
				array(),
				self::asset_version( $plugin_dir . '/assets/tokens.css' )
			);
			wp_enqueue_style(
				'dg-definition-form',
				plugins_url( 'assets/definition-form.css', $plugin_file ),
				array( 'dg-public-fonts', 'dg-tokens', 'portal-styles' ),
				self::asset_version( $plugin_dir . '/assets/definition-form.css' )
			);
			wp_enqueue_script(
				'dg-definition-form',
				plugins_url( 'assets/definition-form.js', $plugin_file ),
				array(),
				self::asset_version( $plugin_dir . '/assets/definition-form.js' ),
				true
			);
		}

		/**
		 * Filemtime so a deploy is visible without bumping PB_VERSION.
		 *
		 * @param string $path Absolute path.
		 * @return string
		 */
		private static function asset_version( $path ) {
			if ( is_readable( $path ) ) {
				return (string) filemtime( $path );
			}
			return PB_VERSION;
		}

		/**
		 * Preconnect Bunny fonts on the singular portal only.
		 *
		 * @param array  $urls          URLs for the hint.
		 * @param string $relation_type Hint relation.
		 * @return array
		 */
		public static function font_preconnect( $urls, $relation_type ) {
			if ( 'preconnect' !== $relation_type || ! is_singular( 'portal' ) ) {
				return $urls;
			}
			$urls[] = array(
				'href'        => 'https://fonts.bunny.net',
				'crossorigin' => 'anonymous',
			);
			return $urls;
		}
	}

	Portal_Public_Render::init();
}
