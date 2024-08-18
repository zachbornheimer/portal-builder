<?php

/**
 * Plugin Name: Portal Builder
 * Plugin URI:  https://example.com/portal-builder
 * Description: A plugin to build portals for accepting applications and managing submissions with Google Sheets and Google Drive integration.
 * Version:     0.0.2a
 * Author:      Z. Bornheimer (ZYSYS)
 * Author URI:  https://zysys.org/
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: portal-builder
 * Domain Path: /languages
 * 
 * Copyright (c) 2024 Zachary Bornheimer - All Rights Reserved.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'PB_RELATIVE_TMP_UPLOADS_DIR' ) ) {
	define( 'PB_RELATIVE_TMP_UPLOADS_DIR', '/tmp-uploads/' );
}

if ( ! defined( 'PB_RELATIVE_PERMANENT_UPLOADS_DIR' ) ) {
	define( 'PB_RELATIVE_PERMANENT_UPLOADS_DIR', '/file-storage/' );
}

if ( ! defined( 'PB_TMP_UPLOADS_DIR' ) ) {
	define( 'PB_TMP_UPLOADS_DIR', ABSPATH . PB_RELATIVE_TMP_UPLOADS_DIR );
}

if ( ! defined( 'PB_PERMANENT_UPLOADS_DIR' ) ) {
	define( 'PB_PERMANENT_UPLOADS_DIR', ABSPATH . PB_RELATIVE_PERMANENT_UPLOADS_DIR );
}

// Include the necessary files
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-builder.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-post-type.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-meta.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-submission.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-portal-file-handler.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-data-table.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/templates.php';
require_once plugin_dir_path( __FILE__ ) . 'gsuite-filestore/zysys-file-store.class.php';

// include composer's autoload file
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

// Function to run on plugin activation
function portal_plugin_activate() {
	// Flush rewrite rules to regenerate them
	flush_rewrite_rules();
}

// Function to run on plugin deactivation
function portal_plugin_deactivate() {
	// Flush rewrite rules to regenerate them
	flush_rewrite_rules();
}

// Register the activation and deactivation hooks
register_activation_hook( __FILE__, 'portal_plugin_activate' );
register_deactivation_hook( __FILE__, 'portal_plugin_deactivate' );


// @TODO add some sort of cron for tmp file cleanup

// Initialize the plugin
function pb_initialize_plugin() {
	$portal_builder = new Portal_Builder();
	$portal_builder->init();

	// Initialize Post Type
	$portal_post_type = new Portal_Post_Type();
	$portal_post_type->register_post_type();

	// Initialize Meta Fields
	$portal_meta = new Portal_Meta();
	$portal_meta->init();

	// Register meta boxes only on admin side
	if ( is_admin() ) {
		pb_register_meta_boxes( $portal_meta );
	}

	handle_submissions();
}
add_action( 'plugins_loaded', 'pb_initialize_plugin' );

// Function to register meta boxes
function pb_register_meta_boxes( $portal_meta ) {
	// get the client-email from pb_google_secret_key option, if possible
	$client_email  = '';
	$label         = '';
	$client_secret = get_option( 'pb_google_secret_key' );
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
				'label' => 'Free for Consortium Members?',
				'type'  => 'switch',
			),
			array(
				'id'    => '_portal_application_fee',
				'label' => 'Application Fee',
				'type'  => 'number',
			),
			array(
				'id'    => '_portal_applicant_notification_date',
				'label' => 'Applicant Notification Date',
				'type'  => 'date',
			),
			array(
				'id'          => '_portal_county_region_script',
				'label'       => 'County/Region Dropdown Menu JS Script',
				'type'        => 'url',
				'placeholder' => get_option( 'pb_county_region_script', '' ),
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

	// Register Record Keeping Data Table Meta Box (Google Sheets)
	$portal_meta->register_meta_box(
		'portal_record_keeping',
		'Record Keeping',
		array(
			array(
				'id'         => '_portal_record_keeping',
				'label'      => sprintf( $label, 'Sheet(s)' ),
				'type'       => 'data-table',
				'columns'    => array( 'Reference ID', 'Google Sheet ID', 'Columns' ), // Add 'Columns' column
				'extraction' => array( 1 => 'google-sheet' ),
				'options'    => array(
					0 => array( 'block' => true ), // Enable block display
					2 => array( 'tags' => true ), // Enable tag handling for the third column (index 2)
				),
			),
		),
		'normal',
		'high'
	);

	// Register File Backups Data Table Meta Box (Google Drive)
	$portal_meta->register_meta_box(
		'portal_file_backups',
		'File Backups',
		array(
			array(
				'id'         => '_portal_file_backups',
				'label'      => sprintf( $label, 'Drive Folder(s)' ),
				'type'       => 'data-table',
				'columns'    => array( 'Reference ID', 'Google Drive Folder ID' ),
				'extraction' => array( 1 => 'google-drive' ),
			),
		),
		'normal',
		'high'
	);
}

// Function to handle form submissions
function handle_submissions() {


	try {

		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['review_nonce'] ) ) {
			# filter the content to change inputs to spans with the class 'reviewable'

			# quickly verify the nonce
			if ( ! wp_verify_nonce( $_POST['review_nonce'], 'review_nonce' ) ) {
				throw new Exception( 'Invalid nonce.  Likely data corruption.' );
			}

			# check the anonymize setting
			$file_handler_settings = array(
				'anonymize' => get_post_meta( $_POST['post_id'], '_portal_anonymize', true ),
			);

			global $pb_file_handler;
			$file_handler = new Portal_File_Handler( $_FILES, $file_handler_settings, '', $_POST['post_id'] );
			$file_handler->set_local_temp_dir( PB_TMP_UPLOADS_DIR );
			$file_handler->process_temp_files();

			# encrypt the appid and the 
			$_POST['appId']  = $file_handler->get_appId();
			$_POST['eappId'] = pb_encrypt_str( 'APPID_' . $_POST['appId'] );
			$_POST['efiles'] = pb_encrypt_str( json_encode( $file_handler->stored_file_paths ) );


			if ( ! defined( 'PB_FILE_HANDLER' ) ) {
				define( 'PB_FILE_HANDLER', $file_handler );
			}

			add_filter( 'the_content', 'pb_reviewable_content_filter', 10, 1 );
		} elseif ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ready_to_submit_nonce'] ) ) {
			$appId             = pb_decrypt_str( $_POST['eappId'] );
			$stored_file_paths = json_decode( pb_decrypt_str( $_POST['efiles'] ), true );
			// get the labels for the files
			$labels = array();
			foreach ( $stored_file_paths as $key => $path ) {
				$labels[ $key ] = get_label_for_pb_file_name( $_POST['post_id'], $key );
			}
			# has it been modified? ensure that APPID_ is at the beginning and remove it
			if ( substr( $appId, 0, 6 ) != 'APPID_' ) {
				throw new Exception( 'App ID has been tampered with.  Likely data corruption.' );
			}
			$_POST['APPID'] = substr( $appId, 6 );


			# ensure that $_POST['post_id'] is set
			if ( ! isset( $_POST['post_id'] ) ) {
				throw new Exception( 'Portal ID is missing.  Likely data corruption.' );
			}

			$post         = get_post( $_POST['post_id'] );
			$slug         = $post->post_name;
			$post_content = $post->post_content;

			# check the anonymize setting
			$file_handler_settings = array(
				'anonymize'     => get_post_meta( $_POST['post_id'], '_portal_anonymize', true ),
				'permanent_dir' => PB_PERMANENT_UPLOADS_DIR . '/' . $slug,
			);

			$file_handler = new Portal_File_Handler( null, $file_handler_settings, '', $_POST['post_id'] );
			$file_handler->set_local_temp_dir( PB_TMP_UPLOADS_DIR );
			$file_handler->set_appId( $_POST['APPID'] );

			define( 'PB_FILE_LABELS', $labels );

			$submission = new Portal_Submission( 'ready_to_submit_nonce', $file_handler );


			$submission->process_submission( $_POST );

			define( 'PB_RECEIPT_LINK', $submission->get_receipt_link() );

			$raw_notification_date = get_post_meta( $_POST['post_id'], '_portal_applicant_notification_date', true );
			# turn the raw date into a human readable date like Monday, June 15, 2021
			$application_notification_date = date( 'l, F j, Y', strtotime( $raw_notification_date ) );
			define( 'PB_APPLICATION_NOTIFICATION_DATE', $application_notification_date );


			add_filter( 'the_content', 'pb_post_submitted_content_filter', 10, 1 );
		}
	} catch ( Exception $e ) {
		if ( ! WP_DEBUG ) {
			error_log( 'Portal Submission Error: ' . $e->getMessage() );
			wp_die( $e->getMessage() );
		} else {
			throw $e;
		}
	}
}

function get_label_for_pb_file_name( $post_id, $name ) {
	# get the post's content

	$content = get_post_field( 'post_content', $post_id );

	# identify if there's a pb_file shortcode with a file_suffix attribute
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

// Content filter to replace inputs with reviewable spans
/**
 * Replaces form inputs with spans displaying submitted values for review.
 *
 * @param string $content The content containing the form.
 * @return string Modified content with inputs replaced by spans.
 */
