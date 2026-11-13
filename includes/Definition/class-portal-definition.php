<?php
/**
 * Portal definition schema (fields / mapping / publish).
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Definition' ) ) {

	/**
	 * Validates and normalizes `_portal_definition` JSON documents.
	 */
	class Portal_Definition {

		const META_KEY = '_portal_definition';

		const FIELD_TYPES = array(
			'short_text',
			'long_text',
			'email',
			'phone',
			'file',
			'score_file',
			'recording_file',
			'bio_file',
			'applicant_pack',
			'group',
			'branch',
			'disclaimer',
			'static_html',
		);

		/**
		 * Decode JSON string into array or WP_Error.
		 *
		 * @param string $json Raw JSON.
		 * @return array|WP_Error
		 */
		public static function from_json( $json ) {
			if ( ! is_string( $json ) || '' === trim( $json ) ) {
				return new WP_Error( 'dg_definition_empty', 'Definition JSON is empty.' );
			}
			$data = json_decode( $json, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
				return new WP_Error( 'dg_definition_invalid_json', 'Definition is not valid JSON.' );
			}
			return self::validate( $data );
		}

		/**
		 * Validate a definition array.
		 *
		 * @param array $data Definition document.
		 * @return array|WP_Error Normalized definition on success.
		 */
		public static function validate( $data ) {
			if ( ! is_array( $data ) ) {
				return new WP_Error( 'dg_definition_not_object', 'Definition must be an object.' );
			}

			$fields = isset( $data['fields'] ) ? $data['fields'] : null;
			if ( ! is_array( $fields ) ) {
				return new WP_Error( 'dg_definition_fields', 'fields must be an array.' );
			}

			$validated_fields = self::validate_fields( $fields, 0 );
			if ( is_wp_error( $validated_fields ) ) {
				return $validated_fields;
			}

			$mapping = isset( $data['mapping'] ) && is_array( $data['mapping'] ) ? $data['mapping'] : array();
			$publish = isset( $data['publish'] ) && is_array( $data['publish'] ) ? $data['publish'] : array();
			$options = isset( $data['options'] ) && is_array( $data['options'] ) ? $data['options'] : array();

			return array(
				'version' => isset( $data['version'] ) ? (int) $data['version'] : 1,
				'title'   => isset( $data['title'] ) ? (string) $data['title'] : '',
				'fields'  => $validated_fields,
				'mapping' => array(
					'sheets' => isset( $mapping['sheets'] ) && is_array( $mapping['sheets'] ) ? $mapping['sheets'] : array(),
					'drive'  => isset( $mapping['drive'] ) && is_array( $mapping['drive'] ) ? $mapping['drive'] : array(),
				),
				'publish' => array(
					'deadline'        => array_key_exists( 'deadline', $publish ) ? $publish['deadline'] : null,
					'timezone'        => isset( $publish['timezone'] ) ? (string) $publish['timezone'] : 'America/New_York',
					'applicationFee'  => array_key_exists( 'applicationFee', $publish ) ? $publish['applicationFee'] : null,
					'forceClosed'     => ! empty( $publish['forceClosed'] ),
				),
				'options' => array(
					'anonymize'                 => ! empty( $options['anonymize'] ),
					'skipHeader'                => ! empty( $options['skipHeader'] ),
					'guidelinesUrl'             => isset( $options['guidelinesUrl'] ) ? $options['guidelinesUrl'] : null,
					'applicantNotificationDate' => isset( $options['applicantNotificationDate'] ) ? $options['applicantNotificationDate'] : null,
					'freeForMembers'            => ! empty( $options['freeForMembers'] ),
				),
			);
		}

		/**
		 * Recursively validate field list.
		 *
		 * @param array $fields Field list.
		 * @param int   $depth  Nesting depth.
		 * @return array|WP_Error
		 */
		private static function validate_fields( $fields, $depth ) {
			if ( $depth > 6 ) {
				return new WP_Error( 'dg_definition_depth', 'Field nesting too deep.' );
			}
			$out           = array();
			$seen_ids      = array();
			$applicant_n   = 0;

			foreach ( $fields as $i => $field ) {
				if ( ! is_array( $field ) ) {
					return new WP_Error( 'dg_definition_field', sprintf( 'Field at index %d must be an object.', $i ) );
				}
				$id = isset( $field['id'] ) ? (string) $field['id'] : '';
				if ( '' === $id || ! preg_match( '/^[a-z][a-z0-9_]*$/', $id ) ) {
					return new WP_Error( 'dg_definition_field_id', sprintf( 'Invalid field id at index %d.', $i ) );
				}
				if ( isset( $seen_ids[ $id ] ) ) {
					return new WP_Error( 'dg_definition_field_dup', sprintf( 'Duplicate field id "%s".', $id ) );
				}
				$seen_ids[ $id ] = true;

				$type = isset( $field['type'] ) ? (string) $field['type'] : '';
				if ( ! in_array( $type, self::FIELD_TYPES, true ) ) {
					return new WP_Error( 'dg_definition_field_type', sprintf( 'Unknown field type "%s" on %s.', $type, $id ) );
				}
				if ( 'applicant_pack' === $type ) {
					++$applicant_n;
					if ( $applicant_n > 1 ) {
						return new WP_Error( 'dg_definition_applicant_pack', 'Only one applicant_pack field is allowed.' );
					}
				}

				$normalized = array(
					'id'       => $id,
					'type'     => $type,
					'label'    => isset( $field['label'] ) ? (string) $field['label'] : $id,
					'required' => ! empty( $field['required'] ),
					'help'     => isset( $field['help'] ) ? (string) $field['help'] : null,
				);
				if ( ! empty( $field['fileSuffix'] ) ) {
					$normalized['fileSuffix'] = (string) $field['fileSuffix'];
				}
				if ( 'group' === $type ) {
					$children = isset( $field['children'] ) && is_array( $field['children'] ) ? $field['children'] : array();
					$vc       = self::validate_fields( $children, $depth + 1 );
					if ( is_wp_error( $vc ) ) {
						return $vc;
					}
					$normalized['children'] = $vc;
				}
				if ( 'branch' === $type ) {
					$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
					$norm_opts = array();
					foreach ( $options as $oi => $opt ) {
						if ( ! is_array( $opt ) || empty( $opt['id'] ) || empty( $opt['label'] ) ) {
							return new WP_Error( 'dg_definition_branch', sprintf( 'Invalid branch option on %s.', $id ) );
						}
						$kids = isset( $opt['children'] ) && is_array( $opt['children'] ) ? $opt['children'] : array();
						$vk   = self::validate_fields( $kids, $depth + 1 );
						if ( is_wp_error( $vk ) ) {
							return $vk;
						}
						$norm_opts[] = array(
							'id'       => (string) $opt['id'],
							'label'    => (string) $opt['label'],
							'children' => $vk,
						);
					}
					$normalized['options'] = $norm_opts;
				}
				if ( 'static_html' === $type && isset( $field['html'] ) ) {
					$normalized['html'] = (string) $field['html'];
				}
				if ( 'disclaimer' === $type && isset( $field['text'] ) ) {
					$normalized['text'] = (string) $field['text'];
				}
				$out[] = $normalized;
			}
			return $out;
		}

		/**
		 * Encode validated definition for storage.
		 *
		 * @param array $definition Validated definition.
		 * @return string
		 */
		public static function to_json( $definition ) {
			return wp_json_encode( $definition );
		}

		/**
		 * Load and validate definition meta for a portal post.
		 *
		 * @param int $post_id Portal post ID.
		 * @return array|null Validated definition, or null if missing/invalid.
		 */
		public static function load_for_post( $post_id ) {
			$raw = get_post_meta( (int) $post_id, self::META_KEY, true );
			if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
				return null;
			}
			$validated = self::from_json( $raw );
			if ( is_wp_error( $validated ) ) {
				return null;
			}
			return $validated;
		}
	}
}
