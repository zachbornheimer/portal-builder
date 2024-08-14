<?php

require_once plugin_dir_path( __FILE__ ) . '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


if ( ! class_exists( 'Portal_Submission' ) ) {

	class Portal_Submission {
		private $nonce;
		private $file_store;
		private $file_handler;
		private $drive_folder_ids = array();
		private $receipt_link;
		private $raw_file_data;

		public function __construct( $nonce, $file_handler ) {
			$this->nonce        = $nonce;
			$this->file_handler = $file_handler;

			// Retrieve Google Sheets, Google Drive, and client secret keys from WordPress options
			$credentials = array(
				'access_key'    => get_option( 'pb_google_access_key' ),
				'client_secret' => get_option( 'pb_google_secret_key' ),
			);

			// Initialize the Zysys_FileStore object with the retrieved credentials
			$this->file_store = new Zysys_FileStore( $credentials );

			if ( $this->file_store->__get( 'update_access_token' ) ) {
				update_option( 'pb_google_access_key', $this->file_store->__get( 'update_access_token' ) );
			}
		}

		public function validate_nonce() {
			if ( ! isset( $_POST[ $this->nonce ] ) || ! wp_verify_nonce( $_POST[ $this->nonce ], 'ready_to_submit_nonce' ) ) {
				throw new Exception( 'Invalid nonce' );
			}
		}

		public function process_submission( $data ) {
			$this->raw_file_data = $data;
			try {
				// Validate nonce
				$this->validate_nonce();

				// Example: Collect payment if required
				if ( $this->should_collect_payment( $data ) ) {
					$this->collect_payment( $data );
				}

				// Example: Generate PDF
				if ( $this->should_generate_pdf( $data ) ) {
					$this->generate_pdf( $data );
				}

				// Example: S3 Backup
				if ( $this->should_backup_to_s3( $data ) ) {
					$this->backup_to_s3( $data );
				}

				// Example: Send Email
				if ( $this->should_send_email( $data ) ) {
					$this->send_email( $data );
				}

				// Example: File Storage Logic
				$this->store_files( $data );
				$this->store_records( $data );
				$this->permanently_store_temp();
			} catch ( Exception $e ) {
				// Handle exceptions (logging, showing error messages, etc.)
				if ( WP_DEBUG ) {
					wp_die( $e->getMessage() );
				} else {
					error_log( $e->getMessage() );
					if ( WP_DEBUG ) {
						wp_die( $e->getMessage() );
					} else {
						wp_die( 'An error occurred during submission processing.' );
					}
				}
			}
		}

		// Example method to determine if payment should be collected
		private function should_collect_payment( $data ) {
			return isset( $data['requires_payment'] ) && $data['requires_payment'] === 'yes';
		}

		// Example payment processing method
		private function collect_payment( $data ) {
			// Integrate with Stripe or another payment processor
			// Example code here
		}

		// Example method to determine if a PDF should be generated
		private function should_generate_pdf( $data ) {
			return isset( $data['generate_pdf'] ) && $data['generate_pdf'] === 'yes';
		}

		// Example PDF generation method
		private function generate_pdf( $data ) {
			// Execute the shell command or other logic to generate a PDF
			// Example: shell_exec('path/to/generate_pdf.sh ' . escapeshellarg($data['pdf_data']));
		}

		// Example method to determine if S3 backup is needed
		private function should_backup_to_s3( $data ) {
			return isset( $data['backup_to_s3'] ) && $data['backup_to_s3'] === 'yes';
		}

		// Example S3 backup method
		private function backup_to_s3( $data ) {
			// Integrate with AWS S3 for backups
			// Example code here
		}

		// Example method to determine if an email should be sent
		private function should_send_email( $data ) {
			return true;
			return isset( $data['send_email'] ) && $data['send_email'] === 'yes';
		}

		// Example email sending method
		private function send_email( $data ) {
			// Use wp_mail or another email method to send the email
			// Example: wp_mail($data['email'], 'Subject', 'Message');
			# get the post title from the id
			$portal_name = get_the_title( $data['post_id'] );

			$raw_notification_date = get_post_meta( $data['post_id'], '_portal_applicant_notification_date', true );
			# turn the raw date into a human readable date like Monday, June 15, 2021
			$application_notification_date = date( 'l, F j, Y', strtotime( $raw_notification_date ) );

			$link = $this->generate_receipt_link();

			$fromEmail      = get_option( 'pb_receipt_from_email', '' );
			$fromName       = get_option( 'pb_receipt_from_name', '' );
			$receiptSubject = get_option( 'pb_receipt_subject', '' );
			$receiptBody    = get_option( 'pb_receipt_body', '' );
			$receiptAltBody = get_option( 'pb_receipt_alt_body', '' );

			$mail = new PHPMailer( true );

			# substitute any mustache variables
			$allowedSubstitutions = array(
				'$receiptLink'                   => $link,
				'$link'                          => $link,
				'$portal_name'                   => $portal_name,
				'$portalName'                    => $portal_name,
				'$application_notification_date' => $application_notification_date,
			);

			$receiptSubject = $this->mustache_replace( $receiptSubject, $allowedSubstitutions );
			$receiptBody    = $this->mustache_replace( $receiptBody, $allowedSubstitutions );
			$receiptAltBody = $this->mustache_replace( $receiptAltBody, $allowedSubstitutions );

			# transform tinymce stripped html into actual HTML (ex. convert newlines to <br>)
			$receiptBody = wpautop( $receiptBody );

			try {
				$mail->setFrom( $fromEmail, $portal_name . ' Application Automated Receipt' );
				$mail->addAddress( $data['sub_email'] );
				#$mail->addAttachment($outdir . 'Application_' . $appId . '.pdf');     
				$mail->Subject = $receiptSubject;
				$mail->Body    = $receiptBody;
				$mail->AltBody = $receiptAltBody;
				$mail->send();
			} catch ( Exception $e ) {
				echo $e->getMessage(); //Boring error messages from anything else!
			}
		}

		private function mustache_replace( $input, $allowed ) {
			$mustache_regex = '/\{\s*\{\s*$var\s*\}\s*\}/';

			$i = $input;
			foreach ( array_keys( $allowed ) as $var ) {
				$local_regex = $mustache_regex;
				$local_regex = str_replace( '$var', $var, $local_regex );

				# handle the $ version
				$local_regex = str_replace( '$', '\\$', $local_regex );
				$i           = preg_replace( $local_regex, $allowed[ $var ], $i );

				# now handle the no-dollar version
				$local_regex = str_replace( '\\$', '', $local_regex );
				$i           = preg_replace( $local_regex, $allowed[ $var ], $i );
			}

			return $i;
		}

		private function remove_mustache( $input ) {
			$mustache_regex = '/\{\s*\{\s*$var\s*\}\s*\}/';

			$mustache_regex = str_replace( '$var', '([\w\$_\-]+)', $mustache_regex );
			return preg_replace( $mustache_regex, '', $input );
		}

		// Example method to handle file storage logic
		private function store_files( $data ) {
			$file_backups = json_decode( get_post_meta( $data['post_id'], '_portal_file_backups', true ), true );
			$index        = 0;
			foreach ( $file_backups as $backup ) {
				$id = $backup[1];

				$this->file_store->drive_parent( $id );
				$this->file_store->create_drive_subfolder( $this->file_handler->get_appId(), true );
				$tmp_dir = $this->file_handler->get_temp_file_dir();
				foreach ( scandir( $tmp_dir ) as $file ) {
					if ( $file == '.' || $file == '..' ) {
						continue;
					}
					$this->file_store->store_drive_file( $tmp_dir . '/' . $file );
				}

				$drive_folder_ids[ $index ] = $this->file_store->get_drive_parent_id();
			}

			$this->drive_folder_ids = $drive_folder_ids;
		}

		private function permanently_store_temp() {
			return $this->file_handler->permanently_store_temp();
		}

		// Example method to handle file storage logic
		private function store_records( $data ) {
			// get the _portal_record_keeping data from the post meta
			$record_keeping = json_decode( get_post_meta( $data['post_id'], '_portal_record_keeping', true ), true );

			$allowed_replacements = array();

			$original_post_content = get_post_field( 'post_content', $data['post_id'] );
			$original_post_content = preg_match( '/\[\s*portal-applicant-information\s*\]/i', $original_post_content, $matches );
			$drive_links           = array();
			$drive_links           = array_map( array( $this, 'drive_id_to_link' ), $this->drive_folder_ids );
			if ( $matches ) {
				$allowed_replacements = array(
					'$sub_title'              => $data['sub_title'],
					'$sub_name'               => $data['sub_name'],
					'$sub_email'              => $data['sub_email'],
					'$sub_inst_affil'         => $data['sub_inst_affil'],
					'$sub_address_first_part' => $data['sub_address_first_part'],
					'$sub_city'               => $data['sub_city'],
					'$sub_country'            => $data['sub_country'],
					'$sub_state'              => $data['sub_state'],
					'$sub_zip'                => $data['sub_zip'],
					'$sub_phone'              => $data['sub_phone'],
					'app_id'                  => $this->file_handler->get_appId(),
					'date_submitted'          => date( 'M d, Y' ),
					'drive_link'              => join( ',', $drive_links ),
					'receipt_link'            => $this->get_receipt_link(),
				);
			}

			
			$allowed_replacements = array_merge( $allowed_replacements, $data );
			$skip_header          = get_post_meta( $data['post_id'], '_portal_skip_header', true );

			# @TODO need to make sure that we get the headers / cells for each sheet and upload properly
			foreach ( $record_keeping as $record ) {
				if ( ! $record[1] ) {
					continue;
				}
				$this->file_store->gsheet( $record[1] );
				if ( is_numeric( $skip_header ) ) {
					$starting = 1 + $skip_header;
				} else {
					$starting = 1;
				}
				$this->file_store->gsheet_row( 'Sheet1', 'A' . $starting ); // skip header row

				# split at /\s*}\s*{\s*,/ to get each cell
				$cells = preg_split( '/\s*}\s*}\s*,/', $record[2] . ',' ); # add a trailing , for splitting purposes

				// add the }} to each cell
				$cells = array_map(
					function ( $cell ) {
						if ( $cell == '' ) {
							return '';
						}
						$cell = preg_replace( '/\s*}\s*}\s*$/', '', $cell );
						return $cell . '}}';
					},
					$cells 
				);

				# replace each cell with the allowed replacements
				$cells = array_map(
					function ( $cell ) use ( $allowed_replacements ) {
						return $this->remove_mustache( $this->mustache_replace( $cell, $allowed_replacements ) );
					},
					$cells 
				);


				$this->file_store->add_row( ...$cells );
			}
			$this->file_store->gsheet( $record_keeping[0][1] );
		}

		private function drive_id_to_link( $id ) {
			$drive_link = 'https://drive.google.com/drive/folders/' . $id;
			return $drive_link;
		}

		public function get_receipt_link() {
			return $this->generate_receipt_link();
		}

		private function generate_receipt_link() {
			if ( $this->receipt_link ) {
				return $this->receipt_link;
			}

			$args_for_url                   = array();
			$args_for_url['app-id']         = $this->file_handler->get_appId();
			$args_for_url['date-submitted'] = date( 'M d, Y' );
			$args_for_url['epost-id']       = pb_encrypt_str( $this->raw_file_data['post_id'] );
			$args_for_url['portal-name']    = get_the_title( $this->raw_file_data['post_id'] );

			// merge the raw file data into the args for the url
			$args_for_url = array_merge( $args_for_url, $this->raw_file_data );

			$protected_keys = array( 'post_id', 'ready_to_submit_nonce', '_wp_http_referer', 'eappId' );
			foreach ( $protected_keys as $key ) {
				if ( isset( $args_for_url[ $key ] ) ) {
					unset( $args_for_url[ $key ] );
				}
			}

			$args_for_url['files'] = json_encode( $this->file_handler->stored_file_paths );
			if ( defined( 'PB_FILE_LABELS' ) ) {
				$args_for_url['file_labels'] = json_encode( PB_FILE_LABELS );
			}
			
			# remove null values
			$args_for_url = array_filter( $args_for_url );

			$receipt_generator = get_option( 'pb_receipt_generator', '' );
			$link              = $receipt_generator . '?' . http_build_query( $args_for_url, '', '&', PHP_QUERY_RFC3986 );


			if ( $receipt_generator ) {
				$this->receipt_link = $link;
			}
			return $link;
		}
	}
}