function pb_reviewable_content_filter( $c ) {
	// Load the submitted data
	$submitted_data = $_POST;

	$content = do_shortcode( $c );

	// Process input fields
	$content = preg_replace_callback(
		'/<input\s+([^>]*?)>/i',
		function ( $matches ) use ( $submitted_data ) {

			$attributes_str = $matches[1];
			$attributes     = shortcode_parse_atts( $attributes_str );

			// Skip inputs that are not of type text, email, number, etc.
			if ( isset( $attributes['type'] ) && in_array( $attributes['type'], array( 'hidden', 'submit', 'button', 'file', 'checkbox', 'radio' ) ) ) {
				return $matches[0]; // Return the original input without changes
			}

			$name = isset( $attributes['name'] ) ? $attributes['name'] : '';

			// Retrieve the submitted value
			$value = isset( $submitted_data[ $name ] ) ? htmlspecialchars( $submitted_data[ $name ] ) : '';

			$class = 'reviewable-blank';
			if ( $value ) {
				$class = 'reviewable';
			}

			// Construct the span and hidden input
			$html  = '<span class="' . $class . '">' . $value . '</span>';
			$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';

			return $html;
		},
		$content
	);

	// make input files a span that says <span class="reviewable">Review uploaded file at the top of the page.</span>
	$content = preg_replace_callback(
		'/<input\s+([^>]*?)>/i',
		function ( $matches ) use ( $submitted_data ) {

			$attributes_str = $matches[1];
			$attributes     = shortcode_parse_atts( $attributes_str );

			// Skip inputs that are not of type text, email, number, etc.
			if ( isset( $attributes['type'] ) && in_array( $attributes['type'], array( 'hidden', 'submit', 'button', 'checkbox', 'radio' ) ) ) {
				return $matches[0]; // Return the original input without changes
			}

			$name = isset( $attributes['name'] ) ? $attributes['name'] : '';

			// Retrieve the submitted value
			$value = isset( $submitted_data[ $name ] ) ? htmlspecialchars( $submitted_data[ $name ] ) : '';



			$class = 'reviewable';

			$html = '';
			# has the file been uploaded? check the $_FILES array
			if ( isset( $_FILES[ $name ] ) && $_FILES[ $name ]['error'] == 0 ) {
				$class = 'reviewable reviewable-file';
				$html .= '<a href="#" class="' . $class . '">See top of the page.</a>';
			} else {
				$class = 'reviewable-blank';
				$html .= '<span class="' . $class . '">&nbsp;</span>';
			}


			// Construct the span and hidden input

			$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';

			return $html;
		},
		$content
	);


	// Process textarea fields
	$content = preg_replace_callback(
		'/<textarea\s+([^>]*?)>(.*?)<\/textarea>/is',
		function ( $matches ) use ( $submitted_data ) {
			$attributes_str = $matches[1];
			$attributes     = shortcode_parse_atts( $attributes_str );
			$name           = isset( $attributes['name'] ) ? $attributes['name'] : '';

			// Retrieve the submitted value
			$value = isset( $submitted_data[ $name ] ) ? htmlspecialchars( $submitted_data[ $name ] ) : '';
			$class = 'reviewable-blank';
			if ( $value ) {
				$class = 'reviewable';
			}

			// Construct the span and hidden textarea
			$html  = '<span class="' . $class . '">' . nl2br( $value ) . '</span>';
			$html .= '<input name="' . esc_attr( $name ) . '" type="hidden" value="' . esc_html( $value ) . '">';

			return $html;
		},
		$content
	);

	// Process select fields
	$content = preg_replace_callback(
		'/<select\s+([^>]*?)>(.*?)<\/select>/is',
		function ( $matches ) use ( $submitted_data ) {
			$attributes_str = $matches[1];
			$options_html   = $matches[2];
			$attributes     = shortcode_parse_atts( $attributes_str );
			$name           = isset( $attributes['name'] ) ? $attributes['name'] : '';

			// Retrieve the submitted value
			$value = isset( $submitted_data[ $name ] ) ? htmlspecialchars( $submitted_data[ $name ] ) : '';

			// Build the options, marking the submitted value as selected
			$options = '';
			preg_match_all( '/<option\s+([^>]*?)>(.*?)<\/option>/is', $options_html, $option_matches, PREG_SET_ORDER );
			foreach ( $option_matches as $option ) {
				$option_attributes_str = $option[1];
				$option_value          = isset( shortcode_parse_atts( $option_attributes_str )['value'] ) ? shortcode_parse_atts( $option_attributes_str )['value'] : $option[2];
				$selected              = ( $option_value == $value ) ? ' selected' : '';
				$options              .= '<option value="' . esc_attr( $option_value ) . '"' . $selected . '>' . esc_html( $option[2] ) . '</option>';
			}

			$class = 'reviewable-blank';
			if ( $value ) {
				$class = 'reviewable';
			}
			// Construct the span and hidden select
			$html  = '<span class="' . $class . '">' . $value . '</span>';
			$html .= '<input name="' . esc_attr( $name ) . '" type="hidden" value="' . esc_html( $value ) . '">';

			return $html;
		},
		$content
	);

	// make all remaining inputs read-only
	$content = preg_replace( '/<input\s+([^>]*?)>/i', '<input $1>', $content );

	// disable any recaptcha

	return $content;
}

