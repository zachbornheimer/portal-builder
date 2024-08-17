<?php

if ( ! class_exists( 'Data_Table' ) ) {

	class Data_Table {

		private $table_id;
		private $meta_key;
		private $columns;
		private $sample_row;
		private $extraction_rules;
		private $column_options;
		private $is_option;
	
		/**
		 * Constructor
		 *
		 * @param string $table_id
		 * @param string $meta_key
		 * @param array $columns
		 * @param array $sample_row
		 * @param array $extraction_rules
		 * @param array $column_options
		 * @param bool $is_option  // New argument to indicate if it's for options
		 */
		public function __construct( $table_id, $meta_key, $columns = array(), $sample_row = array(), $extraction_rules = array(), $column_options = array(), $is_option = false ) {
			$this->table_id         = $table_id;
			$this->meta_key         = $meta_key;
			$this->columns          = $columns;
			$this->sample_row       = $sample_row;
			$this->extraction_rules = $extraction_rules;
			$this->column_options   = $column_options;
			$this->is_option        = $is_option;
		}
	
		/**
		 * Render the data table
		 *
		 * @param WP_Post|NULL $post  // Adjusted to accept null if rendering for an option
		 */
		public function render( $post = null ) {
			// Retrieve the stored data based on context (post meta or option)
			if ( $this->is_option ) {
				$values = get_option( $this->meta_key, json_encode( array( $this->sample_row ) ) );
			} else {
				$values = get_post_meta( $post->ID, $this->meta_key, true );
			}
	
			// Decode the JSON string into an array
			if ( $values ) {
				$values = json_decode( $values, true );
				# keep decoding until we get an array
				$max_iterations  = 20;
				$iteration_count = 0;
				while ( ! is_array( $values ) ) {
					$values = json_decode( $values, true );
					++$iteration_count;
					if ( $iteration_count >= $max_iterations ) {
						break;
					}
				}
			}
	
			// If decoding failed or resulted in something other than an array, reset to a default sample row
			if ( ! is_array( $values ) ) {
				$values = array( $this->sample_row );
			}
	
			if ( isset( $this->column_options[0]['block'] ) ) {
				$block_style = 'style="border-collapse: separate;border-spacing: 0px 1rem;"';
			} else {
				$block_style = '';
			}
	
			echo '<table id="' . esc_attr( $this->table_id ) . '" class="data-table" ' . $block_style . '>';
			if ( ! isset( $this->column_options[0]['block'] ) ) {
				echo '<thead><tr>';
				foreach ( $this->columns as $column ) {
					echo '<th>' . esc_html( $column ) . '</th>';
				}
				echo '<th>Action</th></tr></thead>';
			}
	
			echo '<tbody>';
			foreach ( $values as $row ) {
				echo $this->render_row( $row );
			}
			echo '</tbody>';
			echo '</table>';
			echo '<button type="button" id="add-row-' . esc_attr( $this->table_id ) . '" class="button add-row">Add Row</button>';
	
			// Store the data based on context (post meta or option)
			echo '<input type="hidden" name="' . esc_attr( $this->meta_key ) . '" id="' . esc_attr( $this->meta_key ) . '" value="' . esc_attr( json_encode( $values ) ) . '" />';
		}

		/**
		 * Render a single row of the data table
		 *
		 * @param array $row
		 * @return string
		 */
		private function render_row( $row = array() ) {
			$block = isset( $this->column_options[0]['block'] ) ? ' display:block ' : '';

			$row_html = '<tr>';
			foreach ( $this->columns as $index => $column ) {
				$value     = isset( $row[ $index ] ) ? $row[ $index ] : '';
				$class     = isset( $this->extraction_rules[ $index ] ) ? 'extract-url' : '';
				$data_type = isset( $this->extraction_rules[ $index ] ) ? 'data-extraction-type="' . esc_attr( $this->extraction_rules[ $index ] ) . '"' : '';

				$block_heading = $block ? '<label>' . $column . '</label>' : '';
				// If the column is set to handle tags
				if ( isset( $this->column_options[ $index ]['tags'] ) && $this->column_options[ $index ]['tags'] === true ) {
					$tags      = explode( ',', $value ); // Split the value by commas to get individual tags
					$row_html .= '<td style="' . $block . '">' . $block_heading . '
                        <div class="columns-tag-container">';
					foreach ( $tags as $tag ) {
						$row_html .= '<span class="tag">' . esc_html( trim( $tag ) ) . '<span class="close-button">×</span></span>';
					}
					$row_html .= '
                            <input type="text" class="tag-input" placeholder="Add a tag" />
							<span class="caption"> (do not enter {{ or }}).  <code>Alt Shift A</code> adds all names from the content.  <code>Enter</code> to add your tag. Other tag options: <code>receipt_link</code>, <code>drive_link</code>, <code>app_id</code>.</span>
                            <input type="hidden" class="tag-hidden-field" name="' . esc_attr( $this->meta_key ) . '[' . $index . ']" value="' . esc_attr( $value ) . '" />
                        </div>
                    </td>';
				} else {
					$row_html .= '<td style="' . $block . '">' . $block_heading . '<input type="text" class="' . esc_attr( $class ) . '" ' . $data_type . ' value="' . esc_attr( $value ) . '" /></td>';
				}
			}
			$row_html .= '<td><button type="button" class="button delete-row">Delete</button></td>';
			$row_html .= '</tr>';

			return $row_html;
		}
	}
}
