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

		const SHEET_PREFIX    = 'sheet:';
		const DRIVE_PREFIX    = 'drive:';
		const RAW_DATA_TAB    = 'Raw Data';
		const FILE_URL_PREFIX = 'https://drive.google.com/file/d/';

		const EXTRA_KEYS = array( 'selection_path', 'portalId', 'createdAt', 'status' );

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
		 * Cells for one sheet name: definition field order, then dest'd extras.
		 *
		 * @param array  $definition Validated definition.
		 * @param array  $row        Logical sheet row.
		 * @param string $sheet_name Mapping sheet name.
		 * @return string[]
		 */
		public static function cells_for_sheet( array $definition, array $row, $sheet_name ) {
			$cells      = array();
			$field_dest = isset( $definition['mapping']['fieldDest'] ) && is_array( $definition['mapping']['fieldDest'] )
				? $definition['mapping']['fieldDest']
				: array();
			$fields     = isset( $definition['fields'] ) && is_array( $definition['fields'] )
				? $definition['fields']
				: array();

			self::walk_fields(
				$fields,
				static function ( $field ) use ( &$cells, $field_dest, $row, $sheet_name ) {
					$id = isset( $field['id'] ) ? (string) $field['id'] : '';
					if ( '' === $id || ! isset( $field_dest[ $id ] ) ) {
						return;
					}
					$parsed = Portal_Submission_Destinations::parse( $field_dest[ $id ] );
					foreach ( $parsed['sheets'] as $target ) {
						if ( $target['name'] === $sheet_name ) {
							$cells[] = Portal_Submission_Destinations::value_for_field( $field, $row );
						}
					}
				}
			);

			foreach ( self::EXTRA_KEYS as $key ) {
				if ( ! isset( $field_dest[ $key ] ) || ! isset( $row[ $key ] ) || is_array( $row[ $key ] ) ) {
					continue;
				}
				$parsed = self::parse( $field_dest[ $key ] );
				foreach ( $parsed['sheets'] as $target ) {
					if ( $target['name'] === $sheet_name ) {
						$cells[] = (string) $row[ $key ];
					}
				}
			}

			return $cells;
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
