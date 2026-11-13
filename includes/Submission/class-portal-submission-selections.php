<?php
/**
 * Filterable selection columns for branch fields on the sheet row.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Submission_Selections' ) ) {

	/**
	 * Assembles branch option id/label columns and a joined selection path.
	 * Always emits a column for every branch field in the definition tree.
	 */
	class Portal_Submission_Selections {

		/** Joined-path separator: space, U+203A SINGLE RIGHT-POINTING ANGLE QUOTATION MARK, space. */
		const PATH_SEPARATOR = ' › ';

		const LABEL_SUFFIX = '_label';
		const PATH_KEY     = 'selection_path';

		/**
		 * Columns to merge onto the sheet row for every branch field.
		 *
		 * @param array $definition Validated definition.
		 * @param array $values     Collected values (field id => option id).
		 * @return array<string,string>
		 */
		public static function columns( array $definition, array $values ) {
			$fields = isset( $definition['fields'] ) && is_array( $definition['fields'] )
				? $definition['fields']
				: array();

			$branches = array();
			self::collect_branches( $fields, $branches );
			if ( empty( $branches ) ) {
				return array();
			}

			$columns     = array();
			$path_labels = array();

			foreach ( $branches as $branch ) {
				$id           = (string) $branch['id'];
				$selected     = Portal_Submission_Field_Rules::lookup_value( $values, $id );
				$option_id    = '';
				$option_label = '';

				if ( ! Portal_Submission_Field_Rules::is_blank( $selected ) ) {
					$option_id    = (string) $selected;
					$option_label = self::label_for_option( $branch, $option_id );
					if ( '' !== $option_label ) {
						$path_labels[] = $option_label;
					}
				}

				$columns[ $id ]                        = $option_id;
				$columns[ $id . self::LABEL_SUFFIX ] = $option_label;
			}

			$columns[ self::PATH_KEY ] = implode( self::PATH_SEPARATOR, $path_labels );
			return $columns;
		}

		/**
		 * Discover every branch field in tree order (groups + all option children).
		 *
		 * @param array $fields Field list.
		 * @param array $out    Accumulator of branch field arrays.
		 * @return void
		 */
		private static function collect_branches( array $fields, array &$out ) {
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$type = isset( $field['type'] ) ? (string) $field['type'] : '';
				if ( 'group' === $type ) {
					$children = isset( $field['children'] ) && is_array( $field['children'] )
						? $field['children']
						: array();
					self::collect_branches( $children, $out );
					continue;
				}
				if ( 'branch' !== $type || empty( $field['id'] ) ) {
					continue;
				}
				$out[]   = $field;
				$options = isset( $field['options'] ) && is_array( $field['options'] )
					? $field['options']
					: array();
				foreach ( $options as $opt ) {
					if ( ! is_array( $opt ) ) {
						continue;
					}
					$children = isset( $opt['children'] ) && is_array( $opt['children'] )
						? $opt['children']
						: array();
					self::collect_branches( $children, $out );
				}
			}
		}

		/**
		 * @param array  $branch    Branch field.
		 * @param string $option_id Selected option id.
		 * @return string Option label, or empty when unknown.
		 */
		private static function label_for_option( array $branch, $option_id ) {
			$options = isset( $branch['options'] ) && is_array( $branch['options'] )
				? $branch['options']
				: array();
			foreach ( $options as $opt ) {
				if ( ! is_array( $opt ) || ! isset( $opt['id'] ) ) {
					continue;
				}
				if ( (string) $opt['id'] === $option_id ) {
					return isset( $opt['label'] ) ? (string) $opt['label'] : '';
				}
			}
			return '';
		}
	}
}
