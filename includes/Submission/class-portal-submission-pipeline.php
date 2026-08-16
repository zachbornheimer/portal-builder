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
	 * Test mode selects file Sheet / Drive adapters. Otherwise the same
	 * verbs write through the Google store (Zysys_FileStore).
	 */
	class Portal_Submission_Pipeline {

		const STATUS_RECEIVED = 'received';
		const STATUS_SYNCED   = 'synced';
		const STATUS_FAILED   = 'failed';

		const RECEIPT_SUBJECT_PREFIX   = 'Application receipt';
		const DATE_RECEIVED_FORMAT     = 'M d, Y';
		const META_NOTIFICATION_DATE   = '_portal_applicant_notification_date';
		const NOTIFICATION_DATE_FORMAT = 'l, F j, Y';

		/** Single-step definition form nonce (Phase 3). */
		const NONCE_ACTION = 'dg_definition_submit';
		const NONCE_FIELD  = 'dg_definition_submit';

		const SUCCESS_COPY        = 'Your application has been submitted successfully!';
		const RECEIPT_LINK_TEXT   = 'Click here to view the details of your application. Please print / save this for your records.';
		const PUBLIC_FAILURE_COPY = 'Something went wrong while submitting your application. Please try again. If the problem continues, contact the host.';
		const PUBLIC_FAILURE_CODE = 'submit';

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
		 * Record a human public-form error; log the technical exception.
		 *
		 * @param Exception|string $exception Caught failure.
		 * @return void
		 */
		public static function record_public_failure( $exception ) {
			$detail = $exception instanceof Exception ? $exception->getMessage() : (string) $exception;
			error_log( 'Portal Submission Error: ' . $detail );
			self::$last_errors = array(
				array(
					'code'     => self::PUBLIC_FAILURE_CODE,
					'field_id' => '',
					'message'  => self::PUBLIC_FAILURE_COPY,
				),
			);
		}

		/**
		 * Flip the public form onto the existing tokenized error surface.
		 *
		 * @return void
		 */
		public static function mark_public_errors() {
			if ( ! defined( 'DG_DEFINITION_SUBMIT_ERRORS' ) ) {
				define( 'DG_DEFINITION_SUBMIT_ERRORS', true );
			}
		}

		/**
		 * Attach the success surface only when the submission completed.
		 *
		 * @param bool   $completed         Whether processing finished.
		 * @param string $receipt_link      Receipt URL.
		 * @param string $notification_date Human-readable date.
		 * @return string 'success' or 'error'
		 */
		public static function finish_public_submit( $completed, $receipt_link = '', $notification_date = '' ) {
			if ( ! $completed ) {
				return 'error';
			}
			if ( ! defined( 'PB_RECEIPT_LINK' ) ) {
				define( 'PB_RECEIPT_LINK', (string) $receipt_link );
			}
			if ( ! defined( 'PB_APPLICATION_NOTIFICATION_DATE' ) ) {
				define( 'PB_APPLICATION_NOTIFICATION_DATE', (string) $notification_date );
			}
			if ( function_exists( 'add_filter' ) ) {
				add_filter( 'the_content', 'pb_post_submitted_content_filter', 10, 1 );
			}
			return 'success';
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
		 * @param int                 $portal_id Portal post ID.
		 * @param array<string,mixed> $post      $_POST-like map.
		 * @param array<string,mixed> $files     $_FILES-like map.
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
				return array(
					'ok'     => false,
					'errors' => self::$last_errors,
				);
			}

			$file_meta = self::normalize_uploaded_files( $files );
			$file_meta = self::apply_staged_tokens( $post, $file_meta );
			$pipeline  = self::for_environment( $definition );
			$result    = $pipeline->process( $portal_id, $definition, $post, $file_meta );

			if ( is_wp_error( $result ) ) {
				self::$last_errors = self::errors_from_wp_error( $result );
				return array(
					'ok'     => false,
					'errors' => self::$last_errors,
				);
			}

			self::forget_staged_tokens( $file_meta );

			if ( empty( $result['receipt_url'] ) && class_exists( 'Portal_Receipt' ) ) {
				$app_id = isset( $result['row']['applicationId'] ) ? (string) $result['row']['applicationId'] : '';
				if ( '' !== $app_id ) {
					$result['receipt_url'] = Portal_Receipt::url( $portal_id, $app_id );
				}
			}
			self::$last_success = $result;
			return array(
				'ok'     => true,
				'result' => $result,
			);
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
				if ( isset( $meta['error'] ) && defined( 'UPLOAD_ERR_NO_FILE' ) && (int) $meta['error'] === UPLOAD_ERR_NO_FILE ) {
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
		 * Prefer staged tokens from POST (sub_{field}_staged) over raw $_FILES.
		 *
		 * @param array                  $post    $_POST-like map.
		 * @param array                  $files   Normalized file meta.
		 * @param Portal_Staged_File|null $staging Injectable staging owner.
		 * @return array<string,array>
		 */
		public static function apply_staged_tokens( array $post, array $files, $staging = null ) {
			if ( ! class_exists( 'Portal_Staged_File' ) ) {
				return $files;
			}
			$staging = $staging instanceof Portal_Staged_File ? $staging : new Portal_Staged_File();
			$prefix  = Portal_Submission_Field_Rules::NAME_PREFIX;
			$suffix  = '_staged';
			foreach ( $post as $key => $token ) {
				if ( ! is_string( $token ) || '' === $token ) {
					continue;
				}
				$key = (string) $key;
				if ( 0 !== strpos( $key, $prefix ) ) {
					continue;
				}
				$suffix_len = strlen( $suffix );
				if ( $suffix_len > strlen( $key ) || substr( $key, -$suffix_len ) !== $suffix ) {
					continue;
				}
				$field_id = substr( $key, strlen( $prefix ), -$suffix_len );
				if ( '' === $field_id ) {
					continue;
				}
				$meta = $staging->as_file_meta( $token );
				if ( is_array( $meta ) ) {
					$files[ $field_id ] = $meta;
				}
			}
			return $files;
		}

		/**
		 * Drop staged tokens after a successful submit.
		 *
		 * @param array                   $files   File meta.
		 * @param Portal_Staged_File|null $staging Staging owner.
		 * @return void
		 */
		private static function forget_staged_tokens( array $files, $staging = null ) {
			if ( ! class_exists( 'Portal_Staged_File' ) ) {
				return;
			}
			$staging = $staging instanceof Portal_Staged_File ? $staging : new Portal_Staged_File();
			foreach ( $files as $meta ) {
				if ( ! is_array( $meta ) || empty( $meta['staged_token'] ) ) {
					continue;
				}
				$staging->forget( (string) $meta['staged_token'] );
			}
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
		 * @param int    $portal_id Portal ID.
		 * @param string $app_id    Application id.
		 * @return string
		 */
		private static function build_receipt_url( $portal_id, $app_id ) {
			if ( class_exists( 'Portal_Receipt' ) ) {
				return Portal_Receipt::url( $portal_id, $app_id );
			}
			return '';
		}

		/** @var object Sheet port (append_row). */
		private $sheets;

		/** @var object Drive port (store_file). */
		private $drive;

		/** @var Portal_Mailer */
		private $mailer;

		/** @var Portal_Files */
		private $files;

		/** @var array|object|null Injected open/preview probe. */
		private $open_state;

		/** @var object|null Transport with send(array $request). */
		private $transport;

		/**
		 * @param object            $sheets     Sheet port (append_row).
		 * @param object            $drive      Drive port (store_file).
		 * @param Portal_Mailer     $mailer     Mailer.
		 * @param Portal_Files|null $files      Filesystem for reading upload paths.
		 * @param array|object|null $open_state Injected open/preview probe.
		 * @param object|null       $transport  Injected anonymize transport.
		 */
		public function __construct( $sheets, $drive, Portal_Mailer $mailer, $files = null, $open_state = null, $transport = null ) {
			$this->sheets     = $sheets;
			$this->drive      = $drive;
			$this->mailer     = $mailer;
			$this->files      = $files instanceof Portal_Files ? $files : new Portal_Files();
			$this->open_state = $open_state;
			$this->transport  = $transport;
		}

		/**
		 * @param array|object|null $open_state Injected open/preview probe.
		 * @return self
		 */
		public function with_open_state( $open_state ) {
			$this->open_state = $open_state;
			return $this;
		}

		/**
		 * @param object|null $transport Injected anonymize transport.
		 * @return self
		 */
		public function with_transport( $transport ) {
			$this->transport = $transport;
			return $this;
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
		 * File adapters when test mode is on; Google adapters otherwise.
		 *
		 * @param array             $definition Validated definition.
		 * @param Portal_Files|null $files      Injectable FS.
		 * @param callable|null     $now_ms     Injectable clock.
		 * @param object|null       $file_store Injected FileStore (tests).
		 * @param array|object|null $open_state Injected open/preview probe.
		 * @return self
		 */
		public static function for_environment( array $definition, $files = null, $now_ms = null, $file_store = null, $open_state = null ) {
			if ( Portal_Test_Mode::is_enabled() ) {
				$pipeline = self::for_artifacts( Portal_Test_Mode::artifact_dir(), $files, $now_ms );
				if ( null !== $open_state ) {
					$pipeline->with_open_state( $open_state );
				}
				return $pipeline;
			}
			return self::for_live( $definition, $files, $now_ms, $file_store, $open_state );
		}

		/**
		 * Google Sheet/Drive ports; mail stays the file capture adapter.
		 *
		 * @param array             $definition Validated definition.
		 * @param Portal_Files|null $files      Injectable FS.
		 * @param callable|null     $now_ms     Injectable clock.
		 * @param object|null       $file_store Injected FileStore (tests).
		 * @param array|object|null $open_state Injected open/preview probe.
		 * @return self
		 */
		public static function for_live( array $definition, $files = null, $now_ms = null, $file_store = null, $open_state = null ) {
			$files = $files instanceof Portal_Files ? $files : new Portal_Files();
			if ( null === $file_store ) {
				$file_store = Portal_Google_Store::file_store_from_options();
			}
			$google = new Portal_Google_Store( $file_store, $definition, $files );
			$mailer = new Portal_Mailer( Portal_Test_Mode::artifact_dir(), $files, $now_ms );
			return new self( $google, $google, $mailer, $files, $open_state );
		}

		/**
		 * Process a portal post when a definition is present (WP runtime helper).
		 *
		 * Loads `_portal_definition` and runs the pipeline with file or Google
		 * adapters selected from test mode.
		 *
		 * @param int                 $post_id Portal post ID.
		 * @param array<string,mixed> $values  Form values (sub_* keys).
		 * @param array<string,mixed> $files   Map fieldId => file meta.
		 * @param array               $options Optional file_store / open_state.
		 * @return array|WP_Error
		 */
		public static function process_for_post( $post_id, array $values, array $files = array(), array $options = array() ) {
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
			if ( class_exists( 'Portal_Access' ) ) {
				$access             = isset( $definition['access'] ) && is_array( $definition['access'] )
					? $definition['access']
					: array();
				$definition_options = isset( $definition['options'] ) && is_array( $definition['options'] )
					? $definition['options']
					: array();
				$decision           = Portal_Access::decide( $access, $definition_options, Portal_Access::current_applicant() );
				if ( empty( $decision['allowed'] ) ) {
					return new WP_Error(
						'dg_submission_forbidden',
						Portal_Access::message_for( $access, isset( $decision['reason'] ) ? $decision['reason'] : 'profile' )
					);
				}
			}
			$file_store = isset( $options['file_store'] ) ? $options['file_store'] : null;
			$open_state = isset( $options['open_state'] ) ? $options['open_state'] : null;
			$pipeline   = self::for_environment( $definition, null, null, $file_store, $open_state );
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
			$site      = class_exists( 'Portal_Site_Defaults' )
				? Portal_Site_Defaults::read_site()
				: array();
			$validated = Portal_Submission_Validator::validate( $definition, $values, $files, $site );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}

			$blocked = $this->admission_error( $portal_id );
			if ( is_wp_error( $blocked ) ) {
				return $blocked;
			}

			$submission_id = self::resolve_submission_id( $values );
			if ( is_object( $this->drive ) && method_exists( $this->drive, 'set_submission_id' ) ) {
				$this->drive->set_submission_id( $submission_id );
			}
			if ( is_object( $this->sheets ) && method_exists( $this->sheets, 'set_submission_id' ) ) {
				$this->sheets->set_submission_id( $submission_id );
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
				$buffer                   = $this->anonymize_judge_bytes( $definition, $buffer, $filename, $meta );
				$path                     = $this->drive->store_file( $portal_id, $field_id, $buffer, $filename );
				$drive_paths[ $field_id ] = $path;
				$file_meta[ $field_id ]   = array(
					'fieldId'  => $field_id,
					'filename' => $filename,
					'path'     => $path,
					'bytes'    => strlen( $buffer ),
				);
			}

			$row                  = $this->build_sheet_row( $portal_id, $definition, $validated, $drive_paths );
			$row['applicationId'] = $submission_id;
			if ( is_object( $this->drive ) && method_exists( $this->drive, 'ensure_application_folder' ) ) {
				$this->drive->ensure_application_folder( $portal_id );
			}
			if ( is_object( $this->drive ) && method_exists( $this->drive, 'folder_url' ) ) {
				$folder_url = (string) $this->drive->folder_url();
				if ( '' !== $folder_url ) {
					$row['files'] = $folder_url;
				}
			}

			$to            = self::applicant_email( $validated );
			$title         = isset( $definition['title'] ) ? (string) $definition['title'] : 'Portal';
			$applicant     = isset( $validated['applicant']['sub_name'] ) ? (string) $validated['applicant']['sub_name'] : '';
			$date_received = gmdate( self::DATE_RECEIVED_FORMAT );
			$receipt_url   = class_exists( 'Portal_Receipt' )
				? Portal_Receipt::url( $portal_id, $submission_id )
				: '';
			$selection     = isset( $row['selection_path'] ) ? (string) $row['selection_path'] : '';

			$row['receiptUrl']   = $receipt_url;
			$row['email']        = $to ? $to : '';
			$row['dateReceived'] = $date_received;

			if ( class_exists( 'Portal_Receipt' ) ) {
				Portal_Receipt::store(
					$submission_id,
					array(
						'applicationId' => $submission_id,
						'portalId'      => $portal_id,
						'portalTitle'   => $title,
						'applicantName' => $applicant,
						'email'         => $row['email'],
						'receiptUrl'    => $receipt_url,
						'selection'     => $selection,
						'submittedAt'   => $date_received,
						'dateReceived'  => $date_received,
					)
				);
			}

			$sheet_path = $this->sheets->append_row( $portal_id, $row );
			$tokens     = array(
				'application_id'    => $submission_id,
				'applicant_name'    => $applicant,
				'applicant_email'   => $to ? $to : '',
				'portal_title'      => $title,
				'receipt_url'       => $receipt_url,
				'selection'         => $selection,
				'submitted_at'      => $date_received,
				'notification_date' => self::notification_date( $portal_id ),
			);
			$mail_path          = $this->send_receipt_mail(
				$to,
				$portal_id,
				$title,
				$tokens,
				$validated['values'],
				$file_meta
			);
			$operator_mail_path = $this->send_operator_mail( $portal_id, $title, $tokens );

			return array(
				'ok'               => true,
				'status'           => self::STATUS_SYNCED,
				'portalId'         => $portal_id,
				'sheetPath'        => $sheet_path,
				'drivePaths'       => $drive_paths,
				'mailPath'         => $mail_path,
				'operatorMailPath' => $operator_mail_path,
				'receipt_url'      => $receipt_url,
				'row'              => $row,
				'values'           => $validated['values'],
				'files'            => $file_meta,
				'applicant'        => $validated['applicant'],
			);
		}

		/**
		 * Flatten validated data into a sheet row (field ids as keys).
		 *
		 * @param string               $portal_id    Portal id.
		 * @param array                $definition   Definition.
		 * @param array                $validated    Validator output.
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

			foreach ( Portal_Submission_Selections::columns( $definition, $validated['values'] ) as $key => $val ) {
				$row[ $key ] = $val;
			}

			if ( isset( $definition['title'] ) ) {
				$row['portalTitle'] = (string) $definition['title'];
			}

			return $row;
		}

		/**
		 * Reject preview / closed before the first write. Skip when WP is absent
		 * and no open-state was injected (CLI harness).
		 *
		 * @param string|int $portal_id Portal id.
		 * @return WP_Error|null
		 */
		private function admission_error( $portal_id ) {
			if ( is_array( $this->open_state ) ) {
				return Portal_Submit_Admission::decide(
					! empty( $this->open_state['preview'] ),
					! empty( $this->open_state['open'] )
				);
			}
			if ( is_object( $this->open_state ) ) {
				$preview = method_exists( $this->open_state, 'is_preview_request' )
					? (bool) $this->open_state->is_preview_request( $portal_id )
					: false;
				$open    = method_exists( $this->open_state, 'is_open' )
					? (bool) $this->open_state->is_open( $portal_id )
					: true;
				return Portal_Submit_Admission::decide( $preview, $open );
			}
			if ( ! function_exists( 'get_post' ) || ! class_exists( 'Portal_Open_State' ) ) {
				return null;
			}
			$id = (int) $portal_id;
			if ( $id <= 0 ) {
				return null;
			}
			return Portal_Submit_Admission::decide(
				Portal_Open_State::is_preview_request( $id ),
				Portal_Open_State::is_open( $id )
			);
		}

		/**
		 * Reuse a file-handler app id when the request already has one.
		 *
		 * @param array $values Form values.
		 * @return string
		 */
		private static function resolve_submission_id( array $values ) {
			foreach ( array( 'APPID', 'appId', 'app_id' ) as $key ) {
				if ( ! empty( $values[ $key ] ) && is_scalar( $values[ $key ] ) ) {
					return (string) $values[ $key ];
				}
			}
			return uniqid( 'dg_', true );
		}

		/**
		 * Strip identity from judge-facing bytes. Fail-open to $buffer.
		 *
		 * @param array  $definition Definition document.
		 * @param string $buffer     Original upload bytes.
		 * @param string $filename   Original filename.
		 * @param array  $meta       File meta.
		 * @return string
		 */
		private function anonymize_judge_bytes( array $definition, $buffer, $filename, array $meta ) {
			// Staged uploads already ran anonymize (or deliberately skipped it).
			if ( ! empty( $meta['already_anonymized'] ) || ! empty( $meta['staged'] ) ) {
				return $buffer;
			}
			if ( ! class_exists( 'Portal_Anonymizer' ) ) {
				return $buffer;
			}
			$options = class_exists( 'Portal_Site_Defaults' )
				? Portal_Site_Defaults::resolve_for_site( $definition )
				: ( isset( $definition['options'] ) && is_array( $definition['options'] )
					? $definition['options']
					: array() );
			$type    = isset( $meta['type'] ) ? (string) $meta['type'] : null;
			$owner   = new Portal_Anonymizer( $options, $this->transport );
			return $owner->maybe_anonymize( $buffer, $filename, $type );
		}

		/**
		 * Fail-open receipt mail. Sheet/drive already written.
		 *
		 * @param string|null          $to       Recipient.
		 * @param string               $portal_id Portal id.
		 * @param string               $title    Portal title.
		 * @param array<string,string> $tokens  Closed token set.
		 * @param array                $values   Validated values.
		 * @param array                $files    File meta.
		 * @return string Capture path.
		 */
		private function send_receipt_mail( $to, $portal_id, $title, array $tokens, array $values, array $files ) {
			$message = array(
				'to'       => $to ? $to : Portal_Mailer::UNKNOWN_RECIPIENT,
				'portalId' => $portal_id,
				'title'    => $title,
				'tokens'   => $tokens,
				'values'   => $values,
				'files'    => $files,
			);
			return $this->safe_send( $message, false );
		}

		/**
		 * Fail-open operator notify after dest writes.
		 *
		 * @param string               $portal_id Portal id.
		 * @param string               $title     Portal title.
		 * @param array<string,string> $tokens    Closed token set.
		 * @return string Capture path or empty.
		 */
		private function send_operator_mail( $portal_id, $title, array $tokens ) {
			$message = array(
				'to'       => Portal_Mailer::operator_recipient(),
				'kind'     => Portal_Mailer::KIND_OPERATOR,
				'portalId' => $portal_id,
				'title'    => $title,
				'tokens'   => $tokens,
			);
			return $this->safe_send( $message, true );
		}

		/**
		 * Capture mail failure; never throw to the submit caller.
		 *
		 * @param array<string,mixed> $message  Mailer payload.
		 * @param bool                $operator True for operator notify.
		 * @return string Capture path or empty.
		 */
		private function safe_send( array $message, $operator ) {
			try {
				if ( $operator && method_exists( $this->mailer, 'send_operator_notify' ) ) {
					$path = $this->mailer->send_operator_notify( $message );
				} elseif ( method_exists( $this->mailer, 'send' ) ) {
					$path = $this->mailer->send( $message );
				} else {
					$path = $this->mailer->capture( $message );
				}
				return is_string( $path ) ? $path : '';
			} catch ( Exception $e ) {
				try {
					$message['error'] = $e->getMessage();
					$path             = $this->mailer->capture( $message );
					return is_string( $path ) ? $path : '';
				} catch ( Exception $ignored ) {
					return '';
				}
			}
		}

		/**
		 * Applicant email from the pack, or any email-named field.
		 *
		 * @param array $validated Validator output.
		 * @return string|null
		 */
		private static function applicant_email( array $validated ) {
			if ( ! empty( $validated['applicant']['sub_email'] ) && is_string( $validated['applicant']['sub_email'] ) ) {
				return $validated['applicant']['sub_email'];
			}
			foreach ( $validated['values'] as $fid => $val ) {
				if ( is_string( $val ) && false !== strpos( $fid, 'email' ) ) {
					return $val;
				}
			}
			return null;
		}

		/**
		 * Human notification date from portal meta.
		 *
		 * @param string|int $portal_id Portal id.
		 * @return string
		 */
		private static function notification_date( $portal_id ) {
			if ( ! function_exists( 'get_post_meta' ) ) {
				return '';
			}
			$raw = get_post_meta( (int) $portal_id, self::META_NOTIFICATION_DATE, true );
			if ( ! is_string( $raw ) || '' === $raw ) {
				return '';
			}
			$ts = strtotime( $raw );
			if ( false === $ts ) {
				return $raw;
			}
			return gmdate( self::NOTIFICATION_DATE_FORMAT, $ts );
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
