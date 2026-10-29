<?php
/**
 * Mail capture facade — writes messages under artifactDir/mail/*.json in test mode.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Options' ) ) {
	require_once dirname( __DIR__ ) . '/class-portal-options.php';
}

if ( ! class_exists( 'Portal_Mailer' ) ) {

	/**
	 * Captures outbound mail as JSON artifacts (real SMTP lands later).
	 */
	class Portal_Mailer {

		const ARTIFACT_DIR_NAME        = 'mail';
		const EXTENSION                = '.json';
		const UNKNOWN_RECIPIENT        = 'unknown';
		const KIND_OPERATOR            = 'operator';
		const KIND_INVITE              = 'invite';
		const DEFAULT_RECEIPT_SUBJECT  = 'Application receipt';
		const DEFAULT_RECEIPT_BODY     = 'We received your application for {{portal_title}}. {{$receiptLink}}';
		const DEFAULT_OPERATOR_SUBJECT = 'New application: {{portal_title}}';
		const DEFAULT_OPERATOR_BODY    = "Portal: {{portal_title}}\nApplication: {{application_id}}\nApplicant: {{applicant_email}}\nReceipt: {{receipt_url}}";
		const DEFAULT_INVITE_SUBJECT   = "You're invited to apply: {{portal_title}}";
		const DEFAULT_INVITE_BODY      = "You have been invited to submit an application for {{portal_title}}.\n\nStart your application here: {{invite_link}}\n\nThis personal link works until {{expires_at}}.";
		const OPTION_FROM_EMAIL        = 'dg_receipt_from_email';
		const OPTION_FROM_NAME         = 'dg_receipt_from_name';
		const DEFAULT_FROM_NAME        = 'Portal Submissions';
		// Mail templates, one WP option per kind; empty option falls back to the default.
		const OPTION_SUBJECT        = 'dg_receipt_subject';
		const OPTION_BODY           = 'dg_receipt_body';
		const OPTION_INVITE_SUBJECT = 'dg_invite_subject';
		const OPTION_INVITE_BODY    = 'dg_invite_body';
		const OPTION_OPERATOR_EMAIL = 'dg_operator_notify_email';
		// CLI / test-mode fallback when WordPress options are absent.
		const TEST_OPERATOR_EMAIL = 'operator@example.com';
		const HTML_CONTENT_TYPE   = 'Content-Type: text/html; charset=UTF-8';

		/** @var Portal_Files */
		private $files;

		/** @var string */
		private $artifact_dir;

		/** @var callable|null Returns milliseconds since epoch. */
		private $now_ms;

		/**
		 * @param string            $artifact_dir Absolute artifact root.
		 * @param Portal_Files|null $files        Injectable filesystem.
		 * @param callable|null     $now_ms       Injectable clock: () => int ms.
		 */
		public function __construct( $artifact_dir, $files = null, $now_ms = null ) {
			$this->artifact_dir = (string) $artifact_dir;
			$this->files        = $files instanceof Portal_Files ? $files : new Portal_Files();
			$this->now_ms       = is_callable( $now_ms ) ? $now_ms : null;
		}

		/**
		 * Capture a message; returns path written.
		 *
		 * @param array<string,mixed> $message Keys: to, subject, body, portalId, …
		 * @return string Absolute path written.
		 */
		public function capture( array $message ) {
			$dir = $this->files->join( $this->artifact_dir, self::ARTIFACT_DIR_NAME );
			$this->files->mkdir( $dir );

			$to      = isset( $message['to'] ) && is_string( $message['to'] ) && '' !== $message['to']
				? $message['to']
				: self::UNKNOWN_RECIPIENT;
			$safe_to = preg_replace( '/[^A-Za-z0-9@._+-]+/', '_', $to );
			$ms      = $this->now_ms ? (int) call_user_func( $this->now_ms ) : (int) round( microtime( true ) * 1000 );
			$dest    = $this->files->join( $dir, $ms . '-' . $safe_to . self::EXTENSION );

			$payload = array_merge(
				array(
					'capturedAt' => gmdate( 'c' ),
				),
				$message
			);

			$encoded = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $encoded ) || '' === $encoded ) {
				$encoded = '{}';
			}

			$this->files->write( $dest, $encoded );
			return $dest;
		}

		/**
		 * Interpolate closed receipt tokens. Accepts {{receipt_url}} and {{$receiptLink}}.
		 *
		 * @param string              $template Template with {{token}} placeholders.
		 * @param array<string,mixed> $tokens   Closed token set.
		 * @return string
		 */
		public static function interpolate( $template, array $tokens ) {
			$map = self::token_map( $tokens );
			$out = (string) $template;
			foreach ( $map as $key => $value ) {
				$quoted = preg_quote( $key, '/' );
				$out    = preg_replace( '/\{\{\s*' . $quoted . '\s*\}\}/', $value, $out );
			}
			return is_string( $out ) ? $out : (string) $template;
		}

		/**
		 * Interpolate templates, capture a copy, and wp_mail when live.
		 *
		 * Mail failure is captured on the payload and does not throw.
		 *
		 * @param array<string,mixed> $message Keys: to, tokens, subject?, body?, portalId.
		 * @return string Capture path.
		 */
		public function send( array $message ) {
			$result = $this->send_and_report( $message );
			return $result['path'];
		}

		/**
		 * One invitation mail: personal link plus expiry through the same
		 * prepare / deliver / capture path as receipts.
		 *
		 * @param array<string,mixed> $payload Keys: invite|tokens, portalId?, portalTitle?, to?, subject?, body?.
		 * @return array{path:string,ok:bool,error:string|null} Capture path and send report.
		 */
		public function send_invite( array $payload ) {
			$payload['kind'] = self::KIND_INVITE;
			if ( empty( $payload['tokens'] ) && isset( $payload['invite'] ) && is_array( $payload['invite'] ) ) {
				$invite    = $payload['invite'];
				$portal_id = isset( $payload['portalId'] ) ? $payload['portalId'] : 0;
				if ( empty( $payload['portalTitle'] ) && function_exists( 'get_the_title' ) ) {
					$payload['portalTitle'] = (string) get_the_title( (int) $portal_id );
				}
				$payload['tokens'] = self::invite_tokens(
					$portal_id,
					$invite,
					isset( $payload['portalTitle'] ) ? (string) $payload['portalTitle'] : ''
				);
				if ( empty( $payload['to'] ) && isset( $invite['email'] ) ) {
					$payload['to'] = (string) $invite['email'];
				}
			}
			if ( empty( $payload['to'] ) || ! is_string( $payload['to'] ) ) {
				$payload['to'] = self::UNKNOWN_RECIPIENT;
			}
			return $this->send_and_report( $payload );
		}

		/**
		 * Interpolate, deliver when live, capture always; report success.
		 *
		 * @param array<string,mixed> $message Raw send() input.
		 * @return array{path:string,ok:bool,error:string|null}
		 */
		private function send_and_report( array $message ) {
			try {
				$payload = $this->prepare( $message );
				if ( $this->should_deliver() ) {
					$payload['wpMail']     = true;
					$payload['wpMailSent'] = $this->deliver( $payload );
					if ( empty( $payload['wpMailSent'] ) ) {
						$payload['error'] = 'wp_mail returned false';
					}
				}
				$path  = $this->capture( $payload );
				$error = isset( $payload['error'] ) ? (string) $payload['error'] : null;
				return array(
					'path'  => $path,
					'ok'    => null === $error,
					'error' => $error,
				);
			} catch ( Exception $e ) {
				$message['error'] = $e->getMessage();
				$path             = $this->capture( $message );
				return array(
					'path'  => $path,
					'ok'    => false,
					'error' => $e->getMessage(),
				);
			}
		}

		/**
		 * Operator notify: dg_operator_notify_email, else WP admin_email,
		 * else TEST_OPERATOR_EMAIL when WordPress options are absent (CLI/test).
		 *
		 * @return string
		 */
		public static function operator_recipient() {
			$configured = class_exists( 'Portal_Options' )
				? Portal_Options::get( self::OPTION_OPERATOR_EMAIL, '' )
				: '';
			if ( is_string( $configured ) && '' !== trim( $configured ) ) {
				return trim( $configured );
			}
			if ( function_exists( 'get_option' ) ) {
				$admin = get_option( 'admin_email', '' );
				if ( is_string( $admin ) && '' !== trim( $admin ) ) {
					return trim( $admin );
				}
			}
			return self::TEST_OPERATOR_EMAIL;
		}

		/**
		 * One operator notify. Fail-open is owned by send().
		 *
		 * @param array<string,mixed> $message Keys: to?, tokens, portalId, subject?, body?.
		 * @return string Capture path.
		 */
		public function send_operator_notify( array $message ) {
			$message['kind'] = self::KIND_OPERATOR;
			if ( empty( $message['to'] ) || ! is_string( $message['to'] ) ) {
				$message['to'] = self::operator_recipient();
			}
			if ( empty( $message['subject'] ) || ! is_string( $message['subject'] ) ) {
				$message['subject'] = self::DEFAULT_OPERATOR_SUBJECT;
			}
			if ( empty( $message['body'] ) || ! is_string( $message['body'] ) ) {
				$message['body'] = self::DEFAULT_OPERATOR_BODY;
			}
			return $this->send( $message );
		}

		/**
		 * Fill subject/body from options and interpolate tokens.
		 *
		 * @param array<string,mixed> $message Raw send() input.
		 * @return array<string,mixed>
		 */
		private function prepare( array $message ) {
			$tokens    = isset( $message['tokens'] ) && is_array( $message['tokens'] )
				? $message['tokens']
				: array();
			$is_invite = isset( $message['kind'] ) && self::KIND_INVITE === $message['kind'];
			$subject   = $this->template_from(
				$message,
				'subject',
				$is_invite ? self::OPTION_INVITE_SUBJECT : self::OPTION_SUBJECT,
				$is_invite ? self::DEFAULT_INVITE_SUBJECT : self::DEFAULT_RECEIPT_SUBJECT
			);
			$body      = $this->template_from(
				$message,
				'body',
				$is_invite ? self::OPTION_INVITE_BODY : self::OPTION_BODY,
				$is_invite ? self::DEFAULT_INVITE_BODY : self::DEFAULT_RECEIPT_BODY
			);
			$subject   = self::interpolate( $subject, $tokens );
			$body      = self::interpolate( $body, $tokens );
			if ( function_exists( 'wpautop' ) ) {
				$body = wpautop( $body );
			}
			$message['subject']    = $subject;
			$message['body']       = $body;
			$message['tokens']     = $tokens;
			$message['from']       = self::from_name();
			$message['fromHeader'] = $this->from_header();
			return $message;
		}

		/**
		 * Live WordPress delivery is skipped in test mode.
		 *
		 * @return bool
		 */
		private function should_deliver() {
			if ( class_exists( 'Portal_Test_Mode' ) && Portal_Test_Mode::is_enabled() ) {
				return false;
			}
			return function_exists( 'wp_mail' );
		}

		/**
		 * Send HTML mail through wp_mail.
		 *
		 * @param array<string,mixed> $payload Prepared message.
		 * @return bool
		 */
		private function deliver( array $payload ) {
			$to      = isset( $payload['to'] ) ? (string) $payload['to'] : self::UNKNOWN_RECIPIENT;
			$subject = isset( $payload['subject'] ) ? (string) $payload['subject'] : self::DEFAULT_RECEIPT_SUBJECT;
			$body    = isset( $payload['body'] ) ? (string) $payload['body'] : '';
			$headers = array( self::HTML_CONTENT_TYPE );
			$from    = $this->from_header();
			if ( '' !== $from ) {
				$headers[] = 'From: ' . $from;
			}
			return (bool) wp_mail( $to, $subject, $body, $headers );
		}

		/**
		 * From display name: site option, or Portal Submissions when empty.
		 *
		 * @return string
		 */
		public static function from_name() {
			$configured = class_exists( 'Portal_Options' )
				? Portal_Options::get( self::OPTION_FROM_NAME, '' )
				: '';
			if ( is_string( $configured ) && '' !== trim( $configured ) ) {
				return trim( $configured );
			}
			return self::DEFAULT_FROM_NAME;
		}

		/**
		 * From header from site options, or empty when the From email is unset.
		 *
		 * @return string
		 */
		private function from_header() {
			$email = $this->site_option( self::OPTION_FROM_EMAIL, '' );
			if ( '' === $email ) {
				return '';
			}
			return self::from_name() . ' <' . $email . '>';
		}

		/**
		 * Prefer an explicit message template, then the site option, then fallback.
		 *
		 * @param array  $message Incoming message.
		 * @param string $key     Message key.
		 * @param string $option  Site option name.
		 * @param string $fallback Fallback template.
		 * @return string
		 */
		private function template_from( array $message, $key, $option, $fallback ) {
			if ( isset( $message[ $key ] ) && is_string( $message[ $key ] ) && '' !== $message[ $key ] ) {
				return $message[ $key ];
			}
			return $this->site_option( $option, $fallback );
		}

		/**
		 * Read a string site option, or the fallback when empty or WP is absent.
		 *
		 * @param string $key      Option name.
		 * @param string $fallback Fallback.
		 * @return string
		 */
		private function site_option( $key, $fallback ) {
			if ( ! class_exists( 'Portal_Options' ) ) {
				return $fallback;
			}
			$value = Portal_Options::get( $key, $fallback );
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				return $fallback;
			}
			return $value;
		}

		/**
		 * Closed token set plus receipt_url / $receiptLink aliases.
		 *
		 * @param array<string,mixed> $tokens Caller tokens.
		 * @return array<string,string>
		 */
		private static function token_map( array $tokens ) {
			$receipt = self::token_string( $tokens, array( 'receipt_url', 'receiptLink', '$receiptLink' ) );
			$title   = self::token_string( $tokens, array( 'portal_title', 'portalName', 'portal_name' ) );
			$notify  = self::token_string( $tokens, array( 'notification_date', 'application_notification_date' ) );
			$when    = self::token_string( $tokens, array( 'submitted_at', 'date_received' ) );
			$invite  = self::token_string( $tokens, array( 'invite_link', 'invite_url', '$inviteLink' ) );
			$expires = self::token_string( $tokens, array( 'expires_at', 'expiresAt' ) );
			return array(
				'$receiptLink'                   => $receipt,
				'$application_notification_date' => $notify,
				'$date_received'                 => $when,
				'$inviteLink'                    => $invite,
				'$expiresAt'                     => $expires,
				'$portalName'                    => $title,
				'$portal_name'                   => $title,
				'$link'                          => $receipt,
				'receiptLink'                    => $receipt,
				'receipt_url'                    => $receipt,
				'invite_link'                    => $invite,
				'invite_url'                     => $invite,
				'expires_at'                     => $expires,
				'expiresAt'                      => $expires,
				'application_id'                 => self::token_string( $tokens, array( 'application_id' ) ),
				'applicant_name'                 => self::token_string( $tokens, array( 'applicant_name' ) ),
				'applicant_email'                => self::token_string( $tokens, array( 'applicant_email', 'email' ) ),
				'portal_title'                   => $title,
				'portalName'                     => $title,
				'portal_name'                    => $title,
				'selection'                      => self::token_string( $tokens, array( 'selection' ) ),
				'submitted_at'                   => $when,
				'notification_date'              => $notify,
			);
		}

		/**
		 * Closed token set for an invite e-mail.
		 *
		 * @param int|string          $portal_id Portal post id.
		 * @param array<string,mixed> $invite    Invite array.
		 * @param string              $title     Portal title.
		 * @return array<string,string>
		 */
		private static function invite_tokens( $portal_id, array $invite, $title = '' ) {
			$link = class_exists( 'Portal_Invites' )
				? (string) Portal_Invites::url( $portal_id, $invite )
				: '';
			return array(
				'portal_title'    => (string) $title,
				'invite_link'     => $link,
				'invite_url'      => $link,
				'expires_at'      => isset( $invite['expiresAt'] ) ? (string) $invite['expiresAt'] : '',
				'applicant_name'  => isset( $invite['name'] ) ? (string) $invite['name'] : '',
				'applicant_email' => isset( $invite['email'] ) ? (string) $invite['email'] : '',
			);
		}

		/**
		 * First present scalar token from a preference list.
		 *
		 * @param array<string,mixed> $tokens Token bag.
		 * @param string[]            $keys   Preference order.
		 * @return string
		 */
		private static function token_string( array $tokens, array $keys ) {
			foreach ( $keys as $key ) {
				if ( isset( $tokens[ $key ] ) && is_scalar( $tokens[ $key ] ) ) {
					return (string) $tokens[ $key ];
				}
			}
			return '';
		}
	}
}
