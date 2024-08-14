<?php

if ( ! class_exists( 'Portal_Builder' ) ) {

	class Portal_Builder {

		public function init() {
			// Initialize custom post type
			$portal_post_type = new Portal_Post_Type();
			$portal_post_type->register_post_type();

			// Initialize settings page
			$portal_settings = new Portal_Settings();
			$portal_settings->init();

			// Initialize portal meta
			$portal_meta = new Portal_Meta();
			$portal_meta->init();
		}
	}

}
