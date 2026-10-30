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

		const PREVIEW_BANNER_TEXT   = 'Preview — this is how applicants see the form. Submissions are off.';
		const PREVIEW_BANNER_OPEN   = 'Preview as open — this is how applicants see the form. Submissions are off.';
		const PREVIEW_BANNER_CLOSED = 'Preview as closed — this is how applicants see the portal once it has closed. Submissions are off.';
		const INVITE_BANNER_FORMAT  = 'You are submitting with an invitation. This invitation link stops working after %s';
		const INVITE_BANNER_NODATE  = 'You are submitting with an invitation. This invitation link stops working once it has expired.';
		const MSG_INVITE_INVALID    = 'This invitation link is no longer valid.';
		const MSG_DEADLINE          = 'The application deadline has passed.';
		const MSG_CLOSED            = 'This portal is closed.';
		const MSG_NOT_ACCEPTING     = 'This portal is not currently accepting applications.';
		const MSG_RECEIPT_INVALID   = 'This receipt link is invalid or has expired.';

		const ACCESS_EYEBROW            = 'Members only';
		const ACCESS_HEADING_LOGIN      = 'Sign in to apply.';
		const ACCESS_HEADING_MEMBERSHIP = 'This application is for paid members.';
		const ACCESS_SIGN_IN            = 'Sign in';
		const ACCESS_JOIN               = 'Join or renew';

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
		 * Prime closed/deadline flags early so leftover chrome stays hidden.
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
				self::define_closed_request_flags( $post_id );
			}
		}

		/**
		 * Hide form chrome for any closed reason; mark deadline only when that is why.
		 *
		 * @param int $post_id Portal post ID.
		 */
		private static function define_closed_request_flags( $post_id ) {
			if ( ! defined( 'DG_APPLICATION_CLOSED' ) ) {
				define( 'DG_APPLICATION_CLOSED', true );
			}
			$reason = class_exists( 'Portal_Open_State' )
				? Portal_Open_State::closed_reason( (int) $post_id )
				: null;
			if ( 'deadline' === $reason && ! defined( 'DG_APPLICATION_DEADLINE_PASSED' ) ) {
				define( 'DG_APPLICATION_DEADLINE_PASSED', true );
			}
		}

		/**
		 * Whether leftover formstart/formend chrome must stay off.
		 *
		 * @return bool
		 */
		public static function form_chrome_hidden() {
			if ( defined( 'DG_APPLICATION_CLOSED' ) && DG_APPLICATION_CLOSED ) {
				return true;
			}
			return defined( 'DG_APPLICATION_DEADLINE_PASSED' ) && DG_APPLICATION_DEADLINE_PASSED;
		}

		/**
		 * Leftover post-content shortcodes stay silent when closed/definition owns the page.
		 *
		 * @return bool
		 */
		public static function leftover_shortcode_is_silent() {
			if ( self::form_chrome_hidden() ) {
				return true;
			}
			$post_id = (int) get_the_ID();
			return $post_id > 0
				&& class_exists( 'Portal_Definition' )
				&& is_array( Portal_Definition::load_for_post( $post_id ) );
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
			if ( defined( 'DG_APPLICATION_SUBMITTED' ) && DG_APPLICATION_SUBMITTED ) {
				return $content;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- review step reuses shortcode body.
			if ( isset( $_POST['review_nonce'] ) ) {
				return $content;
			}

			$mode       = Portal_Open_State::preview_mode( $post_id );
			$is_preview = null !== $mode;
			$show_form  = Portal_Open_State::should_show_form( $post_id );

			if ( ! $show_form ) {
				self::define_closed_request_flags( $post_id );
				return self::render_closed_message( $post_id );
			}

			$definition = Portal_Definition::load_for_post( $post_id );
			$staff_bar  = self::render_staff_bar( $post_id );
			if ( is_array( $definition ) && ! $is_preview && class_exists( 'Portal_Access' ) ) {
				$access   = isset( $definition['access'] ) && is_array( $definition['access'] )
					? $definition['access']
					: array();
				$options  = isset( $definition['options'] ) && is_array( $definition['options'] )
					? $definition['options']
					: array();
				$decision = Portal_Access::decide( $access, $options, self::applicant_for( $post_id ) );
				if ( empty( $decision['allowed'] ) ) {
					$denied = self::render_restricted_message( $post_id, $definition, $decision );
					$notice = self::render_view_as_banner( $post_id );
					return '' === $notice ? $denied : $notice . $denied;
				}
			}

			$banner = $is_preview
				? self::render_preview_banner( $mode )
				: self::render_view_as_banner( $post_id );
			if ( '' === $banner && Portal_Open_State::invite_admission( $post_id ) ) {
				$banner = self::render_invite_banner( $post_id );
			}

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
					$staff_bar
					. $banner
					. self::render_packet_head( $title )
					. self::render_packet_meta( $definition, $site )
					. $errors_html
					. self::render_applicant_packets( $post_id )
					. Portal_Definition_Renderer::render( $definition, $site, self::applicant_defaults( $post_id ) )
					. self::preview_root_markup()
					. self::render_spam_widget( $definition )
				);
			}

			return $staff_bar . $banner . self::wrap_legacy( $content );
		}

		/**
		 * Invite-provided applicant defaults. Prefill only; the applicant still
		 * confirms each value before submitting.
		 *
		 * @param int $post_id Portal ID.
		 * @return array{name?: string, email?: string}
		 */
		private static function applicant_defaults( $post_id ) {
			if ( ! Portal_Open_State::invite_admission( $post_id ) ) {
				return array();
			}
			$invite = Portal_Open_State::invite_for_request( $post_id );
			if ( ! is_array( $invite ) ) {
				return array();
			}
			$defaults = array();
			if ( ! empty( $invite['name'] ) ) {
				$defaults['name'] = (string) $invite['name'];
			}
			if ( ! empty( $invite['email'] ) ) {
				$defaults['email'] = (string) $invite['email'];
			}
			return $defaults;
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
			$mode   = Portal_Open_State::preview_mode( $post_id );
			$reason = Portal_Open_State::closed_reason( $post_id );
			if ( null === $reason && Portal_Open_State::PREVIEW_CLOSED === $mode ) {
				// View-as-closed: show the closed rendering applicants will see.
				$instant = Portal_Open_State::publish_instant( $post_id, 'close' );
				$reason  = null !== $instant['value'] ? 'deadline' : 'force';
			}
			$message = self::MSG_NOT_ACCEPTING;
			if ( 'deadline' === $reason ) {
				$message = self::MSG_DEADLINE;
			} elseif ( 'force' === $reason ) {
				$message = self::MSG_CLOSED;
			}

			$extra = '';
			if ( Portal_Open_State::invite_token_present() && ! Portal_Open_State::invite_admission( $post_id ) ) {
				$extra = sprintf(
					'<p class="dg-invite-invalid" data-dg-invite-invalid="true">%s</p>',
					esc_html( self::MSG_INVITE_INVALID )
				);
			}

			$closed = sprintf(
				'<div class="dg-portal-closed" data-dg-portal-state="closed" data-dg-closed-reason="%s"><p>%s</p>%s</div>',
				esc_attr( (string) $reason ),
				esc_html( $message ),
				$extra
			);

			$definition = class_exists( 'Portal_Definition' )
				? Portal_Definition::load_for_post( $post_id )
				: null;

			$banner = Portal_Open_State::PREVIEW_CLOSED === $mode
				? self::render_preview_banner( $mode )
				: '';

			return self::wrap_packet(
				self::render_staff_bar( $post_id )
				. $banner
				. self::render_packet_head( get_the_title( $post_id ) )
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
			$heading = self::restricted_heading( $reason, $message );
			$action  = self::restricted_action( $post_id, $definition, $reason );

			$body = sprintf(
				'<div class="dg-access" data-dg-portal-state="restricted" data-dg-closed-reason="%1$s"><p class="dg-public-eyebrow">%2$s</p><h2>%3$s</h2><p>%4$s</p>%5$s</div>',
				esc_attr( $reason ),
				esc_html( self::ACCESS_EYEBROW ),
				esc_html( $heading ),
				esc_html( $message ),
				$action
			);
			return self::wrap_packet(
				self::render_staff_bar( $post_id )
				. self::render_packet_head( get_the_title( $post_id ) )
				. self::render_packet_meta( $definition )
				. $body
			);
		}

		/**
		 * @param string $reason  Access deny reason.
		 * @param string $message Deny copy.
		 * @return string
		 */
		private static function restricted_heading( $reason, $message ) {
			if ( 'login' === $reason ) {
				return self::ACCESS_HEADING_LOGIN;
			}
			if ( 'membership' === $reason ) {
				return self::ACCESS_HEADING_MEMBERSHIP;
			}
			return $message;
		}

		/**
		 * Same-tab primary action. Login always; membership only when a join URL exists.
		 *
		 * @param int    $post_id    Portal ID.
		 * @param array  $definition Definition.
		 * @param string $reason     Access deny reason.
		 * @return string
		 */
		private static function restricted_action( $post_id, array $definition, $reason ) {
			if ( 'login' === $reason ) {
				$target = function_exists( 'get_permalink' ) ? get_permalink( $post_id ) : '';
				$login  = class_exists( 'Portal_Site_Defaults' )
					? Portal_Site_Defaults::login_url( is_string( $target ) ? $target : '' )
					: '';
				if ( '' === $login ) {
					return '';
				}
				return sprintf(
					'<p><a class="dg-public-submit" href="%s">%s</a></p>',
					esc_url( $login ),
					esc_html( self::ACCESS_SIGN_IN )
				);
			}
			if ( 'membership' === $reason ) {
				$join = self::membership_join_url( $definition );
				if ( '' !== $join ) {
					return sprintf(
						'<p><a class="dg-public-submit" href="%s">%s</a></p>',
						esc_url( $join ),
						esc_html( self::ACCESS_JOIN )
					);
				}
			}
			return '';
		}

		/**
		 * @param array $definition Definition.
		 * @return string
		 */
		private static function membership_join_url( array $definition ) {
			$access = isset( $definition['access'] ) && is_array( $definition['access'] )
				? $definition['access']
				: array();
			if ( ! empty( $access['joinUrl'] ) ) {
				return (string) $access['joinUrl'];
			}
			$options = isset( $definition['options'] ) && is_array( $definition['options'] )
				? $definition['options']
				: array();
			if ( ! empty( $options['joinUrl'] ) ) {
				return (string) $options['joinUrl'];
			}
			if ( class_exists( 'Portal_Site_Defaults' ) ) {
				return Portal_Site_Defaults::join_url();
			}
			return '';
		}

		/**
		 * Honest preview banner for editors. The attribute is a public contract.
		 *
		 * @param string|null $mode Portal_Open_State::preview_mode value.
		 * @return string
		 */
		public static function render_preview_banner( $mode = null ) {
			$text = self::PREVIEW_BANNER_TEXT;
			if ( Portal_Open_State::PREVIEW_OPEN === $mode ) {
				$text = self::PREVIEW_BANNER_OPEN;
			} elseif ( Portal_Open_State::PREVIEW_CLOSED === $mode ) {
				$text = self::PREVIEW_BANNER_CLOSED;
			}
			return sprintf(
				'<div class="dg-preview-banner" data-dg-preview="true" role="status"><strong>%s</strong></div>',
				esc_html( $text )
			);
		}

		/**
		 * Calm banner for an invited applicant on a closed portal.
		 *
		 * @param int $post_id Portal post ID.
		 * @return string
		 */
		public static function render_invite_banner( $post_id ) {
			$invite = Portal_Open_State::invite_for_request( $post_id );
			$text   = self::INVITE_BANNER_NODATE;
			if ( is_array( $invite ) && ! empty( $invite['expiresAt'] ) ) {
				$instant = Portal_Open_State::publish_instant( $post_id, 'close' );
				$expires = self::format_when( $invite['expiresAt'], $instant['timezone'], 'date' );
				$text    = '' !== $expires
					? sprintf( self::INVITE_BANNER_FORMAT, $expires )
					: self::INVITE_BANNER_NODATE;
			}
			return sprintf(
				'<div class="dg-preview-banner dg-invite-banner" data-dg-invite-banner="true" role="status"><strong>%s</strong></div>',
				esc_html( $text )
			);
		}

		/**
		 * Staff bar: view-as-open / view-as-closed links for anyone who can edit
		 * this portal. Never rendered for anyone else (no unpublished leak).
		 *
		 * @param int $post_id Portal post ID.
		 * @return string
		 */
		public static function render_staff_bar( $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
				return '';
			}
			$mode     = Portal_Open_State::preview_mode( $post_id );
			$mode_txt = 'live';
			$mode_key = 'live';
			if ( Portal_Open_State::PREVIEW_OPEN === $mode ) {
				$mode_txt = 'preview as open';
				$mode_key = 'preview-open';
			} elseif ( Portal_Open_State::PREVIEW_CLOSED === $mode ) {
				$mode_txt = 'preview as closed';
				$mode_key = 'preview-closed';
			} elseif ( null !== $mode ) {
				$mode_txt = 'preview';
				$mode_key = 'preview';
			}

			$style = 'display:flex;flex-wrap:wrap;gap:.35rem .9rem;align-items:center;font:500 .8125rem/1.4 system-ui,sans-serif;color:#3b3b3b;background:#f6f4ef;border:1px solid #d9d2c2;border-radius:.375rem;padding:.4rem .65rem;margin:0 0 .75rem;';
			$link  = 'color:#5b4a2f;text-decoration:underline;';

			$open_url   = esc_url( self::preview_url( Portal_Open_State::PREVIEW_OPEN ) );
			$closed_url = esc_url( self::preview_url( Portal_Open_State::PREVIEW_CLOSED ) );
			$open_cur   = Portal_Open_State::PREVIEW_OPEN === $mode ? ' aria-current="true"' : '';
			$closed_cur = Portal_Open_State::PREVIEW_CLOSED === $mode ? ' aria-current="true"' : '';

			return sprintf(
				'<div class="dg-staff-bar" data-dg-staff-bar="true" data-dg-staff-mode="%1$s" data-testid="dg-staff-bar" role="status" style="%2$s"><strong>Staff view</strong><span>Mode: %3$s</span><a style="%4$s" href="%5$s"%6$s>View as open</a><a style="%4$s" href="%7$s"%8$s>View as closed</a></div>',
				esc_attr( $mode_key ),
				esc_attr( $style ),
				esc_html( $mode_txt ),
				esc_attr( $link ),
				$open_url,
				$open_cur,
				$closed_url,
				$closed_cur
			);
		}

		/**
		 * Current URL with ?preview=<mode>. Uses WP's add_query_arg when present;
		 * CLI render harnesses have no URL helpers.
		 *
		 * @param string $mode open|closed|true.
		 * @return string
		 */
		private static function preview_url( $mode ) {
			if ( function_exists( 'add_query_arg' ) ) {
				return add_query_arg( 'preview', $mode );
			}
			$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
			$path  = $uri;
			$query = '';
			$pos   = strpos( $uri, '?' );
			if ( false !== $pos ) {
				$path  = substr( $uri, 0, $pos );
				$query = substr( $uri, $pos + 1 );
			}
			$pairs = array();
			foreach ( explode( '&', $query ) as $pair ) {
				if ( '' === $pair || 0 === strpos( $pair, 'preview=' ) ) {
					continue;
				}
				$pairs[] = $pair;
			}
			$pairs[] = 'preview=' . rawurlencode( $mode );
			return $path . '?' . implode( '&', $pairs );
		}

		/**
		 * View-as banner when a privileged overlay is active.
		 *
		 * @param int $post_id Portal post ID.
		 * @return string
		 */
		public static function render_view_as_banner( $post_id ) {
			if ( ! class_exists( 'Portal_View_As' ) || ! Portal_View_As::is_active( $post_id ) ) {
				return '';
			}
			$persona = Portal_View_As::requested_persona();
			$catalog = class_exists( 'Portal_Access' ) ? Portal_Access::catalog() : array();
			return sprintf(
				'<div class="dg-view-as-banner" data-testid="dg-view-as-banner" role="status"><strong>%s</strong></div>',
				esc_html( Portal_View_As::banner_text( (string) $persona, $catalog ) )
			);
		}

		/**
		 * Overlay applicant when view-as is active.
		 *
		 * @param int $post_id Portal post ID.
		 * @return array
		 */
		private static function applicant_for( $post_id ) {
			if ( class_exists( 'Portal_View_As' ) ) {
				return Portal_View_As::applicant( $post_id );
			}
			return Portal_Access::current_applicant();
		}

		/**
		 * Whether the definition path should emit formstart and submit.
		 *
		 * @param int $post_id Portal post ID.
		 * @return bool
		 */
		public static function should_show_form_actions( $post_id ) {
			if ( self::form_chrome_hidden() ) {
				return false;
			}
			if ( defined( 'DG_DEFINITION_SUBMIT_OK' ) && DG_DEFINITION_SUBMIT_OK ) {
				return false;
			}
			if ( defined( 'DG_APPLICATION_SUBMITTED' ) && DG_APPLICATION_SUBMITTED ) {
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
			if ( class_exists( 'Portal_View_As' ) && Portal_View_As::is_active( (int) $post_id ) ) {
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
			$decision = Portal_Access::decide( $access, $options, self::applicant_for( (int) $post_id ) );
			return ! empty( $decision['allowed'] );
		}

		/**
		 * Primary submit control. Name and label are public contracts.
		 *
		 * @return string
		 */
		public static function render_submit_control() {
			return sprintf(
				'<p class="dg-actions sub_submit_container"><button class="dg-public-submit" name="sub_submit" type="submit" data-testid="dg-submit">%s</button></p>',
				esc_html__( 'Submit application', 'dragongate-portals' )
			);
		}

		/**
		 * Seal + Application eyebrow + title. Used for open, closed, and receipt.
		 *
		 * @param string $title Portal title.
		 * @return string
		 */
		public static function render_packet_head( $title ) {
			$plugin_file = dirname( __DIR__, 2 ) . '/portal-builder.php';
			$brand       = self::effective_brand();
			$hide_seal   = class_exists( 'Portal_Brand' ) && Portal_Brand::hides_logo( $brand );
			$seal        = '';
			$head_class  = 'dg-packet-head dg-public-application-head';
			if ( ! $hide_seal ) {
				$seal_src = plugins_url( 'assets/icon.svg', $plugin_file );
				if ( class_exists( 'Portal_Brand' ) ) {
					$logo = Portal_Brand::logo_url( $brand );
					if ( '' !== $logo ) {
						$seal_src = $logo;
					}
				}
				$seal = sprintf(
					'<img class="dg-seal" src="%s" alt="" width="56" height="56" />',
					esc_url( $seal_src )
				);
			} else {
				$head_class .= ' dg-packet-head--no-seal';
			}
			$rule = '<div class="dg-rule-ornament" aria-hidden="true"><svg width="10" height="10" viewBox="0 0 10 10"><path d="M5 0.6 9.2 5 5 9.4 0.8 5Z" fill="currentColor"/></svg></div>';

			return sprintf(
				'<header class="%5$s">%1$s<div><p class="dg-public-eyebrow">%2$s</p><h1 class="dg-packet-title">%3$s</h1></div></header>%4$s',
				$seal,
				esc_html__( 'Application', 'dragongate-portals' ),
				esc_html( $title ),
				$rule,
				esc_attr( $head_class )
			);
		}

		/**
		 * Scored meta row from definition publish/options. Omits empty items.
		 *
		 * @param array|null $definition Definition document or null.
		 * @param array|null $site       Site bag. Null reads live WP options.
		 * @return string
		 */
		public static function render_packet_meta( $definition, $site = null ) {
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
					'<a class="dg-meta-link" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
					esc_url( $guidelines ),
					esc_html( self::guidelines_link_label( $definition, $site ) )
				);
			}

			if ( empty( $items ) ) {
				return '';
			}

			return '<div class="dg-meta">' . implode( '<span class="dg-meta-split" aria-hidden="true"></span>', $items ) . '</div>';
		}

		/**
		 * Portal label, else site, else built-in. Never appends an arrow.
		 *
		 * @param array      $definition Definition document.
		 * @param array|null $site       Site bag or null for live WP options.
		 * @return string
		 */
		private static function guidelines_link_label( array $definition, $site ) {
			if ( ! class_exists( 'Portal_Site_Defaults' ) ) {
				return 'Submission guidelines';
			}
			$site_bag = is_array( $site ) ? $site : Portal_Site_Defaults::read_site();
			$resolved = Portal_Site_Defaults::resolve( $definition, $site_bag );
			$label    = isset( $resolved['guidelinesLinkLabel'] ) ? (string) $resolved['guidelinesLinkLabel'] : '';
			return '' !== $label ? $label : Portal_Site_Defaults::BUILTIN_GUIDELINES_LINK_LABEL;
		}

		/**
		 * Deadline item for the applicant meta bar.
		 *
		 * @param array    $publish  Publish block.
		 * @param int|null $post_id  Portal ID for the legacy deadline backstop.
		 * @return string
		 */
		private static function meta_deadline_item( $publish, $post_id = null ) {
			$deadline = array_key_exists( 'deadline', $publish ) ? $publish['deadline'] : null;
			$timezone = isset( $publish['timezone'] ) && is_string( $publish['timezone'] ) && '' !== $publish['timezone']
				? $publish['timezone']
				: 'America/New_York';

			if ( null === $deadline || '' === $deadline ) {
				$post_id = null === $post_id && function_exists( 'get_the_ID' ) ? (int) get_the_ID() : (int) $post_id;
				if ( $post_id > 0 && class_exists( 'Portal_Open_State' ) ) {
					$instant  = Portal_Open_State::publish_instant( $post_id, 'close' );
					$deadline = $instant['value'];
					$timezone = $instant['timezone'];
				}
			}
			if ( null === $deadline || '' === $deadline ) {
				return '';
			}

			$formatted = self::format_when( $deadline, $timezone, 'date' );
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
				esc_html( self::timezone_label( $deadline, $timezone ) )
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
					/* translators: %s: fee amount shown as a label, not collected here */
					esc_html__( 'Application fee $%s. Not charged in this form', 'dragongate-portals' ),
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
		 * The one applicant-facing date formatter. Shortcodes reuse it.
		 *
		 * Styles: 'date'   → 24 October 2026, 4:00 a.m. (meta bar clock, house a.m./p.m.)
		 *         'phrase' → Monday, August 17, 2026 (prose)
		 *         'ymd'    → 2026-08-17
		 *
		 * @param mixed  $value    Datetime string or empty.
		 * @param string $timezone IANA timezone.
		 * @param string $style    One of date|phrase|ymd.
		 * @return string Empty when unparseable.
		 */
		public static function format_when( $value, $timezone, $style = 'date' ) {
			$dt = self::parse_moment( $value, $timezone );
			if ( ! $dt ) {
				return '';
			}
			if ( 'phrase' === $style ) {
				return $dt->format( 'l, F j, Y' );
			}
			if ( 'ymd' === $style ) {
				return $dt->format( 'Y-m-d' );
			}
			$meridiem = 'am' === $dt->format( 'a' ) ? 'a.m.' : 'p.m.';
			return $dt->format( 'j F Y, g:i ' ) . $meridiem;
		}

		/**
		 * Real-world timezone abbreviation for the instant (EST in winter, EDT
		 * in daylight time), falling back to the IANA name when the zone has no
		 * meaningful abbreviation instead of printing a raw offset.
		 *
		 * @param mixed  $value    Datetime string or empty.
		 * @param string $timezone IANA timezone.
		 * @return string
		 */
		public static function timezone_label( $value, $timezone ) {
			$timezone = (string) $timezone;
			$dt       = self::parse_moment( $value, $timezone );
			if ( ! $dt ) {
				return $timezone;
			}
			$abbr = $dt->format( 'T' );
			if ( '' === $abbr || preg_match( '/^[+-]?\d/', $abbr ) ) {
				return $timezone;
			}
			return $abbr;
		}

		/**
		 * Parse a datetime string and pin it to the portal timezone.
		 *
		 * @param mixed  $value    Datetime string or empty.
		 * @param string $timezone IANA timezone.
		 * @return DateTime|null Pinned to the portal timezone, so offset-bearing
		 *                       strings render at the portal's wall clock.
		 */
		private static function parse_moment( $value, $timezone ) {
			if ( null === $value || '' === $value ) {
				return null;
			}
			try {
				$tz = new DateTimeZone( (string) $timezone );
			} catch ( Exception $e ) {
				$tz = new DateTimeZone( 'America/New_York' );
			}
			try {
				$dt = new DateTime( (string) $value, $tz );
			} catch ( Exception $e ) {
				return null;
			}
			return $dt->setTimezone( $tz );
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
		 * Mount point for the public Bits file-confirm island.
		 *
		 * @return string
		 */
		public static function preview_root_markup() {
			return '<div data-dg-preview-root></div>';
		}

		/**
		 * Turnstile widget when a site key is set.
		 *
		 * @param array $definition Definition.
		 * @return string
		 */
		/**
		 * Logged-in applicant: past packets for this portal + recall control.
		 *
		 * @param int $post_id Portal id.
		 * @return string
		 */
		public static function render_applicant_packets( $post_id ) {
			if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
				return '';
			}
			if ( ! class_exists( 'Portal_Packet_Store' ) ) {
				return '';
			}
			$user  = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
			$email = $user && isset( $user->user_email ) ? (string) $user->user_email : '';
			if ( '' === $email ) {
				return '';
			}
			$rows = Portal_Packet_Store::for_uploads()->list_for_email( $post_id, $email );
			if ( empty( $rows ) ) {
				return '';
			}
			$html  = '<section class="dg-applicant-packets" data-dg-applicant-packets aria-labelledby="dg-applicant-packets-title">';
			$html .= '<h2 id="dg-applicant-packets-title">Your submissions for this portal</h2>';
			$html .= '<ul>';
			foreach ( $rows as $row ) {
				$id     = isset( $row['applicationId'] ) ? (string) $row['applicationId'] : '';
				$status = isset( $row['status'] ) ? (string) $row['status'] : '';
				$html  .= '<li><span>' . esc_html( $id ) . ' — ' . esc_html( $status ) . '</span>';
				if ( Portal_Packet_Policy::is_current( $row ) ) {
					$html .= ' <button type="button" class="dg-btn" data-dg-recall="' . esc_attr( $id ) . '" aria-label="Recall submission ' . esc_attr( $id ) . '">Recall</button>';
					$html .= ' <label><span class="screen-reader-text">Field to replace for ' . esc_html( $id ) . '</span>';
					$html .= '<input type="text" name="field_id" value="" aria-label="Field to replace for ' . esc_attr( $id ) . '"></label>';
					$html .= ' <label><span class="screen-reader-text">Replacement file for ' . esc_html( $id ) . '</span>';
					$html .= '<input type="file" name="file" aria-label="Replacement file for ' . esc_attr( $id ) . '"></label>';
					$html .= ' <button type="button" class="dg-btn" data-dg-replace="' . esc_attr( $id ) . '" aria-label="Replace file for submission ' . esc_attr( $id ) . '">Replace file</button>';
				}
				$html .= '</li>';
			}
			return $html . '</ul></section>';
		}

		public static function render_spam_widget( array $definition ) {
			if ( ! class_exists( 'Portal_Spam_Gate' ) ) {
				return '';
			}
			$access   = isset( $definition['access'] ) && is_array( $definition['access'] )
				? $definition['access']
				: array();
			$audience = isset( $access['audience'] ) ? (string) $access['audience'] : 'anyone';
			if ( ! Portal_Spam_Gate::required_for_audience( $audience ) ) {
				return '';
			}
			$key = Portal_Spam_Gate::site_key();
			if ( '' === $key ) {
				return '';
			}
			return sprintf(
				'<div class="dg-turnstile" data-dg-spam-gate="turnstile" data-testid="dg-turnstile"><div class="cf-turnstile" data-sitekey="%1$s" role="group" aria-label="%2$s"></div><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script></div>',
				esc_attr( $key ),
				esc_attr( 'Spam check' )
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
			$plugin_file = dirname( __DIR__, 2 ) . '/portal-builder.php';
			$plugin_dir  = dirname( $plugin_file );

			wp_enqueue_style(
				'dg-public-fonts',
				self::PUBLIC_FONTS_URL,
				array(),
				null
			);
			$brand      = self::effective_brand();
			$host_fonts = class_exists( 'Portal_Brand' ) ? Portal_Brand::fonts_url( $brand ) : '';
			if ( '' !== $host_fonts ) {
				wp_enqueue_style( 'dg-host-fonts', $host_fonts, array(), null );
			}
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
			if ( class_exists( 'Portal_Brand' ) && Portal_Brand::is_host( $brand ) && '' !== Portal_Brand::css( $brand ) ) {
				add_action( 'wp_head', array( __CLASS__, 'print_host_brand_style' ), 40 );
			}
			wp_enqueue_script(
				'dg-file-preview',
				plugins_url( 'assets/file-preview.js', $plugin_file ),
				array(),
				self::asset_version( $plugin_dir . '/assets/file-preview.js' ),
				true
			);
			wp_enqueue_script(
				'dg-public-preview',
				plugins_url( 'assets/dist/dragongate-public.js', $plugin_file ),
				array(),
				self::asset_version( $plugin_dir . '/assets/dist/dragongate-public.js' ),
				true
			);
			add_filter(
				'script_loader_tag',
				static function ( $tag, $handle ) {
					// Classic defer can run before ES modules on Safari; keep the
					// public form stack as modules so FilePreview/DGPreview exist
					// before definition-form.js init (Open and confirm).
					$modules = array( 'dg-file-preview', 'dg-public-preview', 'dg-definition-form' );
					if ( in_array( $handle, $modules, true ) && false === strpos( $tag, 'type=' ) ) {
						return str_replace( '<script ', '<script type="module" ', $tag );
					}
					return $tag;
				},
				10,
				2
			);
			wp_enqueue_script(
				'dg-definition-form',
				plugins_url( 'assets/definition-form.js', $plugin_file ),
				array( 'dg-file-preview', 'dg-public-preview' ),
				self::asset_version( $plugin_dir . '/assets/definition-form.js' ),
				true
			);

			$post_id    = (int) get_the_ID();
			$anonymize  = false;
			$definition = class_exists( 'Portal_Definition' )
				? Portal_Definition::load_for_post( $post_id )
				: null;
			if ( is_array( $definition ) && class_exists( 'Portal_Site_Defaults' ) ) {
				$resolved  = Portal_Site_Defaults::resolve_for_site( $definition );
				$anonymize = ! empty( $resolved['anonymize'] );
			}

			$stage_url = '';
			if ( $post_id > 0 && function_exists( 'rest_url' ) ) {
				$stage_url = rest_url( 'dragongate/v1/portals/' . $post_id . '/files' );
			}

			wp_localize_script(
				'dg-definition-form',
				'dgPublicForm',
				array(
					'portalId'  => $post_id,
					'nonce'     => function_exists( 'wp_create_nonce' )
						? wp_create_nonce( 'wp_rest' )
						: '',
					'stageUrl'  => $stage_url,
					'anonymize' => $anonymize,
				)
			);
			if ( class_exists( 'Portal_Setup_Screen' ) ) {
				Portal_Setup_Screen::enqueue_packet_console(
					$plugin_file,
					$post_id,
					static function ( $rel ) use ( $plugin_dir ) {
						$path = $plugin_dir . '/' . $rel;
						return is_readable( $path ) ? (string) filemtime( $path ) : DG_VERSION;
					}
				);
			}
		}

		/**
		 * Resolved site brand bag. Empty inherit is null; product is not a host look.
		 *
		 * @return array|null
		 */
		private static function effective_brand() {
			if ( ! class_exists( 'Portal_Site_Defaults' ) ) {
				return null;
			}
			$resolved = Portal_Site_Defaults::resolve_for_site( array() );
			return isset( $resolved['brand'] ) && is_array( $resolved['brand'] )
				? $resolved['brand']
				: null;
		}

		/**
		 * Variable remap only — existing form stylesheet stays the owner of rules.
		 *
		 * @return void
		 */
		public static function print_host_brand_style() {
			if ( ! class_exists( 'Portal_Brand' ) ) {
				return;
			}
			$css = Portal_Brand::css( self::effective_brand() );
			if ( '' === $css ) {
				return;
			}
			$safe = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $css ) : $css;
			echo '<style id="dg-host-brand">' . $safe . '</style>';
		}

		/**
		 * Filemtime so a deploy is visible without bumping DG_VERSION.
		 *
		 * @param string $path Absolute path.
		 * @return string
		 */
		private static function asset_version( $path ) {
			if ( is_readable( $path ) ) {
				return (string) filemtime( $path );
			}
			return DG_VERSION;
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
			$fonts = class_exists( 'Portal_Brand' ) ? Portal_Brand::fonts_url( self::effective_brand() ) : '';
			if ( '' !== $fonts && false !== strpos( $fonts, 'fonts.googleapis.com' ) ) {
				$urls[] = array( 'href' => 'https://fonts.googleapis.com' );
				$urls[] = array(
					'href'        => 'https://fonts.gstatic.com',
					'crossorigin' => 'anonymous',
				);
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
