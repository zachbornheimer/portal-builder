<?php
/**
 * Site-wide legal disclaimer rows from dg_legal_disclaimers.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Legal_Disclaimers' ) ) {

	/**
	 * Parses settings Data_Table rows into public-form checkboxes.
	 */
	class Portal_Legal_Disclaimers {

		const OPTION_KEY       = 'dg_legal_disclaimers';
		const NAME_PREFIX      = 'sub_';
		const TEST_ROWS_GLOBAL = 'dg_test_legal_disclaimers';
		const UNWRAP_LIMIT     = 8;

		/**
		 * Unwrap stored JSON (live is double-encoded) into disclaimer rows.
		 *
		 * Each Data_Table row is [id, text]. Empty id or text is dropped.
		 * field_id strips a leading sub_ so input_name posts sub_{field_id}.
		 *
		 * @param mixed $raw Option string, decoded table, or injected {id,text} list.
		 * @return array<int,array{id:string,text:string,field_id:string}>
		 */
		public static function parse( $raw ) {
			$table = self::unwrap_to_array( $raw );
			$rows  = array();
			foreach ( $table as $item ) {
				$row = self::row_from( $item );
				if ( null === $row ) {
					continue;
				}
				$rows[] = $row;
			}
			return $rows;
		}

		/**
		 * Current site rows: test hook, else the stored option.
		 *
		 * @return array<int,array{id:string,text:string,field_id:string}>
		 */
		public static function rows() {
			if ( isset( $GLOBALS[ self::TEST_ROWS_GLOBAL ] ) && is_array( $GLOBALS[ self::TEST_ROWS_GLOBAL ] ) ) {
				return self::parse( $GLOBALS[ self::TEST_ROWS_GLOBAL ] );
			}
			$raw = array();
			if ( class_exists( 'Portal_Options' ) ) {
				$raw = Portal_Options::get( self::OPTION_KEY, array() );
			}
			return self::parse( $raw );
		}

		/**
		 * Decode nested JSON strings until an array remains.
		 *
		 * @param mixed $raw Stored value.
		 * @return array
		 */
		private static function unwrap_to_array( $raw ) {
			$guard = 0;
			while ( is_string( $raw ) && $guard < self::UNWRAP_LIMIT ) {
				$decoded = json_decode( $raw, true );
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					return array();
				}
				$raw = $decoded;
				++$guard;
			}
			return is_array( $raw ) ? $raw : array();
		}

		/**
		 * Normalize one table or injected row, or null when empty.
		 *
		 * @param mixed $row [id, text] or {id, text}.
		 * @return array{id:string,text:string,field_id:string}|null
		 */
		private static function row_from( $row ) {
			if ( ! is_array( $row ) ) {
				return null;
			}
			if ( isset( $row['id'] ) || isset( $row['text'] ) ) {
				$id   = isset( $row['id'] ) ? trim( (string) $row['id'] ) : '';
				$text = isset( $row['text'] ) ? trim( (string) $row['text'] ) : '';
			} else {
				$id   = isset( $row[0] ) ? trim( (string) $row[0] ) : '';
				$text = isset( $row[1] ) ? trim( (string) $row[1] ) : '';
			}
			if ( '' === $id || '' === $text ) {
				return null;
			}
			$field_id = self::field_id_for( $id );
			if ( '' === $field_id ) {
				return null;
			}
			return array(
				'id'       => $id,
				'text'     => $text,
				'field_id' => $field_id,
			);
		}

		/**
		 * Strip a leading sub_ prefix so the renderer posts sub_{field_id}.
		 *
		 * @param string $id Stored row id.
		 * @return string
		 */
		private static function field_id_for( $id ) {
			if ( 0 === strpos( $id, self::NAME_PREFIX ) ) {
				return substr( $id, strlen( self::NAME_PREFIX ) );
			}
			return $id;
		}
	}
}
