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

		const TEST_BANNER_TEXT  = 'Test — not a real application.';
		const TEST_BANNER_CLASS = 'dg-test-banner';

		const ACCEPT_SCORE     = 'application/pdf';
		const ACCEPT_RECORDING = 'audio/mpeg,.mp3';
		const ACCEPT_BIO       = 'application/pdf';

		const FILE_SIZE_CAPTION = 'keep under 32 MB';

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
			$site = class_exists( 'Portal_Site_Defaults' )
				? Portal_Site_Defaults::read_site()
				: array();
			return self::render( $definition, $site );
		}

		/**
		 * Render a validated definition document to HTML.
		 *
		 * @param array $definition Validated definition (fields required).
		 * @param array $site       Site bag for inherit resolve (empty in CLI harness).
		 * @return string
		 */
		public static function render( $definition, $site = array() ) {
			if ( ! is_array( $definition ) || empty( $definition['fields'] ) || ! is_array( $definition['fields'] ) ) {
				return '';
			}

			$inner  = self::render_test_banner( $definition );
			$inner .= self::render_fields( $definition['fields'] );
			$inner .= self::render_site_disclaimers();
			$inner .= self::render_anonymize_ack_if_needed( $definition, $site );

			return sprintf(
				'<div class="%1$s" %2$s="%3$s" data-dg-form data-testid="dg-form">%4$s</div>',
				esc_attr( self::ROOT_CLASS ),
				esc_attr( self::ROOT_ATTR ),
				esc_attr( self::ROOT_VALUE ),
				$inner
			);
		}

		/**
		 * Applicant-facing banner when the portal is in per-portal testMode.
		 *
		 * @param array $definition Validated definition.
		 * @return string
		 */
		private static function render_test_banner( array $definition ) {
			if ( ! class_exists( 'Portal_Definition' ) || ! Portal_Definition::test_mode_on( $definition ) ) {
				return '';
			}
			return sprintf(
				'<div class="%1$s" data-dg-test-mode="true" role="status"><strong>%2$s</strong></div>',
				esc_attr( self::TEST_BANNER_CLASS ),
				esc_html( self::TEST_BANNER_TEXT )
			);
		}

		/**
		 * Required site-wide legal checkboxes from dg_legal_disclaimers.
		 *
		 * Honors $GLOBALS['dg_test_legal_disclaimers'] via Portal_Legal_Disclaimers::rows().
		 *
		 * @return string
		 */
		private static function render_site_disclaimers() {
			if ( ! class_exists( 'Portal_Legal_Disclaimers' ) ) {
				return '';
			}
			$html = '';
			foreach ( Portal_Legal_Disclaimers::rows() as $row ) {
				$html .= self::render_disclaimer(
					array(
						'id'       => $row['field_id'],
						'label'    => $row['text'],
						'required' => true,
					)
				);
			}
			return $html;
		}

		/**
		 * Required certification when resolved anonymize is on.
		 *
		 * @param array $definition Definition document.
		 * @param array $site       Site bag.
		 * @return string
		 */
		private static function render_anonymize_ack_if_needed( array $definition, array $site ) {
			if ( ! class_exists( 'Portal_Site_Defaults' ) ) {
				return '';
			}
			$resolved = Portal_Site_Defaults::resolve( $definition, $site );
			if ( empty( $resolved['anonymize'] ) ) {
				return '';
			}
			$text = isset( $resolved['anonymizeAck'] ) ? (string) $resolved['anonymizeAck'] : '';
			if ( '' === $text ) {
				$text = Portal_Site_Defaults::BUILTIN_ANONYMIZE_ACK;
			}
			return self::render_anonymize_ack( $text );
		}

		/**
		 * Disclaimer-style required checkbox for anonymize certification.
		 *
		 * Public contract: field id anonymize_ack, input name sub_anonymize_ack.
		 *
		 * @param string $label Certification text.
		 * @return string
		 */
		private static function render_anonymize_ack( $label ) {
			$id   = 'anonymize_ack';
			$name = self::input_name( $id ); // sub_anonymize_ack
			return sprintf(
				'<div class="dg-field dg-field--disclaimer full-span" data-dg-field-id="%1$s" data-dg-field-type="disclaimer" data-testid="dg-field-%1$s"><label class="dg-check" for="%2$s"><input type="checkbox" id="%2$s" name="%2$s" value="1" required aria-required="true" /><span>%3$s</span></label></div>',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_html( $label )
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
			<div class="dg-field dg-field--applicant_pack dg-section" data-dg-field-id="<?php echo esc_attr( $field['id'] ); ?>" data-dg-field-type="applicant_pack" data-testid="dg-field-<?php echo esc_attr( $field['id'] ); ?>">
				<fieldset>
					<legend><?php echo esc_html( $label ); ?></legend>
					<div class="dg-grid dg-grid-title-name">
						<div class="dg-field">
							<label for="sub_title" id="sub_title_label">Title<?php self::echo_required_mark( $required ); ?></label>
							<input class="dg-control" maxlength="5" name="sub_title" id="sub_title" size="5" type="text"<?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-labelledby="sub_title_label" autocomplete="honorific-prefix" />
						</div>
						<div class="dg-field">
							<label for="sub_name" id="sub_name_label">Name<?php self::echo_required_mark( $required ); ?></label>
							<input class="dg-control" name="sub_name" id="sub_name" type="text"<?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-labelledby="sub_name_label" autocomplete="name" />
						</div>
					</div>
					<div class="dg-grid dg-grid-2">
						<div class="dg-field">
							<label for="sub_email" id="sub_email_label">Email Address<?php self::echo_required_mark( $required ); ?></label>
							<input class="dg-control" name="sub_email" id="sub_email" type="email"<?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-labelledby="sub_email_label" autocomplete="email" />
						</div>
						<div class="dg-field">
							<label for="sub_inst_affil" id="sub_inst_affil_label">Institutional Affiliation <span class="caption">(optional)</span></label>
							<input class="dg-control" name="sub_inst_affil" id="sub_inst_affil" type="text" aria-labelledby="sub_inst_affil_label" autocomplete="organization" />
						</div>
					</div>
					<div class="dg-field">
						<label for="sub_address_first_part" id="sub_address_first_part_label">Address (Home/Work)<?php self::echo_required_mark( $required ); ?></label>
						<input class="dg-control" name="sub_address_first_part" id="sub_address_first_part" type="text"<?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-labelledby="sub_address_first_part_label" autocomplete="street-address" />
					</div>
					<div class="dg-grid dg-grid-3">
						<div class="dg-field">
							<label for="sub_city" id="sub_city_label">City<?php self::echo_required_mark( $required ); ?></label>
							<input class="dg-control" name="sub_city" id="sub_city" type="text"<?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-labelledby="sub_city_label" autocomplete="address-level2" />
						</div>
						<div class="dg-field">
							<label for="sub_country" id="sub_country_label">Country<?php self::echo_required_mark( $required ); ?></label>
							<select class="gds-cr dg-control" country-data-region-id="gds-cr-one" data-language="en" name="sub_country" id="sub_country"<?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-labelledby="sub_country_label" autocomplete="country"></select>
						</div>
						<div class="dg-field">
							<label for="sub_state" id="sub_state_label">State/Region<?php self::echo_required_mark( $required ); ?></label>
							<select class="dg-control" id="gds-cr-one" name="sub_state"<?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-labelledby="sub_state_label" autocomplete="address-level1"></select>
						</div>
					</div>
					<div class="dg-grid dg-grid-zip-phone">
						<div class="dg-field">
							<label for="sub_zip" id="sub_zip_label">Zip</label>
							<input class="dg-control" name="sub_zip" id="sub_zip" type="text" aria-labelledby="sub_zip_label" autocomplete="postal-code" />
						</div>
						<div class="dg-field">
							<label for="sub_phone" id="sub_phone_label">Phone<?php self::echo_required_mark( $required ); ?></label>
							<input class="dg-control" name="sub_phone" id="sub_phone" type="tel"<?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-labelledby="sub_phone_label" autocomplete="tel" />
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
				'<div class="dg-field dg-field--group dg-section" data-dg-field-id="%1$s" data-dg-field-type="group" data-testid="dg-field-%1$s"><fieldset><legend>%2$s</legend><div class="dg-stack">%3$s</div></fieldset></div>',
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
					'<div class="dg-branch-option"><label for="%1$s"><input type="radio" name="%2$s" id="%1$s" value="%3$s"%4$s /> %5$s</label></div>',
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
				'<div class="dg-field dg-field--branch" data-dg-field-id="%1$s" data-dg-field-type="branch" data-testid="dg-field-%1$s"><fieldset><legend>%2$s</legend><div class="dg-choice-list">%3$s</div>%4$s</fieldset></div>',
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

			$req_mark = ! empty( $field['required'] ) ? self::required_mark_html() : '';

			return sprintf(
				'<div class="dg-field dg-field--%1$s" data-dg-field-id="%2$s" data-dg-field-type="%1$s" data-testid="dg-field-%2$s"><label for="%3$s">%4$s%5$s%6$s</label><input class="dg-control" type="%7$s" id="%3$s" name="%3$s"%8$s /></div>',
				esc_attr( $field['type'] ),
				esc_attr( $field['id'] ),
				esc_attr( $name ),
				esc_html( $label ),
				$req_mark,
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

			$req_mark = ! empty( $field['required'] ) ? self::required_mark_html() : '';

			return sprintf(
				'<div class="dg-field dg-field--long_text full-span" data-dg-field-id="%1$s" data-dg-field-type="long_text" data-testid="dg-field-%1$s"><label for="%2$s">%3$s%4$s%5$s</label><textarea class="dg-control" id="%2$s" name="%2$s" rows="5"%6$s></textarea></div>',
				esc_attr( $field['id'] ),
				esc_attr( $name ),
				esc_html( $label ),
				$req_mark,
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
			$staged   = $name . '_staged';
			$label    = self::label_text( $field );
			$required = ! empty( $field['required'] );
			$req_a    = $required ? ' required aria-required="true"' : '';
			$accept_a = '' !== $accept ? sprintf( ' accept="%s"', esc_attr( $accept ) ) : '';
			$hint     = self::file_hint( $field, $accept );
			$icon     = self::file_icon_label( $accept );
			$prompt   = self::file_drop_prompt( $accept );

			$open_idle     = self::translate( 'Open to confirm' );
			$open_opened   = self::translate( 'Open' );
			$confirm_idle  = self::translate( 'Open the file to confirm it isn’t corrupt.' );
			$confirm_ready = self::translate( 'This file opened and is readable.' );
			$remove_label  = self::translate( 'Remove upload' );
			$error_label   = self::translate( 'Open the file to confirm it isn’t corrupt.' );

			return sprintf(
				'<div class="dg-field dg-field--%1$s dg-file is-empty" data-dg-field-id="%2$s" data-dg-field-type="%1$s" data-testid="dg-field-%2$s"><div class="dg-file-spec"><label class="dg-field-label" for="%3$s">%4$s%5$s</label><span class="dg-file-hint">%6$s</span></div><div class="dg-file-body"><div class="dg-file-pick"><input type="file" class="dg-file-input" id="%3$s" name="%3$s"%7$s%8$s /><input type="hidden" name="%16$s" value="" data-dg-file-staged /><span class="dg-file-empty">%9$s</span><span class="dg-file-ready"><span class="dg-file-icon" aria-hidden="true">%10$s</span><span class="dg-file-meta"><span class="dg-file-name" data-dg-file-name></span><span class="dg-file-size"><span data-dg-file-size></span><span class="dg-file-size-sep" data-dg-file-sep hidden> · </span><span data-dg-file-original></span></span></span></span><div class="dg-file-progress" data-dg-file-progress hidden><div class="dg-file-progress-bar" data-dg-file-progress-bar></div></div><p class="dg-file-status" data-dg-file-status hidden></p></div><div class="dg-file-confirm"><button type="button" class="dg-file-open" data-dg-file-open data-testid="dg-file-open-%2$s" data-label-idle="%11$s" data-label-opened="%17$s">%11$s</button><span class="dg-file-confirm-copy" data-dg-file-confirm-copy data-idle="%12$s" data-ready="%13$s">%12$s</span><button type="button" class="dg-file-swap" data-dg-file-swap>%14$s</button></div><p class="dg-file-error" role="alert">%15$s</p></div></div>',
				esc_attr( $field['type'] ),
				esc_attr( $field['id'] ),
				esc_attr( $name ),
				esc_html( $label ),
				$required ? self::required_mark_html() : '',
				esc_html( $hint ),
				$accept_a,
				$req_a,
				$prompt,
				esc_html( $icon ),
				esc_attr( $open_idle ),
				esc_attr( $confirm_idle ),
				esc_attr( $confirm_ready ),
				esc_html( $remove_label ),
				esc_html( $error_label ),
				esc_attr( $staged ),
				esc_attr( $open_opened )
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
				'<div class="dg-field dg-field--disclaimer full-span" data-dg-field-id="%1$s" data-dg-field-type="disclaimer" data-testid="dg-field-%1$s"><label class="dg-check" for="%2$s"><input type="checkbox" id="%2$s" name="%2$s" value="1"%3$s /><span>%4$s</span></label>%5$s</div>',
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
		 * @return string Label text without the required marker.
		 */
		private static function label_text( $field ) {
			return isset( $field['label'] ) ? (string) $field['label'] : (string) $field['id'];
		}

		/**
		 * Visible required marker. Color is not the only signal.
		 *
		 * @return string
		 */
		private static function required_mark_html() {
			return '<span class="dg-req" aria-hidden="true">*</span>';
		}

		/**
		 * Translate when WordPress is loaded; stay usable in the PHP CLI harness.
		 *
		 * @param string $text Source string.
		 * @return string Escaped.
		 */
		private static function translate( $text ) {
			if ( function_exists( 'esc_html__' ) ) {
				return esc_html__( $text, 'dragongate-portals' );
			}
			return esc_html( $text );
		}

		/**
		 * @param bool $required Whether the field is required.
		 */
		private static function echo_required_mark( $required ) {
			if ( $required ) {
				echo self::required_mark_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant markup.
			}
		}

		/**
		 * @param array  $field  Field.
		 * @param string $accept Accept attribute.
		 * @return string
		 */
		private static function file_hint( $field, $accept ) {
			if ( ! empty( $field['help'] ) ) {
				return (string) $field['help'];
			}
			if ( false !== strpos( $accept, 'pdf' ) ) {
				return 'PDF only · ' . self::FILE_SIZE_CAPTION;
			}
			if ( false !== strpos( $accept, 'mpeg' ) || false !== strpos( $accept, 'mp3' ) ) {
				return 'MP3 only · ' . self::FILE_SIZE_CAPTION;
			}
			return self::FILE_SIZE_CAPTION;
		}

		/**
		 * @param string $accept Accept attribute.
		 * @return string
		 */
		private static function file_icon_label( $accept ) {
			if ( false !== strpos( $accept, 'pdf' ) ) {
				return 'PDF';
			}
			if ( false !== strpos( $accept, 'mpeg' ) || false !== strpos( $accept, 'mp3' ) ) {
				return 'MP3';
			}
			return 'FILE';
		}

		/**
		 * @param string $accept Accept attribute.
		 * @return string Safe HTML prompt.
		 */
		private static function file_drop_prompt( $accept ) {
			if ( false !== strpos( $accept, 'pdf' ) ) {
				$kind = 'PDF';
				$art  = 'a';
			} elseif ( false !== strpos( $accept, 'mpeg' ) || false !== strpos( $accept, 'mp3' ) ) {
				$kind = 'MP3';
				$art  = 'an';
			} else {
				$kind = 'file';
				$art  = 'a';
			}
			return sprintf(
				'Drop %1$s %2$s or <em>browse</em>',
				esc_html( $art ),
				esc_html( $kind )
			);
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
