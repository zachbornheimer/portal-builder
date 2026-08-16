<?php
/**
 * Admin Google connectivity probe: list a folder and append a DRAGONGATE_TEST row.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Google_Probe_Failure' ) ) {
	require_once __DIR__ . '/class-portal-google-probe-failure.php';
}

if ( ! class_exists( 'Portal_Google_Probe' ) ) {

	/**
	 * Settings → Google connection test.
	 */
	class Portal_Google_Probe {

		const TEST_MARKER    = 'DRAGONGATE_TEST';
		const SUCCESS_OPTION = 'dg_google_test_ok';
		const NONCE_ACTION   = 'dg_google_probe';
		const NONCE_FIELD    = 'dg_google_probe_nonce';
		const AJAX_ACTION    = 'dg_google_probe';
		const FOLDER_FIELD   = 'dg_google_probe_folder_id';
		const SHEET_FIELD    = 'dg_google_probe_sheet_id';

		/**
		 * Injected store or live FileStore wrapper.
		 *
		 * @var object|null
		 */
		private $store;

		/**
		 * Optional clock that returns an ISO-8601 UTC timestamp.
		 *
		 * @var callable|null
		 */
		private $clock;

		/**
		 * Optional options bag with get/update.
		 *
		 * @var object|null
		 */
		private $options;

		/**
		 * Construct the probe.
		 *
		 * @param object|null   $store   FileStore or fake with list_folder / append_test_row.
		 * @param callable|null $clock   Returns an ISO-8601 UTC timestamp.
		 * @param object|null   $options Options facade with get/update.
		 */
		public function __construct( $store = null, $clock = null, $options = null ) {
			$this->store   = $store;
			$this->clock   = $clock;
			$this->options = $options;
		}

		/**
		 * One probe row: marker plus timestamp.
		 *
		 * @param string $timestamp ISO-8601 UTC.
		 * @return string[]
		 */
		public static function test_row( $timestamp ) {
			return array( self::TEST_MARKER, (string) $timestamp );
		}

		/**
		 * Human sentence for a named probe failure.
		 *
		 * @param string $code Portal_Google_Probe_Failure cause.
		 * @return string
		 */
		public static function explain( $code ) {
			return Portal_Google_Probe_Failure::sentence( $code );
		}

		/**
		 * Accept a bare ID or a Drive/Sheets URL.
		 *
		 * @param mixed $raw Pasted value.
		 * @return string
		 */
		public static function pasted_id( $raw ) {
			$raw = trim( (string) $raw );
			if ( '' === $raw ) {
				return '';
			}
			if ( preg_match( '#/(?:d|folders)/([a-zA-Z0-9_-]+)#', $raw, $m ) ) {
				return $m[1];
			}
			if ( preg_match( '#[?&]id=([a-zA-Z0-9_-]+)#', $raw, $m ) ) {
				return $m[1];
			}
			return $raw;
		}

		/**
		 * List the folder when an ID is present, then append the test row.
		 *
		 * @param string $folder_id Optional Drive folder ID.
		 * @param string $sheet_id  Required spreadsheet ID.
		 * @return array{ok:bool,message:string,code?:string,row?:string[],listed?:mixed}
		 */
		public function run( $folder_id, $sheet_id ) {
			$secret_key = class_exists( 'Portal_Google_Store' ) ? Portal_Google_Store::OPTION_SECRET_KEY : 'pb_google_secret_key';
			$token_key  = class_exists( 'Portal_Google_Store' ) ? Portal_Google_Store::OPTION_ACCESS_KEY : 'pb_google_access_key';
			if ( '' === trim( (string) $this->option( $secret_key ) ) ) {
				return $this->fail( Portal_Google_Probe_Failure::MISSING_SECRET );
			}
			if ( '' === trim( (string) $this->option( $token_key ) ) ) {
				return $this->fail( Portal_Google_Probe_Failure::MISSING_TOKEN );
			}

			$folder_id = self::pasted_id( $folder_id );
			$sheet_id  = self::pasted_id( $sheet_id );
			if ( '' === $sheet_id ) {
				return $this->fail( Portal_Google_Probe_Failure::MISSING_SHEET );
			}
			if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $sheet_id ) || ( '' !== $folder_id && ! preg_match( '/^[A-Za-z0-9_-]+$/', $folder_id ) ) ) {
				return $this->fail( Portal_Google_Probe_Failure::BAD_ID );
			}

			$listed = null;
			$row    = self::test_row( $this->now() );
			try {
				$store = $this->store();
				if ( '' !== $folder_id ) {
					$listed = $store->list_folder( $folder_id );
				}
				$store->append_test_row( $sheet_id, $row );
			} catch ( Exception $e ) {
				return $this->fail( Portal_Google_Probe_Failure::classify( $e ) );
			}

			$this->remember_success( $row[1] );
			$message = '' === $folder_id
				? __( 'Appended a DRAGONGATE_TEST row. Delete that row from the Sheet.', 'portal-builder' )
				: sprintf(
					/* translators: %d: number of files listed in the folder */
					__( 'Listed %d item(s) and appended a DRAGONGATE_TEST row. Delete that row from the Sheet.', 'portal-builder' ),
					is_array( $listed ) ? count( $listed ) : 0
				);
			return array(
				'ok'      => true,
				'message' => $message,
				'row'     => $row,
				'listed'  => $listed,
			);
		}

		/**
		 * Settings field markup: folder ID, Sheet ID, button, help, result slot.
		 *
		 * @return void
		 */
		public static function render_admin() {
			$last = function_exists( 'get_option' ) ? (string) get_option( self::SUCCESS_OPTION, '' ) : '';
			echo '<div id="dg-google-probe">';
			echo '<p><label for="dg-google-probe-folder-id">' . esc_html__( 'Drive folder ID (optional)', 'portal-builder' ) . '</label><br />';
			echo '<input type="text" class="regular-text" id="dg-google-probe-folder-id" name="' . esc_attr( self::FOLDER_FIELD ) . '" value="" autocomplete="off" /></p>';
			echo '<p><label for="dg-google-probe-sheet-id">' . esc_html__( 'Sheet ID (required to append a test row)', 'portal-builder' ) . '</label><br />';
			echo '<input type="text" class="regular-text" id="dg-google-probe-sheet-id" name="' . esc_attr( self::SHEET_FIELD ) . '" value="" autocomplete="off" /></p>';
			if ( function_exists( 'wp_nonce_field' ) ) {
				wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
			}
			echo '<p><button type="button" class="button" id="dg-google-probe-submit">' . esc_html__( 'Test Google connection', 'portal-builder' ) . '</button></p>';
			echo '<p class="description">' . esc_html__( 'This appends one row labeled DRAGONGATE_TEST plus a timestamp. Delete this row after the test.', 'portal-builder' ) . '</p>';
			echo '<div id="dg-google-probe-result" class="notice inline" hidden></div>';
			if ( '' !== $last ) {
				echo '<p class="description">' . esc_html(
					sprintf(
						/* translators: %s: ISO timestamp of last successful probe */
						__( 'Last successful test: %s', 'portal-builder' ),
						$last
					)
				) . '</p>';
			}
			echo '</div>';
		}

		/**
		 * Named failure payload. Never wp_die.
		 *
		 * @param string $code Failure cause.
		 * @return array{ok:false,message:string,code:string}
		 */
		private function fail( $code ) {
			return array(
				'ok'      => false,
				'message' => self::explain( $code ),
				'code'    => $code,
			);
		}

		/**
		 * Live FileStore wrapped so list/append send Shared Drive flags.
		 *
		 * @return object
		 * @throws Exception If Google store classes are missing.
		 */
		private function store() {
			if ( is_object( $this->store ) ) {
				return $this->store;
			}
			if ( ! class_exists( 'Portal_Google_Store' ) || ! class_exists( 'Portal_Google_Probe_Store' ) ) {
				throw new Exception( 'Google store is not available.' );
			}
			$this->store = new Portal_Google_Probe_Store( Portal_Google_Store::file_store_from_options() );
			return $this->store;
		}

		/**
		 * Current UTC timestamp.
		 *
		 * @return string
		 */
		private function now() {
			if ( is_callable( $this->clock ) ) {
				return (string) call_user_func( $this->clock );
			}
			return gmdate( 'Y-m-d\TH:i:s\Z' );
		}

		/**
		 * Read a stored option from the injected bag or WordPress.
		 *
		 * @param string $name     Option name.
		 * @param mixed  $fallback Value when the option is unset.
		 * @return mixed
		 */
		private function option( $name, $fallback = '' ) {
			if ( is_object( $this->options ) && method_exists( $this->options, 'get' ) ) {
				return $this->options->get( $name, $fallback );
			}
			return function_exists( 'get_option' ) ? get_option( $name, $fallback ) : $fallback;
		}

		/**
		 * Stamp the success option so a later activate notice can dismiss.
		 *
		 * @param string $timestamp ISO-8601 UTC.
		 * @return void
		 */
		private function remember_success( $timestamp ) {
			if ( is_object( $this->options ) && method_exists( $this->options, 'update' ) ) {
				$this->options->update( self::SUCCESS_OPTION, $timestamp );
				return;
			}
			if ( function_exists( 'update_option' ) ) {
				update_option( self::SUCCESS_OPTION, $timestamp );
			}
		}
	}
}
