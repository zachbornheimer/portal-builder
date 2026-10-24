<?php
/**
 * Plugin Name: DragonGate Portals
 * Plugin URI:  https://dragongateportals.com
 * Description: A plugin to build portals for accepting applications and managing submissions with Google Sheets and Google Drive integration.
 * Version:     0.1.10
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Update URI:  https://github.com/zachbornheimer/portal-builder
 * Author:      Z. Bornheimer (ZYSYS)
 * Author URI:  https://zysys.org/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dragongate-portals
 * Domain Path: /languages
 *
 * Copyright (c) 2024 Zachary Bornheimer - All Rights Reserved.
 *
 * @package DragonGate_Portals
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Upload roots resolve after Portal_Upload_Store is loaded (not ABSPATH).

// Define plugin version constant.
if ( ! defined( 'DG_VERSION' ) ) {
	define( 'DG_VERSION', '0.1.10' );
}

// Include the necessary files.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-options.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-builder.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-post-type.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-meta.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-notification-when.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-submission.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-file-handler.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-data-table.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-about.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Definition/class-portal-definition.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Definition/class-portal-site-defaults.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Definition/class-portal-legal-disclaimers.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Definition/class-portal-brand.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Definition/class-portal-definition-renderer.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Definition/class-portal-open-state.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Definition/class-portal-access.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Definition/class-portal-view-as.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Definition/class-portal-public-render.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-files.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-test-mode.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-sheet-store.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-drive-store.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-mailer.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-receipt.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-submission-field-rules.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-submission-validator.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-submission-selections.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-submit-admission.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-submission-destinations.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-file-signature.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-anonymizer-api.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-anonymizer-reply.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-anonymizer-transport.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-anonymize-decision.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-anonymizer.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-staged-file.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-upload-store.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-submit-log.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-submission-pipeline.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/adapters/class-portal-google-store.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/adapters/class-portal-google-probe-failure.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/adapters/class-portal-google-probe-store.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/adapters/class-portal-google-probe.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-google-connect.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-secret-field.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-spam-gate.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-packet-policy.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Submission/class-portal-packet-store.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-console-rest.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-release-notes.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-update.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-definition-rest.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-staged-file-rest.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-setup-screen.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/templates.php';
require_once plugin_dir_path( __FILE__ ) . 'gsuite-filestore/zysys-file-store.class.php';

// Include Composer's autoload file.
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

if ( ! defined( 'DG_TMP_UPLOADS_DIR' ) ) {
	define( 'DG_TMP_UPLOADS_DIR', Portal_Upload_Store::tmp_dir() );
}

if ( ! defined( 'DG_PERMANENT_UPLOADS_DIR' ) ) {
	define( 'DG_PERMANENT_UPLOADS_DIR', Portal_Upload_Store::store_dir() );
}

/**
 * Flush rewrites, schedule cleanup, and run connect activation.
 */
function portal_plugin_activate() {
	// Flush rewrite rules to regenerate them.
	flush_rewrite_rules();
	Portal_Upload_Store::schedule_cleanup();
	Portal_Upload_Store::ensure_plugin_roots();
	Portal_Google_Connect::on_activate();
}

/**
 * Flush rewrites and unschedule upload cleanup on deactivation.
 */
function portal_plugin_deactivate() {
	// Flush rewrite rules to regenerate them.
	flush_rewrite_rules();
	Portal_Upload_Store::unschedule_cleanup();
}

// Register the activation and deactivation hooks.
register_activation_hook( __FILE__, 'portal_plugin_activate' );
register_deactivation_hook( __FILE__, 'portal_plugin_deactivate' );
add_action( Portal_Upload_Store::CLEANUP_HOOK, array( 'Portal_Upload_Store', 'purge_expired' ) );
add_action( 'init', array( 'Portal_Packet_Policy', 'register_caps' ) );

/**
 * Register portal types, meta, and submission handling.
 */
function dg_initialize_plugin() {
	$portal_builder = new Portal_Builder();
	$portal_builder->init();

	// Initialize post type.
	$portal_post_type = new Portal_Post_Type();
	$portal_post_type->register_post_type();

	// Initialize meta fields.
	$portal_meta = new Portal_Meta();
	$portal_meta->init();

	// Register meta boxes only on the admin side.
	if ( is_admin() ) {
		dg_register_meta_boxes( $portal_meta );
		add_action( 'admin_notices', 'dg_duplicate_success_notice' );
		add_action( 'admin_notices', array( 'Portal_Google_Connect', 'render_admin_notice' ) );
	}

	if ( class_exists( 'Portal_View_As' ) ) {
		Portal_View_As::init();
	}

	handle_submissions();
}
add_action( 'plugins_loaded', 'dg_initialize_plugin' );

