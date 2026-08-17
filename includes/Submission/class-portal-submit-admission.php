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
		const CODE_VIEW_AS = 'dg_submission_view_as';

		const MSG_PREVIEW = 'Preview cannot accept a live submission.';
		const MSG_CLOSED  = 'This portal is not open for submissions.';
		const MSG_VIEW_AS = 'View-as cannot accept a live submission.';

		/**
		 * Reject preview, view-as, or a closed portal.
		 *
		 * @param bool $is_preview Editor preview request.
		 * @param bool $is_open    Portal is open for public submit.
		 * @param bool $is_view_as Staff view-as overlay is active.
		 * @return WP_Error|null Error to reject, or null to proceed.
		 */
		public static function decide( $is_preview, $is_open, $is_view_as = false ) {
			if ( $is_preview ) {
				return new WP_Error( self::CODE_PREVIEW, self::MSG_PREVIEW );
			}
			if ( $is_view_as ) {
				return new WP_Error( self::CODE_VIEW_AS, self::MSG_VIEW_AS );
			}
			if ( ! $is_open ) {
				return new WP_Error( self::CODE_CLOSED, self::MSG_CLOSED );
			}
			return null;
		}
	}
}
