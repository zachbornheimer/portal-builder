<?php
function pb_group_selection_shortcode( $atts, $content = null ) {

	# if $_POST review_nonce is set, return $content
	if ( isset( $_POST['review_nonce'] ) ) {
		return do_shortcode( $content );
	}
	if ( defined( 'PB_APPLICATION_SUBMITTED' ) && PB_APPLICATION_SUBMITTED ) {
		return do_shortcode( $content );
	}
	if ( defined( 'PB_APPLICATION_DEADLINE_PASSED' ) && PB_APPLICATION_DEADLINE_PASSED ) {
		return do_shortcode( $content );
	}

	static $group_selection_count = 0;
	++$group_selection_count;


	$atts = shortcode_atts(
		array(
			'label'               => '', // Legend for the fieldset
			'select-instructions' => '', // Instructions for the select element
			'options'             => '', // Comma-separated list of options
		),
		$atts
	);

	# random_string should be a mixture of the time, a random number, and the label
	$random_string = time() . rand( 0, 1000 ) . preg_replace( '/\W/', '', $atts['label'] );
	# sha256 it and take a substring of the first 10 characters
	$random_string = substr( hash( 'sha256', $random_string ), 0, 10 ) . $random_string;

	$selection_label     = $atts['label'];
	$select_instructions = $atts['select-instructions'];
	$options             = $atts['options'] ? explode( ',', $atts['options'] ) : array();


	$select_options = '<option value="" disabled selected>Select an option</option>';
	$group_html     = '';
	$index          = 0;

	// Process the options provided
	if ( ! empty( $options ) ) {
		foreach ( $options as $option ) {
			$option            = trim( $option );
			$idx_for_selection = $index . $random_string;
			$pattern           = sprintf( '/\[pb_group(.*?)\s+label="%s"[^\]]*\](.*?)\[\/pb_group\1\]/s', preg_quote( $option, '/' ) );
			if ( preg_match( $pattern, $content, $match ) ) {
				$group_content = do_shortcode( $match[0] ); // Process the pb_group shortcode

				$select_options .= sprintf( '<option value="group-%s">%s</option>', $idx_for_selection, esc_html( $option ) );
				$group_html     .= sprintf(
					'<div id="group-%s" class="pb-group-content-%s" style="display:none;">%s</div>',
					$idx_for_selection,
					$random_string,
					$group_content
				);

				++$index;
			}
		}
	} else {
		// Handle all top-level pb_group labels if no options are provided
		preg_match_all( '/\[pb_group\s+label="([^"]+)"[^\]]*\](.*?)\[\/pb_group\]/s', $content, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$label             = $match[1];
			$idx_for_selection = $index . $random_string;
			$group_content     = do_shortcode( $match[0] ); // Process the entire pb_group shortcode

			$select_options .= sprintf( '<option value="group-%s">%s</option>', $idx_for_selection, esc_html( $label ) );
			$group_html     .= sprintf(
				'<div id="group-%s" class="pb-group-content-%s" style="display:none;">%s</div>',
				$idx_for_selection,
				$random_string,
				$group_content
			);

			++$index;
		}
	}

	// Generate the select element and the grouped content
	$output = '
        <fieldset class="pb-group-selection portal-group">
            <legend>' . esc_html( $selection_label ) . '</legend>
            <div>
                <label for="pb-group-select-' . $group_selection_count . '">' . esc_html( $select_instructions ) . '</label>
                <select id="pb-group-select-' . $random_string . '" required>
                    ' . $select_options . '
                </select>
            </div>

            <div class="pb-group-contents" style="margin-top:10px;">
                ' . $group_html . '
            </div>
        </fieldset>
    ';

	// Add the JavaScript to handle the display logic
	$output .= '
        <script type="text/javascript">
            document.addEventListener("DOMContentLoaded", function() {
                var select = document.getElementById("pb-group-select-' . $random_string . '");
                var groups = document.querySelectorAll(".pb-group-content-' . $random_string . '");

                select.addEventListener("change", function() {
                    // Hide all groups
                    groups.forEach(function(group) {
                        group.style.display = "none";
                        // Remove the required attribute from inputs/selects inside hidden groups
                        group.querySelectorAll("[required]").forEach(function(input) {
                            input.setAttribute("data-required", "true");
                            input.removeAttribute("required");
                        });
                    });

                    // Show the selected group
                    var selectedGroup = document.getElementById(select.value);
                    if (selectedGroup) {
                        selectedGroup.style.display = "block";
                        selectedGroup.querySelectorAll("[data-required=\"true\"]").forEach(function(input) {
                            input.setAttribute("required", "required");
                        });
                    }
                });
            });
        </script>
    ';

	return $output;
}

add_shortcode( 'pb_group_selection', 'pb_group_selection_shortcode' );
add_shortcode( 'pb_group_selection_wrap1', 'pb_group_selection_shortcode' );
add_shortcode( 'pb_group_selection_wrap2', 'pb_group_selection_shortcode' );
add_shortcode( 'pb_group_selection_wrap3', 'pb_group_selection_shortcode' );
add_shortcode( 'pb_group_selection_wrap4', 'pb_group_selection_shortcode' );
add_shortcode( 'pb_group_selection_wrap5', 'pb_group_selection_shortcode' );
