<?php
function pb_application_upload_notes_shortcode() {
	if ( defined( 'PB_APPLICATION_SUBMITTED' ) && PB_APPLICATION_SUBMITTED ) {
		return;
	}
	if ( defined( 'PB_APPLICATION_DEADLINE_PASSED' ) && PB_APPLICATION_DEADLINE_PASSED ) {
		return '<p>The application deadline has passed.</p>';
	}

	if ( isset( $_POST['review_nonce'] ) || isset( $_POST['ready_to_submit_nonce'] ) ) {
		return;
	}

	ob_start();
	?>
	<div style="padding: 15px; background-color: rgba(155, 150, 150, 0.5); border-radius: 25px; margin-top: 20px; margin-bottom: 10px;">
		<h3 style="text-decoration: underline;">Note:</h3>
		Depending on the size of your uploaded files, it may take a while to upload your files. Please keep file uploads to under 32MB.
		<br /><br />
		"*" denotes Required Field. For the Call For Scores, all fields are required in the categories you choose.
		<br /><br />
		If you need help converting your files to pdf's visit: <a href="https://cloudconvert.com/anything-to-pdf">https://cloudconvert.com/anything-to-pdf</a><br />
		If you need help converting your files to mp3's visit: <a href="https://cloudconvert.com/anything-to-mp3">https://cloudconvert.com/anything-to-mp3</a>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'portal-application-upload-notes', 'pb_application_upload_notes_shortcode' );
