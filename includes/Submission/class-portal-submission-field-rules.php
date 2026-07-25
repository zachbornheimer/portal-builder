<?php
/**
 * Per-field validation rules for definition submissions.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Submission_Field_Rules' ) ) {

	/**
	 * Validates one field (or expands applicant_pack / file meta).
	 * Used only by Portal_Submission_Validator.
	 */
	class Portal_Submission_Field_Rules {

		const NAME_PREFIX = 'sub_';

		/** Applicant pack required POST keys when the pack field is required. */
		const APPLICANT_PACK_REQUIRED = array(
			'sub_title',
			'sub_name',
			'sub_email',
			'sub_address_first_part',
			'sub_city',
			'sub_country',
			'sub_state',
			'sub_phone',
		);

		/** Optional applicant pack keys still collected when present. */
		const APPLICANT_PACK_OPTIONAL = array(
			'sub_inst_affil',
			'sub_zip',
		);

		const FILE_TYPES = array(
			'file',
			'score_file',
			'recording_file',
			'bio_file',
		);

		const TEXT_TYPES = array(
			'short_text',
			'long_text',
			'email',
			'phone',
		);

		const MAGIC_PDF = '%PDF';
		const MAGIC_ID3 = 'ID3';
		const EXT_PDF   = 'pdf';
		const EXT_MP3   = 'mp3';

		/**
		 * Lookup sub_{id} then bare id.
		 *
		 * @param array  $values Values.
		 * @param string $id     Field id.
		 * @return mixed
		 */
		public static function lookup_value( array $values, $id ) {
			$prefixed = self::NAME_PREFIX . $id;
			if ( array_key_exists( $prefixed, $values ) ) {
				return $values[ $prefixed ];
			}
			if ( array_key_exists( $id, $values ) ) {
				return $values[ $id ];
			}
			return null;
		}

		/**
		 * @param mixed $value Value.
		 * @return bool
		 */
		public static function is_blank( $value ) {
			if ( null === $value ) {
				return true;
			}
			if ( is_string( $value ) ) {
				return '' === trim( $value );
			}
			if ( is_array( $value ) ) {
				return empty( $value );
			}
			return false;
		}

		/**
		 * @param mixed $value Checkbox-ish value.
		 * @return bool
		 */
		public static function is_checked( $value ) {
			if ( true === $value || 1 === $value || '1' === $value ) {
				return true;
			}
			if ( is_string( $value ) ) {
				$v = strtolower( trim( $value ) );
				return in_array( $v, array( 'on', 'yes', 'true', 'checked', 'accepted' ), true );
			}
			return false;
		}

		/**
		 * Lightweight email shape check (no network).
		 *
		 * @param string $email Email.
		 * @return bool
		 */
		public static function looks_like_email( $email ) {
			return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
		}

		/**
		 * @param array $field     Applicant pack field.
		 * @param array $values    Raw values.
		 * @param array $collected Accumulator.
		 * @param array $errors    Errors.
		 * @return void
		 */
		public static function validate_applicant_pack( array $field, array $values, array &$collected, array &$errors ) {
			$required = ! empty( $field['required'] );
			$id       = (string) $field['id'];
			$label    = isset( $field['label'] ) ? (string) $field['label'] : 'Applicant';

			foreach ( self::APPLICANT_PACK_REQUIRED as $key ) {
				$raw = isset( $values[ $key ] ) ? $values[ $key ] : null;
				if ( $required && self::is_blank( $raw ) ) {
					$errors[ $key ] = sprintf( '%s: "%s" is required.', $label, $key );
					continue;
				}
				if ( ! self::is_blank( $raw ) ) {
					$collected['applicant'][ $key ] = (string) $raw;
				}
			}

			if ( isset( $collected['applicant']['sub_email'] )
				&& ! self::looks_like_email( $collected['applicant']['sub_email'] ) ) {
				$errors['sub_email'] = sprintf( '%s: email must be valid.', $label );
			}

			foreach ( self::APPLICANT_PACK_OPTIONAL as $key ) {
				if ( isset( $values[ $key ] ) && ! self::is_blank( $values[ $key ] ) ) {
					$collected['applicant'][ $key ] = (string) $values[ $key ];
				}
			}

			if ( ! empty( $collected['applicant'] ) ) {
				$collected['values'][ $id ] = $collected['applicant'];
			}
		}

		/**
		 * @param array $field     File field.
		 * @param array $files     Raw files.
		 * @param array $collected Accumulator.
		 * @param array $errors    Errors.
		 * @return void
		 */
		public static function validate_file_field( array $field, array $files, array &$collected, array &$errors ) {
			$id       = (string) $field['id'];
			$label    = isset( $field['label'] ) ? (string) $field['label'] : $id;
			$required = ! empty( $field['required'] );
			$type     = (string) $field['type'];

			$meta = null;
			if ( isset( $files[ $id ] ) && is_array( $files[ $id ] ) ) {
				$meta = $files[ $id ];
			} elseif ( isset( $files[ self::NAME_PREFIX . $id ] ) && is_array( $files[ self::NAME_PREFIX . $id ] ) ) {
				$meta = $files[ self::NAME_PREFIX . $id ];
			}

			$has_content = is_array( $meta ) && (
				( isset( $meta['contents'] ) && '' !== $meta['contents'] && false !== $meta['contents'] )
				|| ( isset( $meta['path'] ) && is_string( $meta['path'] ) && is_readable( $meta['path'] ) )
				|| ( ! empty( $meta['name'] ) && ! empty( $meta['tmp_name'] ) && is_readable( $meta['tmp_name'] ) )
			);

			if ( $required && ! $has_content ) {
				$errors[ $id ] = sprintf( '"%s" file is required.', $label );
				return;
			}
			if ( ! $has_content ) {
				return;
			}

			if ( ! self::mime_allowed( $type, $meta ) ) {
				$errors[ $id ] = sprintf(
					'"%s" must be a valid %s file.',
					$label,
					self::mime_human( $type )
				);
				return;
			}

			$collected['files'][ $id ] = $meta;
		}

		/**
		 * Accept score/recording/bio by extension + magic bytes (client MIME is unreliable).
		 *
		 * @param string $field_type Definition field type.
		 * @param array  $meta       File meta (name/path/tmp_name/contents).
		 * @return bool
		 */
		public static function mime_allowed( $field_type, array $meta ) {
			if ( 'file' === $field_type ) {
				return true;
			}

			$filename = isset( $meta['name'] ) ? (string) $meta['name'] : '';
			$ext      = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			$head     = self::file_head_bytes( $meta, 16 );

			switch ( $field_type ) {
				case 'score_file':
				case 'bio_file':
					return self::EXT_PDF === $ext && 0 === strpos( $head, self::MAGIC_PDF );
				case 'recording_file':
					$magic_ok = ( 0 === strpos( $head, self::MAGIC_ID3 ) )
						|| ( strlen( $head ) >= 2 && "\xFF" === $head[0] && ( ord( $head[1] ) & 0xE0 ) === 0xE0 );
					return self::EXT_MP3 === $ext && $magic_ok;
				default:
					return true;
			}
		}

		/**
		 * @param array $meta   File meta.
		 * @param int   $length Bytes to read.
		 * @return string
		 */
		private static function file_head_bytes( array $meta, $length ) {
			if ( isset( $meta['contents'] ) && is_string( $meta['contents'] ) ) {
				return substr( $meta['contents'], 0, $length );
			}
			$path = null;
			if ( isset( $meta['path'] ) && is_string( $meta['path'] ) && is_readable( $meta['path'] ) ) {
				$path = $meta['path'];
			} elseif ( ! empty( $meta['tmp_name'] ) && is_readable( $meta['tmp_name'] ) ) {
				$path = $meta['tmp_name'];
			}
			if ( null === $path ) {
				return '';
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$chunk = file_get_contents( $path, false, null, 0, $length );
			return is_string( $chunk ) ? $chunk : '';
		}

		/**
		 * @param string $field_type Type.
		 * @return string
		 */
		private static function mime_human( $field_type ) {
			switch ( $field_type ) {
				case 'score_file':
				case 'bio_file':
					return 'PDF';
				case 'recording_file':
					return 'MP3';
				default:
					return 'supported';
			}
		}

		/**
		 * @param array $field     Text field.
		 * @param array $values    Raw values.
		 * @param array $collected Accumulator.
		 * @param array $errors    Errors.
		 * @return void
		 */
		public static function validate_text_field( array $field, array $values, array &$collected, array &$errors ) {
			$id       = (string) $field['id'];
			$type     = (string) $field['type'];
			$label    = isset( $field['label'] ) ? (string) $field['label'] : $id;
			$required = ! empty( $field['required'] );
			$raw      = self::lookup_value( $values, $id );

			if ( $required && self::is_blank( $raw ) ) {
				$errors[ $id ] = sprintf( '"%s" is required.', $label );
				return;
			}
			if ( 'email' === $type && ! self::is_blank( $raw ) && ! self::looks_like_email( $raw ) ) {
				$errors[ $id ] = sprintf( '"%s" must be a valid email.', $label );
				return;
			}
			if ( ! self::is_blank( $raw ) ) {
				$collected['values'][ $id ] = is_scalar( $raw ) ? (string) $raw : '';
			}
		}

		/**
		 * @param array $field  Disclaimer field.
		 * @param array $values Raw values.
		 * @param array $collected Accumulator.
		 * @param array $errors Errors.
		 * @return void
		 */
		public static function validate_disclaimer( array $field, array $values, array &$collected, array &$errors ) {
			$id       = (string) $field['id'];
			$label    = isset( $field['label'] ) ? (string) $field['label'] : $id;
			$required = ! empty( $field['required'] );
			$raw      = self::lookup_value( $values, $id );
			if ( $required && ! self::is_checked( $raw ) ) {
				$errors[ $id ] = sprintf( '"%s" must be accepted.', $label );
				return;
			}
			$collected['values'][ $id ] = self::is_checked( $raw );
		}
	}
}
