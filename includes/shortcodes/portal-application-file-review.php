<?php
function pb_application_file_review_shortcode( $atts ) {
	// Make sure the necessary condition is met (e.g., 'review_nonce' is set in the POST request)
	if ( ! isset( $_POST['review_nonce'] ) ) {
		return '';
	}

	// Retrieve stored file paths from the file handler
	global $file_handler;
	$file_handler = defined( 'PB_FILE_HANDLER' ) ? PB_FILE_HANDLER : $file_handler;
	if ( ! $file_handler ) {
		return '<p>File handler not initialized.</p>';
	}

	// Start the output buffer
	ob_start();
	?>
	<div class="entry-content alignfull wp-block-post-content has-global-padding is-layout-constrained wp-block-post-content-is-layout-constrained">
		<fieldset style="margin-bottom:30px;">
			<legend>Review Submitted Files</legend>
			<span class="instructions">Please review your files to ensure that they have been processed correctly. Each link opens in a new tab.</span>
			<ul>
				<?php
				foreach ( array_keys( $file_handler->stored_file_paths ) as $key ) {
					$file       = $_FILES[ $key ];
					$file_name  = basename( $file['tmp_name'] );
					$file_size  = $file['size'];
					$file_type  = $file['type'];
					$file_error = $file['error'];

					if ( $file_error === 0 ) {
						# get the correct extension based on MIME
						$extension = '';
						switch ( $file_type ) {
							case 'application/pdf':
								$extension = '.pdf';
								break;
							case 'audio/mpeg':
								$extension = '.mp3';
								break;
							default:
								$extension = '';
								break;
						}
						$url = site_url( PB_RELATIVE_TMP_UPLOADS_DIR . '/' . $file_handler->stored_file_paths[ $key ] );
						echo '<li><a href="' . esc_url( $url ) . '" target="_blank" data-display-label-override="1" data-display-label="' . esc_attr( $key ) . '">' . esc_html( $file_name ) . '</a></li>';
					}
				}
				?>
			</ul>
		</fieldset>
		<input type="hidden" name="eappId" value="<?php echo esc_attr( $_POST['eappId'] ); ?>" />
		<input type="hidden" name="efiles" value="<?php echo esc_attr( $_POST['efiles'] ); ?>" />
	</div>
	<?php

	// Return the buffer content
	return ob_get_clean();
}
add_shortcode( 'portal-application-file-review', 'pb_application_file_review_shortcode' );
