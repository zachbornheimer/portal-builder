<?php

if ( ! class_exists( 'Portal_Post_Type' ) ) {

	class Portal_Post_Type {

		public function register_post_type() {
			add_action( 'init', array( $this, 'create_post_type' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_filter( 'post_row_actions', array( $this, 'add_duplicate_action' ), 10, 2 );
			add_action( 'wp_ajax_duplicate_portal', array( $this, 'duplicate_portal_ajax' ) );
		}

		public function create_post_type() {
		$labels = array(
			'name'               => _x( 'DragonGate Portals', 'Post Type General Name', 'dragongate-portals' ),
			'singular_name'      => _x( 'Portal', 'Post Type Singular Name', 'dragongate-portals' ),
			'menu_name'          => __( 'Portals', 'dragongate-portals' ),
			'name_admin_bar'     => __( 'Portal', 'dragongate-portals' ),
			'add_new'            => __( 'Add New Portal', 'dragongate-portals' ),
			'add_new_item'       => __( 'Add New Portal', 'dragongate-portals' ),
			'edit_item'          => __( 'Edit Portal', 'dragongate-portals' ),
			'new_item'           => __( 'New Portal', 'dragongate-portals' ),
			'view_item'          => __( 'View Portal', 'dragongate-portals' ),
			'all_items'          => __( 'All Portals', 'dragongate-portals' ),
			'search_items'       => __( 'Search DragonGate Portals', 'dragongate-portals' ),
			'not_found'          => __( 'No DragonGate Portals found.', 'dragongate-portals' ),
			'not_found_in_trash' => __( 'No DragonGate Portals found in Trash.', 'dragongate-portals' ),
		);

		$args = array(
			'labels'          => $labels,
			'public'          => true,
			'has_archive'     => true,
			'show_in_menu'    => true,
			'show_in_rest'    => true, // required for harness seed/cleanup + future REST clients
			'menu_icon'       => $this->get_menu_icon_data_uri(),
			'supports'        => array( 'title', 'editor', 'thumbnail' ),
			'capability_type' => 'post',
			'rewrite'         => array( 'slug' => 'portal' ),
			'description'     => __( 'DragonGate Portals - Simply collect data, save it locally, and sync across Google Sheets and Drive. Build custom forms that automatically organize submissions and streamline your data workflow.', 'dragongate-portals' ),
		);

			register_post_type( 'portal', $args );
		}

		/**
		 * Get the menu icon as a data URI for proper WordPress display
		 *
		 * @return string Data URI for the SVG icon
		 */
		private function get_menu_icon_data_uri() {
			$svg_content = '<?xml version="1.0" encoding="UTF-8"?>
<svg id="Layer_1" data-name="Layer 1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 610.04 610.04" width="20" height="20">
  <defs>
    <style>
      .cls-1 {
        fill: url(#linear-gradient-2);
      }

      .cls-2 {
        fill: url(#linear-gradient);
      }

      .cls-3 {
        fill: #fff;
      }
    </style>
    <linearGradient id="linear-gradient" x1="144.84" y1="240" x2="464.18" y2="240" gradientUnits="userSpaceOnUse">
      <stop offset="0" stop-color="#000" stop-opacity=".9"/>
      <stop offset="1" stop-color="#000"/>
    </linearGradient>
    <linearGradient id="linear-gradient-2" x1="0" y1="466.52" x2="362.95" y2="466.52" gradientUnits="userSpaceOnUse">
      <stop offset="0" stop-color="#000"/>
      <stop offset="1" stop-color="#000" stop-opacity=".9"/>
    </linearGradient>
  </defs>
  <g transform="translate(72.93, 0)">
  <path d="M140.37,485.99c-4.66,6.72-1.15,15.04-2.95,23.05-1.23,5.49-4.33,10.77-8.58,14.48-4.1,1.71-27.36-13.27-28.95-19.06l.02-233.02c-.85-6.39-1.69-12.78-4.26-18.74-1.22-2.83-4.05-5.48-4.77-7.24-1.21-2.96,2.32-.48,2.49-.48h233c9.38,0,20.5,16.6,20.5,25.5v214l-1.5,1.5h-205ZM285.58,312.19l-83.21,82.78-35.22-34.81c-1.5-.49-2.89-.2-4.33.27-2.39.77-15.88,15.76-15.88,18.06l.67,2.22,50.24,50.8c1.89,1.82,4.41,3.31,6.99,1.99l100.1-100.93c1.49-2.22,1.28-3.92-.02-6.14-.54-.92-13.17-13.51-14.06-13.92-1.68-.77-3.52-.86-5.27-.33Z"/>
  <path class="cls-2" d="M364.87,358.49c.38-1.74,6.72-9.91,8.18-12.82,23.52-46.96,21.05-108.73-9.36-151.99-8.15-11.59-19.58-24.28-32.81-29.68,1.82,23.1-10.62,35.17-33.5,33.98-24.51-1.27-37.93-8.43-64.69-3.67-19.97,3.55-52.78,14.59-53.82,38.68-26.07-2.67-38.98-40.06-32.27-61.78,4.86-15.74,6.48-8.09,15.02-6.46,16.42,3.14,35.5-17.15,39.78-31.74,5.97-20.36,4.41-20.39,20.46-35.54,18.17-17.15,39.2-33.18,58.03-49.97,9.1-8.11,17.16-17.58,26.51-25.49,10.12-8.55,20.46-15.49,31.98-22.02-.26,4.28-2.27,9.19-3.76,13.24-8.37,22.75-19.73,43.31-37.75,59.74,37.36-6.87,73.47-21.58,111.99-20.98l-23.31,14.18c-8.24,7.06-20.68,13.01-23.56,24.1.65,2.55,29.37,19.49,34.18,22.9,26.16,18.55,49.08,41.86,59.69,72.8-9.27-4.04-18.6-8.31-28.99-8.5,46.65,72.15,51.14,168.04,4.41,241.42-15.76,24.74-39.27,49.24-66.42,61.08v-121.5ZM268.39,121.04c-10.43,3.65-41.17,3.52-40.44,19.38.41,8.98,11.28,10.47,18.34,9.49,11.47-1.59,18.18-17.77,23.19-26.84l-1.08-2.03ZM169.6,186.16c-4.74.97-8.02,8.15-4.21,11.84,5.69-.08,13.34-13.7,4.21-11.84Z"/>
  <path class="cls-1" d="M79.87,322.99v73.5c-34.67,30.36-29.57,79.66-1.97,112.97,26.09,31.49,63.19,44.71,103.43,46.57,56.44,2.61,125.15-25.02,168.05,25.95,4.54,5.39,13.44,19.57,13.53,26.49.01,1.12.3,1.77-1.03,1.51-4.91-8.19-16.56-15.19-25.34-19.17-51.38-23.28-102.65.17-155.13,2.21-63.75,2.49-126.97-25.49-160.35-80.73C-21.18,442.39.96,352.07,79.87,322.99Z"/>
  <path d="M145.88,531.98c6.41-7.69,10.91-17.12,10.14-27.35l2.36-1.64,239.04.26c3.42.61-.7,13.5-1.77,16.02-4.32,10.15-14.63,15.75-25.29,16.71-14.96,1.34-39.83,1.01-55.03.06-9.17-.57-18.85-3.42-27.97-4.03-38.76-2.61-78.83,6.58-119.03,4.03-2.86-.18-21.6-2.97-22.46-4.05Z"/>
  <path d="M80.87,286.99H26.87v-19.5c0-.9,2.5-7.49,3.18-8.82,8.69-16.93,32.78-18.75,44.34-3.7,1.97,2.56,6.48,11.72,6.48,14.52v17.5Z"/>
  <path class="cls-3" d="M285.58,312.19c1.75-.53,3.6-.45,5.27.33.9.41,13.53,13,14.06,13.92,1.3,2.21,1.52,3.92.02,6.14l-100.1,100.93c-2.58,1.31-5.11-.17-6.99-1.99l-50.24-50.8-.67-2.22c0-2.31,13.49-17.29,15.88-18.06,1.44-.47,2.84-.75,4.33-.27l35.22,34.81,83.21-82.78Z"/>
  <path class="cls-3" d="M268.39,121.04l1.08,2.03c-5.01,9.06-11.72,25.24-23.19,26.84-7.06.98-17.92-.51-18.34-9.49-.73-15.86,30.02-15.73,40.44-19.38Z"/>
  <path class="cls-3" d="M169.6,186.16c9.14-1.86,1.48,11.76-4.21,11.84-3.81-3.69-.53-10.87,4.21-11.84Z"/>
  </g>
</svg>';

			// Encode the SVG as base64
			$base64 = base64_encode( $svg_content );
			
			// Return as data URI
			return 'data:image/svg+xml;base64,' . $base64;
		}

		public function enqueue_assets() {
			// Enqueue Dashicons for trash icon
			wp_enqueue_style( 'dashicons' );
			
			// Enqueue general admin scripts and styles
			wp_enqueue_script( 'pb-admin-js', plugins_url( '../assets/admin.js', __FILE__ ), array( 'jquery' ), PB_VERSION, true );
			wp_enqueue_style( 'pb-admin-css', plugins_url( '../assets/admin.css', __FILE__ ), array(), PB_VERSION );

			// Enqueue data table specific scripts and styles
			// wp_enqueue_script( 'pb-data-table-js', plugins_url( '../assets/data-table.js', __FILE__ ), array( 'jquery' ), PB_VERSION, true );
			wp_enqueue_style( 'pb-data-table-css', plugins_url( '../assets/data-table.css', __FILE__ ), array(), PB_VERSION );
		}

		/**
		 * Add duplicate action to portal post row actions
		 *
		 * @param array   $actions Existing row actions
		 * @param WP_Post $post The post object
		 * @return array Modified row actions
		 */
		public function add_duplicate_action( $actions, $post ) {
			if ( $post->post_type === 'portal' && current_user_can( 'edit_post', $post->ID ) ) {
				$duplicate_url = wp_nonce_url(
					admin_url( 'admin-ajax.php?action=duplicate_portal&post_id=' . $post->ID ),
					'duplicate_portal_' . $post->ID,
					'duplicate_nonce'
				);

				$actions['duplicate'] = sprintf(
					'<a href="%s" class="duplicate-portal" data-post-id="%d">%s</a>',
					esc_url( $duplicate_url ),
					$post->ID,
					__( 'Duplicate', 'dragongate-portals' )
				);
			}

			return $actions;
		}

		/**
		 * Handle AJAX request to duplicate a portal
		 */
		public function duplicate_portal_ajax() {
			// Verify nonce
			if ( ! isset( $_GET['duplicate_nonce'] ) || ! wp_verify_nonce( $_GET['duplicate_nonce'], 'duplicate_portal_' . $_GET['post_id'] ) ) {
				wp_die( __( 'Security check failed', 'dragongate-portals' ) );
			}

			// Check permissions
			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_die( __( 'You do not have permission to duplicate posts', 'dragongate-portals' ) );
			}

			$original_post_id = intval( $_GET['post_id'] );
			$original_post    = get_post( $original_post_id );

			if ( ! $original_post || $original_post->post_type !== 'portal' ) {
				wp_die( __( 'Invalid post', 'dragongate-portals' ) );
			}

			// Create the duplicate post
			$duplicate_post_id = $this->duplicate_portal( $original_post_id );

			if ( $duplicate_post_id ) {
				// Return JSON response with redirect URL
				wp_send_json_success(
					array(
						'redirect_url' => admin_url( 'post.php?post=' . $duplicate_post_id . '&action=edit&duplicated=1' ),
						'message'      => __( 'DragonGate Portal duplicated successfully!', 'dragongate-portals' ),
					)
				);
			} else {
				wp_send_json_error(
					array(
						'message' => __( 'Failed to duplicate DragonGate Portal', 'dragongate-portals' ),
					)
				);
			}
		}

		/**
		 * Duplicate a portal post with all its meta data
		 *
		 * @param int $post_id The ID of the post to duplicate
		 * @return int|false The ID of the new post or false on failure
		 */
		private function duplicate_portal( $post_id ) {
			$original_post = get_post( $post_id );

			if ( ! $original_post ) {
				return false;
			}

			// Create new post data
			$new_post_data = array(
				'post_title'   => $original_post->post_title . ' (Copy)',
				'post_content' => $original_post->post_content,
				'post_status'  => 'draft',
				'post_type'    => $original_post->post_type,
				'post_author'  => get_current_user_id(),
			);

			// Insert the new post
			$new_post_id = wp_insert_post( $new_post_data );

			if ( is_wp_error( $new_post_id ) ) {
				return false;
			}

			// Copy all meta fields
			$meta_fields = get_post_meta( $post_id );
			foreach ( $meta_fields as $key => $values ) {
				foreach ( $values as $value ) {
					add_post_meta( $new_post_id, $key, maybe_unserialize( $value ) );
				}
			}

			// Copy taxonomies
			$taxonomies = get_object_taxonomies( $original_post->post_type );
			foreach ( $taxonomies as $taxonomy ) {
				$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'slugs' ) );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					wp_set_object_terms( $new_post_id, $terms, $taxonomy );
				}
			}

			return $new_post_id;
		}
	}

}
