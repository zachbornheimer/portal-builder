<?php
/**
 * Masked secret options: after save, UI never re-echoes the raw value.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Secret_Field' ) ) {

	/**
	 * Last-four hint + keep-if-blank sanitize for OAuth JSON and tokens.
	 */
	class Portal_Secret_Field {

		const ROTATION_HELP = 'Leave blank to keep the saved value. Paste a new value to rotate.';

		/**
		 * @param mixed $stored Saved option.
		 * @return bool
		 */
		public static function is_set( $stored ) {
			return is_string( $stored ) && '' !== trim( $stored );
		}

		/**
		 * @param mixed $stored Saved option.
		 * @return string Empty when unset.
		 */
		public static function last_four( $stored ) {
			if ( ! self::is_set( $stored ) ) {
				return '';
			}
			$trimmed = trim( (string) $stored );
			$tail    = strlen( $trimmed ) >= 4 ? substr( $trimmed, -4 ) : $trimmed;
			return '••••' . $tail;
		}

		/**
		 * Incoming blank keeps the stored secret (rotation is replace-on-paste).
		 *
		 * @param mixed $incoming Posted value.
		 * @param mixed $stored   Current option.
		 * @return string
		 */
		public static function keep_if_blank( $incoming, $stored ) {
			if ( is_string( $incoming ) && '' !== trim( $incoming ) ) {
				return (string) $incoming;
			}
			return is_string( $stored ) ? $stored : '';
		}

		/**
		 * Textarea HTML that never contains the raw secret.
		 *
		 * @param string $name    Input name.
		 * @param mixed  $stored  Saved option.
		 * @param string $help    Description (already translated).
		 * @return string
		 */
		public static function render_textarea( $name, $stored, $help ) {
			$set  = self::is_set( $stored );
			$hint = self::last_four( $stored );
			$html = '<div class="pb-protected-wrapper">';
			if ( $set ) {
				$html .= '<p class="description" data-dg-secret-set="1">'
					. esc_html( 'Key set ' . $hint )
					. '</p>';
			}
			$html .= '<textarea name="' . esc_attr( $name ) . '" id="' . esc_attr( $name )
				. '" class="pb-protected-code-field" rows="6" cols="50" autocomplete="new-password"></textarea></div>';
			$html .= '<p class="description">' . esc_html( $help ) . ' ' . esc_html( self::ROTATION_HELP ) . '</p>';
			return $html;
		}
	}
}
