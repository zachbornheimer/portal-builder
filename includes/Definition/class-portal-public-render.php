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

		const ACCESS_EYEBROW            = 'Members only';
		const ACCESS_HEADING_LOGIN      = 'Sign in to apply.';
		const ACCESS_HEADING_MEMBERSHIP = 'This call is for paid members.';
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
			if ( ! defined( 'PB_APPLICATION_CLOSED' ) ) {
				define( 'PB_APPLICATION_CLOSED', true );
			}
			$reason = class_exists( 'Portal_Open_State' )
				? Portal_Open_State::closed_reason( (int) $post_id )
				: null;
			if ( 'deadline' === $reason && ! defined( 'PB_APPLICATION_DEADLINE_PASSED' ) ) {
				define( 'PB_APPLICATION_DEADLINE_PASSED', true );
			}
		}

		/**
		 * Whether leftover formstart/formend chrome must stay off.
		 *
		 * @return bool
		 */
		public static function form_chrome_hidden() {
			if ( defined( 'PB_APPLICATION_CLOSED' ) && PB_APPLICATION_CLOSED ) {
				return true;
			}
			return defined( 'PB_APPLICATION_DEADLINE_PASSED' ) && PB_APPLICATION_DEADLINE_PASSED;
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
				self::define_closed_request_flags( $post_id );
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
					. self::render_packet_meta( $definition, $site )
					. $errors_html
					. self::render_applicant_packets( $post_id )
					. Portal_Definition_Renderer::render( $definition, $site )
					. self::render_spam_widget( $definition )
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
				self::render_packet_head( get_the_title( $post_id ) )
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
			if ( 'login' === $reason && function_exists( 'wp_login_url' ) ) {
				$target = function_exists( 'get_permalink' ) ? get_permalink( $post_id ) : '';
				return sprintf(
					'<p><a class="dg-public-submit" href="%s">%s</a></p>',
					esc_url( wp_login_url( $target ? $target : '' ) ),
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
			if ( function_exists( 'wc_get_page_permalink' ) ) {
				$shop = wc_get_page_permalink( 'shop' );
				return is_string( $shop ) ? $shop : '';
			}
			return '';
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
				$seal       = sprintf(
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
				return 'Link to Guidelines';
			}
			$site_bag = is_array( $site ) ? $site : Portal_Site_Defaults::read_site();
			$resolved = Portal_Site_Defaults::resolve( $definition, $site_bag );
			$label    = isset( $resolved['guidelinesLinkLabel'] ) ? (string) $resolved['guidelinesLinkLabel'] : '';
			return '' !== $label ? $label : Portal_Site_Defaults::BUILTIN_GUIDELINES_LINK_LABEL;
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
		 * Turnstile widget for anyone-audience portals when a site key is set.
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
			$html = '<section class="dg-applicant-packets" data-dg-applicant-packets aria-labelledby="dg-applicant-packets-title">';
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
				'<div class="dg-turnstile" data-dg-spam-gate="turnstile"><div class="cf-turnstile" data-sitekey="%1$s" role="group" aria-label="%2$s"></div><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script></div>',
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
				'dg-definition-form',
				plugins_url( 'assets/definition-form.js', $plugin_file ),
				array(),
				self::asset_version( $plugin_dir . '/assets/definition-form.js' ),
				true
			);

			$post_id   = (int) get_the_ID();
			$anonymize = false;
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
						return is_readable( $path ) ? (string) filemtime( $path ) : PB_VERSION;
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
