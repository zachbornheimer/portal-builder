<?php

if ( ! class_exists( 'Portal_Options' ) ) {
	require_once __DIR__ . '/class-portal-options.php';
}

if (! class_exists('Portal_Settings')) {

    class Portal_Settings
    {
        const PROMOTE_NONCE = 'dg_promote_options';

        public function init()
        {
            add_action('admin_menu', array( $this, 'add_menu_pages' ));
            add_action('admin_init', array( $this, 'register_settings' ));
            add_action('admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ));
            add_action('wp_ajax_validate_url', array( $this, 'validate_url_callback' )); // AJAX callback for URL validation
            add_action( 'wp_ajax_dg_google_probe', array( $this, 'google_probe_callback' ) );
        }

        public function add_menu_pages()
        {
            add_submenu_page(
                'edit.php?post_type=portal',
                __('Default Settings', 'dragongate-portals' ),
                __('Default Settings', 'dragongate-portals' ),
                'manage_options',
                'portal-default-settings',
                array( $this, 'settings_page_callback' )
            );

            add_submenu_page(
                'edit.php?post_type=portal', // The parent slug
                __('Google API Setup', 'dragongate-portals' ), // Page title
                __('Google API Setup', 'dragongate-portals' ), // Menu title
                'manage_options', // Capability
                'portal-google-api-setup', // Menu slug
                array( $this, 'google_api_setup_page_callback' ) // Callback function
            );
        }

        // Callback function for the Google API Setup page
        public function google_api_setup_page_callback()
        {
            ?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Google API Setup Instructions', 'dragongate-portals' ); ?></h1>
				<p><?php esc_html_e( 'The plugin uses an OAuth client JSON plus an access token. FileStore refreshes the token. These steps produce a working Drive and Sheets connection.', 'dragongate-portals' ); ?></p>
				<ol>
					<li><?php _e( 'Go to the Google Cloud Console at <a href="https://console.cloud.google.com/" target="_blank">https://console.cloud.google.com/</a>.', 'dragongate-portals' ); ?></li>
					<li><?php esc_html_e( 'Create a Google Cloud project or select an existing one.', 'dragongate-portals' ); ?></li>
					<li><?php esc_html_e( 'Enable the Drive API and the Sheets API.', 'dragongate-portals' ); ?></li>
					<li><?php esc_html_e( 'Create an OAuth client (Desktop or Web).', 'dragongate-portals' ); ?></li>
					<li><?php esc_html_e( 'Paste the OAuth client JSON into Google Secret Key on Default Settings.', 'dragongate-portals' ); ?></li>
					<li><?php esc_html_e( 'Complete OAuth as the Google user who will own the files, then paste the access token into Google Access Key.', 'dragongate-portals' ); ?></li>
					<li><?php esc_html_e( 'Share the Drive folder and Sheet with the Google identity that token represents (the human or user the OAuth consent used), not a service-account email.', 'dragongate-portals' ); ?></li>
				</ol>
			</div>
			<?php
        }

        public function register_settings()
        {
            // Register the settings
            register_setting(
                'dg_settings_group',
                'dg_google_secret_key',
                array( 'sanitize_callback' => array( $this, 'sanitize_google_secret_key' ) )
            );
            register_setting(
                'dg_settings_group',
                'dg_google_access_key',
                array( 'sanitize_callback' => array( $this, 'sanitize_google_access_key' ) )
            );
            register_setting('dg_settings_group', Portal_Spam_Gate::OPTION_SITE);
            register_setting(
                'dg_settings_group',
                Portal_Spam_Gate::OPTION_SECRET,
                array( 'sanitize_callback' => array( $this, 'sanitize_turnstile_secret' ) )
            );
            register_setting('dg_settings_group', 'dg_county_region_script');
            register_setting(
                'dg_settings_group',
                'dg_legal_disclaimers',
                array(
                    'sanitize_callback' => 'wp_json_encode',
                    'default'           => json_encode(array()),
                )
            );
            register_setting('dg_settings_group', 'dg_recaptcha_sitekey'); // leftover; hidden from UI.
            register_setting('dg_settings_group', 'dg_exiftool_path');
            register_setting('dg_settings_group', 'dg_qpdf_path');
            register_setting('dg_settings_group', 'dg_eyed3_path');
            register_setting('dg_settings_group', 'dg_lame_path');
            register_setting('dg_settings_group', 'dg_perl_path');
            register_setting('dg_settings_group', 'dg_receipt_generator');
            register_setting('dg_settings_group', 'dg_receipt_from_email');
            register_setting('dg_settings_group', 'dg_receipt_from_name');
            register_setting('dg_settings_group', 'dg_receipt_subject');
            register_setting(
                'dg_settings_group',
                'dg_operator_notify_email',
                array(
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_email',
                    'default'           => '',
                )
            );
            register_setting(
                'dg_settings_group',
                'dg_receipt_body',
                array(
                    'sanitize_callback' => 'wp_kses_post', // This allows standard HTML tags
                )
            );
            register_setting('dg_settings_group', 'dg_receipt_alt_body');

            $this->register_site_default_settings();

            // Add a section for Google API keys
            add_settings_section(
                'dg_google_api_section',
                __('Google API Keys', 'dragongate-portals' ),
                array( $this, 'google_api_section_callback' ),
                'portal-default-settings'
            );

            add_settings_field(
                'dg_google_secret_key',
                __('Google Secret Key', 'dragongate-portals' ),
                array( $this, 'render_google_secret_key_field' ),
                'portal-default-settings',
                'dg_google_api_section'
            );
            add_settings_field(
                'dg_google_access_key',
                __('Google Access Key', 'dragongate-portals' ),
                array( $this, 'render_google_access_key_field' ),
                'portal-default-settings',
                'dg_google_api_section'
            );
            add_settings_field(
                'dg_google_probe',
                __( 'Google connection test', 'dragongate-portals' ),
                array( $this, 'render_google_probe_field' ),
                'portal-default-settings',
                'dg_google_api_section'
            );

            // Add a section for the JS URL
            add_settings_section(
                'dg_general_settings_section',
                __('General Settings', 'dragongate-portals' ),
                null,
                'portal-default-settings'
            );

            // Add a section for the JS URL
            add_settings_section(
                'dg_agreements_section',
                __('Agreements Settings', 'dragongate-portals' ),
                null,
                'portal-default-settings'
            );

            // Add a section for the JS URL
            add_settings_section(
                'dg_server_section',
                __('Server Settings', 'dragongate-portals' ),
                null,
                'portal-default-settings'
            );

            // Add a section for the JS URL
            add_settings_section(
                'dg_email_section',
                __('Email Settings', 'dragongate-portals' ),
                null,
                'portal-default-settings'
            );

            // Add field for County/Region Dropdown Menu JS Script URL
            add_settings_field(
                'dg_county_region_script',
                __('County/Region Dropdown Menu JS Script', 'dragongate-portals' ),
                array( $this, 'render_county_region_script_field' ),
                'portal-default-settings',
                'dg_general_settings_section'
            );

            add_settings_field(
                'dg_receipt_generator',
                __('Receipt Viewer PHP URL', 'dragongate-portals' ),
                array( $this, 'render_receipt_generator_field' ),
                'portal-default-settings',
                'dg_general_settings_section'
            );

            add_settings_field(
                Portal_Spam_Gate::OPTION_SITE,
                __( 'Turnstile site key', 'dragongate-portals' ),
                array( $this, 'render_turnstile_site_field' ),
                'portal-default-settings',
                'dg_general_settings_section'
            );
            add_settings_field(
                Portal_Spam_Gate::OPTION_SECRET,
                __( 'Turnstile secret', 'dragongate-portals' ),
                array( $this, 'render_turnstile_secret_field' ),
                'portal-default-settings',
                'dg_general_settings_section'
            );

            add_settings_field(
                'dg_legal_disclaimers',
                __('Legal Disclaimers', 'dragongate-portals' ),
                array( $this, 'render_legal_disclaimers_field' ),
                'portal-default-settings',
                'dg_agreements_section'
            );

            add_settings_field(
                'dg_exiftool_path',
                __('Path to exiftool', 'dragongate-portals' ),
                array( $this, 'render_exiftool_path_field' ),
                'portal-default-settings',
                'dg_server_section'
            );
            add_settings_field(
                'dg_qpdf_path',
                __('Path to qpdf', 'dragongate-portals' ),
                array( $this, 'render_qpdf_path_field' ),
                'portal-default-settings',
                'dg_server_section'
            );
            add_settings_field(
                'dg_eyed3_path',
                __('Path to eyed3', 'dragongate-portals' ),
                array( $this, 'render_eyed3_path_field' ),
                'portal-default-settings',
                'dg_server_section'
            );
            add_settings_field(
                'dg_lame_path',
                __('Path to lame', 'dragongate-portals' ),
                array( $this, 'render_lame_path_field' ),
                'portal-default-settings',
                'dg_server_section'
            );
            add_settings_field(
                'dg_perl_path',
                __('Path to perl', 'dragongate-portals' ),
                array( $this, 'render_perl_path_field' ),
                'portal-default-settings',
                'dg_server_section'
            );

            add_settings_field(
                'dg_receipt_from_email',
                __('Receipt From Email', 'dragongate-portals' ),
                array( $this, 'render_receipt_from_email_field' ),
                'portal-default-settings',
                'dg_email_section'
            );
            add_settings_field(
                'dg_receipt_from_name',
                __('Receipt From Name', 'dragongate-portals' ),
                array( $this, 'render_receipt_from_name_field' ),
                'portal-default-settings',
                'dg_email_section'
            );
            add_settings_field(
                'dg_receipt_subject',
                __('Receipt Subject', 'dragongate-portals' ),
                array( $this, 'render_receipt_subject_field' ),
                'portal-default-settings',
                'dg_email_section'
            );
            add_settings_field(
                'dg_receipt_body',
                __('Receipt Body', 'dragongate-portals' ),
                array( $this, 'render_receipt_body_field' ),
                'portal-default-settings',
                'dg_email_section'
            );
            add_settings_field(
                'dg_receipt_alt_body',
                __('Receipt Plain-Text Body', 'dragongate-portals' ),
                array( $this, 'render_receipt_alt_body_field' ),
                'portal-default-settings',
                'dg_email_section'
            );
            add_settings_field(
                'dg_operator_notify_email',
                __('Operator notify email', 'dragongate-portals' ),
                array( $this, 'render_operator_notify_email_field' ),
                'portal-default-settings',
                'dg_email_section'
            );

            $this->add_site_default_fields();
        }

        /**
         * Site-wide Anonymizer and inherit bag. Same page, not a new menu.
         */
        private function register_site_default_settings() {
            $bool = array(
                'type'              => 'boolean',
                'sanitize_callback' => array( $this, 'sanitize_default_bool' ),
                'default'           => false,
            );
            register_setting( 'dg_settings_group', 'dg_default_anonymize', $bool );
            register_setting( 'dg_settings_group', 'dg_default_anonymize_fail_closed', $bool );
            register_setting( 'dg_settings_group', 'dg_default_free_for_members', $bool );
            register_setting(
                'dg_settings_group',
                'dg_default_anonymize_endpoint',
                array(
                    'type'              => 'string',
                    'sanitize_callback' => array( $this, 'sanitize_default_url' ),
                    'default'           => '',
                )
            );
            register_setting(
                'dg_settings_group',
                'dg_default_anonymize_api_key',
                array(
                    'type'              => 'string',
                    'sanitize_callback' => array( $this, 'sanitize_default_api_key' ),
                    'default'           => '',
                )
            );
            register_setting(
                'dg_settings_group',
                'dg_default_anonymize_ack',
                array(
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                    'default'           => '',
                )
            );
            register_setting(
                'dg_settings_group',
                'dg_default_guidelines_url',
                array(
                    'type'              => 'string',
                    'sanitize_callback' => array( $this, 'sanitize_default_url' ),
                    'default'           => '',
                )
            );
            register_setting(
                'dg_settings_group',
                'dg_default_guidelines_link_label',
                array(
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                )
            );
            register_setting(
                'dg_settings_group',
                'dg_login_url',
                array(
                    'type'              => 'string',
                    'sanitize_callback' => array( $this, 'sanitize_path_or_url' ),
                    'default'           => '',
                )
            );
            register_setting(
                'dg_settings_group',
                'dg_join_url',
                array(
                    'type'              => 'string',
                    'sanitize_callback' => array( $this, 'sanitize_path_or_url' ),
                    'default'           => '',
                )
            );
            register_setting(
                'dg_settings_group',
                'dg_default_timezone',
                array(
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                )
            );
            register_setting(
                'dg_settings_group',
                'dg_default_brand',
                array(
                    'type'              => 'array',
                    'sanitize_callback' => array( $this, 'sanitize_default_brand' ),
                    'default'           => array(),
                )
            );
        }

        /**
         * @return void
         */
        private function add_site_default_fields() {
            add_settings_section(
                'dg_anonymizer_defaults_section',
                __( 'Anonymizer & defaults', 'dragongate-portals' ),
                array( $this, 'anonymizer_defaults_section_callback' ),
                'portal-default-settings'
            );
            add_settings_field(
                'dg_default_anonymize',
                __( 'Anonymize files', 'dragongate-portals' ),
                array( $this, 'render_default_anonymize_field' ),
                'portal-default-settings',
                'dg_anonymizer_defaults_section'
            );
            add_settings_field(
                'dg_default_anonymize_fail_closed',
                __( 'Fail closed', 'dragongate-portals' ),
                array( $this, 'render_default_anonymize_fail_closed_field' ),
                'portal-default-settings',
                'dg_anonymizer_defaults_section'
            );
            add_settings_field(
                'dg_default_anonymize_endpoint',
                __( 'Anonymize API URL', 'dragongate-portals' ),
                array( $this, 'render_default_anonymize_endpoint_field' ),
                'portal-default-settings',
                'dg_anonymizer_defaults_section'
            );
            add_settings_field(
                'dg_default_anonymize_api_key',
                __( 'Anonymize API key', 'dragongate-portals' ),
                array( $this, 'render_default_anonymize_api_key_field' ),
                'portal-default-settings',
                'dg_anonymizer_defaults_section'
            );
            add_settings_field(
                'dg_default_anonymize_ack',
                __( 'Anonymize certification', 'dragongate-portals' ),
                array( $this, 'render_default_anonymize_ack_field' ),
                'portal-default-settings',
                'dg_anonymizer_defaults_section'
            );
            add_settings_field(
                'dg_default_guidelines_url',
                __( 'Guidelines URL', 'dragongate-portals' ),
                array( $this, 'render_default_guidelines_url_field' ),
                'portal-default-settings',
                'dg_anonymizer_defaults_section'
            );
            add_settings_field(
                'dg_default_guidelines_link_label',
                __( 'Guidelines link label', 'dragongate-portals' ),
                array( $this, 'render_default_guidelines_link_label_field' ),
                'portal-default-settings',
                'dg_anonymizer_defaults_section'
            );
            add_settings_field(
                'dg_login_url',
                __( 'Sign-in URL', 'dragongate-portals' ),
                array( $this, 'render_login_url_field' ),
                'portal-default-settings',
                'dg_anonymizer_defaults_section'
            );
            add_settings_field(
                'dg_join_url',
                __( 'Membership URL', 'dragongate-portals' ),
                array( $this, 'render_join_url_field' ),
                'portal-default-settings',
                'dg_anonymizer_defaults_section'
            );
            add_settings_field(
                'dg_default_free_for_members',
                __( 'Free for members', 'dragongate-portals' ),
                array( $this, 'render_default_free_for_members_field' ),
                'portal-default-settings',
                'dg_anonymizer_defaults_section'
            );
            add_settings_field(
                'dg_default_timezone',
                __( 'Timezone', 'dragongate-portals' ),
                array( $this, 'render_default_timezone_field' ),
                'portal-default-settings',
                'dg_anonymizer_defaults_section'
            );

            add_settings_section(
                'dg_white_label_section',
                __( 'White label', 'dragongate-portals' ),
                array( $this, 'white_label_section_callback' ),
                'portal-default-settings'
            );
            add_settings_field(
                'dg_default_brand',
                __( 'Look', 'dragongate-portals' ),
                array( $this, 'render_default_brand_field' ),
                'portal-default-settings',
                'dg_white_label_section'
            );
        }

        /**
         * @return void
         */
        public function anonymizer_defaults_section_callback() {
            $plugin_file = dirname( __DIR__ ) . '/portal-builder.php';
            $contract    = plugins_url( 'docs/design/ANONYMIZER.md', $plugin_file );
            $builtin     = class_exists( 'Portal_Site_Defaults' )
                ? Portal_Site_Defaults::BUILTIN_ANONYMIZE_ENDPOINT
                : 'https://api.allintersections.com';
            echo '<p>' . esc_html__(
                'Portals inherit these unless they override them. A blank Anonymize API URL uses All Intersections. A custom host is allowed if it speaks the same contract.', 'dragongate-portals' ) . ' <a href="' . esc_url( $contract ) . '">' . esc_html__( 'Anonymizer contract', 'dragongate-portals' ) . '</a>.</p>';
            echo '<p class="description">' . esc_html(
                sprintf(
                    /* translators: %s: built-in All Intersections URL */
                    __( 'Built-in endpoint if the site URL is also blank: %s', 'dragongate-portals' ),
                    $builtin
                )
            ) . '</p>';
        }

        /**
         * Unchecked checkbox is not posted; treat missing as false.
         *
         * @param mixed $value Raw.
         * @return bool
         */
        public function sanitize_default_bool( $value ) {
            return ! empty( $value );
        }

        /**
         * @param mixed $value Raw.
         * @return string
         */
        public function sanitize_default_url( $value ) {
            if ( ! is_string( $value ) ) {
                return '';
            }
            $trimmed = trim( $value );
            if ( '' === $trimmed ) {
                return '';
            }
            return esc_url_raw( $trimmed );
        }

        /**
         * Site-relative path (/login) or absolute http(s) URL.
         * esc_url_raw drops a bare path, so paths stay as paths.
         *
         * @param mixed $value Raw.
         * @return string
         */
        public function sanitize_path_or_url( $value ) {
            if ( ! is_string( $value ) ) {
                return '';
            }
            $trimmed = trim( $value );
            if ( '' === $trimmed ) {
                return '';
            }
            if ( 0 === strpos( $trimmed, 'https://' ) || 0 === strpos( $trimmed, 'http://' ) ) {
                return esc_url_raw( $trimmed );
            }
            if ( '/' !== $trimmed[0] ) {
                $trimmed = '/' . $trimmed;
            }
            $path = preg_replace( '#[^a-zA-Z0-9/_.\-~%]#', '', $trimmed );
            return is_string( $path ) ? $path : '';
        }

        /**
         * Blank keeps the stored key so the password field never has to echo it.
         *
         * @param mixed $value Posted value.
         * @return string
         */
        public function sanitize_default_api_key( $value ) {
            $existing = function_exists( 'get_option' )
                ? (string) Portal_Options::get( 'dg_default_anonymize_api_key', '' )
                : '';
            if ( ! is_string( $value ) ) {
                return $existing;
            }
            $trimmed = trim( $value );
            return '' === $trimmed ? $existing : $trimmed;
        }

        /**
         * @return void
         */
        public function render_default_anonymize_field() {
            $on = ! empty( Portal_Options::get( 'dg_default_anonymize', false ) );
            echo '<label><input type="hidden" name="dg_default_anonymize" value="0" />';
            echo '<input type="checkbox" name="dg_default_anonymize" value="1" ' . checked( $on, true, false ) . ' /> ';
            echo esc_html__( 'Anonymize files for adjudicators by default', 'dragongate-portals' ) . '</label>';
        }

        /**
         * @return void
         */
        public function render_default_anonymize_fail_closed_field() {
            $on = ! empty( Portal_Options::get( 'dg_default_anonymize_fail_closed', false ) );
            echo '<label><input type="hidden" name="dg_default_anonymize_fail_closed" value="0" />';
            echo '<input type="checkbox" name="dg_default_anonymize_fail_closed" value="1" ' . checked( $on, true, false ) . ' /> ';
            echo esc_html__( 'Do not store the original if anonymize fails', 'dragongate-portals' ) . '</label>';
            echo '<p class="description">' . esc_html__( 'Off keeps the identifying file when the API errors. On refuses the original.', 'dragongate-portals' ) . '</p>';
        }

        /**
         * @return void
         */
        public function render_default_anonymize_endpoint_field() {
            $value   = (string) Portal_Options::get( 'dg_default_anonymize_endpoint', '' );
            $builtin = class_exists( 'Portal_Site_Defaults' )
                ? Portal_Site_Defaults::BUILTIN_ANONYMIZE_ENDPOINT
                : 'https://api.allintersections.com';
            echo '<input type="url" name="dg_default_anonymize_endpoint" class="regular-text" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $builtin ) . '" />';
            echo '<p class="description">' . esc_html__( 'Leave blank to use All Intersections. Custom hosts must implement the same two POSTs.', 'dragongate-portals' ) . '</p>';
        }

        /**
         * @return void
         */
        public function render_default_anonymize_api_key_field() {
            $stored = (string) Portal_Options::get( 'dg_default_anonymize_api_key', '' );
            $hint   = class_exists( 'Portal_Site_Defaults' )
                ? Portal_Site_Defaults::key_hint( $stored )
                : '';
            $placeholder = '' !== $hint
                ? sprintf(
                    /* translators: %s: last-four hint such as ••••1234 */
                    __( 'Saved (%s) — leave blank to keep', 'dragongate-portals' ),
                    $hint
                )
                : __( 'Paste API key', 'dragongate-portals' );
            echo '<input type="password" name="dg_default_anonymize_api_key" class="regular-text" value="" autocomplete="new-password" placeholder="' . esc_attr( $placeholder ) . '" />';
            echo '<p class="description">' . esc_html__( 'Never shown on the public form. Leave blank to keep the saved key.', 'dragongate-portals' ) . '</p>';
        }

        /**
         * @return void
         */
        public function render_default_anonymize_ack_field() {
            $value   = (string) Portal_Options::get( 'dg_default_anonymize_ack', '' );
            $builtin = class_exists( 'Portal_Site_Defaults' )
                ? Portal_Site_Defaults::BUILTIN_ANONYMIZE_ACK
                : 'I certify that my scores and recordings exclude any information that might identify the composer but do include title of work, instrumentation, and duration.';
            echo '<textarea name="dg_default_anonymize_ack" class="large-text" rows="3" placeholder="' . esc_attr( $builtin ) . '">' . esc_textarea( $value ) . '</textarea>';
            echo '<p class="description">' . esc_html__( 'Shown as a required checkbox when anonymize is on. Leave blank to use the built-in sentence. Portals may override.', 'dragongate-portals' ) . '</p>';
        }

        /**
         * @return void
         */
        public function render_default_guidelines_url_field() {
            $value = (string) Portal_Options::get( 'dg_default_guidelines_url', '' );
            echo '<input type="url" name="dg_default_guidelines_url" class="regular-text" value="' . esc_attr( $value ) . '" placeholder="https://" />';
        }

        /**
         * @return void
         */
        public function render_default_guidelines_link_label_field() {
            $value   = (string) Portal_Options::get( 'dg_default_guidelines_link_label', '' );
            $builtin = class_exists( 'Portal_Site_Defaults' )
                ? Portal_Site_Defaults::BUILTIN_GUIDELINES_LINK_LABEL
                : 'Link to Guidelines';
            echo '<input type="text" name="dg_default_guidelines_link_label" class="regular-text" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $builtin ) . '" />';
            echo '<p class="description">' . esc_html__( 'Text of the public guidelines link. Leave blank to use the built-in label. Portals may override.', 'dragongate-portals' ) . '</p>';
        }

        /**
         * @return void
         */
        public function render_login_url_field() {
            $value       = (string) Portal_Options::get( 'dg_login_url', '' );
            $placeholder = class_exists( 'Portal_Site_Defaults' )
                ? Portal_Site_Defaults::BUILTIN_LOGIN_PATH
                : '/login';
            echo '<input type="text" name="dg_login_url" class="regular-text" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" />';
            echo '<p class="description">' . esc_html__( 'Applicant Sign in. Never wp-login.php. Path or https:// URL.', 'dragongate-portals' ) . '</p>';
        }

        /**
         * @return void
         */
        public function render_join_url_field() {
            $value       = (string) Portal_Options::get( 'dg_join_url', '' );
            $placeholder = class_exists( 'Portal_Site_Defaults' )
                ? Portal_Site_Defaults::BUILTIN_JOIN_PATH
                : '/membership';
            echo '<input type="text" name="dg_join_url" class="regular-text" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" />';
            echo '<p class="description">' . esc_html__( 'Membership join or renew page. Path or https:// URL.', 'dragongate-portals' ) . '</p>';
        }

        /**
         * @return void
         */
        public function render_default_free_for_members_field() {
            $on = ! empty( Portal_Options::get( 'dg_default_free_for_members', false ) );
            echo '<label><input type="hidden" name="dg_default_free_for_members" value="0" />';
            echo '<input type="checkbox" name="dg_default_free_for_members" value="1" ' . checked( $on, true, false ) . ' /> ';
            echo esc_html__( 'Waive the application fee for members by default', 'dragongate-portals' ) . '</label>';
        }

        /**
         * @return void
         */
        public function render_default_timezone_field() {
            $value = (string) Portal_Options::get( 'dg_default_timezone', '' );
            echo '<input type="text" name="dg_default_timezone" class="regular-text" value="' . esc_attr( $value ) . '" placeholder="America/New_York" />';
            echo '<p class="description">' . esc_html__( 'IANA timezone. Portals inherit this when their timezone field is blank.', 'dragongate-portals' ) . '</p>';
        }

        /**
         * @return void
         */
        public function white_label_section_callback() {
            echo '<p>' . esc_html__(
                'This look applies to public application pages. The setup wizard stays DragonGate.', 'dragongate-portals' ) . '</p>';
            $example = class_exists( 'Portal_Brand' )
                ? Portal_Brand::preset( Portal_Brand::PRESET_ISJAC )
                : array();
            echo '<script type="application/json" id="pb-brand-isjac">' . wp_json_encode( $example ) . '</script>';
        }

        /**
         * @param mixed $value Posted bag.
         * @return array
         */
        public function sanitize_default_brand( $value ) {
            if ( class_exists( 'Portal_Brand' ) ) {
                return Portal_Brand::sanitize( $value );
            }
            return array();
        }

        /**
         * @return void
         */
        public function render_default_brand_field() {
            $brand  = Portal_Options::get( 'dg_default_brand', array() );
            $brand  = is_array( $brand ) ? $brand : array();
            $preset = isset( $brand['preset'] ) ? (string) $brand['preset'] : 'product';
            $choices = array(
                'product' => __( 'DragonGate (product tokens)', 'dragongate-portals' ),
                'custom'  => __( 'Custom', 'dragongate-portals' ),
                'isjac'   => __( 'Example host (ISJAC guide)', 'dragongate-portals' ),
            );
            echo '<p><label for="dg_default_brand_preset">' . esc_html__( 'Preset', 'dragongate-portals' ) . '</label><br />';
            echo '<select name="dg_default_brand[preset]" id="dg_default_brand_preset">';
            foreach ( $choices as $value => $label ) {
                echo '<option value="' . esc_attr( $value ) . '" ' . selected( $preset, $value, false ) . '>' . esc_html( $label ) . '</option>';
            }
            echo '</select></p>';

            $colors = array(
                'ink'         => __( 'Ink', 'dragongate-portals' ),
                'paper'       => __( 'Paper', 'dragongate-portals' ),
                'accent'      => __( 'Accent', 'dragongate-portals' ),
                'accentHover' => __( 'Accent hover', 'dragongate-portals' ),
                'wash'        => __( 'Wash', 'dragongate-portals' ),
                'eyebrow'     => __( 'Eyebrow', 'dragongate-portals' ),
                'rule'        => __( 'Rule', 'dragongate-portals' ),
                'error'       => __( 'Error', 'dragongate-portals' ),
                'success'     => __( 'Success', 'dragongate-portals' ),
            );
            echo '<div class="pb-brand-colors" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(12rem,1fr));gap:0.75rem;max-width:48rem;">';
            foreach ( $colors as $key => $label ) {
                $hex = isset( $brand[ $key ] ) ? (string) $brand[ $key ] : '';
                echo '<label>' . esc_html( $label ) . '<br />';
                echo '<input type="text" class="regular-text pb-brand-token" data-brand-key="' . esc_attr( $key ) . '" name="dg_default_brand[' . esc_attr( $key ) . ']" value="' . esc_attr( $hex ) . '" placeholder="#000000" /></label>';
            }
            echo '</div>';

            $fonts = array(
                'fontDisplay' => __( 'Display font', 'dragongate-portals' ),
                'fontUi'      => __( 'UI font', 'dragongate-portals' ),
                'fontMono'    => __( 'Mono font', 'dragongate-portals' ),
                'fontsUrl'    => __( 'Fonts stylesheet URL', 'dragongate-portals' ),
            );
            echo '<div style="margin-top:1rem;max-width:40rem;">';
            foreach ( $fonts as $key => $label ) {
                $val = isset( $brand[ $key ] ) ? (string) $brand[ $key ] : '';
                echo '<p><label>' . esc_html( $label ) . '<br />';
                echo '<input type="text" class="large-text pb-brand-token" data-brand-key="' . esc_attr( $key ) . '" name="dg_default_brand[' . esc_attr( $key ) . ']" value="' . esc_attr( $val ) . '" /></label></p>';
            }
            $logo = isset( $brand['logoUrl'] ) ? (string) $brand['logoUrl'] : '';
            echo '<p><label>' . esc_html__( 'Logo URL', 'dragongate-portals' ) . '<br />';
            echo '<input type="url" class="large-text pb-brand-token" data-brand-key="logoUrl" name="dg_default_brand[logoUrl]" value="' . esc_attr( $logo ) . '" placeholder="https://" /></label></p>';
            $hide = array_key_exists( 'hideLogo', $brand )
                ? ! empty( $brand['hideLogo'] )
                : ( 'isjac' === $preset );
            echo '<p><label><input type="hidden" name="dg_default_brand[hideLogo]" value="0" />';
            echo '<input type="checkbox" name="dg_default_brand[hideLogo]" value="1" ' . checked( $hide, true, false ) . ' /> ';
            echo esc_html__( 'Hide the seal on public applications', 'dragongate-portals' ) . '</label></p>';
            echo '</div>';
            echo '<script>(function(){var s=document.getElementById("dg_default_brand_preset");var j=document.getElementById("pb-brand-isjac");if(!s||!j)return;var example={};try{example=JSON.parse(j.textContent||"{}");}catch(e){example={};}s.addEventListener("change",function(){if(s.value!=="isjac")return;document.querySelectorAll(".pb-brand-token").forEach(function(el){var k=el.getAttribute("data-brand-key");if(k&&example[k])el.value=example[k];});});})();</script>';
        }

        public function google_api_section_callback()
        {
            $setup_url = admin_url('edit.php?post_type=portal&page=portal-google-api-setup');
            echo '<p>' . esc_html__( 'Paste the OAuth client JSON into Google Secret Key and the OAuth access token into Google Access Key. FileStore refreshes the token.', 'dragongate-portals' ) . '</p>';
            echo '<p>' . __( 'For the full steps, visit the ', 'dragongate-portals' ) .
                '<a href="' . esc_url( $setup_url ) . '">' . __( 'Google API Setup Instructions', 'dragongate-portals' ) . '</a>' .
                __( ' page.', 'dragongate-portals' ) . '</p>';
        }

        public function render_google_secret_key_field()
        {
            echo Portal_Secret_Field::render_textarea(
                'dg_google_secret_key',
                Portal_Options::get( 'dg_google_secret_key', '' ),
                __( 'OAuth client JSON from Google Cloud (Desktop or Web client). Not a service-account key.', 'dragongate-portals' )
            );
        }

        public function render_google_access_key_field()
        {
            echo Portal_Secret_Field::render_textarea(
                'dg_google_access_key',
                Portal_Options::get( 'dg_google_access_key', '' ),
                __( 'OAuth access token for the Google identity that completed consent. FileStore refreshes this token.', 'dragongate-portals' )
            );
        }

        public function sanitize_google_secret_key( $incoming ) {
            return Portal_Secret_Field::keep_if_blank( $incoming, Portal_Options::get( 'dg_google_secret_key', '' ) );
        }

        public function sanitize_google_access_key( $incoming ) {
            return Portal_Secret_Field::keep_if_blank( $incoming, Portal_Options::get( 'dg_google_access_key', '' ) );
        }

        public function sanitize_turnstile_secret( $incoming ) {
            return Portal_Secret_Field::keep_if_blank( $incoming, Portal_Options::get( Portal_Spam_Gate::OPTION_SECRET, '' ) );
        }

        /**
         * Folder ID, Sheet ID, probe button, and pass/fail slot.
         *
         * @return void
         */
        public function render_google_probe_field()
        {
            if ( class_exists( 'Portal_Google_Probe' ) ) {
                Portal_Google_Probe::render_admin();
            }
        }

        /**
         * AJAX entry. Capability and nonce are checked in handle_google_probe.
         *
         * @return void
         */
        public function google_probe_callback()
        {
            $request = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();
            $result  = $this->handle_google_probe( $request );
            if ( function_exists( 'wp_send_json' ) ) {
                wp_send_json( $result );
                return;
            }
            echo wp_json_encode( $result );
        }

        /**
         * Run the Google probe. Never wp_die — returns a named pass/fail payload.
         *
         * @param array                        $request Posted fields.
         * @param Portal_Google_Probe|null     $probe   Injected probe (tests).
         * @return array{ok:bool,message:string}
         */
        public function handle_google_probe( $request, $probe = null )
        {
            if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
                return array(
                    'ok'      => false,
                    'message' => Portal_Google_Probe::explain( Portal_Google_Probe_Failure::CAPABILITY ),
                );
            }
            $request = is_array( $request ) ? $request : array();
            $nonce   = isset( $request[ Portal_Google_Probe::NONCE_FIELD ] )
                ? (string) $request[ Portal_Google_Probe::NONCE_FIELD ]
                : '';
            if ( ! function_exists( 'wp_verify_nonce' ) || ! wp_verify_nonce( $nonce, Portal_Google_Probe::NONCE_ACTION ) ) {
                return array(
                    'ok'      => false,
                    'message' => Portal_Google_Probe::explain( Portal_Google_Probe_Failure::NONCE ),
                );
            }
            if ( ! $probe instanceof Portal_Google_Probe ) {
                $probe = new Portal_Google_Probe();
            }
            $folder = isset( $request[ Portal_Google_Probe::FOLDER_FIELD ] ) ? $request[ Portal_Google_Probe::FOLDER_FIELD ] : '';
            $sheet  = isset( $request[ Portal_Google_Probe::SHEET_FIELD ] ) ? $request[ Portal_Google_Probe::SHEET_FIELD ] : '';
            return $probe->run( $folder, $sheet );
        }

        public function render_county_region_script_field()
        {
            $value = Portal_Options::get( 'dg_county_region_script', '');
            ?>
			<input type="url" name="dg_county_region_script" id="dg_county_region_script" class="form-control monospace-url" value="<?php echo esc_url($value); ?>" placeholder="<?php _e('Enter the URL for the JS script', 'dragongate-portals'); ?>" />
			<div id="dg_county_region_script-validation" class="url-validation empty"></div>
			<?php
        }
        public function render_receipt_generator_field()
        {
            $value = Portal_Options::get( 'dg_receipt_generator', '');
            ?>
			<input type="url" name="dg_receipt_generator" id="dg_receipt_generator" class="form-control monospace-url" value="<?php echo esc_url($value); ?>" placeholder="<?php _e('Enter the URL for the file', 'dragongate-portals'); ?>" />
			<div id="dg_receipt_generator-validation" class="url-validation empty"></div>
			<?php
        }

        public function render_turnstile_site_field() {
            $value = Portal_Options::get( Portal_Spam_Gate::OPTION_SITE, '' );
            echo '<input type="text" name="' . esc_attr( Portal_Spam_Gate::OPTION_SITE ) . '" class="regular-text" value="' . esc_attr( (string) $value ) . '" autocomplete="off" />';
            echo '<p class="description">' . esc_html__( 'Cloudflare Turnstile site key. Public submits need a valid token when this is set.', 'dragongate-portals' ) . '</p>';
        }

        public function render_turnstile_secret_field() {
            echo Portal_Secret_Field::render_textarea(
                Portal_Spam_Gate::OPTION_SECRET,
                Portal_Options::get( Portal_Spam_Gate::OPTION_SECRET, '' ),
                __( 'Cloudflare Turnstile secret. Public submits are rejected without a valid token.', 'dragongate-portals' )
            );
        }

        public function render_legal_disclaimers_field()
        {
            $meta_key = 'dg_legal_disclaimers';

            // Define the columns for the data table
            $columns = array( 'Internal Id', 'Disclaimer' );

            // Define an empty row (used for adding new rows)
            $sample_row = array_fill(0, count($columns), '');

            // Define options (adjust as per your need)
            $options = array(
                1 => array( 'tags' => false ),  // Disclaimer column does not require tags
            );

            // Instantiate the Data_Table class, indicating that it's for options
            $data_table = new Data_Table($meta_key, $meta_key, $columns, $sample_row, array(), $options, true);

            // Render the data table
            $data_table->render();
        }

        public function render_exiftool_path_field()
        {
            $value = Portal_Options::get( 'dg_exiftool_path', '');
            ?>
			<input type="text" name="dg_exiftool_path" id="dg_exiftool_path" class="form-control monospace" style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e('/usr/local/bin/exiftool', 'dragongate-portals'); ?>" />

			<?php
        }

        public function render_qpdf_path_field()
        {
            $value = Portal_Options::get( 'dg_qpdf_path', '');
            ?>
			<input type="text" name="dg_qpdf_path" id="dg_qpdf_path" class="form-control monospace" style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e('/usr/local/bin/qpdf', 'dragongate-portals'); ?>" />

			<?php
        }

        public function render_lame_path_field()
        {
            $value = Portal_Options::get( 'dg_lame_path', '');
            ?>
			<input type="text" name="dg_lame_path" id="dg_lame_path" class="form-control monospace" style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e('/usr/local/bin/lame', 'dragongate-portals'); ?>" />

			<?php
        }

        public function render_eyed3_path_field()
        {
            $value = Portal_Options::get( 'dg_eyed3_path', '');
            ?>
			<input type="text" name="dg_eyed3_path" id="dg_eyed3_path" class="form-control monospace" style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e('/usr/local/bin/eyed3', 'dragongate-portals'); ?>" />

			<?php
        }

        public function render_perl_path_field()
        {
            $value = Portal_Options::get( 'dg_perl_path', '');
            ?>
			<input type="text" name="dg_perl_path" id="dg_perl_path" class="form-control monospace" style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e('/usr/local/bin/perl', 'dragongate-portals'); ?>" />

			<?php
        }

        public function render_receipt_from_email_field()
        {
            $value = Portal_Options::get( 'dg_receipt_from_email', '');
            ?>
			<input type="email" name="dg_receipt_from_email" id="dg_receipt_from_email" class="form-control " style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e('submissions@applications.yoursite.com', 'dragongate-portals'); ?>" />

			<?php
        }
        public function render_receipt_from_name_field()
        {
            $value = Portal_Options::get( 'dg_receipt_from_name', '');
            ?>
			<input type="text" name="dg_receipt_from_name" id="dg_receipt_from_name" class="form-control " style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e('Automated Submission Receipts', 'dragongate-portals'); ?>" />

			<?php
        }
        public function render_receipt_subject_field()
        {
            $value = Portal_Options::get( 'dg_receipt_subject', '');
            ?>
			<input type="text" name="dg_receipt_subject" id="dg_receipt_subject" class="form-control " style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e('{{ $portalName }} Application Receipt', 'dragongate-portals'); ?>" />

			<?php
        }

        public function render_receipt_body_field()
        {
            // Retrieve the current value of the option
            $value = Portal_Options::get( 'dg_receipt_body', '');

            // Define settings for the wp_editor
            $editor_settings = array(
                'textarea_name' => 'dg_receipt_body',  // The name of the textarea in the form
                'textarea_rows' => 10,  // Number of rows in the editor
                'media_buttons' => false,  // Whether to show the media insert/upload button
                'teeny'         => true,  // Whether to use the simplified version of the editor
                'quicktags'     => true,  // Whether to enable quicktags (HTML view)
            );

            // Render the wp_editor
            wp_editor($value, 'dg_receipt_body', $editor_settings);
        }

        public function render_receipt_alt_body_field()
        {
            $value = Portal_Options::get( 'dg_receipt_alt_body', '');
            ?>
			<textarea rows="5" style="width:100%" name="dg_receipt_alt_body" id="dg_receipt_alt_body" class="form-control" placeholder="<?php _e('Your {{$receiptLink }} ...', 'dragongate-portals'); ?>"><?php echo $value; ?></textarea>

			<?php
        }

        /**
         * Empty value falls back to WordPress admin_email at send time.
         */
        public function render_operator_notify_email_field()
        {
            $value = Portal_Options::get( 'dg_operator_notify_email', '');
            $admin = get_option('admin_email', '');
            ?>
			<input type="email" name="dg_operator_notify_email" id="dg_operator_notify_email" class="form-control" style="width:100%" value="<?php echo esc_attr($value); ?>" placeholder="<?php echo esc_attr($admin); ?>" />
			<p class="description"><?php _e('Receives one notify per successful application (title, application id, applicant email, receipt URL). Leave blank to use the WordPress admin email.', 'dragongate-portals'); ?></p>
			<?php
        }

        /**
         * POST handler for leftover pb_* → dg_* move. Null when the form was not submitted.
         *
         * @return array{moved: int, dropped: int}|null
         */
        private function maybe_promote_legacy_options() {
            if ( empty( $_POST[ self::PROMOTE_NONCE ] ) ) {
                return null;
            }
            if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
                return null;
            }
            if ( function_exists( 'check_admin_referer' ) ) {
                check_admin_referer( self::PROMOTE_NONCE );
            }
            return Portal_Options::promote();
        }

        /**
         * @param array{moved?: int, dropped?: int} $result Promote counts.
         * @return void
         */
        private function render_promote_notice( $result ) {
            $moved   = isset( $result[ Portal_Options::PROMOTE_MOVED ] ) ? (int) $result[ Portal_Options::PROMOTE_MOVED ] : 0;
            $dropped = isset( $result[ Portal_Options::PROMOTE_DROPPED ] ) ? (int) $result[ Portal_Options::PROMOTE_DROPPED ] : 0;
            $twins   = $moved + $dropped;
            if ( 0 === $twins ) {
                $message = __( 'No leftover pb_ options were in the database.', 'dragongate-portals' );
                $class   = 'notice-info';
            } else {
                $message = sprintf(
                    /* translators: 1: leftover pb_* keys, 2: copied onto empty dg_*, 3: dropped twins */
                    _n(
                        '%1$d leftover pb_ key processed. %2$d moved onto dg_. %3$d dropped because dg_ already had a value.',
                        '%1$d leftover pb_ keys processed. %2$d moved onto dg_. %3$d dropped because dg_ already had a value.',
                        $twins,
                        'dragongate-portals'
                    ),
                    $twins,
                    $moved,
                    $dropped
                );
                $class = 'notice-success';
            }
            echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        /**
         * Optional one-shot: move leftover pb_* keys onto dg_*.
         *
         * @return void
         */
        private function render_promote_legacy_form() {
            echo '<h2>' . esc_html__( 'Leftover pb_ options', 'dragongate-portals' ) . '</h2>';
            echo '<p>' . esc_html__(
                'Move leftover pb_ options to dg_. Only needed if an old pb_* option is still in the database.',
                'dragongate-portals'
            ) . '</p>';
            echo '<form method="post">';
            if ( function_exists( 'wp_nonce_field' ) ) {
                wp_nonce_field( self::PROMOTE_NONCE );
            }
            submit_button(
                __( 'Move leftover pb_ options to dg_', 'dragongate-portals' ),
                'secondary',
                self::PROMOTE_NONCE
            );
            echo '</form>';
        }

        public function settings_page_callback()
        {
            $promote_result = $this->maybe_promote_legacy_options();
            ?>
			<div class="wrap">
				<?php
				if ( class_exists( 'Portal_Google_Connect' ) ) {
					Portal_Google_Connect::render_checklist_if_needed( Portal_Google_Connect::SCREEN_SETTINGS );
				}
				if ( is_array( $promote_result ) ) {
					$this->render_promote_notice( $promote_result );
				}
				?>
				<h1><?php _e('Default Settings', 'dragongate-portals'); ?></h1>
				<form method="post" action="options.php">
					<?php
                    settings_fields('dg_settings_group');
            do_settings_sections('portal-default-settings');
            submit_button();
            ?>
				</form>
				<?php $this->render_promote_legacy_form(); ?>
			</div>
			<?php
        }

        public function enqueue_admin_scripts()
        {
            // Enqueue CSS and JS for protected code fields and URL validation
            wp_enqueue_style('dg-admin-css', plugins_url('../assets/admin.css', __FILE__), array(), DG_VERSION);
            wp_enqueue_script('dg-admin-js', plugins_url('../assets/admin.js', __FILE__), array( 'jquery' ), DG_VERSION, true);
            wp_enqueue_script('dg-url-validation-js', plugins_url('../assets/url-validation.js', __FILE__), array( 'jquery' ), DG_VERSION, true);
            
            // Localize the script with some data for translation or other dynamic values
            wp_localize_script(
                'dg-admin-js',
                'portalBuilderLocalize',
                array(
                    'removeText' => __('Remove', 'dragongate-portals' ),
                    'addText'    => __('Add Disclaimer', 'dragongate-portals' ),
                )
            );
            if ( class_exists( 'Portal_Google_Probe' ) ) {
                wp_localize_script(
                    'dg-admin-js',
                    'portalBuilderGoogleProbe',
                    array(
                        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                        'action'  => Portal_Google_Probe::AJAX_ACTION,
                        'nonce'   => wp_create_nonce( Portal_Google_Probe::NONCE_ACTION ),
                        'working' => __( 'Testing Google connection…', 'dragongate-portals' ),
                    )
                );
            }
        }

        public function validate_url_callback()
        {
            $url      = esc_url_raw($_POST['url']);
            $response = wp_remote_get($url);

            if (is_wp_error($response)) {
                wp_send_json_error(array( 'message' => 'Invalid URL' ));
            }

            $status_code = wp_remote_retrieve_response_code($response);

            if ($status_code === 200) {
                wp_send_json_success(array( 'message' => 'Valid URL' ));
            } else {
                wp_send_json_error(array( 'message' => 'Invalid URL' ));
            }
        }
    }
}
