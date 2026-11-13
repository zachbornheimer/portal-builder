<?php
/**
 * Local staging store for public form file picks (before Drive submit).
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Staged_File' ) ) {

	/**
	 * Stages upload bytes under an unguessable token; never writes Drive.
	 */
	class Portal_Staged_File {

		const TOKEN_BYTES      = 16;
		const STAGED_DIR_NAME  = 'staged';
		const WP_STAGED_DIR    = 'dg-staged';
		const META_FILENAME    = 'meta.json';
		const CONTENT_FILENAME = 'content';
		const FALLBACK_NAME    = 'upload.bin';

		/** @var Portal_Files */
		private $files;

		/** @var object|null Injectable anonymize transport. */
		private $transport;

		/** @var string Absolute staging root (…/staged). */
		private $base_dir;

		/**
		 * @param Portal_Files|null $files     Filesystem facade.
		 * @param object|null       $transport Anonymize transport.
		 * @param string|null       $base_dir  Override staging root (CLI tests).
		 */
		public function __construct( $files = null, $transport = null, $base_dir = null ) {
			$this->files     = $files instanceof Portal_Files ? $files : new Portal_Files();
			$this->transport = $transport;
			$this->base_dir  = is_string( $base_dir ) && '' !== $base_dir
				? rtrim( $base_dir, "/\\" )
				: self::default_base_dir();
		}

		/**
		 * Pure retained server filename: suffix before extension, no double suffix.
		 *
		 * @param string $original Original pick name.
		 * @param string $suffix   Field fileSuffix (e.g. _BIO).
		 * @return string
		 */
		public static function retained_name( $original, $suffix ) {
			$base = self::sanitize_filename( (string) $original );
			$suffix = (string) $suffix;
			if ( '' === $suffix ) {
				return $base;
			}

			$dot = strrpos( $base, '.' );
			if ( false === $dot || 0 === $dot ) {
				if ( self::ends_with( $base, $suffix ) ) {
					return $base;
				}
				return $base . $suffix;
			}

			$stem = substr( $base, 0, $dot );
			$ext  = substr( $base, $dot );
			if ( self::ends_with( $stem, $suffix ) ) {
				return $base;
			}
			return $stem . $suffix . $ext;
		}

		/**
		 * Stage bytes for a portal field. Returns token + retained name.
		 *
		 * @param string|int $portal_id     Portal id.
		 * @param string     $field_id      Definition field id.
		 * @param string     $buffer        Upload bytes.
		 * @param string     $original_name Client pick name.
		 * @param array      $definition    Validated definition document.
		 * @return array{token:string,storedName:string,originalName:string,bytes:int,anonymized:bool}|WP_Error
		 */
		public function stage( $portal_id, $field_id, $buffer, $original_name, $definition ) {
			$field_id      = (string) $field_id;
			$original_name = (string) $original_name;
			$buffer        = (string) $buffer;
			$portal_id     = (string) $portal_id;

			if ( '' === $buffer ) {
				return new WP_Error( 'dg_staged_empty', 'Upload is empty.' );
			}

			$field = self::find_field( $definition, $field_id );
			if ( null === $field ) {
				return new WP_Error( 'dg_staged_unknown_field', 'Unknown file field.' );
			}

			$suffix      = isset( $field['fileSuffix'] ) ? (string) $field['fileSuffix'] : '';
			$stored_name = self::retained_name( $original_name, $suffix );
			$field_type  = isset( $field['type'] ) ? (string) $field['type'] : 'file';

			$meta_probe = array(
				'name'     => $stored_name,
				'contents' => $buffer,
			);
			if ( class_exists( 'Portal_Submission_Field_Rules' )
				&& ! Portal_Submission_Field_Rules::mime_allowed( $field_type, $meta_probe ) ) {
				return new WP_Error(
					'dg_staged_mime',
					sprintf( 'File must be a valid %s.', $field_type )
				);
			}

			$anonymized = false;
			$final      = $this->maybe_anonymize( $definition, $buffer, $stored_name, $field_type );
			if ( $final !== $buffer ) {
				$anonymized = true;
			}

			$token = self::mint_token();
			$dir   = $this->token_dir( $token );
			$this->files->mkdir( $dir );

			$content_path = $this->files->join( $dir, self::CONTENT_FILENAME );
			$this->files->write( $content_path, $final );

			$record = array(
				'token'        => $token,
				'portalId'     => $portal_id,
				'fieldId'      => $field_id,
				'storedName'   => $stored_name,
				'originalName' => $original_name,
				'anonymized'   => $anonymized,
				'bytes'        => strlen( $final ),
			);
			$this->files->write(
				$this->files->join( $dir, self::META_FILENAME ),
				wp_json_encode( $record )
			);

			return array(
				'token'        => $token,
				'storedName'   => $stored_name,
				'originalName' => $original_name,
				'bytes'        => strlen( $final ),
				'anonymized'   => $anonymized,
			);
		}

		/**
		 * Load a staged record by token. Token is the capability.
		 *
		 * @param string $token Unguessable hex token.
		 * @return array{token:string,storedName:string,originalName:string,anonymized:bool,bytes:string,path:string,portalId?:string,fieldId?:string}|null|WP_Error
		 */
		public function read( $token ) {
			$token = self::normalize_token( $token );
			if ( '' === $token ) {
				return null;
			}
			$dir  = $this->token_dir( $token );
			$meta = $this->files->join( $dir, self::META_FILENAME );
			$content = $this->files->join( $dir, self::CONTENT_FILENAME );
			if ( ! $this->files->exists( $meta ) || ! $this->files->exists( $content ) ) {
				return null;
			}
			$decoded = json_decode( $this->files->read_text( $meta ), true );
			if ( ! is_array( $decoded ) ) {
				return new WP_Error( 'dg_staged_corrupt', 'Staged file metadata is corrupt.' );
			}
			$bytes = $this->files->read_text( $content );
			return array(
				'token'        => $token,
				'portalId'     => isset( $decoded['portalId'] ) ? (string) $decoded['portalId'] : '',
				'fieldId'      => isset( $decoded['fieldId'] ) ? (string) $decoded['fieldId'] : '',
				'storedName'   => isset( $decoded['storedName'] ) ? (string) $decoded['storedName'] : self::FALLBACK_NAME,
				'originalName' => isset( $decoded['originalName'] ) ? (string) $decoded['originalName'] : '',
				'anonymized'   => ! empty( $decoded['anonymized'] ),
				'bytes'        => $bytes,
				'path'         => $content,
			);
		}

		/**
		 * Delete a staged token and its bytes.
		 *
		 * @param string $token Token.
		 * @return void
		 */
		public function forget( $token ) {
			$token = self::normalize_token( $token );
			if ( '' === $token ) {
				return;
			}
			$dir = $this->token_dir( $token );
			if ( $this->files->exists( $dir ) ) {
				$this->files->remove( $dir );
			}
		}

		/**
		 * Build pipeline file meta from a live token.
		 *
		 * @param string $token Token.
		 * @return array|null
		 */
		public function as_file_meta( $token ) {
			$rec = $this->read( $token );
			if ( ! is_array( $rec ) ) {
				return null;
			}
			return array(
				'name'               => $rec['storedName'],
				'path'               => $rec['path'],
				'contents'           => $rec['bytes'],
				'already_anonymized' => true,
				'staged'             => true,
				'staged_token'       => $token,
			);
		}

		/**
		 * @return string
		 */
		public function base_dir() {
			return $this->base_dir;
		}

		/**
		 * @param array  $definition Definition.
		 * @param string $buffer     Bytes.
		 * @param string $filename   Retained name.
		 * @param string $field_type Field type.
		 * @return string
		 */
		private function maybe_anonymize( array $definition, $buffer, $filename, $field_type ) {
			if ( ! class_exists( 'Portal_Anonymizer' ) ) {
				return $buffer;
			}
			$options = class_exists( 'Portal_Site_Defaults' )
				? Portal_Site_Defaults::resolve( $definition, array() )
				: ( isset( $definition['options'] ) && is_array( $definition['options'] )
					? $definition['options']
					: array() );
			// Prefer definition options when site bag is empty (CLI).
			if ( empty( $options['anonymize'] ) && isset( $definition['options']['anonymize'] ) ) {
				$options = array_merge( $options, $definition['options'] );
			}
			if ( empty( $options['anonymize'] ) ) {
				return $buffer;
			}
			$type = null;
			if ( 'score_file' === $field_type || 'bio_file' === $field_type ) {
				$type = 'application/pdf';
			} elseif ( 'recording_file' === $field_type ) {
				$type = 'audio/mpeg';
			}
			$owner = new Portal_Anonymizer( $options, $this->transport );
			return $owner->maybe_anonymize( $buffer, $filename, $type );
		}

		/**
		 * @param string $token Token.
		 * @return string
		 */
		private function token_dir( $token ) {
			return $this->files->join( $this->base_dir, $token );
		}

		/**
		 * @return string
		 */
		private static function default_base_dir() {
			if ( class_exists( 'Portal_Test_Mode' ) && Portal_Test_Mode::is_enabled() ) {
				$root = Portal_Test_Mode::artifact_dir();
				return rtrim( $root, "/\\" ) . DIRECTORY_SEPARATOR . self::STAGED_DIR_NAME;
			}
			if ( function_exists( 'wp_upload_dir' ) ) {
				$upload = wp_upload_dir();
				if ( is_array( $upload ) && ! empty( $upload['basedir'] ) ) {
					return rtrim( (string) $upload['basedir'], "/\\" ) . DIRECTORY_SEPARATOR . self::WP_STAGED_DIR;
				}
			}
			// CLI without test mode: still use repo artifacts when possible.
			if ( class_exists( 'Portal_Test_Mode' ) ) {
				$root = Portal_Test_Mode::artifact_dir();
				return rtrim( $root, "/\\" ) . DIRECTORY_SEPARATOR . self::STAGED_DIR_NAME;
			}
			return sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::WP_STAGED_DIR;
		}

		/**
		 * @return string 32+ hex chars.
		 */
		private static function mint_token() {
			try {
				return bin2hex( random_bytes( self::TOKEN_BYTES ) );
			} catch ( Exception $e ) {
				return bin2hex( openssl_random_pseudo_bytes( self::TOKEN_BYTES ) );
			}
		}

		/**
		 * @param string $token Raw token.
		 * @return string
		 */
		private static function normalize_token( $token ) {
			$token = strtolower( (string) $token );
			if ( ! preg_match( '/^[a-f0-9]{32,}$/', $token ) ) {
				return '';
			}
			return $token;
		}

		/**
		 * Same character class as Portal_Drive_Store.
		 *
		 * @param string $filename Raw name.
		 * @return string
		 */
		private static function sanitize_filename( $filename ) {
			$base = basename( str_replace( '\\', '/', (string) $filename ) );
			$base = preg_replace( '/[^A-Za-z0-9._-]+/', '_', $base );
			if ( ! is_string( $base ) || '' === $base || '.' === $base || '..' === $base ) {
				return self::FALLBACK_NAME;
			}
			return $base;
		}

		/**
		 * @param array  $definition Definition.
		 * @param string $field_id   Field id.
		 * @return array|null
		 */
		public static function find_field( array $definition, $field_id ) {
			$fields = isset( $definition['fields'] ) && is_array( $definition['fields'] )
				? $definition['fields']
				: array();
			return self::find_in_fields( $fields, (string) $field_id );
		}

		/**
		 * @param array  $fields   Field list.
		 * @param string $field_id Target id.
		 * @return array|null
		 */
		private static function find_in_fields( array $fields, $field_id ) {
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				if ( isset( $field['id'] ) && (string) $field['id'] === $field_id ) {
					return $field;
				}
				if ( ! empty( $field['children'] ) && is_array( $field['children'] ) ) {
					$found = self::find_in_fields( $field['children'], $field_id );
					if ( null !== $found ) {
						return $found;
					}
				}
				if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
					foreach ( $field['options'] as $opt ) {
						if ( ! is_array( $opt ) || empty( $opt['children'] ) || ! is_array( $opt['children'] ) ) {
							continue;
						}
						$found = self::find_in_fields( $opt['children'], $field_id );
						if ( null !== $found ) {
							return $found;
						}
					}
				}
			}
			return null;
		}

		/**
		 * @param string $haystack Haystack.
		 * @param string $needle   Needle.
		 * @return bool
		 */
		private static function ends_with( $haystack, $needle ) {
			if ( '' === $needle ) {
				return true;
			}
			$len = strlen( $needle );
			if ( $len > strlen( $haystack ) ) {
				return false;
			}
			return substr( $haystack, -$len ) === $needle;
		}
	}
}