function pb_post_submitted_content_filter( $c ) {
	// Load the submitted data

	$content  = '<div class="entry-content alignfull wp-block-post-content has-global-padding is-layout-constrained wp-block-post-content-is-layout-constrained">';
	$content .= 'Your application has been submitted successfully! Submissions will be reviewed shortly and official notification of acceptance will be made ' . PB_APPLICATION_NOTIFICATION_DATE . '.';
	$content .= sprintf( '<br /><br/><a href="%s" target="_new">Click here to view the details of your application. Please print / save this for your records.</a>', PB_RECEIPT_LINK );
	$content .= '</div>';

	if ( ! defined( 'PB_APPLICATION_SUBMITTED' ) ) {
		define( 'PB_APPLICATION_SUBMITTED', true );
	}
	return $content;
}

function pb_encrypt_str( $string ) {
	# encrypt the $string using the NONCE_SALT as the key
	$key       = NONCE_SALT;
	$method    = 'aes-256-cbc';
	$iv        = openssl_random_pseudo_bytes( openssl_cipher_iv_length( $method ) );
	$encrypted = openssl_encrypt( $string, $method, $key, 0, $iv );
	return base64_encode( $iv . $encrypted );
}

function pb_decrypt_str( $string ) {
	# decrypt the $string using the NONCE_SALT as the key
	$key       = NONCE_SALT;
	$method    = 'aes-256-cbc';
	$data      = base64_decode( $string );
	$iv        = substr( $data, 0, openssl_cipher_iv_length( $method ) );
	$encrypted = substr( $data, openssl_cipher_iv_length( $method ) );
	return openssl_decrypt( $encrypted, $method, $key, 0, $iv );
}



