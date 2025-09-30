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
			'singular_name'      => _x( 'DragonGate Portal', 'Post Type Singular Name', 'dragongate-portals' ),
			'menu_name'          => __( 'Portals', 'dragongate-portals' ),
			'name_admin_bar'     => __( 'DragonGate Portal', 'dragongate-portals' ),
			'add_new'            => __( 'Add New Portal', 'dragongate-portals' ),
			'add_new_item'       => __( 'Add New DragonGate Portal', 'dragongate-portals' ),
			'edit_item'          => __( 'Edit DragonGate Portal', 'dragongate-portals' ),
			'new_item'           => __( 'New DragonGate Portal', 'dragongate-portals' ),
			'view_item'          => __( 'View DragonGate Portal', 'dragongate-portals' ),
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
			'menu_icon'       => plugins_url( '../assets/icon.svg', __FILE__ ),
			'supports'        => array( 'title', 'editor', 'thumbnail' ),
			'capability_type' => 'post',
			'rewrite'         => array( 'slug' => 'portal' ),
			'description'     => __( 'DragonGate Portals - Simply collect data, save it locally, and sync across Google Sheets and Drive. Build custom forms that automatically organize submissions and streamline your data workflow.', 'dragongate-portals' ),
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