/**
 * Register the portal options, record-keeping, and backup meta boxes.
 *
 * @param Portal_Meta $portal_meta Meta registrar.
 */
function dg_register_meta_boxes( $portal_meta ) {
	// Get the client email from the Google secret, when present.
	$client_email  = '';
	$label         = '';
	$client_secret = Portal_Options::get( 'dg_google_secret_key' );
	if ( $client_secret ) {
		$client_secret = json_decode( $client_secret, true );
		if ( isset( $client_secret['client_email'] ) ) {
			$client_email = $client_secret['client_email'];

			if ( $client_email ) {
				$label = 'Make sure that the %s is accessible to `' . $client_email . '`.  Either share it directly or share a parent folder. Editor permissions required.';
			}
		}
	}

	$portal_meta->register_meta_box(
		'portal_options',
		'Portal Options',
		array(
			array(
				'id'    => '_portal_anonymize',
				'label' => 'Anonymize',
				'type'  => 'switch',
			),
			array(
				'id'    => '_portal_skip_header',
				'label' => 'Start on the 2nd row (skip header)',
				'type'  => 'switch',
			),
			array(
				'id'    => '_portal_deadline',
				'label' => 'Deadline',
				'type'  => 'datetime-local',
			),
			array(
				'id'      => '_portal_timezone',
				'label'   => 'Timezone',
				'type'    => 'select',
				'options' => timezone_identifiers_list(),
			),
			array(
				'id'    => '_portal_paid',
				'label' => 'Paid',
				'type'  => 'switch',
			),
			array(
				'id'    => '_portal_free_for_members',
				'label' => 'Free for members',
				'type'  => 'switch',
			),
			array(
				'id'    => '_portal_application_fee',
				'label' => 'Application Fee',
				'type'  => 'number',
			),
			array(
				'id'      => '_portal_applicant_notification_kind',
				'label'   => 'Applicant notification',
				'type'    => 'select',
				'options' => array(
					'date'   => 'On or before a date',
					'window' => 'A general time',
				),
			),
			array(
				'id'    => '_portal_applicant_notification_date',
				'label' => 'Applicant notification date',
				'type'  => 'date',
			),
			array(
				'id'          => '_portal_applicant_notification_window',
				'label'       => 'General time',
				'type'        => 'text',
				'placeholder' => 'mid-December',
			),
			array(
				'id'          => '_portal_county_region_script',
				'label'       => 'County/Region Dropdown Menu JS Script',
				'type'        => 'url',
				'placeholder' => Portal_Options::get( 'dg_county_region_script', '' ),
			),
			array(
				'id'    => '_portal_guidelines_url',
				'label' => 'Link to Guidelines',
				'type'  => 'url',
			),
		),
		'normal',
		'high'
	);

	// Register the record-keeping data table meta box (Google Sheets).
	$portal_meta->register_meta_box(
		'portal_record_keeping',
		'Record Keeping',
		array(
			array(
				'id'         => '_portal_record_keeping',
				'label'      => 'Google Sheets Configuration',
				'type'       => 'data-table',
				'columns'    => array( 'Google Sheet Name (For Identification Purposes)', 'Google Sheet ID', 'Columns' ), // Add the Columns column.
				'extraction' => array( 1 => 'google-sheet' ),
				'options'    => array(
					0 => array( 'block' => true ), // Enable block display.
					2 => array( 'tags' => true ), // Enable tag handling for the third column (index 2).
				),
				'disclosure' => $label ? sprintf( $label, 'Sheet(s)' ) : '', // Add disclosure text.
			),
		),
		'normal',
		'high'
	);

	// Register the file-backup data table meta box (Google Drive).
	$portal_meta->register_meta_box(
		'portal_file_backups',
		'File Backups',
		array(
			array(
				'id'         => '_portal_file_backups',
				'label'      => 'Google Drive Configuration',
				'type'       => 'data-table',
				'columns'    => array( 'Google Drive Folder Name (For Identification Purposes)', 'Google Drive Folder ID' ),
				'extraction' => array( 1 => 'google-drive' ),
				'disclosure' => $label ? sprintf( $label, 'Drive Folder(s)' ) : '', // Add disclosure text.
			),
		),
		'normal',
		'high'
	);
}

