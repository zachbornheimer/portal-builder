<?php
/**
 * Public portal content: definition-first render + closed/preview gate.
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

		/**
		 * Wire the_content filter.
		 */
		public static function init() {
			add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 9 );
		}

		/**
		 * Definition-first content filter with open/closed/preview behavior.
		 *
		 * @param string $content Post content.
		 * @return string
		 */
		public static function filter_content( $content ) {
			if ( ! is_singular( 'portal' ) ) {
				return $content;
			}

			$post_id = (int) get_the_ID();
			if ( $post_id <= 0 ) {
				return $content;
			}

			// Submission success / review flows own the content.
			if ( defined( 'PB_APPLICATION_SUBMITTED' ) && PB_APPLICATION_SUBMITTED ) {
				return $content;
			}

			$is_preview = Portal_Open_State::is_preview_request( $post_id );
			$is_open    = Portal_Open_State::is_open( $post_id );
			$show_form  = $is_open || $is_preview;

			if ( ! $show_form ) {
				if ( ! defined( 'PB_APPLICATION_DEADLINE_PASSED' ) ) {
					// Reused by formstart/formend shortcodes to hide form chrome.
					define( 'PB_APPLICATION_DEADLINE_PASSED', true );
				}
				return self::render_closed_message( $post_id );
			}

			$banner = $is_preview ? self::render_preview_banner() : '';
			$definition = Portal_Definition::load_for_post( $post_id );

			if ( is_array( $definition ) ) {
				return $banner . self::render_definition_stub( $definition );
			}

			return $banner . self::wrap_legacy( $content );
		}

		/**
		 * Closed message (no form fields).
		 *
		 * @param int $post_id Portal ID.
		 * @return string
		 */
		public static function render_closed_message( $post_id ) {
			$reason = Portal_Open_State::closed_reason( $post_id );
			$message = self::MSG_NOT_ACCEPTING;
			if ( 'deadline' === $reason ) {
				$message = self::MSG_DEADLINE;
			} elseif ( 'force' === $reason ) {
				$message = self::MSG_CLOSED;
			}

			return sprintf(
				'<div class="dg-portal-closed" data-dg-portal-state="closed" data-dg-closed-reason="%s"><p>%s</p></div>',
				esc_attr( (string) $reason ),
				esc_html( $message )
			);
		}

		/**
		 * Preview banner for editors.
		 *
		 * @return string
		 */
		public static function render_preview_banner() {
			return sprintf(
				'<div class="dg-preview-banner" data-dg-preview="true" role="status">%s</div>',
				esc_html( self::PREVIEW_BANNER_TEXT )
			);
		}

		/**
		 * Minimal definition field renderer (labels + markers until full field UI lands).
		 *
		 * @param array $definition Validated definition.
		 * @return string
		 */
		public static function render_definition_stub( array $definition ) {
			$fields = isset( $definition['fields'] ) && is_array( $definition['fields'] )
				? $definition['fields']
				: array();

			$parts   = array();
			$parts[] = sprintf(
				'<div class="dg-portal-form" data-dg-render="%s" data-dg-form="definition">',
				esc_attr( self::ATTR_RENDER_DEFINITION )
			);
			$parts[] = self::render_fields_html( $fields );
			$parts[] = '</div>';

			return implode( '', $parts );
		}

		/**
		 * Recursively render field labels as form markers.
		 *
		 * @param array $fields Field list.
		 * @return string
		 */
		private static function render_fields_html( array $fields ) {
			$html = '';
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) || empty( $field['id'] ) ) {
					continue;
				}
				$id    = (string) $field['id'];
				$type  = isset( $field['type'] ) ? (string) $field['type'] : '';
				$label = isset( $field['label'] ) ? (string) $field['label'] : $id;

				$html .= sprintf(
					'<div class="dg-field" data-dg-field-id="%s" data-dg-field-type="%s">',
					esc_attr( $id ),
					esc_attr( $type )
				);
				$html .= sprintf(
					'<span class="dg-field-label">%s</span>',
					esc_html( $label )
				);

				if ( 'group' === $type && ! empty( $field['children'] ) && is_array( $field['children'] ) ) {
					$html .= '<div class="dg-field-children">';
					$html .= self::render_fields_html( $field['children'] );
					$html .= '</div>';
				}

				if ( 'branch' === $type && ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
					$html .= '<div class="dg-field-branch-options">';
					foreach ( $field['options'] as $opt ) {
						if ( ! is_array( $opt ) || empty( $opt['id'] ) ) {
							continue;
						}
						$html .= sprintf(
							'<div class="dg-branch-option" data-dg-option-id="%s"><span class="dg-field-label">%s</span>',
							esc_attr( (string) $opt['id'] ),
							esc_html( isset( $opt['label'] ) ? (string) $opt['label'] : (string) $opt['id'] )
						);
						if ( ! empty( $opt['children'] ) && is_array( $opt['children'] ) ) {
							$html .= self::render_fields_html( $opt['children'] );
						}
						$html .= '</div>';
					}
					$html .= '</div>';
				}

				$html .= '</div>';
			}
			return $html;
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
	}
}
