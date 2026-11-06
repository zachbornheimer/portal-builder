<?php
/**
 * Plugin Name: Vendor guard fixture
 */

require_once plugin_dir_path( __FILE__ ) . 'vendor-prefixed/autoload.php';

// Third-party libraries load only from vendor-prefixed/; never require vendor/autoload.php.