// Function to check if the application deadline has passed
function is_application_deadline_passed( $post_id ) {
	// Retrieve the deadline and timezone index from post meta
	$deadline       = get_post_meta( $post_id, '_portal_deadline', true );
	$timezone_index = get_post_meta( $post_id, '_portal_timezone', true );

	// If no deadline is set, assume it hasn't passed
	if ( empty( $deadline ) ) {
		return false;
	}

	// Convert the timezone index to a timezone string
	$timezones = timezone_identifiers_list();

	// Default to 'America/New_York' if the index is invalid or not set
	$timezone_string = isset( $timezones[ $timezone_index ] ) ? $timezones[ $timezone_index ] : 'America/New_York';

	try {
		// Attempt to create a DateTimeZone object
		$timezone = new DateTimeZone( $timezone_string );
	} catch ( Exception $e ) {
		// If it fails, fallback to 'America/New_York'
		$timezone = new DateTimeZone( 'America/New_York' );
	}

	// Convert the deadline to a DateTime object with the correct timezone
	$deadline_date = new DateTime( $deadline, $timezone );

	// Get the current date and time in the same timezone
	$current_date = new DateTime( 'now', $timezone );

	// Compare the dates
	return $current_date > $deadline_date;
}

// Hook to replace the content if the deadline has passed
function replace_content_if_deadline_passed( $content ) {
	if ( is_singular( 'portal' ) ) { // Ensure this runs only on single 'portal' post types
		$post_id = get_the_ID();

		if ( is_application_deadline_passed( $post_id ) ) {
			// Deadline has passed, replace the content with a message
			define( 'PB_APPLICATION_DEADLINE_PASSED', true );
			return '<p>The application deadline has passed.</p>';
		}
	}

	return $content; // Return the original content if the deadline hasn't passed
}
add_filter( 'the_content', 'replace_content_if_deadline_passed' );
