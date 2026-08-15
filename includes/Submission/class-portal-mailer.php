<?php
/**
 * Mail capture facade — writes messages under artifactDir/mail/*.json in test mode.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Mailer' ) ) {

	/**
	 * Captures outbound mail as JSON artifacts (real SMTP lands later).
	 */
	class Portal_Mailer {

		const ARTIFACT_DIR_NAME       = 'mail';
		const EXTENSION               = '.json';
		const UNKNOWN_RECIPIENT       = 'unknown';
		const DEFAULT_RECEIPT_SUBJECT = 'Application receipt';
		const DEFAULT_RECEIPT_BODY    = 'We received your application for {{portal_title}}. {{$receiptLink}}';
		const OPTION_FROM_EMAIL       = 'pb_receipt_from_email';
		const OPTION_FROM_NAME        = 'pb_receipt_from_name';
		const OPTION_SUBJECT          = 'pb_receipt_subject';
		const OPTION_BODY             = 'pb_receipt_body';
		const HTML_CONTENT_TYPE       = 'Content-Type: text/html; charset=UTF-8';

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
			try {
				$payload = $this->prepare( $message );
				if ( $this->should_deliver() ) {
					$payload['wpMail']     = true;
					$payload['wpMailSent'] = $this->deliver( $payload );
					if ( empty( $payload['wpMailSent'] ) ) {
						$payload['error'] = 'wp_mail returned false';
					}
				}
				return $this->capture( $payload );
			} catch ( Exception $e ) {
				$message['error'] = $e->getMessage();
				return $this->capture( $message );
			}
		}

		/**
		 * Fill subject/body from options and interpolate tokens.
		 *
		 * @param array<string,mixed> $message Raw send() input.
		 * @return array<string,mixed>
		 */
		private function prepare( array $message ) {
			$tokens  = isset( $message['tokens'] ) && is_array( $message['tokens'] )
				? $message['tokens']
				: array();
			$subject = $this->template_from( $message, 'subject', self::OPTION_SUBJECT, self::DEFAULT_RECEIPT_SUBJECT );
			$body    = $this->template_from( $message, 'body', self::OPTION_BODY, self::DEFAULT_RECEIPT_BODY );
			$subject = self::interpolate( $subject, $tokens );
			$body    = self::interpolate( $body, $tokens );
			if ( function_exists( 'wpautop' ) ) {
				$body = wpautop( $body );
			}
			$message['subject'] = $subject;
			$message['body']    = $body;
			$message['tokens']  = $tokens;
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
		 * From header from site options, or empty.
		 *
		 * @return string
		 */
		private function from_header() {
			$email = $this->site_option( self::OPTION_FROM_EMAIL, '' );
			if ( '' === $email ) {
				return '';
			}
			$name = $this->site_option( self::OPTION_FROM_NAME, '' );
			if ( '' === $name ) {
				return $email;
			}
			return $name . ' <' . $email . '>';
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
			if ( ! function_exists( 'get_option' ) ) {
				return $fallback;
			}
			$value = get_option( $key, $fallback );
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
			return array(
				'$receiptLink'                   => $receipt,
				'$application_notification_date' => $notify,
				'$date_received'                 => $when,
				'$portalName'                    => $title,
				'$portal_name'                   => $title,
				'$link'                          => $receipt,
				'receiptLink'                    => $receipt,
				'receipt_url'                    => $receipt,
				'application_id'                 => self::token_string( $tokens, array( 'application_id' ) ),
				'applicant_name'                 => self::token_string( $tokens, array( 'applicant_name' ) ),
				'portal_title'                   => $title,
				'portalName'                     => $title,
				'portal_name'                    => $title,
				'selection'                      => self::token_string( $tokens, array( 'selection' ) ),
				'submitted_at'                   => $when,
				'notification_date'              => $notify,
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
