<?php

if ( ! class_exists( 'Data_Table' ) ) {

	class Data_Table {

		// Column type constants
		const COLUMN_GOOGLE_SHEET_ID = 'Google Sheet ID';
		const COLUMN_COLUMNS = 'Columns';
		const COLUMN_GOOGLE_DRIVE_FOLDER_ID = 'Google Drive Folder ID';

		private $table_id;
		private $meta_key;
		private $columns;
		private $sample_row;
		private $extraction_rules;
		private $column_options;
		private $is_option;
		private $disclosure;

		/**
		 * Constructor
		 *
		 * @param string $table_id
		 * @param string $meta_key
		 * @param array  $columns
		 * @param array  $sample_row
		 * @param array  $extraction_rules
		 * @param array  $column_options
		 * @param bool   $is_option  // New argument to indicate if it's for options
		 * @param string $disclosure // Disclosure text to show under relevant fields
		 */
		public function __construct( $table_id, $meta_key, $columns = array(), $sample_row = array(), $extraction_rules = array(), $column_options = array(), $is_option = false, $disclosure = '' ) {
			$this->table_id         = $table_id;
			$this->meta_key         = $meta_key;
			$this->columns          = $columns;
			$this->sample_row       = $sample_row;
			$this->extraction_rules = $extraction_rules;
			$this->column_options   = $column_options;
			$this->is_option        = $is_option;
			$this->disclosure       = $disclosure;
		}

		/**
		 * Render the data table
		 *
		 * @param WP_Post|null $post  // Adjusted to accept null if rendering for an option
		 */
		public function render( $post = null ) {
			// Retrieve the stored data based on context (post meta or option)
			if ( $this->is_option ) {
				$values = class_exists( 'Portal_Options' )
					? Portal_Options::get( $this->meta_key, json_encode( array( $this->sample_row ) ) )
					: get_option( $this->meta_key, json_encode( array( $this->sample_row ) ) );
			} else {
				$values = get_post_meta( $post->ID, $this->meta_key, true );
			}

			// Decode the JSON string into an array
			if ( $values ) {
				$values = json_decode( $values, true );
				// keep decoding until we get an array
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

			// Use div-based layout for better mobile responsiveness
			echo '<div id="' . esc_attr( $this->table_id ) . '" class="data-table ">';
			
			// Header row (only for non-block mode)
			if ( ! isset( $this->column_options[0]['block'] ) ) {
				echo '<div class="hidden md:grid md:grid-cols-' . ( count( $this->columns ) + 1 ) . ' bg-gray-50 border-b border-gray-200">';
				foreach ( $this->columns as $column ) {
					echo '<div class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">' . esc_html( $column ) . '</div>';
				}
				echo '<div class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</div>';
				echo '</div>';
			}

			// Data rows
			foreach ( $values as $row_index => $row ) {
				echo $this->render_row( $row, $row_index );
			}
			echo '</div>';

			// Store the data based on context (post meta or option)
			echo '<input type="hidden" name="' . esc_attr( $this->meta_key ) . '" id="' . esc_attr( $this->meta_key ) . '" value="' . esc_attr( json_encode( $values ) ) . '" />';
		}

		/**
		 * Check if a column is a specific type
		 *
		 * @param string $column
		 * @param string $type
		 * @return bool
		 */
		private function is_column_type( $column, $type ) {
			return $column === $type;
		}

		/**
		 * Build column label with special actions
		 *
		 * @param string $column
		 * @param string $value
		 * @param int    $index
		 * @return string
		 */
		private function build_column_label( $column, $value, $index ) {
			$label = '<label class="block text-sm font-medium text-gray-700 mb-2">' . $column . '</label>';
			
			// Add copy button for Columns
			if ( $this->is_column_type( $column, self::COLUMN_COLUMNS ) ) {
				$label = '<label class="block text-sm font-medium text-gray-700 mb-0 inline">' . $column . '</label> <button type="button" class="button button-secondary copy-columns ml-2 inline">Copy</button>';
			}
			
			// Add Google Sheet link
			if ( $this->is_column_type( $column, self::COLUMN_GOOGLE_SHEET_ID ) && !empty($value) ) {
				$sheet_url = 'https://docs.google.com/spreadsheets/d/' . esc_attr($value);
				$label = '<label class="block text-sm font-medium text-gray-700 mb-2 inline">' . $column . '</label> <a href="' . $sheet_url . '" target="_blank" rel="noopener noreferrer" class="mb-2 text-blue-600 hover:text-blue-800 inline-flex items-center gap-1 ml-2">View Sheet <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg></a>';
			}
			
			return $label;
		}

		/**
		 * Build input field for a column
		 *
		 * @param string $column
		 * @param string $value
		 * @param int    $index
		 * @return string
		 */
		private function build_input_field( $column, $value, $index ) {
			$class = isset( $this->extraction_rules[ $index ] ) ? 'extract-url' : '';
			$data_type = isset( $this->extraction_rules[ $index ] ) ? 'data-extraction-type="' . esc_attr( $this->extraction_rules[ $index ] ) . '"' : '';
			
			// Handle tag fields
			if ( isset( $this->column_options[ $index ]['tags'] ) && $this->column_options[ $index ]['tags'] === true ) {
				return $this->build_tag_field( $value, $index );
			}
			
			// Regular input field
			$input = '<input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent ' . esc_attr( $class ) . '" ' . $data_type . ' value="' . esc_attr( $value ) . '" />';
			
			// Add disclosure for Google Sheet ID
			if ( $this->is_column_type( $column, self::COLUMN_GOOGLE_SHEET_ID ) && $this->disclosure ) {
				$input .= '<div class="text-xs text-gray-500 mt-1">' . $this->disclosure . '</div>';
			}
			
			// Add disclosure for Google Drive Folder ID
			if ( $this->is_column_type( $column, self::COLUMN_GOOGLE_DRIVE_FOLDER_ID ) && $this->disclosure ) {
				$input .= '<div class="text-xs text-gray-500 mt-1">' . $this->disclosure . '</div>';
			}
			
			return $input;
		}

		/**
		 * Build tag field for columns that support tags
		 *
		 * @param string $value
		 * @param int    $index
		 * @return string
		 */
		private function build_tag_field( $value, $index ) {
			$tags = explode( ',', $value );
			$html = '<div class="columns-tag-container border border-gray-300 rounded-md p-3 bg-gray-50">';
			
			foreach ( $tags as $tag ) {
				$html .= '<span class="tag inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 mr-2 mb-2">' . esc_html( trim( $tag ) ) . '<span class="close-button ml-1 cursor-pointer hover:text-red-600">×</span></span>';
			}
			
			$html .= '<input type="text" class="tag-input w-full border-0 bg-transparent focus:outline-none" placeholder="Add a tag" />';
			$html .= '<div class="text-xs text-gray-500 mt-2">';
			$html .= '<code class="bg-gray-200 px-1 rounded">Enter</code> to add your tag. Use commas to add multiple tags at once. <code class="bg-gray-200 px-1 rounded">Alt Shift A</code> adds all names from the content. Tag options: <code class="bg-gray-200 px-1 rounded">receipt_link</code>, <code class="bg-gray-200 px-1 rounded">drive_link</code>, <code class="bg-gray-200 px-1 rounded">app_id</code>. {{ }} wrappers are automatically added/removed.';
			$html .= '</div>';
			$html .= '<input type="hidden" class="tag-hidden-field" name="' . esc_attr( $this->meta_key ) . '[' . $index . ']" value="' . esc_attr( $value ) . '" />';
			$html .= '</div>';
			
			return $html;
		}

		/**
		 * Render row in block mode
		 *
		 * @param array $row
		 * @return string
		 */
		private function render_block_mode_row( $row ) {
			$html = '<div class="flex-1">';
			
			foreach ( $this->columns as $index => $column ) {
				$value = $row[ $index ] ?? '';
				
				$html .= '<div class="mb-4">';
				$html .= '<div class="mb-0 flex align-center items-center flex-row gap-2">';
				$html .= $this->build_column_label( $column, $value, $index );
				$html .= '</div>';
				$html .= $this->build_input_field( $column, $value, $index );
				$html .= '</div>';
			}
			
			$html .= '</div>'; // Close flex-1
			
			// Delete button on the right
			$html .= '<div class="flex-shrink-0 ml-4">';
			$html .= '<button type="button" class="delete-row text-gray-400 hover:text-red-600 hover:bg-red-50 px-2 py-2 rounded-md transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-opacity-50 border-0 bg-transparent" title="Delete" aria-label="Delete row"><span class="dashicons dashicons-trash"></span></button>';
			$html .= '</div>';
			
			return $html;
		}

		/**
		 * Render a single row of the data table
		 *
		 * @param array $row
		 * @param int   $row_index
		 * @return string
		 */
		private function render_row( $row = array(), $row_index = 0 ) {
			$block = isset( $this->column_options[0]['block'] ) ? true : false;
			
		// Mobile-first card layout
		$row_html = '<div id="' . esc_attr( $this->table_id ) . '-row-' . esc_attr( $row_index ) . '" class="grow p-6 lg:rounded-lg lg:bg-white lg:p-10 lg:shadow-xs lg:ring-1 lg:ring-gray-200 dark:lg:bg-gray-900 dark:lg:ring-gray-700 flex items-start justify-between mb-4">';
			
			if ( $block ) {
				$row_html .= $this->render_block_mode_row( $row );
			} else {
				// Desktop grid layout - content on left, delete button on right
				$row_html .= '<div class="flex-1">';
				$row_html .= '<div class="hidden md:grid md:grid-cols-' . count( $this->columns ) . ' items-center gap-4">';
				
				foreach ( $this->columns as $index => $column ) {
					$value     = $row[ $index ] ?? '';
					$class     = isset( $this->extraction_rules[ $index ] ) ? 'extract-url' : '';
					$data_type = isset( $this->extraction_rules[ $index ] ) ? 'data-extraction-type="' . esc_attr( $this->extraction_rules[ $index ] ) . '"' : '';

					$row_html .= '<div class="py-2">';
					
					// If the column is set to handle tags
					if ( isset( $this->column_options[ $index ]['tags'] ) && $this->column_options[ $index ]['tags'] === true ) {
						$tags = explode( ',', $value );
						$row_html .= '<div class="columns-tag-container">';
						foreach ( $tags as $tag ) {
							$row_html .= '<span class="tag inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 mr-1 mb-1">' . esc_html( trim( $tag ) ) . '<span class="close-button ml-1 cursor-pointer hover:text-red-600">×</span></span>';
						}
						$row_html .= '<input type="text" class="tag-input w-full border-0 focus:outline-none" placeholder="Add a tag" />';
						$row_html .= '<input type="hidden" class="tag-hidden-field" name="' . esc_attr( $this->meta_key ) . '[' . $index . ']" value="' . esc_attr( $value ) . '" />';
						$row_html .= '</div>';
					} else {
						$row_html .= '<input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent ' . esc_attr( $class ) . '" ' . $data_type . ' value="' . esc_attr( $value ) . '" />';
						
						// Add disclosure caption for Google Sheet ID field
						if ( $index === 1 && $this->is_column_type( $column, self::COLUMN_GOOGLE_SHEET_ID ) && $this->disclosure ) {
							$row_html .= '<div class="text-xs text-gray-500 mt-1">';
							$row_html .= $this->disclosure;
							$row_html .= '</div>';
						}
						
						
						// Add disclosure caption for Google Drive Folder ID field
						if ( $index === 1 && $this->is_column_type( $column, self::COLUMN_GOOGLE_DRIVE_FOLDER_ID ) && $this->disclosure ) {
							$row_html .= '<div class="text-xs text-gray-500 mt-1">';
							$row_html .= $this->disclosure;
							$row_html .= '</div>';
						}
					}
					
					$row_html .= '</div>';
				}
				
				$row_html .= '</div>'; // Close grid
				
				// Mobile card layout
				$row_html .= '<div class="md:hidden">';
				foreach ( $this->columns as $index => $column ) {
					$value = $row[ $index ] ?? '';
					$row_html .= '<div class="mb-4">';
					$row_html .= '<label class="block text-sm font-medium text-gray-700 mb-1">' . esc_html( $column ) . '</label>';
					
					if ( isset( $this->column_options[ $index ]['tags'] ) && $this->column_options[ $index ]['tags'] === true ) {
						$tags = explode( ',', $value );
						$row_html .= '<div class="columns-tag-container border border-gray-300 rounded-md p-3 bg-gray-50">';
						foreach ( $tags as $tag ) {
							$row_html .= '<span class="tag inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 mr-2 mb-2">' . esc_html( trim( $tag ) ) . '<span class="close-button ml-1 cursor-pointer hover:text-red-600">×</span></span>';
						}
						$row_html .= '<input type="text" class="tag-input w-full border-0 bg-transparent focus:outline-none" placeholder="Add a tag" />';
						$row_html .= '<input type="hidden" class="tag-hidden-field" name="' . esc_attr( $this->meta_key ) . '[' . $index . ']" value="' . esc_attr( $value ) . '" />';
						$row_html .= '</div>';
					} else {
						$row_html .= '<input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" value="' . esc_attr( $value ) . '" />';
						
						// Add disclosure caption for Google Sheet ID field
						if ( $index === 1 && $this->is_column_type( $column, self::COLUMN_GOOGLE_SHEET_ID ) && $this->disclosure ) {
							$row_html .= '<div class="text-xs text-gray-500 mt-1">';
							$row_html .= $this->disclosure;
							$row_html .= '</div>';
						}
						
						
						// Add disclosure caption for Google Drive Folder ID field
						if ( $index === 1 && $this->is_column_type( $column, self::COLUMN_GOOGLE_DRIVE_FOLDER_ID ) && $this->disclosure ) {
							$row_html .= '<div class="text-xs text-gray-500 mt-1">';
							$row_html .= $this->disclosure;
							$row_html .= '</div>';
						}
					}
					
					$row_html .= '</div>';
				}
				$row_html .= '</div>'; // Close mobile layout
				$row_html .= '</div>'; // Close flex-1
				
				// Delete button on the right
				$row_html .= '<div class="flex-shrink-0 ml-4">';
				$row_html .= '<button type="button" class="delete-row text-gray-400 hover:text-red-600 hover:bg-red-50 px-2 py-2 rounded-md transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-opacity-50 border-0 bg-transparent" title="Delete" aria-label="Delete row"><span class="dashicons dashicons-trash"></span></button>';
				$row_html .= '</div>';
			}
			
			$row_html .= '</div>';
			return $row_html;
		}
	}
}
