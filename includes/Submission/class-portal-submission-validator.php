<?php
/**
 * Validates submission values against a portal definition.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Submission_Validator' ) ) {

	/**
	 * Pipeline step 1: validate fields + selected branches (PORTAL-MODEL §5).
	 */
	class Portal_Submission_Validator {

		const NAME_PREFIX = 'sub_';
		const ERROR_CODE  = 'dg_submission_invalid';

		/**
		 * Validate values + files against a validated definition document.
		 *
		 * @param array               $definition Validated definition (fields required).
		 * @param array<string,mixed> $values     Form values (sub_* and/or bare field ids).
		 * @param array<string,mixed> $files      Map fieldId => file meta (name/contents/path).
		 * @param array               $site       Site bag for inherit resolve (empty in CLI harness).
		 * @return array|WP_Error {
		 *   values: map fieldId => scalar,
		 *   files: map fieldId => meta,
		 *   applicant: map sub_* => scalar
		 * }
		 */
		public static function validate( array $definition, array $values, array $files = array(), array $site = array() ) {
			if ( empty( $definition['fields'] ) || ! is_array( $definition['fields'] ) ) {
				return new WP_Error( self::ERROR_CODE, 'Definition has no fields to validate.' );
			}

			$collected = array(
				'values'    => array(),
				'files'     => array(),
				'applicant' => array(),
			);
			$errors    = array();

			self::walk_fields( $definition['fields'], $values, $files, $collected, $errors, true );
			self::require_anonymize_ack( $definition, $values, $collected, $errors, $site );
			self::require_anonymize_config( $definition, $errors, $site );

			if ( ! empty( $errors ) ) {
				return new WP_Error(
					self::ERROR_CODE,
					'Submission failed validation.',
					array( 'fields' => $errors )
				);
			}

			return $collected;
		}

		/**
		 * When resolved anonymize is on, require the certification checkbox.
		 *
		 * @param array $definition Definition.
		 * @param array $values     Raw values.
		 * @param array $collected  Accumulator.
		 * @param array $errors     Field errors.
		 * @param array $site       Site bag.
		 * @return void
		 */
		private static function require_anonymize_ack( array $definition, array $values, array &$collected, array &$errors, array $site ) {
			if ( ! class_exists( 'Portal_Site_Defaults' ) ) {
				return;
			}
			$resolved = Portal_Site_Defaults::resolve( $definition, $site );
			if ( empty( $resolved['anonymize'] ) ) {
				return;
			}
			$text = isset( $resolved['anonymizeAck'] ) ? (string) $resolved['anonymizeAck'] : '';
			if ( '' === $text ) {
				$text = Portal_Site_Defaults::BUILTIN_ANONYMIZE_ACK;
			}
			// Posted as sub_anonymize_ack; lookup_value accepts bare or sub_ keys.
			$raw = Portal_Submission_Field_Rules::lookup_value( $values, 'anonymize_ack' );
			if ( ! Portal_Submission_Field_Rules::is_checked( $raw ) ) {
				$errors['anonymize_ack'] = sprintf( '"%s" must be accepted.', $text );
				return;
			}
			$collected['values']['anonymize_ack'] = true;
		}

		/**
		 * When resolved anonymize is on, the key (and a usable host) must be present.
		 *
		 * @param array $definition Definition.
		 * @param array $errors     Field errors.
		 * @param array $site       Site bag.
		 * @return void
		 */
		private static function require_anonymize_config( array $definition, array &$errors, array $site ) {
			if ( ! class_exists( 'Portal_Site_Defaults' ) || ! class_exists( 'Portal_Anonymizer' ) ) {
				return;
			}
			$resolved = Portal_Site_Defaults::resolve( $definition, $site );
			$missing  = Portal_Anonymizer::missing_config( $resolved );
			if ( null === $missing ) {
				return;
			}
			$errors['anonymize'] = $missing;
		}

		/**
		 * Lookup sub_{id} then bare id.
		 *
		 * @param array  $values Values.
		 * @param string $id     Field id.
		 * @return mixed
		 */
		public static function lookup_value( array $values, $id ) {
			return Portal_Submission_Field_Rules::lookup_value( $values, $id );
		}

		/**
		 * @param array $fields    Field list.
		 * @param array $values    Raw values.
		 * @param array $files     Raw files.
		 * @param array $collected Accumulator.
		 * @param array $errors    Field id => message.
		 * @param bool  $active    Whether this branch/group is active.
		 * @return void
		 */
		private static function walk_fields( array $fields, array $values, array $files, array &$collected, array &$errors, $active ) {
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) || empty( $field['type'] ) || empty( $field['id'] ) ) {
					continue;
				}
				self::validate_field( $field, $values, $files, $collected, $errors, $active );
			}
		}

		/**
		 * @param array $field     Single field.
		 * @param array $values    Raw values.
		 * @param array $files     Raw files.
		 * @param array $collected Accumulator.
		 * @param array $errors    Errors.
		 * @param bool  $active    Active branch.
		 * @return void
		 */
		private static function validate_field( array $field, array $values, array $files, array &$collected, array &$errors, $active ) {
			$type = (string) $field['type'];

			if ( 'static_html' === $type ) {
				return;
			}

			if ( 'group' === $type ) {
				$children = isset( $field['children'] ) && is_array( $field['children'] ) ? $field['children'] : array();
				self::walk_fields( $children, $values, $files, $collected, $errors, $active );
				return;
			}

			if ( 'branch' === $type ) {
				self::validate_branch( $field, $values, $files, $collected, $errors, $active );
				return;
			}

			if ( ! $active ) {
				return;
			}

			if ( 'applicant_pack' === $type ) {
				Portal_Submission_Field_Rules::validate_applicant_pack( $field, $values, $collected, $errors );
				return;
			}

			if ( 'disclaimer' === $type ) {
				Portal_Submission_Field_Rules::validate_disclaimer( $field, $values, $collected, $errors );
				return;
			}

			if ( in_array( $type, Portal_Submission_Field_Rules::FILE_TYPES, true ) ) {
				Portal_Submission_Field_Rules::validate_file_field( $field, $files, $collected, $errors );
				return;
			}

			if ( in_array( $type, Portal_Submission_Field_Rules::TEXT_TYPES, true ) ) {
				Portal_Submission_Field_Rules::validate_text_field( $field, $values, $collected, $errors );
			}
		}

		/**
		 * @param array $field     Branch field.
		 * @param array $values    Raw values.
		 * @param array $files     Raw files.
		 * @param array $collected Accumulator.
		 * @param array $errors    Errors.
		 * @param bool  $active    Parent active.
		 * @return void
		 */
		private static function validate_branch( array $field, array $values, array $files, array &$collected, array &$errors, $active ) {
			$id       = (string) $field['id'];
			$label    = isset( $field['label'] ) ? (string) $field['label'] : $id;
			$required = ! empty( $field['required'] );
			$options  = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

			if ( ! $active ) {
				return;
			}

			$selected = Portal_Submission_Field_Rules::lookup_value( $values, $id );
			if ( $required && Portal_Submission_Field_Rules::is_blank( $selected ) ) {
				$errors[ $id ] = sprintf( '"%s" is required.', $label );
				return;
			}
			if ( Portal_Submission_Field_Rules::is_blank( $selected ) ) {
				return;
			}

			$selected_s = (string) $selected;
			$matched    = null;
			foreach ( $options as $opt ) {
				if ( is_array( $opt ) && isset( $opt['id'] ) && (string) $opt['id'] === $selected_s ) {
					$matched = $opt;
					break;
				}
			}
			if ( null === $matched ) {
				$errors[ $id ] = sprintf( '"%s" has an unknown option.', $label );
				return;
			}

			$collected['values'][ $id ] = $selected_s;
			$children                   = isset( $matched['children'] ) && is_array( $matched['children'] ) ? $matched['children'] : array();
			self::walk_fields( $children, $values, $files, $collected, $errors, true );

			foreach ( $options as $opt ) {
				if ( ! is_array( $opt ) || ! isset( $opt['id'] ) || (string) $opt['id'] === $selected_s ) {
					continue;
				}
				$sib = isset( $opt['children'] ) && is_array( $opt['children'] ) ? $opt['children'] : array();
				self::walk_fields( $sib, $values, $files, $collected, $errors, false );
			}
		}
	}
}
