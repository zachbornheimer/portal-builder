<?php

if ( ! class_exists( 'Portal_Post_Type' ) ) {

	class Portal_Post_Type {

		public function register_post_type() {
			add_action( 'init', array( $this, 'create_post_type' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		public function create_post_type() {
			$labels = array(
				'name'               => _x( 'Portals', 'Post Type General Name', 'portal-builder' ),
				'singular_name'      => _x( 'Portal', 'Post Type Singular Name', 'portal-builder' ),
				'menu_name'          => __( 'Portals', 'portal-builder' ),
				'name_admin_bar'     => __( 'Portal', 'portal-builder' ),
				'add_new'            => __( 'Add New Portal', 'portal-builder' ),
				'add_new_item'       => __( 'Add New Portal', 'portal-builder' ),
				'edit_item'          => __( 'Edit Portal', 'portal-builder' ),
				'new_item'           => __( 'New Portal', 'portal-builder' ),
				'view_item'          => __( 'View Portal', 'portal-builder' ),
				'all_items'          => __( 'All Portals', 'portal-builder' ),
				'search_items'       => __( 'Search Portals', 'portal-builder' ),
				'not_found'          => __( 'No Portals found.', 'portal-builder' ),
				'not_found_in_trash' => __( 'No Portals found in Trash.', 'portal-builder' ),
			);

			$args = array(
				'labels'          => $labels,
				'public'          => true,
				'has_archive'     => true,
				'show_in_menu'    => true,
				'menu_icon'       => plugins_url( '../assets/icon.png', __FILE__ ),
				'supports'        => array( 'title', 'editor', 'thumbnail' ),
				'capability_type' => 'post',
				'rewrite'         => array( 'slug' => 'portal' ),
			);

			register_post_type( 'portal', $args );
		}

		public function enqueue_assets() {
			// Enqueue general admin scripts and styles
			wp_enqueue_script( 'pb-admin-js', plugins_url( '../assets/admin.js', __FILE__ ), array( 'jquery' ), null, true );
			wp_enqueue_style( 'pb-admin-css', plugins_url( '../assets/admin.css', __FILE__ ) );

			// Enqueue data table specific scripts and styles
			wp_enqueue_script( 'pb-data-table-js', plugins_url( '../assets/data-table.js', __FILE__ ), array( 'jquery' ), null, true );
			wp_enqueue_style( 'pb-data-table-css', plugins_url( '../assets/data-table.css', __FILE__ ) );
		}
	}

}
