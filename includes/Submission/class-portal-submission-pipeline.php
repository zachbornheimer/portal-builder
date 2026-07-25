<?php
/**
 * Definition-aware submission pipeline (validate → drive → sheet → mail).
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Submission_Pipeline' ) ) {

	/**
	 * Logical pipeline from PORTAL-MODEL §5 for definition-based forms.
	 *
	 * In test mode (default for this foundation), Sheet / Drive / Mail write
	 * file-backed artifacts under the configured artifact directory.
	 */
	class Portal_Submission_Pipeline {

		const STATUS_RECEIVED = 'received';
		const STATUS_SYNCED   = 'synced';
		const STATUS_FAILED   = 'failed';

		const RECEIPT_SUBJECT_PREFIX = 'Application receipt';

		/** Single-step definition form nonce (Phase 3). */
		const NONCE_ACTION = 'dg_definition_submit';
		const NONCE_FIELD  = 'dg_definition_submit';

		const SUCCESS_COPY      = 'Your application has been submitted successfully!';
		const RECEIPT_LINK_TEXT = 'Click here to view the details of your application. Please print / save this for your records.';

		/** @var array|null Last validation/pipeline errors for re-render. */
		private static $last_errors = null;

		/** @var array|null Last success payload for re-render. */
		private static $last_success = null;

		/**
		 * @return array|null
		 */
		public static function last_errors() {
			return self::$last_errors;
		}

		/**
		 * @return array|null
		 */
		public static function last_success() {
			return self::$last_success;
		}

		/**
		 * Process a definition single-step form POST (values + $_FILES).
		 *
		 * @param int                  $portal_id Portal post ID.
		 * @param array<string,mixed>  $post      $_POST-like map.
		 * @param array<string,mixed>  $files     $_FILES-like map.
		 * @return array{ok:bool,errors?:array,result?:array}
		 */
		public static function process_request( $portal_id, array $post, array $files ) {
			self::$last_errors  = null;
			self::$last_success = null;

			$portal_id  = (int) $portal_id;
			$definition = Portal_Definition::load_for_post( $portal_id );
			if ( null === $definition ) {
				self::$last_errors = array(
					array(
						'code'     => 'definition',
						'field_id' => '',
						'message'  => 'This portal has no form definition.',
					),
				);
				return array( 'ok' => false, 'errors' => self::$last_errors );
			}

			if ( ! Portal_Test_Mode::is_enabled() ) {
				self::$last_errors = array(
					array(
						'code'     => 'test_mode',
						'field_id' => '',
						'message'  => 'Definition submit requires DG_TEST_MODE until live adapters ship.',
					),
				);
				return array( 'ok' => false, 'errors' => self::$last_errors );
			}

			$file_meta = self::normalize_uploaded_files( $files );
			$pipeline  = self::for_artifacts( Portal_Test_Mode::artifact_dir() );
			$result    = $pipeline->process( $portal_id, $definition, $post, $file_meta );

			if ( is_wp_error( $result ) ) {
				self::$last_errors = self::errors_from_wp_error( $result );
				return array( 'ok' => false, 'errors' => self::$last_errors );
			}

			$receipt_url = self::build_receipt_url( $portal_id, $result );
			$result['receipt_url'] = $receipt_url;
			self::$last_success    = $result;
			return array( 'ok' => true, 'result' => $result );
		}

		/**
		 * @param array $result Pipeline result.
		 * @return string
		 */
		public static function render_success( array $result ) {
			$url    = isset( $result['receipt_url'] ) ? (string) $result['receipt_url'] : '#';
			$app_id = isset( $result['portalId'] ) ? (string) $result['portalId'] : '';

			return sprintf(
				'<div class="dg-submit-success" data-dg-submit-status="success" data-dg-app-id="%1$s"><p>%2$s</p><p><a href="%3$s" data-dg-receipt-link target="_blank" rel="noopener">%4$s</a></p></div>',
				esc_attr( $app_id ),
				esc_html( self::SUCCESS_COPY ),
				esc_url( $url ),
				esc_html( self::RECEIPT_LINK_TEXT )
			);
		}

		/**
		 * @param array $errors Error list.
		 * @return string
		 */
		public static function render_errors( array $errors ) {
			$items = '';
			foreach ( $errors as $err ) {
				$code     = isset( $err['code'] ) ? (string) $err['code'] : '';
				$field_id = isset( $err['field_id'] ) ? (string) $err['field_id'] : '';
				$message  = isset( $err['message'] ) ? (string) $err['message'] : 'Submission error.';
				$items   .= sprintf(
					'<li data-dg-error-code="%1$s" data-dg-field-id="%2$s">%3$s</li>',
					esc_attr( $code ),
					esc_attr( $field_id ),
					esc_html( $message )
				);
			}
			return sprintf(
				'<div class="dg-submit-errors" data-dg-submit-status="error" role="alert"><ul>%s</ul></div>',
				$items
			);
		}

		/**
		 * Map $_FILES entries onto field ids (strip sub_ prefix).
		 *
		 * @param array $files $_FILES.
		 * @return array<string,array>
		 */
		private static function normalize_uploaded_files( array $files ) {
			$out = array();
			foreach ( $files as $name => $meta ) {
				if ( ! is_array( $meta ) ) {
					continue;
				}
				// Skip empty file inputs.
				if ( isset( $meta['error'] ) && (int) $meta['error'] === UPLOAD_ERR_NO_FILE ) {
					continue;
				}
				$field_id = (string) $name;
				if ( 0 === strpos( $field_id, Portal_Submission_Field_Rules::NAME_PREFIX ) ) {
					$field_id = substr( $field_id, strlen( Portal_Submission_Field_Rules::NAME_PREFIX ) );
				}
				$out[ $field_id ] = $meta;
			}
			return $out;
		}

		/**
		 * @param WP_Error $err Error.
		 * @return array<int,array{code:string,field_id:string,message:string}>
		 */
		private static function errors_from_wp_error( $err ) {
			$errors = array();
			$data   = $err->get_error_data();
			if ( is_array( $data ) && isset( $data['fields'] ) && is_array( $data['fields'] ) ) {
				foreach ( $data['fields'] as $field_id => $message ) {
					$code = 'required';
					$msg  = (string) $message;
					if ( false !== stripos( $msg, 'valid PDF' ) || false !== stripos( $msg, 'valid MP3' ) || false !== stripos( $msg, 'must be a valid' ) ) {
						$code = 'mime';
					}
					$errors[] = array(
						'code'     => $code,
						'field_id' => (string) $field_id,
						'message'  => $msg,
					);
				}
			}
			if ( empty( $errors ) ) {
				$errors[] = array(
					'code'     => $err->get_error_code(),
					'field_id' => '',
					'message'  => $err->get_error_message(),
				);
			}
			return $errors;
		}

		/**
		 * @param int   $portal_id Portal ID.
		 * @param array $result    Pipeline result.
		 * @return string
		 */
		private static function build_receipt_url( $portal_id, array $result ) {
			$args = array(
				'dg-receipt' => '1',
				'portal-id'  => (int) $portal_id,
			);
			if ( ! empty( $result['applicant']['sub_email'] ) ) {
				$args['email'] = $result['applicant']['sub_email'];
			}
			if ( ! empty( $result['applicant']['sub_name'] ) ) {
				$args['name'] = $result['applicant']['sub_name'];
			}
			if ( ! empty( $result['values']['work_title'] ) ) {
				$args['work_title'] = $result['values']['work_title'];
			}
			$permalink = get_permalink( $portal_id );
			if ( ! $permalink ) {
				$permalink = home_url( '/' );
			}
			return add_query_arg( $args, $permalink );
		}

		/** @var Portal_Sheet_Store */
		private $sheets;

		/** @var Portal_Drive_Store */
		private $drive;

		/** @var Portal_Mailer */
		private $mailer;

		/** @var Portal_Files */
		private $files;

		/**
		 * @param Portal_Sheet_Store $sheets Sheet store.
		 * @param Portal_Drive_Store $drive  Drive store.
		 * @param Portal_Mailer      $mailer Mailer.
		 * @param Portal_Files|null  $files  Filesystem for reading upload paths.
		 */
		public function __construct( Portal_Sheet_Store $sheets, Portal_Drive_Store $drive, Portal_Mailer $mailer, $files = null ) {
			$this->sheets = $sheets;
			$this->drive  = $drive;
			$this->mailer = $mailer;
			$this->files  = $files instanceof Portal_Files ? $files : new Portal_Files();
		}

		/**
		 * Build a pipeline wired to the given artifact directory (test mode).
		 *
		 * @param string            $artifact_dir Absolute artifact root.
		 * @param Portal_Files|null $files        Injectable FS.
		 * @param callable|null     $now_ms       Injectable clock for mail names.
		 * @return self
		 */
		public static function for_artifacts( $artifact_dir, $files = null, $now_ms = null ) {
			$files  = $files instanceof Portal_Files ? $files : new Portal_Files();
			$sheets = new Portal_Sheet_Store( $artifact_dir, $files );
			$drive  = new Portal_Drive_Store( $artifact_dir, $files );
			$mailer = new Portal_Mailer( $artifact_dir, $files, $now_ms );
			return new self( $sheets, $drive, $mailer, $files );
		}

		/**
		 * Process a portal post when a definition is present (WP runtime helper).
		 *
		 * Loads `_portal_definition`, builds artifact stores when test mode is on,
		 * and runs the pipeline. Returns WP_Error when definition is missing or
		 * test mode is off (production Google adapters land later).
		 *
		 * @param int                 $post_id Portal post ID.
		 * @param array<string,mixed> $values  Form values (sub_* keys).
		 * @param array<string,mixed> $files   Map fieldId => file meta.
		 * @return array|WP_Error
		 */
		public static function process_for_post( $post_id, array $values, array $files = array() ) {
			$post_id = (int) $post_id;
			if ( $post_id <= 0 ) {
				return new WP_Error( 'dg_submission_portal', 'Invalid portal id.' );
			}
			if ( ! class_exists( 'Portal_Definition' ) ) {
				return new WP_Error( 'dg_submission_definition', 'Definition module not loaded.' );
			}
			$definition = Portal_Definition::load_for_post( $post_id );
			if ( null === $definition ) {
				return new WP_Error( 'dg_submission_no_definition', 'Portal has no valid definition.' );
			}
			if ( ! Portal_Test_Mode::is_enabled() ) {
				return new WP_Error(
					'dg_submission_not_test_mode',
					'Definition submit requires DG_TEST_MODE until live Sheet/Drive adapters ship.'
				);
			}
			$artifact_dir = Portal_Test_Mode::artifact_dir();
			$pipeline     = self::for_artifacts( $artifact_dir );
			return $pipeline->process( $post_id, $definition, $values, $files );
		}

		/**
		 * Run the full pipeline for one submission.
		 *
		 * @param string|int          $portal_id  Portal id (opaque; used in artifact paths).
		 * @param array               $definition Validated definition document.
		 * @param array<string,mixed> $values     Form values (sub_* keys).
		 * @param array<string,mixed> $files      Map fieldId => { name, contents|path }.
		 * @return array|WP_Error Result payload on success.
		 */
		public function process( $portal_id, array $definition, array $values, array $files = array() ) {
			$portal_id = (string) $portal_id;
			$validated = Portal_Submission_Validator::validate( $definition, $values, $files );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}

			$drive_paths = array();
			$file_meta   = array();

			foreach ( $validated['files'] as $field_id => $meta ) {
				$buffer   = $this->read_file_buffer( $meta );
				$filename = isset( $meta['name'] ) ? (string) $meta['name'] : ( $field_id . '.bin' );
				if ( null === $buffer ) {
					return new WP_Error(
						'dg_submission_file_read',
						sprintf( 'Could not read upload for field "%s".', $field_id )
					);
				}
				$path                       = $this->drive->store_file( $portal_id, $field_id, $buffer, $filename );
				$drive_paths[ $field_id ]   = $path;
				$file_meta[ $field_id ]     = array(
					'fieldId'  => $field_id,
					'filename' => $filename,
					'path'     => $path,
					'bytes'    => strlen( $buffer ),
				);
			}

			$row = $this->build_sheet_row( $portal_id, $definition, $validated, $drive_paths );
			$sheet_path = $this->sheets->append_row( $portal_id, $row );

			$to = isset( $validated['applicant']['sub_email'] )
				? $validated['applicant']['sub_email']
				: null;
			if ( null === $to ) {
				// Fallback: any email-type field value.
				foreach ( $validated['values'] as $fid => $val ) {
					if ( is_string( $val ) && false !== strpos( $fid, 'email' ) ) {
						$to = $val;
						break;
					}
				}
			}

			$title   = isset( $definition['title'] ) ? (string) $definition['title'] : 'Portal';
			$subject = self::RECEIPT_SUBJECT_PREFIX . ': ' . $title;
			$mail_path = $this->mailer->capture(
				array(
					'to'       => $to ? $to : Portal_Mailer::UNKNOWN_RECIPIENT,
					'subject'  => $subject,
					'portalId' => $portal_id,
					'title'    => $title,
					'body'     => sprintf(
						'We received your application for %s.',
						$title
					),
					'values'   => $validated['values'],
					'files'    => $file_meta,
				)
			);

			return array(
				'ok'         => true,
				'status'     => self::STATUS_SYNCED,
				'portalId'   => $portal_id,
				'sheetPath'  => $sheet_path,
				'drivePaths' => $drive_paths,
				'mailPath'   => $mail_path,
				'row'        => $row,
				'values'     => $validated['values'],
				'files'      => $file_meta,
				'applicant'  => $validated['applicant'],
			);
		}

		/**
		 * Flatten validated data into a sheet row (field ids as keys).
		 *
		 * @param string              $portal_id    Portal id.
		 * @param array               $definition   Definition.
		 * @param array               $validated    Validator output.
		 * @param array<string,string> $drive_paths Field id => stored path.
		 * @return array<string,mixed>
		 */
		private function build_sheet_row( $portal_id, array $definition, array $validated, array $drive_paths ) {
			$row = array(
				'portalId'  => $portal_id,
				'createdAt' => gmdate( 'c' ),
				'status'    => self::STATUS_SYNCED,
			);

			foreach ( $validated['applicant'] as $key => $val ) {
				$row[ $key ] = $val;
			}

			foreach ( $validated['values'] as $field_id => $val ) {
				if ( is_array( $val ) ) {
					// Applicant pack already expanded via applicant keys.
					continue;
				}
				$row[ $field_id ] = $val;
				// Compatibility alias for migration (PORTAL-MODEL §5).
				$row[ Portal_Submission_Field_Rules::NAME_PREFIX . $field_id ] = $val;
			}

			foreach ( $drive_paths as $field_id => $path ) {
				$row[ $field_id . '_path' ] = $path;
				$row[ $field_id . '_link' ] = $path;
			}

			if ( isset( $definition['title'] ) ) {
				$row['portalTitle'] = (string) $definition['title'];
			}

			return $row;
		}

		/**
		 * @param array $meta File meta with contents, path, or tmp_name.
		 * @return string|null Bytes or null on failure.
		 */
		private function read_file_buffer( array $meta ) {
			if ( isset( $meta['contents'] ) && is_string( $meta['contents'] ) ) {
				return $meta['contents'];
			}
			$path = null;
			if ( isset( $meta['path'] ) && is_string( $meta['path'] ) ) {
				$path = $meta['path'];
			} elseif ( isset( $meta['tmp_name'] ) && is_string( $meta['tmp_name'] ) ) {
				$path = $meta['tmp_name'];
			}
			if ( null === $path || ! $this->files->exists( $path ) ) {
				return null;
			}
			return $this->files->read_text( $path );
		}
	}
}
