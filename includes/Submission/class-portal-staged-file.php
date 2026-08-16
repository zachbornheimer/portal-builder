<?php
/**
 * Local staging store for public form file picks (before Drive submit).
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Anonymize_Decision' ) ) {
	$decision = __DIR__ . '/class-portal-anonymize-decision.php';
	if ( is_readable( $decision ) ) {
		require_once $decision;
	}
}

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
		const DEST_EXT_PDF     = 'pdf';
		const DEST_EXT_AUDIO   = 'mp3';
		const DEST_EXT_FILE    = 'bin';

		const ROLE_SCORE    = 'SCORE';
		const ROLE_REC      = 'REC';
		const ROLE_BIO      = 'BIO';
		const ROLE_ABSTRACT = 'ABSTRACT';
		const ROLE_DESC     = 'DESC';
		const ROLE_FILE     = 'FILE';

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
		 * Role from the fileSuffix tail after the last underscore.
		 *
		 * @param string $suffix Field fileSuffix (e.g. _NMMA_SCORE).
		 * @return string SCORE|REC|BIO|ABSTRACT|DESC|FILE
		 */
		public static function dest_role( $suffix ) {
			$raw = strtoupper( trim( (string) $suffix, " \t\n\r\0\x0B_" ) );
			if ( '' === $raw ) {
				return self::ROLE_FILE;
			}
			if ( 'POSTER_DESC' === $raw || self::ends_with( $raw, '_POSTER_DESC' ) ) {
				return self::ROLE_DESC;
			}
			$pos  = strrpos( $raw, '_' );
			$tail = false === $pos ? $raw : substr( $raw, $pos + 1 );
			if ( self::ROLE_SCORE === $tail ) {
				return self::ROLE_SCORE;
			}
			if ( self::ROLE_REC === $tail ) {
				return self::ROLE_REC;
			}
			if ( self::ROLE_BIO === $tail ) {
				return self::ROLE_BIO;
			}
			if ( self::ROLE_ABSTRACT === $tail ) {
				return self::ROLE_ABSTRACT;
			}
			if ( self::ROLE_DESC === $tail ) {
				return self::ROLE_DESC;
			}
			return self::ROLE_FILE;
		}

		/**
		 * Drive / staged dest basename: Score.pdf, Score1.pdf when several.
		 *
		 * @param string $original   Original pick name (extension only).
		 * @param string $suffix     Field fileSuffix.
		 * @param int    $ordinal    1-based index among same-role file fields.
		 * @param int    $peer_count Same-role file fields in definition walk order.
		 * @return string
		 */
		public static function dest_basename( $original, $suffix, $ordinal = 0, $peer_count = 1 ) {
			$role = self::dest_role( $suffix );
			$stem = self::dest_stem( $role );
			if ( (int) $peer_count > 1 && (int) $ordinal > 0 ) {
				$stem .= (string) (int) $ordinal;
			}
			return $stem . '.' . self::dest_extension( $original, $role );
		}

		/**
		 * Pure dest name for a lone field (unnumbered).
		 *
		 * @param string $original Original pick name.
		 * @param string $suffix   Field fileSuffix (e.g. _BIO).
		 * @return string
		 */
		public static function retained_name( $original, $suffix ) {
			return self::dest_basename( $original, $suffix, 0, 1 );
		}

		/**
		 * Dest name numbered among same-role file fields in definition walk order.
		 *
		 * @param string $original   Original pick name.
		 * @param string $suffix     Field fileSuffix.
		 * @param array  $definition Validated definition document.
		 * @param string $field_id   Field being staged.
		 * @return string
		 */
		public static function numbered_dest_name( $original, $suffix, array $definition, $field_id ) {
			$role  = self::dest_role( $suffix );
			$peers = self::file_fields_with_role( $definition, $role );
			$count = count( $peers );
			$index = 0;
			foreach ( $peers as $i => $peer ) {
				if ( isset( $peer['id'] ) && (string) $peer['id'] === (string) $field_id ) {
					$index = $i + 1;
					break;
				}
			}
			return self::dest_basename( $original, $suffix, $index, $count );
		}

		/**
		 * @param string $role Dest role.
		 * @return string
		 */
		private static function dest_stem( $role ) {
			if ( self::ROLE_SCORE === $role ) {
				return 'Score';
			}
			if ( self::ROLE_REC === $role ) {
				return 'Recording';
			}
			if ( self::ROLE_BIO === $role ) {
				return 'Bio';
			}
			if ( self::ROLE_ABSTRACT === $role ) {
				return 'Abstract';
			}
			if ( self::ROLE_DESC === $role ) {
				return 'Description';
			}
			return 'File';
		}

		/**
		 * @param string $original Original pick name.
		 * @param string $role     Dest role.
		 * @return string
		 */
		private static function dest_extension( $original, $role ) {
			$base = self::sanitize_filename( (string) $original );
			$ext  = strtolower( (string) pathinfo( $base, PATHINFO_EXTENSION ) );
			if ( '' !== $ext && 'bin' !== $ext ) {
				return $ext;
			}
			if ( self::ROLE_REC === $role ) {
				return self::DEST_EXT_AUDIO;
			}
			if ( self::ROLE_FILE === $role ) {
				return '' !== $ext ? $ext : self::DEST_EXT_FILE;
			}
			return self::DEST_EXT_PDF;
		}

		/**
		 * File fields with the same dest role, definition walk order.
		 *
		 * @param array  $definition Definition.
		 * @param string $role       Dest role.
		 * @return array<int,array>
		 */
		public static function file_fields_with_role( array $definition, $role ) {
			$out    = array();
			$fields = isset( $definition['fields'] ) && is_array( $definition['fields'] )
				? $definition['fields']
				: array();
			foreach ( self::walk_file_fields( $fields ) as $field ) {
				$suffix = isset( $field['fileSuffix'] ) ? (string) $field['fileSuffix'] : '';
				if ( self::dest_role( $suffix ) === $role ) {
					$out[] = $field;
				}
			}
			return $out;
		}

		/**
		 * @param array $fields Field list.
		 * @return array<int,array>
		 */
		private static function walk_file_fields( array $fields ) {
			$out = array();
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				if ( self::is_file_field( $field ) ) {
					$out[] = $field;
				}
				if ( ! empty( $field['children'] ) && is_array( $field['children'] ) ) {
					$out = array_merge( $out, self::walk_file_fields( $field['children'] ) );
				}
				if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
					foreach ( $field['options'] as $opt ) {
						if ( ! is_array( $opt ) || empty( $opt['children'] ) || ! is_array( $opt['children'] ) ) {
							continue;
						}
						$out = array_merge( $out, self::walk_file_fields( $opt['children'] ) );
					}
				}
			}
			return $out;
		}

		/**
		 * @param array $field Field.
		 * @return bool
		 */
		private static function is_file_field( array $field ) {
			$type = isset( $field['type'] ) ? (string) $field['type'] : '';
			if ( class_exists( 'Portal_Submission_Field_Rules' ) ) {
				return in_array( $type, Portal_Submission_Field_Rules::FILE_TYPES, true );
			}
			return isset( $field['fileSuffix'] ) || false !== strpos( $type, 'file' );
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
			$stored_name = self::numbered_dest_name( $original_name, $suffix, $definition, $field_id );
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

			$decision = $this->decide_anonymize( $definition, $buffer, $stored_name, $field_type );
			if ( $decision->blocks_store() ) {
				return new WP_Error( $decision->code, $decision->message );
			}
			$final      = $decision->bytes;
			$anonymized = Portal_Anonymize_Decision::ACTION_REPLACE === $decision->action;

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
		 * @return Portal_Anonymize_Decision
		 */
		private function decide_anonymize( array $definition, $buffer, $filename, $field_type ) {
			$options = $this->resolved_options( $definition );
			if ( ! class_exists( 'Portal_Anonymizer' ) ) {
				if ( empty( $options['anonymize'] ) ) {
					return Portal_Anonymize_Decision::skip( $buffer );
				}
				if ( ! empty( $options['anonymizeFailClosed'] ) ) {
					return Portal_Anonymize_Decision::refuse( Portal_Anonymize_Decision::MSG_UNUSABLE );
				}
				return Portal_Anonymize_Decision::keep( $buffer );
			}
			$type = null;
			if ( 'score_file' === $field_type || 'bio_file' === $field_type ) {
				$type = 'application/pdf';
			} elseif ( 'recording_file' === $field_type ) {
				$type = 'audio/mpeg';
			}
			$owner = new Portal_Anonymizer( $options, $this->transport );
			return $owner->decide( $buffer, $filename, $type );
		}

		/**
		 * @param array $definition Definition.
		 * @return array
		 */
		private function resolved_options( array $definition ) {
			$options = class_exists( 'Portal_Site_Defaults' ) && function_exists( 'get_option' )
				? Portal_Site_Defaults::resolve_for_site( $definition )
				: ( class_exists( 'Portal_Site_Defaults' )
					? Portal_Site_Defaults::resolve( $definition, array() )
					: ( isset( $definition['options'] ) && is_array( $definition['options'] )
						? $definition['options']
						: array() ) );
			if ( empty( $options['anonymize'] ) && isset( $definition['options']['anonymize'] ) ) {
				$options = array_merge( $options, $definition['options'] );
			}
			return $options;
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
			if ( class_exists( 'Portal_Upload_Store' ) ) {
				return Portal_Upload_Store::staged_dir();
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
