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

		public function add_meta_boxes() {
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

		public function render_meta_box( $post, $meta_box ) {
			$fields = $meta_box['args'];

			echo '<div class="portal-meta-box">';
			foreach ( $fields as $field ) {
				$meta_value  = get_post_meta( $post->ID, $field['id'], true );
				$placeholder = isset( $field['placeholder'] ) ? esc_attr( $field['placeholder'] ) : '';
				$label       = isset( $field['label'] ) ? esc_html( $field['label'] ) : '';
				# replace `(.*?)` with <code>\1</code> tags
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
						$this->render_data_table( $field['id'], $field['columns'], $field['extraction'], $post->ID, $field['options'] ?? array() );
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

					if ( isset( $_POST[ $field['id'] ] ) ) {
						update_post_meta( $post_id, $field['id'], sanitize_text_field( $_POST[ $field['id'] ] ) );
					} else {
						delete_post_meta( $post_id, $field['id'] );
					}
				}
			}
		}

		public function enqueue_scripts() {
			wp_enqueue_style( 'portal-meta-box-styles', plugins_url( '../assets/portal-meta-box.css', __FILE__ ) );
			wp_enqueue_script( 'portal-meta-box-script', plugins_url( '../assets/portal-meta-box.js', __FILE__ ), array( 'jquery' ), null, true );
			wp_enqueue_script( 'pb-url-validation', plugins_url( '../assets/url-validation.js', __FILE__ ), array( 'jquery' ), null, true );
		}

		public function validate_url_callback() {
			$url      = esc_url_raw( $_POST['url'] );
			$response = wp_remote_get( $url );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( array( 'message' => 'Invalid URL' ) );
			}

			$status_code = wp_remote_retrieve_response_code( $response );

			if ( $status_code === 200 ) {
				wp_send_json_success( array( 'message' => 'Valid URL' ) );
			} else {
				wp_send_json_error( array( 'message' => 'Invalid URL' ) );
			}
		}

		private function render_data_table( $meta_key, $columns, $extraction, $post_id, $options = array() ) {
			$data_table = new Data_Table( $meta_key, $meta_key, $columns, array_fill( 0, count( $columns ), '' ), $extraction, $options );
			$data_table->render( get_post( $post_id ) );

			if ( $options ) {
				foreach ( $options as $column_index => $column_options ) {
					if ( isset( $column_options['tags'] ) && $column_options['tags'] === true ) {
					
					}
				}
			}
		}
	}
}
