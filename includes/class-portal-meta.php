<?php

if ( ! class_exists( 'Portal_Meta' ) ) {

	class Portal_Meta {

		private $meta_boxes = array();

		public function init() {
			add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
			add_action( 'save_post', array( $this, 'save_post_meta' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) ); // Enqueue scripts
			add_action( 'wp_ajax_validate_url', array( $this, 'validate_url_callback' ) ); // AJAX callback
		}

		public function register_meta_box( $id, $title, $fields, $context = 'advanced', $priority = 'default' ) {
			$this->meta_boxes[] = array(
				'id'       => $id,
				'title'    => $title,
				'fields'   => $fields,
				'context'  => $context,
				'priority' => $priority,
			);
		}

		/**
		 * Whether the Svelte wizard is a post.php surface.
		 *
		 * Product editing is the setup screen. post.php is Legacy fields only.
		 *
		 * @return bool
		 */
		public static function registers_wizard_on_post_editor() {
			return false;
		}

		public function add_meta_boxes() {
			// Leftover field boxes stay registered for save_post; CSS shows them only on Legacy fields.
			if ( self::registers_wizard_on_post_editor() ) {
				add_meta_box(
					'portal_setup_wizard',
					__( 'Portal setup', 'dragongate-portals' ),
					array( $this, 'render_wizard_mount' ),
					'portal',
					'normal',
					'high'
				);
			}

			foreach ( $this->meta_boxes as $meta_box ) {
				add_meta_box(
					$meta_box['id'],
					$meta_box['title'],
					array( $this, 'render_meta_box' ),
					'portal',
					$meta_box['context'],
					$meta_box['priority'],
					$meta_box['fields']
				);
			}
		}

		/**
		 * Svelte wizard mount (Start → Build → Map → Publish).
		 *
		 * @param WP_Post $post Current post.
		 */
		public function render_wizard_mount( $post ) {
			$portal_id = (int) $post->ID;
			$rest_root = esc_url_raw( rest_url( 'dragongate/v1' ) );
			$nonce     = wp_create_nonce( 'wp_rest' );
			$title     = get_the_title( $portal_id );
			printf(
				'<div data-portal-wizard class="dg-wizard-root" data-portal-id="%1$d" data-rest-root="%2$s" data-rest-nonce="%3$s" data-portal-title="%4$s" data-wp-rest-root="%5$s"></div>',
				$portal_id,
				esc_attr( $rest_root ),
				esc_attr( $nonce ),
				esc_attr( $title ),
				esc_attr( esc_url_raw( rest_url() ) )
			);
		}

		public function render_meta_box( $post, $meta_box ) {
			$fields = $meta_box['args'];

			echo '<div class="portal-meta-box">';
			foreach ( $fields as $field ) {
				$meta_value  = get_post_meta( $post->ID, $field['id'], true );
				$placeholder = isset( $field['placeholder'] ) ? esc_attr( $field['placeholder'] ) : '';
				$label       = isset( $field['label'] ) ? esc_html( $field['label'] ) : '';
				// replace `(.*?)` with <code>\1</code> tags
				$label = preg_replace( '/`(.*?)`/', '<code class="copyable">\1</code>', $label );

				switch ( $field['type'] ) {
					case 'switch':
						echo '<div class="form-check form-switch">';
						echo '<input class="form-check-input" type="checkbox" id="' . esc_attr( $field['id'] ) . '" name="' . esc_attr( $field['id'] ) . '" value="1"' . checked( $meta_value, '1', false ) . ' />';
						echo '<label class="form-check-label" for="' . esc_attr( $field['id'] ) . '">' . $label . '</label>';
						echo '</div>';
						break;

					case 'url':
						echo '<div class="form-group">';
						echo '<label for="' . esc_attr( $field['id'] ) . '">' . $label . '</label>';
						echo '<input type="url" id="' . esc_attr( $field['id'] ) . '" name="' . esc_attr( $field['id'] ) . '" class="form-control monospace-url" value="' . esc_attr( $meta_value ) . '" placeholder="' . $placeholder . '" />';
						echo '<div id="' . esc_attr( $field['id'] ) . '-validation" class="url-validation empty"></div>';
						echo '</div>';
						break;

					case 'datetime-local':
						echo '<div class="form-group">';
						echo '<label for="' . esc_attr( $field['id'] ) . '">' . $label . '</label>';
						echo '<input type="datetime-local" id="' . esc_attr( $field['id'] ) . '" name="' . esc_attr( $field['id'] ) . '" class="form-control" value="' . esc_attr( $meta_value ) . '" />';
						echo '</div>';
						break;

					case 'select':
						echo '<div class="form-group">';
						echo '<label for="' . esc_attr( $field['id'] ) . '">' . $label . '</label>';
						echo '<select id="' . esc_attr( $field['id'] ) . '" class="form-control" name="' . esc_attr( $field['id'] ) . '">';
						foreach ( $field['options'] as $option_value => $option_label ) {
							echo '<option value="' . esc_attr( $option_value ) . '"' . selected( $meta_value, $option_value, false ) . '>' . esc_html( $option_label ) . '</option>';
						}
						echo '</select>';
						echo '</div>';
						break;

					case 'number':
						echo '<div class="form-group">';
						echo '<label for="' . esc_attr( $field['id'] ) . '">' . $label . '</label>';
						echo '<input type="number" step="0.01" id="' . esc_attr( $field['id'] ) . '" class="form-control" name="' . esc_attr( $field['id'] ) . '" value="' . esc_attr( $meta_value ) . '" placeholder="' . $placeholder . '" />';
						echo '</div>';
						break;

					case 'date':
						echo '<div class="form-group">';
						echo '<label for="' . esc_attr( $field['id'] ) . '">' . $label . '</label>';
						echo '<input type="date" id="' . esc_attr( $field['id'] ) . '" class="form-control" name="' . esc_attr( $field['id'] ) . '" value="' . esc_attr( $meta_value ) . '" />';
						echo '</div>';
						break;

					case 'textarea':
						echo '<div class="form-group">';
						echo '<label for="' . esc_attr( $field['id'] ) . '">' . $label . '</label>';
						echo '<textarea id="' . esc_attr( $field['id'] ) . '" name="' . esc_attr( $field['id'] ) . '" class="form-control" rows="4" placeholder="' . $placeholder . '">' . esc_textarea( $meta_value ) . '</textarea>';
						echo '</div>';
						break;

					case 'data-table':
						echo '<div class="form-group">';
						echo '<label>' . $label . '</label>';
						$disclosure = isset( $field['disclosure'] ) ? $field['disclosure'] : '';
						$this->render_data_table( $field['id'], $field['columns'], $field['extraction'], $post->ID, $field['options'] ?? array(), $disclosure );
						echo '</div>';
						break;

					default:
						break;
				}
			}
			echo '</div>';
		}

		public function save_post_meta( $post_id ) {
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			foreach ( $this->meta_boxes as $meta_box ) {
				foreach ( $meta_box['fields'] as $field ) {
					if ( ! isset( $_POST[ $field['id'] ] ) ) {
						delete_post_meta( $post_id, $field['id'] );
						continue;
					}

					$raw = wp_unslash( $_POST[ $field['id'] ] );

					if ( $this->is_json_meta_field( $field ) ) {
						$this->save_json_meta( $post_id, $field['id'], $raw );
						continue;
					}

					update_post_meta( $post_id, $field['id'], sanitize_text_field( $raw ) );
				}
			}
		}

		/**
		 * Whether a field stores structured JSON (must not run through sanitize_text_field).
		 *
		 * @param array $field Meta field config.
		 * @return bool
		 */
		private function is_json_meta_field( $field ) {
			$type = isset( $field['type'] ) ? $field['type'] : '';
			$id   = isset( $field['id'] ) ? $field['id'] : '';

			if ( 'data-table' === $type ) {
				return true;
			}

			$json_ids = array( '_portal_record_keeping', '_portal_file_backups' );
			return in_array( $id, $json_ids, true );
		}

		/**
		 * Persist a JSON array meta value without sanitize_text_field (which mangles quotes).
		 *
		 * @param int    $post_id Post ID.
		 * @param string $meta_key Meta key.
		 * @param mixed  $raw     Unslashed POST value.
		 */
		private function save_json_meta( $post_id, $meta_key, $raw ) {
			if ( ! is_string( $raw ) ) {
				return;
			}

			$decoded = json_decode( $raw, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				return;
			}

			update_post_meta( $post_id, $meta_key, wp_json_encode( $decoded ) );
		}

		/**
		 * Enqueue admin assets on portal edit screens only.
		 *
		 * @param string $hook_suffix Current admin page hook.
		 */
		public function enqueue_scripts( $hook_suffix = '' ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen || 'portal' !== $screen->post_type ) {
				return;
			}
			// edit.php list table: Tailwind preflight + Svelte bundle break WP sticky thead/scroll.
			if ( 'edit' === $screen->base ) {
				return;
			}

			wp_enqueue_style(
				'dragongate-portal-css',
				plugins_url( '../assets/dist/dragongate-portal.css', __FILE__ ),
				array(),
				PB_VERSION
			);

			wp_enqueue_style(
				'dg-portal-admin',
				plugins_url( '../assets/portal-admin.css', __FILE__ ),
				array( 'dragongate-portal-css' ),
				PB_VERSION
			);

			wp_enqueue_style( 'dashicons' );

			wp_enqueue_style(
				'portal-meta-box-styles',
				plugins_url( '../assets/portal-meta-box.css', __FILE__ ),
				array( 'dragongate-portal-css' ),
				PB_VERSION
			);
			wp_enqueue_script(
				'portal-meta-box-script',
				plugins_url( '../assets/portal-meta-box.js', __FILE__ ),
				array( 'jquery' ),
				PB_VERSION,
				true
			);
			wp_enqueue_script(
				'pb-url-validation',
				plugins_url( '../assets/url-validation.js', __FILE__ ),
				array( 'jquery' ),
				PB_VERSION,
				true
			);

			// Shared Svelte bundle: data tables on Legacy fields only — not a wizard surface.
			$legacy_fields = class_exists( 'Portal_Setup_Screen' )
				&& Portal_Setup_Screen::is_legacy_fields_screen();
			if ( $legacy_fields ) {
				wp_enqueue_script(
					'dragongate-portal-js',
					plugins_url( '../assets/dist/dragongate-portal.js', __FILE__ ),
					array(),
					PB_VERSION,
					true
				);
				add_filter(
					'script_loader_tag',
					static function ( $tag, $handle ) {
						if ( 'dragongate-portal-js' === $handle ) {
							return str_replace( '<script ', '<script type="module" ', $tag );
						}
						return $tag;
					},
					10,
					2
				);
			}
		}

		public function validate_url_callback() {
			$url      = esc_url_raw( $_POST['url'] );
			$response = wp_remote_get( $url );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( [ 'message' => 'Invalid URL' ] );
			}

			$status_code = wp_remote_retrieve_response_code( $response );

			if ( $status_code === 200 ) {
				wp_send_json_success( [ 'message' => 'Valid URL' ] );
			} else {
				wp_send_json_error( [ 'message' => 'Invalid URL' ] );
			}
		}

	private function render_data_table( $meta_key, $columns, $extraction, $post_id, $options = [], $disclosure = '' ) {
		// Wrap everything in the scoped portal-builder class
		echo '<div class="portal-builder">';
		
		// Add segmented control for sheet selection
		$this->render_sheet_segmented_control( $meta_key, $post_id );
		
		// Create a minimal data table container for Svelte to take over
		echo '<div id="' . esc_attr( $meta_key ) . '" class="data-table">';
		echo '</div>';
		
		// Store the data for Svelte to read
		$values = get_post_meta( $post_id, $meta_key, true );
		if ( $values ) {
			$values = json_decode( $values, true );
			// Keep decoding until we get an array
			$max_iterations = 20;
			$iteration_count = 0;
			while ( ! is_array( $values ) ) {
				$values = json_decode( $values, true );
				++$iteration_count;
				if ( $iteration_count >= $max_iterations ) {
					break;
				}
			}
		}
		
		// If decoding failed, create empty array
		if ( ! is_array( $values ) ) {
			$values = array();
		}
		
		// Hidden input for form submission (Svelte will update this)
		echo '<input type="hidden" name="' . esc_attr( $meta_key ) . '" id="' . esc_attr( $meta_key ) . '" value="' . esc_attr( json_encode( $values ) ) . '" />';
		
		// Close the scoped wrapper
		echo '</div>';
	}

	/**
	 * Render segmented control for sheet selection
	 *
	 * @param string $meta_key
	 * @param int    $post_id
	 */
	private function render_sheet_segmented_control( $meta_key, $post_id ) {
		// Create container for Svelte component
		echo '<div data-segmented-control data-meta-key="' . esc_attr( $meta_key ) . '" data-post-id="' . esc_attr( $post_id ) . '">';
		echo '</div>';
	}
}
}
