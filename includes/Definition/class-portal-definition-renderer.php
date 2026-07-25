<?php
/**
 * Renders a validated portal definition as public form HTML.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Definition_Renderer' ) ) {

	/**
	 * Turns definition fields[] into accessible form markup.
	 */
	class Portal_Definition_Renderer {

		const ROOT_ATTR  = 'data-dg-render';
		const ROOT_VALUE = 'definition';
		const ROOT_CLASS = 'dg-form portal-definition-form';

		const ACCEPT_SCORE     = 'application/pdf';
		const ACCEPT_RECORDING = 'audio/mpeg,.mp3';
		const ACCEPT_BIO       = 'application/pdf';

		const NAME_PREFIX = 'sub_';

		/**
		 * Render HTML for a portal post's definition, or empty string if none.
		 *
		 * @param int $post_id Portal post ID.
		 * @return string
		 */
		public static function render_for_post( $post_id ) {
			$definition = Portal_Definition::load_for_post( $post_id );
			if ( null === $definition ) {
				return '';
			}
			return self::render( $definition );
		}

		/**
		 * Render a validated definition document to HTML.
		 *
		 * @param array $definition Validated definition (fields required).
		 * @return string
		 */
		public static function render( $definition ) {
			if ( ! is_array( $definition ) || empty( $definition['fields'] ) || ! is_array( $definition['fields'] ) ) {
				return '';
			}

			$inner = self::render_fields( $definition['fields'] );

			return sprintf(
				'<div class="%1$s" %2$s="%3$s">%4$s</div>',
				esc_attr( self::ROOT_CLASS ),
				esc_attr( self::ROOT_ATTR ),
				esc_attr( self::ROOT_VALUE ),
				$inner
			);
		}

		/**
		 * @param array $fields Field list.
		 * @return string
		 */
		public static function render_fields( $fields ) {
			$html = '';
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) || empty( $field['type'] ) ) {
					continue;
				}
				$html .= self::render_field( $field );
			}
			return $html;
		}

		/**
		 * @param array $field Single field.
		 * @return string
		 */
		public static function render_field( $field ) {
			$type = (string) $field['type'];

			switch ( $type ) {
				case 'applicant_pack':
					return self::render_applicant_pack( $field );
				case 'group':
					return self::render_group( $field );
				case 'branch':
					return self::render_branch( $field );
				case 'short_text':
				case 'email':
				case 'phone':
					return self::render_text_input( $field );
				case 'long_text':
					return self::render_textarea( $field );
				case 'score_file':
					return self::render_file_input( $field, self::ACCEPT_SCORE );
				case 'recording_file':
					return self::render_file_input( $field, self::ACCEPT_RECORDING );
				case 'bio_file':
					return self::render_file_input( $field, self::ACCEPT_BIO );
				case 'file':
					return self::render_file_input( $field, '' );
				case 'disclaimer':
					return self::render_disclaimer( $field );
				case 'static_html':
					return self::render_static_html( $field );
				default:
					return '';
			}
		}

		/**
		 * Input name for a non-pack field (legacy sub_ prefix).
		 *
		 * @param string $field_id Field id.
		 * @return string
		 */
		public static function input_name( $field_id ) {
			$id = (string) $field_id;
			if ( 0 === strpos( $id, self::NAME_PREFIX ) ) {
				return $id;
			}
			return self::NAME_PREFIX . $id;
		}

		/**
		 * @param array $field Field.
		 * @return string
		 */
		private static function render_applicant_pack( $field ) {
			$label    = isset( $field['label'] ) ? (string) $field['label'] : 'Your Information';
			$required = ! empty( $field['required'] );

			$req = $required ? ' required aria-required="true"' : '';

			ob_start();
			?>
			<div class="portal-group dg-field dg-field--applicant_pack" data-dg-field-id="<?php echo esc_attr( $field['id'] ); ?>" data-dg-field-type="applicant_pack">
				<fieldset>
					<legend><?php echo esc_html( $label ); ?></legend>
					<div class="form-grid">
						<div class="form-group">
							<label for="sub_title" id="sub_title_label">Title<?php echo $required ? '*' : ''; ?></label>
							<input style="width: auto;" maxlength="5" name="sub_title" id="sub_title" size="5" type="text"<?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-labelledby="sub_title_label" autocomplete="honorific-prefix" />
						</div>
						<div class="form-group">
							<label for="sub_name" id="sub_name_label">Name<?php echo $required ? '*' : ''; ?></label>
							<input name="sub_name" id="sub_name" type="text"<?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-labelledby="sub_name_label" autocomplete="name" />
						</div>
						<div class="form-group">
							<label for="sub_email" id="sub_email_label">Email Address<?php echo $required ? '*' : ''; ?></label>
							<input name="sub_email" id="sub_email" type="email"<?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-labelledby="sub_email_label" autocomplete="email" />
						</div>
						<div class="form-group">
							<label for="sub_inst_affil" id="sub_inst_affil_label">Institutional Affiliation<br /><span class="caption">(if any)</span></label>
							<input name="sub_inst_affil" id="sub_inst_affil" type="text" aria-labelledby="sub_inst_affil_label" autocomplete="organization" />
						</div>
						<div class="form-group">
							<label for="sub_address_first_part" id="sub_address_first_part_label">Address (Home/Work)<?php echo $required ? '*' : ''; ?></label>
							<input name="sub_address_first_part" id="sub_address_first_part" type="text"<?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-labelledby="sub_address_first_part_label" autocomplete="street-address" />
						</div>
						<div class="form-group">
							<label for="sub_city" id="sub_city_label">City<?php echo $required ? '*' : ''; ?></label>
							<input name="sub_city" id="sub_city" type="text"<?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-labelledby="sub_city_label" autocomplete="address-level2" />
						</div>
						<div class="form-group">
							<label for="sub_country" id="sub_country_label">Country<?php echo $required ? '*' : ''; ?></label>
							<select class="gds-cr" country-data-region-id="gds-cr-one" data-language="en" name="sub_country" id="sub_country"<?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-labelledby="sub_country_label" autocomplete="country"></select>
						</div>
						<div class="form-group">
							<label for="sub_state" id="sub_state_label">State/Region<?php echo $required ? '*' : ''; ?></label>
							<select id="gds-cr-one" name="sub_state"<?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-labelledby="sub_state_label" autocomplete="address-level1"></select>
						</div>
						<div class="form-group">
							<label for="sub_zip" id="sub_zip_label">Zip</label>
							<input name="sub_zip" id="sub_zip" type="text" aria-labelledby="sub_zip_label" autocomplete="postal-code" />
						</div>
						<div class="form-group">
							<label for="sub_phone" id="sub_phone_label">Phone<?php echo $required ? '*' : ''; ?></label>
							<input name="sub_phone" id="sub_phone" type="tel"<?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-labelledby="sub_phone_label" autocomplete="tel" />
						</div>
					</div>
				</fieldset>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * @param array $field Field.
		 * @return string
		 */
		private static function render_group( $field ) {
			$label    = isset( $field['label'] ) ? (string) $field['label'] : '';
			$children = isset( $field['children'] ) && is_array( $field['children'] ) ? $field['children'] : array();
			$inner    = self::render_fields( $children );

			return sprintf(
				'<div class="portal-group dg-field dg-field--group" data-dg-field-id="%1$s" data-dg-field-type="group"><fieldset><legend>%2$s</legend><div class="form-grid">%3$s</div></fieldset></div>',
				esc_attr( $field['id'] ),
				esc_html( $label ),
				$inner
			);
		}

		/**
		 * @param array $field Field.
		 * @return string
		 */
		private static function render_branch( $field ) {
			$label   = isset( $field['label'] ) ? (string) $field['label'] : '';
			$name    = self::input_name( $field['id'] );
			$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
			$req     = ! empty( $field['required'] ) ? ' required' : '';

			$options_html = '';
			$children_html = '';
			foreach ( $options as $opt ) {
				if ( ! is_array( $opt ) || empty( $opt['id'] ) ) {
					continue;
				}
				$opt_id    = (string) $opt['id'];
				$opt_label = isset( $opt['label'] ) ? (string) $opt['label'] : $opt_id;
				$input_id  = $name . '_' . $opt_id;
				$options_html .= sprintf(
					'<div class="form-group dg-branch-option"><label for="%1$s"><input type="radio" name="%2$s" id="%1$s" value="%3$s"%4$s /> %5$s</label></div>',
					esc_attr( $input_id ),
					esc_attr( $name ),
					esc_attr( $opt_id ),
					$req,
					esc_html( $opt_label )
				);
				$kids = isset( $opt['children'] ) && is_array( $opt['children'] ) ? $opt['children'] : array();
				if ( ! empty( $kids ) ) {
					$children_html .= sprintf(
						'<div class="dg-branch-children" data-dg-branch-option="%1$s" hidden>%2$s</div>',
						esc_attr( $opt_id ),
						self::render_fields( $kids )
					);
				}
				// Only first option needs required on radio group.
				$req = '';
			}

			return sprintf(
				'<div class="portal-group dg-field dg-field--branch" data-dg-field-id="%1$s" data-dg-field-type="branch"><fieldset><legend>%2$s</legend><div class="form-grid">%3$s</div>%4$s</fieldset></div>',
				esc_attr( $field['id'] ),
				esc_html( $label ),
				$options_html,
				$children_html
			);
		}

		/**
		 * @param array $field Field.
		 * @return string
		 */
		private static function render_text_input( $field ) {
			$name     = self::input_name( $field['id'] );
			$label    = self::label_text( $field );
			$help     = self::help_html( $field );
			$required = ! empty( $field['required'] ) ? ' required aria-required="true"' : '';
			$input_type = 'text';
			if ( 'email' === $field['type'] ) {
				$input_type = 'email';
			} elseif ( 'phone' === $field['type'] ) {
				$input_type = 'tel';
			}

			return sprintf(
				'<div class="form-group dg-field dg-field--%1$s" data-dg-field-id="%2$s" data-dg-field-type="%1$s"><label for="%3$s">%4$s%5$s</label><input type="%6$s" id="%3$s" name="%3$s"%7$s /></div>',
				esc_attr( $field['type'] ),
				esc_attr( $field['id'] ),
				esc_attr( $name ),
				esc_html( $label ),
				$help,
				esc_attr( $input_type ),
				$required
			);
		}

		/**
		 * @param array $field Field.
		 * @return string
		 */
		private static function render_textarea( $field ) {
			$name     = self::input_name( $field['id'] );
			$label    = self::label_text( $field );
			$help     = self::help_html( $field );
			$required = ! empty( $field['required'] ) ? ' required aria-required="true"' : '';

			return sprintf(
				'<div class="form-group dg-field dg-field--long_text full-span" data-dg-field-id="%1$s" data-dg-field-type="long_text"><label for="%2$s">%3$s%4$s</label><textarea id="%2$s" name="%2$s" rows="5"%5$s></textarea></div>',
				esc_attr( $field['id'] ),
				esc_attr( $name ),
				esc_html( $label ),
				$help,
				$required
			);
		}

		/**
		 * @param array  $field  Field.
		 * @param string $accept Accept attribute value.
		 * @return string
		 */
		private static function render_file_input( $field, $accept ) {
			$name     = self::input_name( $field['id'] );
			$label    = self::label_text( $field );
			$help     = self::help_html( $field );
			$required = ! empty( $field['required'] ) ? ' required aria-required="true"' : '';
			$accept_a = '' !== $accept ? sprintf( ' accept="%s"', esc_attr( $accept ) ) : '';

			return sprintf(
				'<div class="form-group dg-field dg-field--%1$s" data-dg-field-id="%2$s" data-dg-field-type="%1$s"><label for="%3$s">%4$s%5$s</label><input type="file" id="%3$s" name="%3$s"%6$s%7$s /></div>',
				esc_attr( $field['type'] ),
				esc_attr( $field['id'] ),
				esc_attr( $name ),
				esc_html( $label ),
				$help,
				$accept_a,
				$required
			);
		}

		/**
		 * @param array $field Field.
		 * @return string
		 */
		private static function render_disclaimer( $field ) {
			$name  = self::input_name( $field['id'] );
			$label = isset( $field['label'] ) ? (string) $field['label'] : 'I agree';
			$text  = isset( $field['text'] ) ? (string) $field['text'] : '';
			$req   = ! empty( $field['required'] ) ? ' required aria-required="true"' : '';

			return sprintf(
				'<div class="form-group dg-field dg-field--disclaimer full-span" data-dg-field-id="%1$s" data-dg-field-type="disclaimer"><label for="%2$s"><input type="checkbox" id="%2$s" name="%2$s" value="1"%3$s /> %4$s</label>%5$s</div>',
				esc_attr( $field['id'] ),
				esc_attr( $name ),
				$req,
				esc_html( $label ),
				$text !== '' ? '<div class="dg-disclaimer-text">' . wp_kses_post( $text ) . '</div>' : ''
			);
		}

		/**
		 * @param array $field Field.
		 * @return string
		 */
		private static function render_static_html( $field ) {
			$html = isset( $field['html'] ) ? (string) $field['html'] : '';
			if ( '' === $html && isset( $field['label'] ) ) {
				$html = '<p>' . esc_html( (string) $field['label'] ) . '</p>';
			}
			return sprintf(
				'<div class="dg-field dg-field--static_html full-span" data-dg-field-id="%1$s" data-dg-field-type="static_html">%2$s</div>',
				esc_attr( $field['id'] ),
				wp_kses_post( $html )
			);
		}

		/**
		 * @param array $field Field.
		 * @return string Label including required marker.
		 */
		private static function label_text( $field ) {
			$label = isset( $field['label'] ) ? (string) $field['label'] : (string) $field['id'];
			if ( ! empty( $field['required'] ) ) {
				$label .= '*';
			}
			return $label;
		}

		/**
		 * @param array $field Field.
		 * @return string
		 */
		private static function help_html( $field ) {
			if ( empty( $field['help'] ) ) {
				return '';
			}
			return '<br /><span class="caption">' . esc_html( (string) $field['help'] ) . '</span>';
		}
	}
}
