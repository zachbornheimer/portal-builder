<?php

// Load every shortcode in the shortcodes folder. Each file is named literally so no
// dynamic path reaches require_once; add a line here when adding a shortcode file.
require_once plugin_dir_path( __FILE__ ) . 'shortcodes/dg-portal-status.php';
require_once plugin_dir_path( __FILE__ ) . 'shortcodes/dg-portal-when.php';
require_once plugin_dir_path( __FILE__ ) . 'shortcodes/pb_application_title.php';
require_once plugin_dir_path( __FILE__ ) . 'shortcodes/pb_file.php';
require_once plugin_dir_path( __FILE__ ) . 'shortcodes/pb_formend.php';
require_once plugin_dir_path( __FILE__ ) . 'shortcodes/pb_formstart.php';
require_once plugin_dir_path( __FILE__ ) . 'shortcodes/pb_full_span.php';
require_once plugin_dir_path( __FILE__ ) . 'shortcodes/pb_group_selection.php';
require_once plugin_dir_path( __FILE__ ) . 'shortcodes/pb_group.php';
require_once plugin_dir_path( __FILE__ ) . 'shortcodes/pb_text.php';
require_once plugin_dir_path( __FILE__ ) . 'shortcodes/portal-applicant-information.php';
require_once plugin_dir_path( __FILE__ ) . 'shortcodes/portal-application-agreements.php';
require_once plugin_dir_path( __FILE__ ) . 'shortcodes/portal-application-file-review.php';
require_once plugin_dir_path( __FILE__ ) . 'shortcodes/portal-application-upload-notes.php';
require_once plugin_dir_path( __FILE__ ) . 'shortcodes/portal-recaptcha.php';
