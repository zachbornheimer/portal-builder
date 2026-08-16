<?php
/**
 * Named Google-probe failures as one human sentence. Never a stack.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Google_Probe_Failure' ) ) {

	/**
	 * Cause codes and the operator-facing sentence for each.
	 */
	class Portal_Google_Probe_Failure {

		const MISSING_SECRET = 'missing_secret';
		const MISSING_TOKEN  = 'missing_token';
		const FORBIDDEN      = 'forbidden';
		const SHARED_DRIVE   = 'shared_drive';
		const BAD_ID         = 'bad_id';
		const MISSING_SHEET  = 'missing_sheet';
		const CAPABILITY     = 'capability';
		const NONCE          = 'nonce';
		const UNKNOWN        = 'unknown';

		/**
		 * Operator-facing sentence for a named cause.
		 *
		 * @param string $code One of the class constants.
		 * @return string
		 */
		public static function sentence( $code ) {
			switch ( (string) $code ) {
				case self::MISSING_SECRET:
					return __( 'The Google Secret Key is missing. Paste the OAuth client JSON on Default Settings.', 'portal-builder' );
				case self::MISSING_TOKEN:
					return __( 'The Google Access Key is missing. Paste the OAuth access token on Default Settings.', 'portal-builder' );
				case self::FORBIDDEN:
					return __( 'Google denied access (403). Share the folder and Sheet with the Google identity that completed OAuth.', 'portal-builder' );
				case self::SHARED_DRIVE:
					return __( 'Google treated this as a Shared Drive and denied the list. Confirm that identity can open the Shared Drive, not only My Drive.', 'portal-builder' );
				case self::BAD_ID:
					return __( 'That folder or Sheet ID is not valid. Check you pasted the ID, not a broken link.', 'portal-builder' );
				case self::MISSING_SHEET:
					return __( 'A Sheet ID is required to append the test row.', 'portal-builder' );
				case self::CAPABILITY:
					return __( 'You need administrator access to test the Google connection.', 'portal-builder' );
				case self::NONCE:
					return __( 'This test form expired. Reload Default Settings and try again.', 'portal-builder' );
				default:
					return __( 'The Google test failed. Check the Secret Key, Access Key, folder ID, and Sheet ID.', 'portal-builder' );
			}
		}

		/**
		 * Map a store or Google exception to one named cause.
		 *
		 * @param mixed $error Exception or message.
		 * @return string Cause code.
		 */
		public static function classify( $error ) {
			$message = $error instanceof Exception ? $error->getMessage() : (string) $error;
			$code    = $error instanceof Exception ? (int) $error->getCode() : 0;
			$lower   = strtolower( $message );

			if ( false !== strpos( $lower, 'client secret' ) || false !== strpos( $lower, 'secret key' ) ) {
				return self::MISSING_SECRET;
			}
			if ( false !== strpos( $lower, 'access token' ) || false !== strpos( $lower, 'invalid_grant' ) || false !== strpos( $lower, 'login required' ) ) {
				return self::MISSING_TOKEN;
			}
			if ( false !== strpos( $lower, 'shared drive' ) || false !== strpos( $lower, 'supportsalldrives' ) || false !== strpos( $lower, 'team drive' ) ) {
				return self::SHARED_DRIVE;
			}
			if ( 403 === $code || false !== strpos( $lower, '403' ) || false !== strpos( $lower, 'the caller does not have permission' ) || false !== strpos( $lower, 'forbidden' ) ) {
				return self::FORBIDDEN;
			}
			if ( 404 === $code || false !== strpos( $lower, '404' ) || false !== strpos( $lower, 'not found' ) || false !== strpos( $lower, 'unable to parse' ) ) {
				return self::BAD_ID;
			}
			return self::UNKNOWN;
		}
	}
}
