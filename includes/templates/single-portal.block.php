<?php
/**
 * Block-theme template for single portal posts.
 * Reuses the classic single-portal markup (form chrome + definition/shortcode body).
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Block themes still need the same form shell; include the classic template body.
require plugin_dir_path( __FILE__ ) . 'single-portal.php';
