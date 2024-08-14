<?php

if ( ! class_exists( 'Portal_Settings' ) ) {

	class Portal_Settings {

		public function init() {
			add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
			add_action( 'wp_ajax_validate_url', array( $this, 'validate_url_callback' ) ); // AJAX callback for URL validation
		}

		public function add_menu_pages() {
			add_submenu_page(
				'edit.php?post_type=portal',
				__( 'Default Settings', 'portal-builder' ),
				__( 'Default Settings', 'portal-builder' ),
				'manage_options',
				'portal-default-settings',
				array( $this, 'settings_page_callback' )
			);

			add_submenu_page(
				'edit.php?post_type=portal', // The parent slug
				__( 'Google API Setup', 'portal-builder' ), // Page title
				__( 'Google API Setup', 'portal-builder' ), // Menu title
				'manage_options', // Capability
				'portal-google-api-setup', // Menu slug
				array( $this, 'google_api_setup_page_callback' ) // Callback function
			);
		}

		// Callback function for the Google API Setup page
		public function google_api_setup_page_callback() {
			?>
			<div class="wrap">
				<h1><?php _e( 'Google API Setup Instructions', 'portal-builder' ); ?></h1>
				<p>Follow these steps to set up Google Drive and Google Sheets integration:</p>
				<ol>
					<li><?php _e( 'Go to the Google Cloud Console at <a href="https://console.cloud.google.com/" target="_blank">https://console.cloud.google.com/</a>.', 'portal-builder' ); ?></li>
					<li><?php _e( 'Create a new project or select an existing one.', 'portal-builder' ); ?></li>
					<li><?php _e( 'Enable the Google Drive and Google Sheets APIs.', 'portal-builder' ); ?></li>
					<li><?php _e( 'Navigate to "IAM & Admin" > "Service Accounts".', 'portal-builder' ); ?></li>
					<li><?php _e( 'Click "Create Service Account" and provide a name and description.', 'portal-builder' ); ?></li>
					<li><?php _e( 'Assign the "Storage Admin" role to the service account.', 'portal-builder' ); ?></li>
					<li><?php _e( 'Under "Keys", click "Add Key" > "Create New Key" and select "JSON".', 'portal-builder' ); ?></li>
					<li><?php _e( 'Download the JSON file containing your service account credentials.', 'portal-builder' ); ?></li>
					<li><?php _e( 'Share your Google Drive folders and Google Sheets with the service account email found in the JSON file.', 'portal-builder' ); ?></li>
					<li><?php _e( 'In the WordPress admin dashboard, go to the plugin settings and paste the JSON content into the appropriate fields.', 'portal-builder' ); ?></li>
				</ol>
				<p><?php _e( 'Once configured, you can test the setup by performing a simple operation, such as listing files in a Google Drive folder or appending a row to a Google Sheet.', 'portal-builder' ); ?></p>
			</div>
			<?php
		}

		public function register_settings() {
			// Register the settings
			register_setting( 'pb_settings_group', 'pb_google_secret_key' );
			register_setting( 'pb_settings_group', 'pb_county_region_script' );
			register_setting(
				'pb_settings_group',
				'pb_legal_disclaimers',
				array(
					'sanitize_callback' => 'wp_json_encode',
					'default'           => json_encode( array() ),
				)
			);
			register_setting( 'pb_settings_group', 'pb_recaptcha_sitekey' );
			register_setting( 'pb_settings_group', 'pb_exiftool_path' );
			register_setting( 'pb_settings_group', 'pb_qpdf_path' );
			register_setting( 'pb_settings_group', 'pb_eyed3_path' );
			register_setting( 'pb_settings_group', 'pb_lame_path' );
			register_setting( 'pb_settings_group', 'pb_perl_path' );
			register_setting( 'pb_settings_group', 'pb_receipt_generator' );
			register_setting( 'pb_settings_group', 'pb_receipt_from_email' );
			register_setting( 'pb_settings_group', 'pb_receipt_from_name' );
			register_setting( 'pb_settings_group', 'pb_receipt_subject' );
			register_setting(
				'pb_settings_group',
				'pb_receipt_body',
				array(
					'sanitize_callback' => 'wp_kses_post', // This allows standard HTML tags
				)
			);
			register_setting( 'pb_settings_group', 'pb_receipt_alt_body' );



			// Add a section for Google API keys
			add_settings_section(
				'pb_google_api_section',
				__( 'Google API Keys', 'portal-builder' ),
				array( $this, 'google_api_section_callback' ),
				'portal-default-settings'
			);


			add_settings_field(
				'pb_google_secret_key',
				__( 'Google Secret Key', 'portal-builder' ),
				array( $this, 'render_google_secret_key_field' ),
				'portal-default-settings',
				'pb_google_api_section'
			);

			// Add a section for the JS URL
			add_settings_section(
				'pb_general_settings_section',
				__( 'General Settings', 'portal-builder' ),
				null,
				'portal-default-settings'
			);

			// Add a section for the JS URL
			add_settings_section(
				'pb_agreements_section',
				__( 'Agreements Settings', 'portal-builder' ),
				null,
				'portal-default-settings'
			);

			// Add a section for the JS URL
			add_settings_section(
				'pb_server_section',
				__( 'Server Settings', 'portal-builder' ),
				null,
				'portal-default-settings'
			);


			// Add a section for the JS URL
			add_settings_section(
				'pb_email_section',
				__( 'Email Settings', 'portal-builder' ),
				null,
				'portal-default-settings'
			);


			// Add field for County/Region Dropdown Menu JS Script URL
			add_settings_field(
				'pb_county_region_script',
				__( 'County/Region Dropdown Menu JS Script', 'portal-builder' ),
				array( $this, 'render_county_region_script_field' ),
				'portal-default-settings',
				'pb_general_settings_section'
			);

			add_settings_field(
				'pb_receipt_generator',
				__( 'Receipt Viewer PHP URL', 'portal-builder' ),
				array( $this, 'render_receipt_generator_field' ),
				'portal-default-settings',
				'pb_general_settings_section'
			);


			add_settings_field(
				'pb_recaptcha_sitekey',
				__( 'reCAPTCHA Site Key', 'portal-builder' ),
				array( $this, 'render_recaptcha_sitekey_field' ),
				'portal-default-settings',
				'pb_general_settings_section'
			);

			add_settings_field(
				'pb_legal_disclaimers',
				__( 'Legal Disclaimers', 'portal-builder' ),
				array( $this, 'render_legal_disclaimers_field' ),
				'portal-default-settings',
				'pb_agreements_section'
			);


			add_settings_field(
				'pb_exiftool_path',
				__( 'Path to exiftool', 'portal-builder' ),
				array( $this, 'render_exiftool_path_field' ),
				'portal-default-settings',
				'pb_server_section'
			);
			add_settings_field(
				'pb_qpdf_path',
				__( 'Path to qpdf', 'portal-builder' ),
				array( $this, 'render_qpdf_path_field' ),
				'portal-default-settings',
				'pb_server_section'
			);
			add_settings_field(
				'pb_eyed3_path',
				__( 'Path to eyed3', 'portal-builder' ),
				array( $this, 'render_eyed3_path_field' ),
				'portal-default-settings',
				'pb_server_section'
			);
			add_settings_field(
				'pb_lame_path',
				__( 'Path to lame', 'portal-builder' ),
				array( $this, 'render_lame_path_field' ),
				'portal-default-settings',
				'pb_server_section'
			);
			add_settings_field(
				'pb_perl_path',
				__( 'Path to perl', 'portal-builder' ),
				array( $this, 'render_perl_path_field' ),
				'portal-default-settings',
				'pb_server_section'
			);


			add_settings_field(
				'pb_receipt_from_email',
				__( 'Receipt From Email', 'portal-builder' ),
				array( $this, 'render_receipt_from_email_field' ),
				'portal-default-settings',
				'pb_email_section'
			);
			add_settings_field(
				'pb_receipt_from_name',
				__( 'Receipt From Name', 'portal-builder' ),
				array( $this, 'render_receipt_from_name_field' ),
				'portal-default-settings',
				'pb_email_section'
			);
			add_settings_field(
				'pb_receipt_subject',
				__( 'Receipt Subject', 'portal-builder' ),
				array( $this, 'render_receipt_subject_field' ),
				'portal-default-settings',
				'pb_email_section'
			);
			add_settings_field(
				'pb_receipt_body',
				__( 'Receipt Body', 'portal-builder' ),
				array( $this, 'render_receipt_body_field' ),
				'portal-default-settings',
				'pb_email_section'
			);
			add_settings_field(
				'pb_receipt_alt_body',
				__( 'Receipt Plain-Text Body', 'portal-builder' ),
				array( $this, 'render_receipt_alt_body_field' ),
				'portal-default-settings',
				'pb_email_section'
			);
		}

		public function google_api_section_callback() {
			$setup_url = admin_url( 'edit.php?post_type=portal&page=portal-google-api-setup' );
			echo '<p>' . __( 'For detailed setup instructions on configuring Google API credentials, please visit the ', 'portal-builder' ) .
				'<a href="' . esc_url( $setup_url ) . '">' . __( 'Google API Setup Instructions', 'portal-builder' ) . '</a>' .
				__( ' page.', 'portal-builder' ) . '</p>';
		}

		public function render_google_secret_key_field() {
			$value = get_option( 'pb_google_secret_key', '' );
			echo '<div class="pb-protected-wrapper"><textarea name="pb_google_secret_key" id="pb_google_secret_key" class="pb-protected-code-field" rows="10" cols="50">' . esc_textarea( $value ) . '</textarea></div>';
		}

		public function render_county_region_script_field() {
			$value = get_option( 'pb_county_region_script', '' );
			?>
			<input type="url" name="pb_county_region_script" id="pb_county_region_script" class="form-control monospace-url" value="<?php echo esc_url( $value ); ?>" placeholder="<?php _e( 'Enter the URL for the JS script', 'portal-builder' ); ?>" />
			<div id="pb_county_region_script-validation" class="url-validation empty"></div>
			<?php
		}
		public function render_receipt_generator_field() {
			$value = get_option( 'pb_receipt_generator', '' );
			?>
			<input type="url" name="pb_receipt_generator" id="pb_receipt_generator" class="form-control monospace-url" value="<?php echo esc_url( $value ); ?>" placeholder="<?php _e( 'Enter the URL for the file', 'portal-builder' ); ?>" />
			<div id="pb_receipt_generator-validation" class="url-validation empty"></div>
			<?php
		}

		public function render_recaptcha_sitekey_field() {
			$value = get_option( 'pb_recaptcha_sitekey', '' );
			?>
			<input type="text" name="pb_recaptcha_sitekey" id="pb_recaptcha_sitekey" class="form-control monospace" style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e( 'reCAPTCHA sitekey', 'portal-builder' ); ?>" />

			<?php
		}

		public function render_legal_disclaimers_field() {
			$meta_key = 'pb_legal_disclaimers';
		
			// Define the columns for the data table
			$columns = array( 'Internal Id', 'Disclaimer' );
		
			// Define an empty row (used for adding new rows)
			$sample_row = array_fill( 0, count( $columns ), '' );
		
			// Define options (adjust as per your need)
			$options = array(
				1 => array( 'tags' => false ),  // Disclaimer column does not require tags
			);
		
			// Instantiate the Data_Table class, indicating that it's for options
			$data_table = new Data_Table( $meta_key, $meta_key, $columns, $sample_row, array(), $options, true );
		
			// Render the data table
			$data_table->render();
		}
		

		public function render_exiftool_path_field() {
			$value = get_option( 'pb_exiftool_path', '' );
			?>
			<input type="text" name="pb_exiftool_path" id="pb_exiftool_path" class="form-control monospace" style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e( '/usr/local/bin/exiftool', 'portal-builder' ); ?>" />

			<?php
		}

		public function render_qpdf_path_field() {
			$value = get_option( 'pb_qpdf_path', '' );
			?>
			<input type="text" name="pb_qpdf_path" id="pb_qpdf_path" class="form-control monospace" style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e( '/usr/local/bin/qpdf', 'portal-builder' ); ?>" />

			<?php
		}

		public function render_lame_path_field() {
			$value = get_option( 'pb_lame_path', '' );
			?>
			<input type="text" name="pb_lame_path" id="pb_lame_path" class="form-control monospace" style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e( '/usr/local/bin/lame', 'portal-builder' ); ?>" />

			<?php
		}

		public function render_eyed3_path_field() {
			$value = get_option( 'pb_eyed3_path', '' );
			?>
			<input type="text" name="pb_eyed3_path" id="pb_eyed3_path" class="form-control monospace" style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e( '/usr/local/bin/eyed3', 'portal-builder' ); ?>" />

			<?php
		}

		public function render_perl_path_field() {
			$value = get_option( 'pb_perl_path', '' );
			?>
			<input type="text" name="pb_perl_path" id="pb_perl_path" class="form-control monospace" style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e( '/usr/local/bin/perl', 'portal-builder' ); ?>" />

			<?php
		}

		public function render_receipt_from_email_field() {
			$value = get_option( 'pb_receipt_from_email', '' );
			?>
			<input type="email" name="pb_receipt_from_email" id="pb_receipt_from_email" class="form-control " style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e( 'submissions@applications.yoursite.com', 'portal-builder' ); ?>" />

			<?php
		}
		public function render_receipt_from_name_field() {
			$value = get_option( 'pb_receipt_from_name', '' );
			?>
			<input type="text" name="pb_receipt_from_name" id="pb_receipt_from_name" class="form-control " style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e( 'Automated Submission Receipts', 'portal-builder' ); ?>" />

			<?php
		}
		public function render_receipt_subject_field() {
			$value = get_option( 'pb_receipt_subject', '' );
			?>
			<input type="text" name="pb_receipt_subject" id="pb_receipt_subject" class="form-control " style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e( '{{ $portalName }} Application Receipt', 'portal-builder' ); ?>" />

			<?php
		}

		public function render_receipt_body_field() {
			// Retrieve the current value of the option
			$value = get_option( 'pb_receipt_body', '' );

			// Define settings for the wp_editor
			$editor_settings = array(
				'textarea_name' => 'pb_receipt_body',  // The name of the textarea in the form
				'textarea_rows' => 10,  // Number of rows in the editor
				'media_buttons' => false,  // Whether to show the media insert/upload button
				'teeny'         => true,  // Whether to use the simplified version of the editor
				'quicktags'     => true,  // Whether to enable quicktags (HTML view)
			);

			// Render the wp_editor
			wp_editor( $value, 'pb_receipt_body', $editor_settings );
		}

		public function render_receipt_alt_body_field() {
			$value = get_option( 'pb_receipt_alt_body', '' );
			?>
			<textarea rows="5" style="width:100%" name="pb_receipt_alt_body" id="pb_receipt_alt_body" class="form-control" placeholder="<?php _e( 'Your {{$receiptLink }} ...', 'portal-builder' ); ?>"><?php echo $value; ?></textarea>

			<?php
		}


		public function settings_page_callback() {
			?>
			<div class="wrap">
				<h1><?php _e( 'Default Settings', 'portal-builder' ); ?></h1>
				<form method="post" action="options.php">
					<?php
					settings_fields( 'pb_settings_group' );
					do_settings_sections( 'portal-default-settings' );
					submit_button();
					?>
				</form>
			</div>
			<?php
		}

		public function enqueue_admin_scripts() {
			// Enqueue CSS and JS for protected code fields and URL validation
			wp_enqueue_style( 'pb-admin-css', plugins_url( '../assets/admin.css', __FILE__ ) );
			wp_enqueue_script( 'pb-admin-js', plugins_url( '../assets/admin.js', __FILE__ ), array( 'jquery' ), null, true );
			wp_enqueue_script( 'pb-admin-js', plugins_url( '../assets/url-validation.js', __FILE__ ), array( 'jquery' ), null, true );

				// Localize the script with some data for translation or other dynamic values
				wp_localize_script(
					'pb-admin-js',
					'portalBuilderLocalize',
					array(
						'removeText' => __( 'Remove', 'portal-builder' ),
						'addText'    => __( 'Add Disclaimer', 'portal-builder' ),
					)
				);
		}

		public function validate_url_callback() {
			$url      = esc_url_raw( $_POST['url'] );
			$response = wp_remote_get( $url );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( array( 'message' => 'Invalid URL' ) );
			}

			$status_code = wp_remote_retrieve_response_code( $response );

			if ( $status_code === 200 ) {
				wp_send_json_success( array( 'message' => 'Valid URL' ) );
			} else {
				wp_send_json_error( array( 'message' => 'Invalid URL' ) );
			}
		}
	}
}
