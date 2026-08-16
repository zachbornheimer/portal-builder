<?php
/**
 * Filesystem facade for submission artifact stores.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Files' ) ) {

	/**
	 * Injectable filesystem boundary (no direct os calls outside this class).
	 */
	class Portal_Files {

		/**
		 * Create a directory (and parents).
		 *
		 * @param string $path Absolute path.
		 * @return void
		 */
		public function mkdir( $path ) {
			if ( ! is_dir( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
				mkdir( $path, 0755, true );
			}
		}

		/**
		 * Write entire file contents.
		 *
		 * @param string          $path Absolute path.
		 * @param string|resource $data Contents.
		 * @return void
		 */
		public function write( $path, $data ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $path, $data );
		}

		/**
		 * Append string data to a file.
		 *
		 * @param string $path Absolute path.
		 * @param string $data Contents to append.
		 * @return void
		 */
		public function append( $path, $data ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $path, $data, FILE_APPEND );
		}

		/**
		 * Read file as string.
		 *
		 * @param string $path Absolute path.
		 * @return string
		 */
		public function read_text( $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return (string) file_get_contents( $path );
		}

		/**
		 * Whether path exists.
		 *
		 * @param string $path Absolute path.
		 * @return bool
		 */
		public function exists( $path ) {
			return file_exists( $path );
		}

		/**
		 * Remove a file or directory tree.
		 *
		 * @param string $path Absolute path.
		 * @return void
		 */
		public function remove( $path ) {
			if ( ! file_exists( $path ) ) {
				return;
			}
			if ( is_dir( $path ) ) {
				$items = scandir( $path );
				if ( false === $items ) {
					return;
				}
				foreach ( $items as $item ) {
					if ( '.' === $item || '..' === $item ) {
						continue;
					}
					$this->remove( $path . DIRECTORY_SEPARATOR . $item );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				rmdir( $path );
				return;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $path );
		}

		/**
		 * Join path segments.
		 *
		 * @param string ...$parts Path segments.
		 * @return string
		 */
		public function join( ...$parts ) {
			$filtered = array();
			foreach ( $parts as $part ) {
				if ( '' === $part || null === $part ) {
					continue;
				}
				$filtered[] = (string) $part;
			}
			if ( empty( $filtered ) ) {
				return '';
			}
			$first = array_shift( $filtered );
			$rest  = array_map(
				static function ( $p ) {
					return trim( (string) $p, "/\\ \t\n\r\0\x0B" );
				},
				$filtered
			);
			return rtrim( $first, "/\\" ) . ( empty( $rest ) ? '' : DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, $rest ) );
		}

		/**
		 * Parent directory of a path.
		 *
		 * @param string $path Path.
		 * @return string
		 */
		public function dirname( $path ) {
			return dirname( $path );
		}

		/**
		 * Whether path is a directory.
		 *
		 * @param string $path Path.
		 * @return bool
		 */
		public function is_dir( $path ) {
			return is_dir( $path );
		}

		/**
		 * Whether path is a regular file.
		 *
		 * @param string $path Path.
		 * @return bool
		 */
		public function is_file( $path ) {
			return is_file( $path );
		}

		/**
		 * Non-dot names in a directory.
		 *
		 * @param string $path Directory.
		 * @return string[]
		 */
		public function list_names( $path ) {
			if ( ! is_dir( $path ) ) {
				return array();
			}
			$items = scandir( $path );
			if ( ! is_array( $items ) ) {
				return array();
			}
			$out = array();
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$out[] = $item;
			}
			return $out;
		}

		/**
		 * File modification time, or 0 when unknown.
		 *
		 * @param string $path Path.
		 * @return int
		 */
		public function mtime( $path ) {
			$mtime = filemtime( $path );
			return false === $mtime ? 0 : (int) $mtime;
		}
	}
}
