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

		const SHEET_ROLES = array( 'housekeeping', 'adjudicator' );

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
			$access  = isset( $data['access'] ) && is_array( $data['access'] ) ? $data['access'] : array();

			$field_dest = array();
			if ( isset( $mapping['fieldDest'] ) && is_array( $mapping['fieldDest'] ) ) {
				foreach ( $mapping['fieldDest'] as $fid => $dest ) {
					if ( is_string( $fid ) && is_string( $dest ) ) {
						$field_dest[ $fid ] = $dest;
					}
				}
			}

			$sheets = isset( $mapping['sheets'] ) && is_array( $mapping['sheets'] )
				? self::normalize_sheets( $mapping['sheets'] )
				: array();
			$drive  = isset( $mapping['drive'] ) && is_array( $mapping['drive'] )
				? self::normalize_drive( $mapping['drive'] )
				: array();

			return array(
				'version' => isset( $data['version'] ) ? (int) $data['version'] : 1,
				'title'   => isset( $data['title'] ) ? (string) $data['title'] : '',
				'fields'  => $validated_fields,
				'mapping' => array(
					'sheets'    => $sheets,
					'drive'     => $drive,
					'fieldDest' => $field_dest,
				),
				'publish' => self::normalize_publish( $publish ),
				'options' => array(
					'anonymize'                 => self::nullable_bool(
						array_key_exists( 'anonymize', $options ) ? $options['anonymize'] : null
					),
					'skipHeader'                => ! empty( $options['skipHeader'] ),
					'guidelinesUrl'             => self::nullable_string(
						array_key_exists( 'guidelinesUrl', $options ) ? $options['guidelinesUrl'] : null
					),
					'applicantNotificationDate' => isset( $options['applicantNotificationDate'] ) ? $options['applicantNotificationDate'] : null,
					'freeForMembers'            => self::nullable_bool(
						array_key_exists( 'freeForMembers', $options ) ? $options['freeForMembers'] : null
					),
					'freeMembershipPlanIds'     => self::string_id_list(
						isset( $options['freeMembershipPlanIds'] ) ? $options['freeMembershipPlanIds'] : array()
					),
					'anonymizeEndpoint'         => self::nullable_url(
						array_key_exists( 'anonymizeEndpoint', $options ) ? $options['anonymizeEndpoint'] : null
					),
					'anonymizeApiKey'           => self::nullable_string(
						array_key_exists( 'anonymizeApiKey', $options ) ? $options['anonymizeApiKey'] : null
					),
					'anonymizeAck'              => self::nullable_string(
						array_key_exists( 'anonymizeAck', $options ) ? $options['anonymizeAck'] : null
					),
				),
				'access'  => self::normalize_access( $access ),
			);
		}

		/**
		 * Dual-write enabled / forceClosed. Empty launchAt becomes null.
		 *
		 * @param array $publish Raw publish block.
		 * @return array
		 */
		private static function normalize_publish( array $publish ) {
			$enabled = array_key_exists( 'enabled', $publish )
				? ! empty( $publish['enabled'] )
				: empty( $publish['forceClosed'] );
			return array(
				'deadline'       => array_key_exists( 'deadline', $publish ) ? $publish['deadline'] : null,
				'timezone'       => self::nullable_string(
					array_key_exists( 'timezone', $publish ) ? $publish['timezone'] : null
				),
				'applicationFee' => array_key_exists( 'applicationFee', $publish ) ? $publish['applicationFee'] : null,
				'enabled'        => $enabled,
				'forceClosed'    => ! $enabled,
				'launchAt'       => self::nullable_string(
					array_key_exists( 'launchAt', $publish ) ? $publish['launchAt'] : null
				),
			);
		}

		/**
		 * Who may apply. audience anyone | logged_in | members.
		 *
		 * @param array $access Raw access block.
		 * @return array
		 */
		private static function normalize_access( $access ) {
			$audience = isset( $access['audience'] ) ? (string) $access['audience'] : 'anyone';
			if ( ! in_array( $audience, array( 'anyone', 'logged_in', 'members' ), true ) ) {
				$audience = 'anyone';
			}
			$rules = array();
			if ( isset( $access['profileRules'] ) && is_array( $access['profileRules'] ) ) {
				foreach ( $access['profileRules'] as $rule ) {
					if ( ! is_array( $rule ) || empty( $rule['key'] ) ) {
						continue;
					}
					$op = isset( $rule['op'] ) ? (string) $rule['op'] : 'eq';
					if ( ! in_array( $op, array( 'eq', 'neq', 'in', 'gte', 'lte', 'contains' ), true ) ) {
						$op = 'eq';
					}
					$rules[] = array(
						'key'   => (string) $rule['key'],
						'op'    => $op,
						'value' => isset( $rule['value'] ) ? (string) $rule['value'] : '',
					);
				}
			}
			return array(
				'audience'          => $audience,
				'membershipPlanIds' => self::string_id_list(
					isset( $access['membershipPlanIds'] ) ? $access['membershipPlanIds'] : array()
				),
				'profileRules'      => $rules,
				'denyMessage'       => isset( $access['denyMessage'] ) ? (string) $access['denyMessage'] : '',
			);
		}

		/**
		 * @param mixed $ids Raw id list.
		 * @return string[]
		 */
		private static function string_id_list( $ids ) {
			if ( ! is_array( $ids ) ) {
				return array();
			}
			$out = array();
			foreach ( $ids as $id ) {
				$id = trim( (string) $id );
				if ( '' !== $id ) {
					$out[] = $id;
				}
			}
			return $out;
		}

		/**
		 * Copy known sheet fields; keep old un-roled ids; drop invalid roles.
		 *
		 * @param array $sheets Raw sheet targets.
		 * @return array
		 */
		private static function normalize_sheets( $sheets ) {
			$out = array();
			foreach ( $sheets as $sheet ) {
				if ( ! is_array( $sheet ) ) {
					continue;
				}
				$row = array(
					'id'            => isset( $sheet['id'] ) ? (string) $sheet['id'] : '',
					'name'          => isset( $sheet['name'] ) ? (string) $sheet['name'] : '',
					'spreadsheetId' => isset( $sheet['spreadsheetId'] ) ? (string) $sheet['spreadsheetId'] : '',
				);
				if ( isset( $sheet['role'] ) && is_string( $sheet['role'] ) && in_array( $sheet['role'], self::SHEET_ROLES, true ) ) {
					$row['role'] = $sheet['role'];
				}
				if ( isset( $sheet['columns'] ) && is_array( $sheet['columns'] ) ) {
					$row['columns'] = $sheet['columns'];
				}
				$out[] = $row;
			}
			return $out;
		}

		/**
		 * @param array $drive Raw drive targets.
		 * @return array
		 */
		private static function normalize_drive( $drive ) {
			$out = array();
			foreach ( $drive as $folder ) {
				if ( ! is_array( $folder ) ) {
					continue;
				}
				$row = array(
					'id'       => isset( $folder['id'] ) ? (string) $folder['id'] : '',
					'name'     => isset( $folder['name'] ) ? (string) $folder['name'] : '',
					'folderId' => isset( $folder['folderId'] ) ? (string) $folder['folderId'] : '',
				);
				if ( isset( $folder['fieldIds'] ) && is_array( $folder['fieldIds'] ) ) {
					$row['fieldIds'] = $folder['fieldIds'];
				}
				$out[] = $row;
			}
			return $out;
		}

		/**
		 * Empty string → null. Non-strings → null. Do not invent a URL.
		 *
		 * @param mixed $value Raw option.
		 * @return string|null
		 */
		private static function nullable_url( $value ) {
			return self::nullable_string( $value );
		}

		/**
		 * Missing / empty / non-bool → null (inherit). true/false stay.
		 *
		 * @param mixed $value Raw option.
		 * @return bool|null
		 */
		private static function nullable_bool( $value ) {
			if ( is_bool( $value ) ) {
				return $value;
			}
			if ( 1 === $value || '1' === $value || 'true' === $value ) {
				return true;
			}
			if ( 0 === $value || '0' === $value || 'false' === $value ) {
				return false;
			}
			return null;
		}

		/**
		 * Empty string → null. Non-strings → null.
		 *
		 * @param mixed $value Raw option.
		 * @return string|null
		 */
		private static function nullable_string( $value ) {
			if ( ! is_string( $value ) ) {
				return null;
			}
			$trimmed = trim( $value );
			return '' === $trimmed ? null : $trimmed;
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