/**
 * Handle review and submit POSTs for the current request.
 *
 * @throws Exception When a review or submit nonce or payload is invalid.
 */
function handle_submissions() {

	try {

		// Definition-backed single-page submit (Phase 3).
		if (
			isset( $_SERVER['REQUEST_METHOD'] )
			&& 'POST' === $_SERVER['REQUEST_METHOD']
			&& isset( $_POST[ Portal_Submission_Pipeline::NONCE_FIELD ] )
			&& isset( $_POST['post_id'] )
		) {
			$portal_id = (int) $_POST['post_id'];
			if (
				! wp_verify_nonce(
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					wp_unslash( $_POST[ Portal_Submission_Pipeline::NONCE_FIELD ] ),
					Portal_Submission_Pipeline::NONCE_ACTION
				)
			) {
				throw new Exception( 'Invalid nonce. Likely data corruption.' );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
			$outcome = Portal_Submission_Pipeline::process_request(
				$portal_id,
				wp_unslash( $_POST ),
				$_FILES
			);

			if ( ! empty( $outcome['ok'] ) ) {
				$result = isset( $outcome['result'] ) ? $outcome['result'] : array();
				if ( ! defined( 'DG_APPLICATION_SUBMITTED' ) ) {
					define( 'DG_APPLICATION_SUBMITTED', true );
				}
				if ( ! defined( 'DG_DEFINITION_SUBMIT_OK' ) ) {
					define( 'DG_DEFINITION_SUBMIT_OK', true );
				}
				if ( ! defined( 'DG_RECEIPT_LINK' ) ) {
					$receipt = isset( $result['receipt_url'] ) ? $result['receipt_url'] : '';
					define( 'DG_RECEIPT_LINK', $receipt );
				}
			} elseif ( ! defined( 'DG_DEFINITION_SUBMIT_ERRORS' ) ) {
				define( 'DG_DEFINITION_SUBMIT_ERRORS', true );
			}
			return;
		}

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['review_nonce'] ) ) {
			// Filter the content to change inputs to spans with the class 'reviewable'.

			// Quickly verify the nonce.
			if ( ! wp_verify_nonce( $_POST['review_nonce'], 'review_nonce' ) ) {
				throw new Exception( 'Invalid nonce.  Likely data corruption.' );
			}

			// Check the anonymize setting.
			$file_handler_settings = array(
				'anonymize' => get_post_meta( $_POST['post_id'], '_portal_anonymize', true ),
			);

			global $pb_file_handler;
			$file_handler = new Portal_File_Handler( $_FILES, $file_handler_settings, '', $_POST['post_id'] );
			$file_handler->set_local_temp_dir( DG_TMP_UPLOADS_DIR );
			$file_handler->process_temp_files();

			// Encrypt the app id and the stored file paths.
			$_POST['appId']  = $file_handler->get_appId();
			$_POST['eappId'] = dg_encrypt_str( 'APPID_' . $_POST['appId'] );
			$_POST['efiles'] = dg_encrypt_str( wp_json_encode( $file_handler->stored_file_paths ) );

			if ( ! defined( 'DG_FILE_HANDLER' ) ) {
				define( 'DG_FILE_HANDLER', $file_handler );
			}

			add_filter( 'the_content', 'dg_reviewable_content_filter', 10, 1 );
		} elseif ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['ready_to_submit_nonce'] ) ) {
			$app_id            = dg_decrypt_str( $_POST['eappId'] );
			$stored_file_paths = json_decode( dg_decrypt_str( $_POST['efiles'] ), true );
			// Get the labels for the files.
			$labels = array();
			foreach ( $stored_file_paths as $key => $path ) {
				$labels[ $key ] = get_label_for_pb_file_name( $_POST['post_id'], $key );
			}
			// Confirm the decrypted value still starts with APPID_ and remove that prefix.
			if ( substr( $app_id, 0, 6 ) !== 'APPID_' ) {
				throw new Exception( 'App ID has been tampered with.  Likely data corruption.' );
			}
			$_POST['APPID'] = substr( $app_id, 6 );

			// Ensure that $_POST['post_id'] is set.
			if ( ! isset( $_POST['post_id'] ) ) {
				throw new Exception( 'Portal ID is missing.  Likely data corruption.' );
			}

			$post         = get_post( $_POST['post_id'] );
			$slug         = $post->post_name;
			$post_content = $post->post_content;

			// Check the anonymize setting.
			$file_handler_settings = array(
				'anonymize'     => get_post_meta( $_POST['post_id'], '_portal_anonymize', true ),
				'permanent_dir' => DG_PERMANENT_UPLOADS_DIR . '/' . $slug,
			);

			$file_handler = new Portal_File_Handler( null, $file_handler_settings, '', $_POST['post_id'] );
			$file_handler->set_local_temp_dir( DG_TMP_UPLOADS_DIR );
			$file_handler->set_appId( $_POST['APPID'] );

			define( 'DG_FILE_LABELS', $labels );

			$definition_submit = dg_try_definition_submission( $_POST, $stored_file_paths );
			if ( is_array( $definition_submit ) && ! empty( $definition_submit['ok'] ) ) {
				$application_notification_date = Portal_Notification_When::phrase( $_POST['post_id'] );
				$receipt                       = ! empty( $definition_submit['mailPath'] ) ? $definition_submit['mailPath'] : '';
				Portal_Submission_Pipeline::finish_public_submit( true, $receipt, $application_notification_date );
			} else {
				Portal_Submission_Pipeline::record_not_configured();
				Portal_Submission_Pipeline::mark_public_errors();
			}
		}
	} catch ( Exception $e ) {
		dg_record_public_submit_failure( $e );
		return;
	}
}

