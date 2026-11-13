<?php
/**
 * Closed / preview admission for definition submit.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Submit_Admission' ) ) {

	/**
	 * Pure decide: preview and closed must not Accept.
	 */
	class Portal_Submit_Admission {

		const CODE_PREVIEW = 'dg_submission_preview';
		const CODE_CLOSED  = 'dg_submission_closed';

		const MSG_PREVIEW = 'Preview cannot accept a live submission.';
		const MSG_CLOSED  = 'This portal is not open for submissions.';

		/**
		 * @param bool $is_preview Editor preview request.
		 * @param bool $is_open    Portal is open for public submit.
		 * @return WP_Error|null Error to reject, or null to proceed.
		 */
		public static function decide( $is_preview, $is_open ) {
			if ( $is_preview ) {
				return new WP_Error( self::CODE_PREVIEW, self::MSG_PREVIEW );
			}
			if ( ! $is_open ) {
				return new WP_Error( self::CODE_CLOSED, self::MSG_CLOSED );
			}
			return null;
		}
	}
}
