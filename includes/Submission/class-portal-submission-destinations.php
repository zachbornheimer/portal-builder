<?php
/**
 * fieldDest tokens → sheet cells and Drive folder names.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Submission_Destinations' ) ) {

	/**
	 * Parses `sheet:Name:Col` / `drive:Folder` pipes and builds ordered cells.
	 */
	class Portal_Submission_Destinations {

		const SHEET_PREFIX       = 'sheet:';
		const DRIVE_PREFIX       = 'drive:';
		const RAW_DATA_TAB       = 'Raw Data';
		const HEADER_RANGE       = "'Raw Data'!A1:ZZ1";
		const HEADER_WRITE_RANGE = "'Raw Data'!A1";
		const DATA_START_CELL    = 'A2';
		const FILE_URL_PREFIX    = 'https://drive.google.com/file/d/';
		const FOLDER_URL_PREFIX  = 'https://drive.google.com/drive/folders/';

		const EXTRA_KEYS = array( 'selection_path', 'portalId', 'createdAt', 'status', 'files', 'applicationId', 'receiptUrl', 'email', 'dateReceived' );

		const SCRATCH_SPREADSHEET_ID = 'dg_test_scratch_sheet';
		const SCRATCH_FOLDER_ID      = 'dg_test_scratch_folder';

		/**
		 * Mapping dest IDs the write ports may use. testMode replaces production IDs.
		 *
		 * @param array $definition Validated definition.
		 * @return array
		 */
		public static function route_for_submit( array $definition ) {
			if ( ! class_exists( 'Portal_Definition' ) || ! Portal_Definition::test_mode_on( $definition ) ) {
				return $definition;
			}
			if ( ! isset( $definition['mapping'] ) || ! is_array( $definition['mapping'] ) ) {
				return $definition;
			}
			$definition['mapping'] = self::scratch_mapping( $definition['mapping'] );
			return $definition;
		}

		/**
		 * Keep dest cards; swap non-empty production IDs for scratch IDs.
		 *
		 * @param array $mapping Definition mapping block.
		 * @return array
		 */
		private static function scratch_mapping( array $mapping ) {
			if ( isset( $mapping['sheets'] ) && is_array( $mapping['sheets'] ) ) {
				foreach ( $mapping['sheets'] as $i => $sheet ) {
					if ( ! is_array( $sheet ) ) {
						continue;
					}
					$sid = isset( $sheet['spreadsheetId'] ) ? (string) $sheet['spreadsheetId'] : '';
					if ( '' !== $sid ) {
						$mapping['sheets'][ $i ]['spreadsheetId'] = self::SCRATCH_SPREADSHEET_ID;
					}
				}
			}
			if ( isset( $mapping['drive'] ) && is_array( $mapping['drive'] ) ) {
				foreach ( $mapping['drive'] as $i => $folder ) {
					if ( ! is_array( $folder ) ) {
						continue;
					}
					$fid = isset( $folder['folderId'] ) ? (string) $folder['folderId'] : '';
					if ( '' !== $fid ) {
						$mapping['drive'][ $i ]['folderId'] = self::SCRATCH_FOLDER_ID;
					}
				}
			}
			return $mapping;
		}

		/**
		 * @param string $dest Pipe-joined dest string.
		 * @return array{sheets:array<int,array{name:string,column:string}>,drive:string[]}
		 */
		public static function parse( $dest ) {
			$sheets = array();
			$drive  = array();
			foreach ( explode( '|', (string) $dest ) as $part ) {
				$part = trim( $part );
				if ( '' === $part ) {
					continue;
				}
				if ( 0 === strpos( $part, self::DRIVE_PREFIX ) ) {
					$name = substr( $part, strlen( self::DRIVE_PREFIX ) );
					if ( '' !== $name ) {
						$drive[] = $name;
					}
					continue;
				}
				if ( 0 !== strpos( $part, self::SHEET_PREFIX ) ) {
					continue;
				}
				$rest = substr( $part, strlen( self::SHEET_PREFIX ) );
				$i    = strpos( $rest, ':' );
				if ( false === $i ) {
					continue;
				}
				$sheets[] = array(
					'name'   => substr( $rest, 0, $i ),
					'column' => substr( $rest, $i + 1 ),
				);
			}
			return array(
				'sheets' => $sheets,
				'drive'  => $drive,
			);
		}

		/**
		 * Dest-column values for one sheet. Keys are fieldDest column strings.
		 *
		 * @param array  $definition Validated definition.
		 * @param array  $row        Logical sheet row.
		 * @param string $sheet_name Mapping sheet name.
		 * @return array<string,string>
		 */
		public static function named_values_for_sheet( array $definition, array $row, $sheet_name ) {
			$named = array();
			self::each_sheet_dest(
				$definition,
				$row,
				$sheet_name,
				static function ( $column, $value ) use ( &$named ) {
					if ( '' === $value && isset( $named[ $column ] ) ) {
						return;
					}
					$named[ $column ] = $value;
				}
			);
			return $named;
		}

		/**
		 * Keep existing header order; append dest names that are not present (trim match).
		 *
		 * @param array $headers Existing A1 cells.
		 * @param array $named   Dest-column map.
		 * @return string[]
		 */
		public static function extend_headers( array $headers, array $named ) {
			$extended = array();
			$seen     = array();
			foreach ( $headers as $header ) {
				$label      = trim( (string) $header );
				$extended[] = $label;
				if ( '' !== $label ) {
					$seen[ $label ] = true;
				}
			}
			foreach ( array_keys( $named ) as $column ) {
				$label = trim( (string) $column );
				if ( '' === $label || isset( $seen[ $label ] ) ) {
					continue;
				}
				$extended[]     = $label;
				$seen[ $label ] = true;
			}
			return $extended;
		}

		/**
		 * Pad named dest values to a header row.
		 *
		 * @param array $named   Dest-column map.
		 * @param array $headers Header labels.
		 * @return string[]
		 */
		public static function align_to_headers( array $named, array $headers ) {
			$lookup = array();
			foreach ( $named as $column => $value ) {
				$lookup[ trim( (string) $column ) ] = (string) $value;
			}
			$cells = array();
			foreach ( $headers as $header ) {
				$label   = trim( (string) $header );
				$cells[] = array_key_exists( $label, $lookup ) ? $lookup[ $label ] : '';
			}
			return $cells;
		}

		/**
		 * Cells for one sheet name. With headers, values follow dest names.
		 * Without headers, definition field order then dest'd extras.
		 *
		 * @param array      $definition Validated definition.
		 * @param array      $row        Logical sheet row.
		 * @param string     $sheet_name Mapping sheet name.
		 * @param array|null $headers    Existing sheet headers, or null for field order.
		 * @return string[]
		 */
		public static function cells_for_sheet( array $definition, array $row, $sheet_name, $headers = null ) {
			if ( is_array( $headers ) ) {
				return self::align_to_headers(
					self::named_values_for_sheet( $definition, $row, $sheet_name ),
					$headers
				);
			}
			$cells = array();
			self::each_sheet_dest(
				$definition,
				$row,
				$sheet_name,
				static function ( $column, $value ) use ( &$cells ) {
					unset( $column );
					$cells[] = $value;
				}
			);
			return $cells;
		}

		/**
		 * Visit each dest column/value pair for one sheet.
		 *
		 * @param array    $definition Validated definition.
		 * @param array    $row        Logical sheet row.
		 * @param string   $sheet_name Mapping sheet name.
		 * @param callable $visitor    function( string $column, string $value ).
		 * @return void
		 */
		private static function each_sheet_dest( array $definition, array $row, $sheet_name, $visitor ) {
			$field_dest = isset( $definition['mapping']['fieldDest'] ) && is_array( $definition['mapping']['fieldDest'] )
				? $definition['mapping']['fieldDest']
				: array();
			$fields     = isset( $definition['fields'] ) && is_array( $definition['fields'] )
				? $definition['fields']
				: array();

			self::walk_fields(
				$fields,
				static function ( $field ) use ( $visitor, $field_dest, $row, $sheet_name, $definition ) {
					$id = isset( $field['id'] ) ? (string) $field['id'] : '';
					if ( '' === $id || ! isset( $field_dest[ $id ] ) ) {
						return;
					}
					Portal_Submission_Destinations::emit_sheet_dests(
						$field_dest[ $id ],
						$sheet_name,
						Portal_Submission_Destinations::value_for_field( $field, $row ),
						$visitor,
						$definition
					);
				}
			);

			foreach ( self::EXTRA_KEYS as $key ) {
				if ( ! isset( $field_dest[ $key ] ) || ! isset( $row[ $key ] ) || is_array( $row[ $key ] ) ) {
					continue;
				}
				self::emit_sheet_dests( $field_dest[ $key ], $sheet_name, (string) $row[ $key ], $visitor, $definition );
			}
		}

		/**
		 * Emit dest columns on $sheet_name from one dest token.
		 *
		 * @param string   $dest       Pipe-joined dest string.
		 * @param string   $sheet_name Mapping sheet name.
		 * @param string   $value      Cell value.
		 * @param callable $visitor    function( string $column, string $value ).
		 * @param array    $definition Definition (role aliases).
		 * @return void
		 */
		private static function emit_sheet_dests( $dest, $sheet_name, $value, $visitor, array $definition = array() ) {
			$parsed = self::parse( $dest );
			foreach ( $parsed['sheets'] as $target ) {
				if ( ! self::dest_targets_sheet( $target['name'], $sheet_name, $definition ) ) {
					continue;
				}
				$column = trim( (string) $target['column'] );
				if ( '' === $column ) {
					continue;
				}
				call_user_func( $visitor, $column, $value );
			}
		}

		/**
		 * Dest sheet name matches the mapping sheet, or the role default
		 * (Housekeeping / Adjudicator) after the card was renamed.
		 *
		 * @param string $dest_name  Name in the dest token.
		 * @param string $sheet_name Mapping sheet name being written.
		 * @param array  $definition Definition document.
		 * @return bool
		 */
		public static function dest_targets_sheet( $dest_name, $sheet_name, array $definition = array() ) {
			if ( (string) $dest_name === (string) $sheet_name ) {
				return true;
			}
			$sheets = isset( $definition['mapping']['sheets'] ) && is_array( $definition['mapping']['sheets'] )
				? $definition['mapping']['sheets']
				: array();
			foreach ( $sheets as $sheet ) {
				if ( ! is_array( $sheet ) ) {
					continue;
				}
				if ( (string) ( $sheet['name'] ?? '' ) !== (string) $sheet_name ) {
					continue;
				}
				$role = isset( $sheet['role'] ) ? (string) $sheet['role'] : '';
				if ( 'housekeeping' === $role && 'Housekeeping' === $dest_name ) {
					return true;
				}
				if ( 'adjudicator' === $role && 'Adjudicator' === $dest_name ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * @param array  $field_dest mapping.fieldDest.
		 * @param string $field_id   Field id.
		 * @return string[] Drive folder names.
		 */
		public static function drive_names_for_field( array $field_dest, $field_id ) {
			if ( ! isset( $field_dest[ $field_id ] ) ) {
				return array();
			}
			return self::parse( $field_dest[ $field_id ] )['drive'];
		}

		/**
		 * @param array  $mapping Definition mapping.
		 * @param string $name    Drive folder name.
		 * @return string folderId or empty.
		 */
		public static function folder_id_for_name( array $mapping, $name ) {
			$drive = isset( $mapping['drive'] ) && is_array( $mapping['drive'] )
				? $mapping['drive']
				: array();
			foreach ( $drive as $folder ) {
				if ( ! is_array( $folder ) ) {
					continue;
				}
				if ( isset( $folder['name'] ) && (string) $folder['name'] === (string) $name ) {
					return isset( $folder['folderId'] ) ? (string) $folder['folderId'] : '';
				}
			}
			return '';
		}

		/**
		 * @param array    $fields Field list.
		 * @param callable $fn     Visitor.
		 * @return void
		 */
		public static function walk_fields( array $fields, $fn ) {
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				call_user_func( $fn, $field );
				$type = isset( $field['type'] ) ? (string) $field['type'] : '';
				if ( 'group' === $type && ! empty( $field['children'] ) && is_array( $field['children'] ) ) {
					self::walk_fields( $field['children'], $fn );
				}
				if ( 'branch' !== $type || empty( $field['options'] ) || ! is_array( $field['options'] ) ) {
					continue;
				}
				foreach ( $field['options'] as $opt ) {
					if ( ! empty( $opt['children'] ) && is_array( $opt['children'] ) ) {
						self::walk_fields( $opt['children'], $fn );
					}
				}
			}
		}

		/**
		 * @param array $field Field.
		 * @param array $row   Logical row.
		 * @return string
		 */
		public static function value_for_field( array $field, array $row ) {
			$id   = isset( $field['id'] ) ? (string) $field['id'] : '';
			$type = isset( $field['type'] ) ? (string) $field['type'] : '';
			if ( class_exists( 'Portal_Submission_Field_Rules' )
				&& in_array( $type, Portal_Submission_Field_Rules::FILE_TYPES, true ) ) {
				if ( ! empty( $row[ $id . '_link' ] ) ) {
					return (string) $row[ $id . '_link' ];
				}
				if ( ! empty( $row[ $id . '_path' ] ) ) {
					return (string) $row[ $id . '_path' ];
				}
				return '';
			}
			if ( 'applicant_pack' === $type ) {
				return isset( $row['sub_name'] ) ? (string) $row['sub_name'] : '';
			}
			if ( isset( $row[ $id ] ) && ! is_array( $row[ $id ] ) ) {
				return (string) $row[ $id ];
			}
			$prefixed = 'sub_' . $id;
			if ( isset( $row[ $prefixed ] ) && ! is_array( $row[ $prefixed ] ) ) {
				return (string) $row[ $prefixed ];
			}
			return '';
		}
	}
}
