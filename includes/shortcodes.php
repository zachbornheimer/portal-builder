<?php

// import all shortcodes in the shortcodes folder
foreach ( glob( plugin_dir_path( __FILE__ ) . 'shortcodes/*.php' ) as $shortcode ) {
	require_once $shortcode;
}