/**
 * Keep a public submit exception off the page; the form re-renders.
 *
 * @param Exception $exception Caught failure.
 * @return void
 */
function dg_record_public_submit_failure( $exception ) {
	if ( class_exists( 'Portal_Submission_Pipeline' ) ) {
		Portal_Submission_Pipeline::record_public_failure( $exception );
		Portal_Submission_Pipeline::mark_public_errors();
		return;
	}
	error_log( 'Portal Submission Error: ' . $exception->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fallback when the submission pipeline is not loaded.
	if ( ! defined( 'DG_DEFINITION_SUBMIT_ERRORS' ) ) {
		define( 'DG_DEFINITION_SUBMIT_ERRORS', true );
	}
}

/**
 * Try definition-aware submit whenever the portal has a definition.
 *
 * @param array $post_values         $_POST-like values (sub_* field names).
 * @param array $stored_file_paths   Map of input name => absolute temp path.
 * @return array|false Result payload on success; false when the portal has no definition.
 * @throws Exception When the definition pipeline returns an error.
 */
function dg_try_definition_submission( $post_values, $stored_file_paths ) {
	if ( ! class_exists( 'Portal_Submission_Pipeline' ) || ! class_exists( 'Portal_Definition' ) ) {
		return false;
	}
	if ( empty( $post_values['post_id'] ) ) {
		return false;
	}
	$post_id    = (int) $post_values['post_id'];
	$definition = Portal_Definition::load_for_post( $post_id );
	if ( null === $definition ) {
		return false;
	}

	$files = array();
	if ( is_array( $stored_file_paths ) ) {
		foreach ( $stored_file_paths as $name => $path ) {
			$field_id = $name;
			if ( 0 === strpos( (string) $name, 'sub_' ) ) {
				$field_id = substr( (string) $name, 4 );
			}
			$files[ $field_id ] = array(
				'name' => basename( (string) $path ),
				'path' => (string) $path,
			);
		}
	}

	$result = Portal_Submission_Pipeline::process_for_post( $post_id, $post_values, $files );
	if ( is_wp_error( $result ) ) {
		// Definition exists — do not fall through to Portal_Submission.
		throw new Exception( esc_html( $result->get_error_message() ) );
	}
	return $result;
}

/**
 * Map a legacy pb_file shortcode name to its label.
 *
 * @param int    $post_id Portal post ID.
 * @param string $name    Shortcode name attribute.
 * @return string Label, or empty when the shortcode is absent.
 */
function get_label_for_pb_file_name( $post_id, $name ) {
	// Get the post content.

	$content = get_post_field( 'post_content', $post_id );

	// Identify a pb_file shortcode with a matching name attribute.
	$pattern = '/\[pb_file[^\]]*name=[\'"]' . $name . '[\'"][^\]]*label=[\'"]([^\'"]+)[\'"][^\]]*\]/s';

	if ( preg_match( $pattern, $content, $match ) ) {
		return $match[1];
	}

	$pattern = '/\[pb_file[^\]]*label=[\'"]([^\'"]+)[\'"][^\]]*name=[\'"]' . $name . '[\'"][^\]]*\]/s';
	if ( preg_match( $pattern, $content, $match ) ) {
		return $match[1];
	}

	return '';
}

/**
 * Replaces form inputs with spans displaying submitted values for review.
 *
 * @param string $c The content containing the form.
 * @return string Modified content with inputs replaced by spans.
 */
function dg_reviewable_content_filter( $c ) {
	// Load the submitted data.
	$submitted_data = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_submissions() before this filter is added.

	$content = do_shortcode( $c );

	// Process input fields.
	$content = preg_replace_callback(
		'/<input\s+([^>]*?)>/i',
		function ( $matches ) use ( $submitted_data ) {

			$attributes_str = $matches[1];
			$attributes     = shortcode_parse_atts( $attributes_str );

			// Skip inputs that are not of type text, email, number, etc.
			if ( isset( $attributes['type'] ) && in_array( $attributes['type'], array( 'hidden', 'submit', 'button', 'file', 'checkbox', 'radio' ), true ) ) {
				return $matches[0]; // Return the original input without changes.
			}

			$name = isset( $attributes['name'] ) ? $attributes['name'] : '';

			// Retrieve the submitted value.
			$value = isset( $submitted_data[ $name ] ) ? htmlspecialchars( $submitted_data[ $name ] ) : '';

			$class = 'reviewable-blank';
			if ( $value ) {
				$class = 'reviewable';
			}

			// Construct the span and hidden input.
			$html  = '<span class="' . $class . '">' . $value . '</span>';
			$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';

			return $html;
		},
		$content
	);

	// Make file inputs a span that says the file is reviewed at the top of the page.
	$content = preg_replace_callback(
		'/<input\s+([^>]*?)>/i',
		function ( $matches ) use ( $submitted_data ) {

			$attributes_str = $matches[1];
			$attributes     = shortcode_parse_atts( $attributes_str );

			// Skip inputs that are not of type text, email, number, etc.
			if ( isset( $attributes['type'] ) && in_array( $attributes['type'], array( 'hidden', 'submit', 'button', 'checkbox', 'radio' ), true ) ) {
				return $matches[0]; // Return the original input without changes.
			}

			$name = isset( $attributes['name'] ) ? $attributes['name'] : '';

			// Retrieve the submitted value.
			$value = isset( $submitted_data[ $name ] ) ? htmlspecialchars( $submitted_data[ $name ] ) : '';

			$class = 'reviewable';

			$html = '';
			// Has the file been uploaded? Check the $_FILES array.
			if ( isset( $_FILES[ $name ] ) && 0 === (int) $_FILES[ $name ]['error'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified before this filter is added.
				$class = 'reviewable reviewable-file';
				$html .= '<a href="#" class="' . $class . '">See top of the page.</a>';
			} else {
				$class = 'reviewable-blank';
				$html .= '<span class="' . $class . '">&nbsp;</span>';
			}

			// Construct the span and hidden input.

			$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';

			return $html;
		},
		$content
	);

	// Process textarea fields.
	$content = preg_replace_callback(
		'/<textarea\s+([^>]*?)>(.*?)<\/textarea>/is',
		function ( $matches ) use ( $submitted_data ) {
			$attributes_str = $matches[1];
			$attributes     = shortcode_parse_atts( $attributes_str );
			$name           = isset( $attributes['name'] ) ? $attributes['name'] : '';

			// Retrieve the submitted value.
			$value = isset( $submitted_data[ $name ] ) ? htmlspecialchars( $submitted_data[ $name ] ) : '';
			$class = 'reviewable-blank';
			if ( $value ) {
				$class = 'reviewable';
			}

			// Construct the span and hidden textarea.
			$html  = '<span class="' . $class . '">' . nl2br( $value ) . '</span>';
			$html .= '<input name="' . esc_attr( $name ) . '" type="hidden" value="' . esc_html( $value ) . '">';

			return $html;
		},
		$content
	);

	// Process select fields.
	$content = preg_replace_callback(
		'/<select\s+([^>]*?)>(.*?)<\/select>/is',
		function ( $matches ) use ( $submitted_data ) {
			$attributes_str = $matches[1];
			$options_html   = $matches[2];
			$attributes     = shortcode_parse_atts( $attributes_str );
			$name           = isset( $attributes['name'] ) ? $attributes['name'] : '';

			// Retrieve the submitted value.
			$value = isset( $submitted_data[ $name ] ) ? htmlspecialchars( $submitted_data[ $name ] ) : '';

			// Build the options, marking the submitted value as selected.
			$options = '';
			preg_match_all( '/<option\s+([^>]*?)>(.*?)<\/option>/is', $options_html, $option_matches, PREG_SET_ORDER );
			foreach ( $option_matches as $option ) {
				$option_attributes_str = $option[1];
				$option_value          = isset( shortcode_parse_atts( $option_attributes_str )['value'] ) ? shortcode_parse_atts( $option_attributes_str )['value'] : $option[2];
				$selected              = ( $option_value === $value ) ? ' selected' : '';
				$options              .= '<option value="' . esc_attr( $option_value ) . '"' . $selected . '>' . esc_html( $option[2] ) . '</option>';
			}

			$class = 'reviewable-blank';
			if ( $value ) {
				$class = 'reviewable';
			}
			// Construct the span and hidden select.
			$html  = '<span class="' . $class . '">' . $value . '</span>';
			$html .= '<input name="' . esc_attr( $name ) . '" type="hidden" value="' . esc_html( $value ) . '">';

			return $html;
		},
		$content
	);

	// Make all remaining inputs read-only.
	$content = preg_replace( '/<input\s+([^>]*?)>/i', '<input $1>', $content );

	// Disable any recaptcha.

	return $content;
}

/**
 * Replace portal content with the submission success sentence.
 *
 * @param string $c Unused. Required by the the_content filter signature.
 * @return string Success markup.
 */
function dg_post_submitted_content_filter( $c ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the_content passes the post content.
	// Load the submitted data.

	$phrase   = defined( 'DG_APPLICATION_NOTIFICATION_DATE' ) ? DG_APPLICATION_NOTIFICATION_DATE : '';
	$content  = '<div class="entry-content alignfull wp-block-post-content has-global-padding is-layout-constrained wp-block-post-content-is-layout-constrained">';
	$content .= Portal_Notification_When::success_copy( $phrase );
	$content .= sprintf( '<br /><br/><a href="%s" target="_new">Click here to view the details of your application. Please print / save this for your records.</a>', DG_RECEIPT_LINK );
	$content .= '</div>';

	if ( ! defined( 'DG_APPLICATION_SUBMITTED' ) ) {
		define( 'DG_APPLICATION_SUBMITTED', true );
	}
	return $content;
}

/**
 * Encrypt a string with NONCE_SALT for the review handoff.
 *
 * @param string $plain Plain value.
 * @return string Base64 ciphertext including the IV.
 */
function dg_encrypt_str( $plain ) {
	// Encrypt the value using NONCE_SALT as the key.
	$key       = NONCE_SALT;
	$method    = 'aes-256-cbc';
	$iv        = openssl_random_pseudo_bytes( openssl_cipher_iv_length( $method ) );
	$encrypted = openssl_encrypt( $plain, $method, $key, 0, $iv );
	return base64_encode( $iv . $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Transports the IV and ciphertext for the review handoff.
}

/**
 * Decrypt a value produced by dg_encrypt_str().
 *
 * @param string $encoded Base64 ciphertext including the IV.
 * @return string|false Plain value, or false on failure.
 */
function dg_decrypt_str( $encoded ) {
	// Decrypt the value using NONCE_SALT as the key.
	$key       = NONCE_SALT;
	$method    = 'aes-256-cbc';
	$data      = base64_decode( $encoded ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reverses the review-handoff encoding.
	$iv        = substr( $data, 0, openssl_cipher_iv_length( $method ) );
	$encrypted = substr( $data, openssl_cipher_iv_length( $method ) );
	return openssl_decrypt( $encrypted, $method, $key, 0, $iv );
}



/**
 * Whether the application deadline has passed (legacy helper; prefers definition publish).
 *
 * @param int $post_id Portal post ID.
 * @return bool True when the portal is not open (deadline, forceClosed, or not published).
 */
function is_application_deadline_passed( $post_id ) {
	return ! Portal_Open_State::is_open( $post_id );
}

// Definition-first public content + closed / preview gate (filter registered in class init).

/**
 * Display success notice when a portal is duplicated
 */
function dg_duplicate_success_notice() {
	if ( isset( $_GET['duplicated'] ) && '1' === $_GET['duplicated'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag. It does not change data.
		echo '<div class="notice notice-success is-dismissible">';
		echo '<p><strong>' . esc_html__( 'DragonGate Portal duplicated successfully!', 'dragongate-portals' ) . '</strong> ' . esc_html__( 'You are now editing the duplicate.', 'dragongate-portals' ) . '</p>';
		echo '</div>';
	}
}
