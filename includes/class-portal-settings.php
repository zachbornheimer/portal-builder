<?php

if (! class_exists('Portal_Settings')) {

    class Portal_Settings
    {
        public function init()
        {
            add_action('admin_menu', array( $this, 'add_menu_pages' ));
            add_action('admin_init', array( $this, 'register_settings' ));
            add_action('admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ));
            add_action('wp_ajax_validate_url', array( $this, 'validate_url_callback' )); // AJAX callback for URL validation
        }

        public function add_menu_pages()
        {
            add_submenu_page(
                'edit.php?post_type=portal',
                __('Default Settings', 'portal-builder'),
                __('Default Settings', 'portal-builder'),
                'manage_options',
                'portal-default-settings',
                array( $this, 'settings_page_callback' )
            );

            add_submenu_page(
                'edit.php?post_type=portal', // The parent slug
                __('Google API Setup', 'portal-builder'), // Page title
                __('Google API Setup', 'portal-builder'), // Menu title
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
				<h1><?php _e('Google API Setup Instructions', 'portal-builder'); ?></h1>
				<p>Follow these steps to set up Google Drive and Google Sheets integration:</p>
				<ol>
					<li><?php _e('Go to the Google Cloud Console at <a href="https://console.cloud.google.com/" target="_blank">https://console.cloud.google.com/</a>.', 'portal-builder'); ?></li>
					<li><?php _e('Create a new project or select an existing one.', 'portal-builder'); ?></li>
					<li><?php _e('Enable the Google Drive and Google Sheets APIs.', 'portal-builder'); ?></li>
					<li><?php _e('Navigate to "IAM & Admin" > "Service Accounts".', 'portal-builder'); ?></li>
					<li><?php _e('Click "Create Service Account" and provide a name and description.', 'portal-builder'); ?></li>
					<li><?php _e('Assign the "Storage Admin" role to the service account.', 'portal-builder'); ?></li>
					<li><?php _e('Under "Keys", click "Add Key" > "Create New Key" and select "JSON".', 'portal-builder'); ?></li>
					<li><?php _e('Download the JSON file containing your service account credentials.', 'portal-builder'); ?></li>
					<li><?php _e('Share your Google Drive folders and Google Sheets with the service account email found in the JSON file.', 'portal-builder'); ?></li>
					<li><?php _e('In the WordPress admin dashboard, go to the plugin settings and paste the JSON content into the appropriate fields.', 'portal-builder'); ?></li>
				</ol>
				<p><?php _e('Once configured, you can test the setup by performing a simple operation, such as listing files in a Google Drive folder or appending a row to a Google Sheet.', 'portal-builder'); ?></p>
			</div>
			<?php
        }

        public function register_settings()
        {
            // Register the settings
            register_setting('pb_settings_group', 'pb_google_secret_key');
            register_setting('pb_settings_group', 'pb_county_region_script');
            register_setting(
                'pb_settings_group',
                'pb_legal_disclaimers',
                array(
                    'sanitize_callback' => 'wp_json_encode',
                    'default'           => json_encode(array()),
                )
            );
            register_setting('pb_settings_group', 'pb_recaptcha_sitekey');
            register_setting('pb_settings_group', 'pb_exiftool_path');
            register_setting('pb_settings_group', 'pb_qpdf_path');
            register_setting('pb_settings_group', 'pb_eyed3_path');
            register_setting('pb_settings_group', 'pb_lame_path');
            register_setting('pb_settings_group', 'pb_perl_path');
            register_setting('pb_settings_group', 'pb_receipt_generator');
            register_setting('pb_settings_group', 'pb_receipt_from_email');
            register_setting('pb_settings_group', 'pb_receipt_from_name');
            register_setting('pb_settings_group', 'pb_receipt_subject');
            register_setting(
                'pb_settings_group',
                'pb_receipt_body',
                array(
                    'sanitize_callback' => 'wp_kses_post', // This allows standard HTML tags
                )
            );
            register_setting('pb_settings_group', 'pb_receipt_alt_body');

            $this->register_site_default_settings();

            // Add a section for Google API keys
            add_settings_section(
                'pb_google_api_section',
                __('Google API Keys', 'portal-builder'),
                array( $this, 'google_api_section_callback' ),
                'portal-default-settings'
            );

            add_settings_field(
                'pb_google_secret_key',
                __('Google Secret Key', 'portal-builder'),
                array( $this, 'render_google_secret_key_field' ),
                'portal-default-settings',
                'pb_google_api_section'
            );
            add_settings_field(
                'pb_google_access_key',
                __('Google Access Key', 'portal-builder'),
                array( $this, 'render_google_access_key_field' ),
                'portal-default-settings',
                'pb_google_api_section'
            );

            // Add a section for the JS URL
            add_settings_section(
                'pb_general_settings_section',
                __('General Settings', 'portal-builder'),
                null,
                'portal-default-settings'
            );

            // Add a section for the JS URL
            add_settings_section(
                'pb_agreements_section',
                __('Agreements Settings', 'portal-builder'),
                null,
                'portal-default-settings'
            );

            // Add a section for the JS URL
            add_settings_section(
                'pb_server_section',
                __('Server Settings', 'portal-builder'),
                null,
                'portal-default-settings'
            );

            // Add a section for the JS URL
            add_settings_section(
                'pb_email_section',
                __('Email Settings', 'portal-builder'),
                null,
                'portal-default-settings'
            );

            // Add field for County/Region Dropdown Menu JS Script URL
            add_settings_field(
                'pb_county_region_script',
                __('County/Region Dropdown Menu JS Script', 'portal-builder'),
                array( $this, 'render_county_region_script_field' ),
                'portal-default-settings',
                'pb_general_settings_section'
            );

            add_settings_field(
                'pb_receipt_generator',
                __('Receipt Viewer PHP URL', 'portal-builder'),
                array( $this, 'render_receipt_generator_field' ),
                'portal-default-settings',
                'pb_general_settings_section'
            );

            add_settings_field(
                'pb_recaptcha_sitekey',
                __('reCAPTCHA Site Key', 'portal-builder'),
                array( $this, 'render_recaptcha_sitekey_field' ),
                'portal-default-settings',
                'pb_general_settings_section'
            );

            add_settings_field(
                'pb_legal_disclaimers',
                __('Legal Disclaimers', 'portal-builder'),
                array( $this, 'render_legal_disclaimers_field' ),
                'portal-default-settings',
                'pb_agreements_section'
            );

            add_settings_field(
                'pb_exiftool_path',
                __('Path to exiftool', 'portal-builder'),
                array( $this, 'render_exiftool_path_field' ),
                'portal-default-settings',
                'pb_server_section'
            );
            add_settings_field(
                'pb_qpdf_path',
                __('Path to qpdf', 'portal-builder'),
                array( $this, 'render_qpdf_path_field' ),
                'portal-default-settings',
                'pb_server_section'
            );
            add_settings_field(
                'pb_eyed3_path',
                __('Path to eyed3', 'portal-builder'),
                array( $this, 'render_eyed3_path_field' ),
                'portal-default-settings',
                'pb_server_section'
            );
            add_settings_field(
                'pb_lame_path',
                __('Path to lame', 'portal-builder'),
                array( $this, 'render_lame_path_field' ),
                'portal-default-settings',
                'pb_server_section'
            );
            add_settings_field(
                'pb_perl_path',
                __('Path to perl', 'portal-builder'),
                array( $this, 'render_perl_path_field' ),
                'portal-default-settings',
                'pb_server_section'
            );

            add_settings_field(
                'pb_receipt_from_email',
                __('Receipt From Email', 'portal-builder'),
                array( $this, 'render_receipt_from_email_field' ),
                'portal-default-settings',
                'pb_email_section'
            );
            add_settings_field(
                'pb_receipt_from_name',
                __('Receipt From Name', 'portal-builder'),
                array( $this, 'render_receipt_from_name_field' ),
                'portal-default-settings',
                'pb_email_section'
            );
            add_settings_field(
                'pb_receipt_subject',
                __('Receipt Subject', 'portal-builder'),
                array( $this, 'render_receipt_subject_field' ),
                'portal-default-settings',
                'pb_email_section'
            );
            add_settings_field(
                'pb_receipt_body',
                __('Receipt Body', 'portal-builder'),
                array( $this, 'render_receipt_body_field' ),
                'portal-default-settings',
                'pb_email_section'
            );
            add_settings_field(
                'pb_receipt_alt_body',
                __('Receipt Plain-Text Body', 'portal-builder'),
                array( $this, 'render_receipt_alt_body_field' ),
                'portal-default-settings',
                'pb_email_section'
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
            register_setting( 'pb_settings_group', 'pb_default_anonymize', $bool );
            register_setting( 'pb_settings_group', 'pb_default_free_for_members', $bool );
            register_setting(
                'pb_settings_group',
                'pb_default_anonymize_endpoint',
                array(
                    'type'              => 'string',
                    'sanitize_callback' => array( $this, 'sanitize_default_url' ),
                    'default'           => '',
                )
            );
            register_setting(
                'pb_settings_group',
                'pb_default_anonymize_api_key',
                array(
                    'type'              => 'string',
                    'sanitize_callback' => array( $this, 'sanitize_default_api_key' ),
                    'default'           => '',
                )
            );
            register_setting(
                'pb_settings_group',
                'pb_default_anonymize_ack',
                array(
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                    'default'           => '',
                )
            );
            register_setting(
                'pb_settings_group',
                'pb_default_guidelines_url',
                array(
                    'type'              => 'string',
                    'sanitize_callback' => array( $this, 'sanitize_default_url' ),
                    'default'           => '',
                )
            );
            register_setting(
                'pb_settings_group',
                'pb_default_timezone',
                array(
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                )
            );
            register_setting(
                'pb_settings_group',
                'pb_default_brand',
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
                'pb_anonymizer_defaults_section',
                __( 'Anonymizer & defaults', 'portal-builder' ),
                array( $this, 'anonymizer_defaults_section_callback' ),
                'portal-default-settings'
            );
            add_settings_field(
                'pb_default_anonymize',
                __( 'Anonymize files', 'portal-builder' ),
                array( $this, 'render_default_anonymize_field' ),
                'portal-default-settings',
                'pb_anonymizer_defaults_section'
            );
            add_settings_field(
                'pb_default_anonymize_endpoint',
                __( 'Anonymize API URL', 'portal-builder' ),
                array( $this, 'render_default_anonymize_endpoint_field' ),
                'portal-default-settings',
                'pb_anonymizer_defaults_section'
            );
            add_settings_field(
                'pb_default_anonymize_api_key',
                __( 'Anonymize API key', 'portal-builder' ),
                array( $this, 'render_default_anonymize_api_key_field' ),
                'portal-default-settings',
                'pb_anonymizer_defaults_section'
            );
            add_settings_field(
                'pb_default_anonymize_ack',
                __( 'Anonymize certification', 'portal-builder' ),
                array( $this, 'render_default_anonymize_ack_field' ),
                'portal-default-settings',
                'pb_anonymizer_defaults_section'
            );
            add_settings_field(
                'pb_default_guidelines_url',
                __( 'Guidelines URL', 'portal-builder' ),
                array( $this, 'render_default_guidelines_url_field' ),
                'portal-default-settings',
                'pb_anonymizer_defaults_section'
            );
            add_settings_field(
                'pb_default_free_for_members',
                __( 'Free for members', 'portal-builder' ),
                array( $this, 'render_default_free_for_members_field' ),
                'portal-default-settings',
                'pb_anonymizer_defaults_section'
            );
            add_settings_field(
                'pb_default_timezone',
                __( 'Timezone', 'portal-builder' ),
                array( $this, 'render_default_timezone_field' ),
                'portal-default-settings',
                'pb_anonymizer_defaults_section'
            );

            add_settings_section(
                'pb_white_label_section',
                __( 'White label', 'portal-builder' ),
                array( $this, 'white_label_section_callback' ),
                'portal-default-settings'
            );
            add_settings_field(
                'pb_default_brand',
                __( 'Look', 'portal-builder' ),
                array( $this, 'render_default_brand_field' ),
                'portal-default-settings',
                'pb_white_label_section'
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
                'Portals inherit these unless they override them. A blank Anonymize API URL uses All Intersections. A custom host is allowed if it speaks the same contract.',
                'portal-builder'
            ) . ' <a href="' . esc_url( $contract ) . '">' . esc_html__( 'Anonymizer contract', 'portal-builder' ) . '</a>.</p>';
            echo '<p class="description">' . esc_html(
                sprintf(
                    /* translators: %s: built-in All Intersections URL */
                    __( 'Built-in endpoint if the site URL is also blank: %s', 'portal-builder' ),
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
         * Blank keeps the stored key so the password field never has to echo it.
         *
         * @param mixed $value Posted value.
         * @return string
         */
        public function sanitize_default_api_key( $value ) {
            $existing = function_exists( 'get_option' )
                ? (string) get_option( 'pb_default_anonymize_api_key', '' )
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
            $on = ! empty( get_option( 'pb_default_anonymize', false ) );
            echo '<label><input type="hidden" name="pb_default_anonymize" value="0" />';
            echo '<input type="checkbox" name="pb_default_anonymize" value="1" ' . checked( $on, true, false ) . ' /> ';
            echo esc_html__( 'Anonymize files for adjudicators by default', 'portal-builder' ) . '</label>';
        }

        /**
         * @return void
         */
        public function render_default_anonymize_endpoint_field() {
            $value   = (string) get_option( 'pb_default_anonymize_endpoint', '' );
            $builtin = class_exists( 'Portal_Site_Defaults' )
                ? Portal_Site_Defaults::BUILTIN_ANONYMIZE_ENDPOINT
                : 'https://api.allintersections.com';
            echo '<input type="url" name="pb_default_anonymize_endpoint" class="regular-text" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $builtin ) . '" />';
            echo '<p class="description">' . esc_html__( 'Leave blank to use All Intersections. Custom hosts must implement the same two POSTs.', 'portal-builder' ) . '</p>';
        }

        /**
         * @return void
         */
        public function render_default_anonymize_api_key_field() {
            $stored = (string) get_option( 'pb_default_anonymize_api_key', '' );
            $hint   = class_exists( 'Portal_Site_Defaults' )
                ? Portal_Site_Defaults::key_hint( $stored )
                : '';
            $placeholder = '' !== $hint
                ? sprintf(
                    /* translators: %s: last-four hint such as ••••1234 */
                    __( 'Saved (%s) — leave blank to keep', 'portal-builder' ),
                    $hint
                )
                : __( 'Paste API key', 'portal-builder' );
            echo '<input type="password" name="pb_default_anonymize_api_key" class="regular-text" value="" autocomplete="new-password" placeholder="' . esc_attr( $placeholder ) . '" />';
            echo '<p class="description">' . esc_html__( 'Never shown on the public form. Leave blank to keep the saved key.', 'portal-builder' ) . '</p>';
        }

        /**
         * @return void
         */
        public function render_default_anonymize_ack_field() {
            $value   = (string) get_option( 'pb_default_anonymize_ack', '' );
            $builtin = class_exists( 'Portal_Site_Defaults' )
                ? Portal_Site_Defaults::BUILTIN_ANONYMIZE_ACK
                : 'I certify that my scores and recordings exclude any information that might identify the composer but do include title of work, instrumentation, and duration.';
            echo '<textarea name="pb_default_anonymize_ack" class="large-text" rows="3" placeholder="' . esc_attr( $builtin ) . '">' . esc_textarea( $value ) . '</textarea>';
            echo '<p class="description">' . esc_html__( 'Shown as a required checkbox when anonymize is on. Leave blank to use the built-in sentence. Portals may override.', 'portal-builder' ) . '</p>';
        }

        /**
         * @return void
         */
        public function render_default_guidelines_url_field() {
            $value = (string) get_option( 'pb_default_guidelines_url', '' );
            echo '<input type="url" name="pb_default_guidelines_url" class="regular-text" value="' . esc_attr( $value ) . '" placeholder="https://" />';
        }

        /**
         * @return void
         */
        public function render_default_free_for_members_field() {
            $on = ! empty( get_option( 'pb_default_free_for_members', false ) );
            echo '<label><input type="hidden" name="pb_default_free_for_members" value="0" />';
            echo '<input type="checkbox" name="pb_default_free_for_members" value="1" ' . checked( $on, true, false ) . ' /> ';
            echo esc_html__( 'Waive the application fee for members by default', 'portal-builder' ) . '</label>';
        }

        /**
         * @return void
         */
        public function render_default_timezone_field() {
            $value = (string) get_option( 'pb_default_timezone', '' );
            echo '<input type="text" name="pb_default_timezone" class="regular-text" value="' . esc_attr( $value ) . '" placeholder="America/New_York" />';
            echo '<p class="description">' . esc_html__( 'IANA timezone. Portals inherit this when their timezone field is blank.', 'portal-builder' ) . '</p>';
        }

        /**
         * @return void
         */
        public function white_label_section_callback() {
            echo '<p>' . esc_html__(
                'This look applies to public application pages. The setup wizard stays DragonGate.',
                'portal-builder'
            ) . '</p>';
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
            $brand  = get_option( 'pb_default_brand', array() );
            $brand  = is_array( $brand ) ? $brand : array();
            $preset = isset( $brand['preset'] ) ? (string) $brand['preset'] : 'product';
            $choices = array(
                'product' => __( 'DragonGate (product tokens)', 'portal-builder' ),
                'custom'  => __( 'Custom', 'portal-builder' ),
                'isjac'   => __( 'Example host (ISJAC guide)', 'portal-builder' ),
            );
            echo '<p><label for="pb_default_brand_preset">' . esc_html__( 'Preset', 'portal-builder' ) . '</label><br />';
            echo '<select name="pb_default_brand[preset]" id="pb_default_brand_preset">';
            foreach ( $choices as $value => $label ) {
                echo '<option value="' . esc_attr( $value ) . '" ' . selected( $preset, $value, false ) . '>' . esc_html( $label ) . '</option>';
            }
            echo '</select></p>';

            $colors = array(
                'ink'         => __( 'Ink', 'portal-builder' ),
                'paper'       => __( 'Paper', 'portal-builder' ),
                'accent'      => __( 'Accent', 'portal-builder' ),
                'accentHover' => __( 'Accent hover', 'portal-builder' ),
                'wash'        => __( 'Wash', 'portal-builder' ),
                'eyebrow'     => __( 'Eyebrow', 'portal-builder' ),
                'rule'        => __( 'Rule', 'portal-builder' ),
                'error'       => __( 'Error', 'portal-builder' ),
                'success'     => __( 'Success', 'portal-builder' ),
            );
            echo '<div class="pb-brand-colors" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(12rem,1fr));gap:0.75rem;max-width:48rem;">';
            foreach ( $colors as $key => $label ) {
                $hex = isset( $brand[ $key ] ) ? (string) $brand[ $key ] : '';
                echo '<label>' . esc_html( $label ) . '<br />';
                echo '<input type="text" class="regular-text pb-brand-token" data-brand-key="' . esc_attr( $key ) . '" name="pb_default_brand[' . esc_attr( $key ) . ']" value="' . esc_attr( $hex ) . '" placeholder="#000000" /></label>';
            }
            echo '</div>';

            $fonts = array(
                'fontDisplay' => __( 'Display font', 'portal-builder' ),
                'fontUi'      => __( 'UI font', 'portal-builder' ),
                'fontMono'    => __( 'Mono font', 'portal-builder' ),
                'fontsUrl'    => __( 'Fonts stylesheet URL', 'portal-builder' ),
            );
            echo '<div style="margin-top:1rem;max-width:40rem;">';
            foreach ( $fonts as $key => $label ) {
                $val = isset( $brand[ $key ] ) ? (string) $brand[ $key ] : '';
                echo '<p><label>' . esc_html( $label ) . '<br />';
                echo '<input type="text" class="large-text pb-brand-token" data-brand-key="' . esc_attr( $key ) . '" name="pb_default_brand[' . esc_attr( $key ) . ']" value="' . esc_attr( $val ) . '" /></label></p>';
            }
            $logo = isset( $brand['logoUrl'] ) ? (string) $brand['logoUrl'] : '';
            echo '<p><label>' . esc_html__( 'Logo URL', 'portal-builder' ) . '<br />';
            echo '<input type="url" class="large-text pb-brand-token" data-brand-key="logoUrl" name="pb_default_brand[logoUrl]" value="' . esc_attr( $logo ) . '" placeholder="https://" /></label></p>';
            $hide = array_key_exists( 'hideLogo', $brand )
                ? ! empty( $brand['hideLogo'] )
                : ( 'isjac' === $preset );
            echo '<p><label><input type="hidden" name="pb_default_brand[hideLogo]" value="0" />';
            echo '<input type="checkbox" name="pb_default_brand[hideLogo]" value="1" ' . checked( $hide, true, false ) . ' /> ';
            echo esc_html__( 'Hide the seal on public applications', 'portal-builder' ) . '</label></p>';
            echo '</div>';
            echo '<script>(function(){var s=document.getElementById("pb_default_brand_preset");var j=document.getElementById("pb-brand-isjac");if(!s||!j)return;var example={};try{example=JSON.parse(j.textContent||"{}");}catch(e){example={};}s.addEventListener("change",function(){if(s.value!=="isjac")return;document.querySelectorAll(".pb-brand-token").forEach(function(el){var k=el.getAttribute("data-brand-key");if(k&&example[k])el.value=example[k];});});})();</script>';
        }

        public function google_api_section_callback()
        {
            $setup_url = admin_url('edit.php?post_type=portal&page=portal-google-api-setup');
            echo '<p>' . __('For detailed setup instructions on configuring Google API credentials, please visit the ', 'portal-builder') .
                '<a href="' . esc_url($setup_url) . '">' . __('Google API Setup Instructions', 'portal-builder') . '</a>' .
                __(' page.', 'portal-builder') . '</p>';
        }

        public function render_google_secret_key_field()
        {
            $value = get_option('pb_google_secret_key', '');
            echo '<div class="pb-protected-wrapper"><textarea name="pb_google_secret_key" id="pb_google_secret_key" class="pb-protected-code-field" rows="10" cols="50">' . esc_textarea($value) . '</textarea></div>';
        }

        public function render_google_access_key_field()
        {
            $value = get_option('pb_google_access_key', '');
            echo '<div class="pb-protected-wrapper"><textarea name="pb_google_access_key" id="pb_google_access_key" class="pb-protected-code-field" rows="10" cols="50">' . esc_textarea($value) . '</textarea></div>';
        }

        public function render_county_region_script_field()
        {
            $value = get_option('pb_county_region_script', '');
            ?>
			<input type="url" name="pb_county_region_script" id="pb_county_region_script" class="form-control monospace-url" value="<?php echo esc_url($value); ?>" placeholder="<?php _e('Enter the URL for the JS script', 'portal-builder'); ?>" />
			<div id="pb_county_region_script-validation" class="url-validation empty"></div>
			<?php
        }
        public function render_receipt_generator_field()
        {
            $value = get_option('pb_receipt_generator', '');
            ?>
			<input type="url" name="pb_receipt_generator" id="pb_receipt_generator" class="form-control monospace-url" value="<?php echo esc_url($value); ?>" placeholder="<?php _e('Enter the URL for the file', 'portal-builder'); ?>" />
			<div id="pb_receipt_generator-validation" class="url-validation empty"></div>
			<?php
        }

        public function render_recaptcha_sitekey_field()
        {
            $value = get_option('pb_recaptcha_sitekey', '');
            ?>
			<input type="text" name="pb_recaptcha_sitekey" id="pb_recaptcha_sitekey" class="form-control monospace" style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e('reCAPTCHA sitekey', 'portal-builder'); ?>" />

			<?php
        }

        public function render_legal_disclaimers_field()
        {
            $meta_key = 'pb_legal_disclaimers';

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
            $value = get_option('pb_exiftool_path', '');
            ?>
			<input type="text" name="pb_exiftool_path" id="pb_exiftool_path" class="form-control monospace" style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e('/usr/local/bin/exiftool', 'portal-builder'); ?>" />

			<?php
        }

        public function render_qpdf_path_field()
        {
            $value = get_option('pb_qpdf_path', '');
            ?>
			<input type="text" name="pb_qpdf_path" id="pb_qpdf_path" class="form-control monospace" style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e('/usr/local/bin/qpdf', 'portal-builder'); ?>" />

			<?php
        }

        public function render_lame_path_field()
        {
            $value = get_option('pb_lame_path', '');
            ?>
			<input type="text" name="pb_lame_path" id="pb_lame_path" class="form-control monospace" style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e('/usr/local/bin/lame', 'portal-builder'); ?>" />

			<?php
        }

        public function render_eyed3_path_field()
        {
            $value = get_option('pb_eyed3_path', '');
            ?>
			<input type="text" name="pb_eyed3_path" id="pb_eyed3_path" class="form-control monospace" style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e('/usr/local/bin/eyed3', 'portal-builder'); ?>" />

			<?php
        }

        public function render_perl_path_field()
        {
            $value = get_option('pb_perl_path', '');
            ?>
			<input type="text" name="pb_perl_path" id="pb_perl_path" class="form-control monospace" style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e('/usr/local/bin/perl', 'portal-builder'); ?>" />

			<?php
        }

        public function render_receipt_from_email_field()
        {
            $value = get_option('pb_receipt_from_email', '');
            ?>
			<input type="email" name="pb_receipt_from_email" id="pb_receipt_from_email" class="form-control " style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e('submissions@applications.yoursite.com', 'portal-builder'); ?>" />

			<?php
        }
        public function render_receipt_from_name_field()
        {
            $value = get_option('pb_receipt_from_name', '');
            ?>
			<input type="text" name="pb_receipt_from_name" id="pb_receipt_from_name" class="form-control " style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e('Automated Submission Receipts', 'portal-builder'); ?>" />

			<?php
        }
        public function render_receipt_subject_field()
        {
            $value = get_option('pb_receipt_subject', '');
            ?>
			<input type="text" name="pb_receipt_subject" id="pb_receipt_subject" class="form-control " style="width:100%" value="<?php echo $value; ?>" placeholder="<?php _e('{{ $portalName }} Application Receipt', 'portal-builder'); ?>" />

			<?php
        }

        public function render_receipt_body_field()
        {
            // Retrieve the current value of the option
            $value = get_option('pb_receipt_body', '');

            // Define settings for the wp_editor
            $editor_settings = array(
                'textarea_name' => 'pb_receipt_body',  // The name of the textarea in the form
                'textarea_rows' => 10,  // Number of rows in the editor
                'media_buttons' => false,  // Whether to show the media insert/upload button
                'teeny'         => true,  // Whether to use the simplified version of the editor
                'quicktags'     => true,  // Whether to enable quicktags (HTML view)
            );

            // Render the wp_editor
            wp_editor($value, 'pb_receipt_body', $editor_settings);
        }

        public function render_receipt_alt_body_field()
        {
            $value = get_option('pb_receipt_alt_body', '');
            ?>
			<textarea rows="5" style="width:100%" name="pb_receipt_alt_body" id="pb_receipt_alt_body" class="form-control" placeholder="<?php _e('Your {{$receiptLink }} ...', 'portal-builder'); ?>"><?php echo $value; ?></textarea>

			<?php
        }

        public function settings_page_callback()
        {
            ?>
			<div class="wrap">
				<h1><?php _e('Default Settings', 'portal-builder'); ?></h1>
				<form method="post" action="options.php">
					<?php
                    settings_fields('pb_settings_group');
            do_settings_sections('portal-default-settings');
            submit_button();
            ?>
				</form>
			</div>
			<?php
        }

        public function enqueue_admin_scripts()
        {
            // Enqueue CSS and JS for protected code fields and URL validation
            wp_enqueue_style('pb-admin-css', plugins_url('../assets/admin.css', __FILE__), array(), PB_VERSION);
            wp_enqueue_script('pb-admin-js', plugins_url('../assets/admin.js', __FILE__), array( 'jquery' ), PB_VERSION, true);
            wp_enqueue_script('pb-url-validation-js', plugins_url('../assets/url-validation.js', __FILE__), array( 'jquery' ), PB_VERSION, true);
            
            // Localize the script with some data for translation or other dynamic values
            wp_localize_script(
                'pb-admin-js',
                'portalBuilderLocalize',
                array(
                    'removeText' => __('Remove', 'portal-builder'),
                    'addText'    => __('Add Disclaimer', 'portal-builder'),
                )
            );
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
