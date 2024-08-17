<?php

function pb_application_agreements_shortcode() {
	if ( defined( 'PB_APPLICATION_SUBMITTED' ) && PB_APPLICATION_SUBMITTED ) {
		return;
	}
	if ( defined( 'PB_APPLICATION_DEADLINE_PASSED' ) && PB_APPLICATION_DEADLINE_PASSED ) {
		return '<p>The application deadline has passed.</p>';
	}

	$disclaimers = json_decode( get_option( 'pb_legal_disclaimers', json_encode( array() ) ), true );
	
	// Decode the disclaimers until it's an array
	while ( ! is_array( $disclaimers ) ) {
		$disclaimers = json_decode( $disclaimers, true );
	}
	
	$agreements = array();
	foreach ( $disclaimers as $disclaimer ) {
		if ( $disclaimer[0] ) {
			$agreements[ $disclaimer[0] ] = array(
				'text'    => $disclaimer[1],
				'checked' => $_POST[ 'sub_agreement_' . $disclaimer[0] ] ?? false,
			);
		}
	}

	$readonly = isset( $_POST['review_nonce'] ) ? ' disabled="disabled" ' : '';

	ob_start();
	?>
	<div style="margin-top:30px">
		<?php if ( $agreements ) : ?>
		<p class="row description">Please mark the statements below. To proceed with the application, all statements must be agreed to.</p>
		<?php endif; ?>
		<?php
		foreach ( $agreements as $index => $disclaimer ) :
			$checked = isset( $_POST[ 'sub_agreement_' . $index ] ) ? 'checked="checked"' : '';
			?>
			<p class="row">
				<input required id="sub_agreement_<?php echo $index; ?>" name="sub_agreement_<?php echo $index; ?>" type="checkbox" <?php echo $checked . ' ' . $readonly; ?> />
				<label for="sub_agreement_<?php echo $index; ?>"><?php echo esc_html( $disclaimer['text'] ); ?></label>
			</p>
			<?php
		endforeach;
		?>

		<?php if ( ! $readonly ) : ?>
			[portal-recaptcha] 
		<?php endif; ?>
		&nbsp;
		<p class="sub_submit_container"><input class="btn btn-primary sub_submit" name="sub_submit" type="submit" value="<?php echo $readonly ? 'Submit' : 'Continue'; ?>" /></p>
	</div>
	<?php

	return do_shortcode( ob_get_clean() );
}
	add_shortcode( 'portal-application-agreements', 'pb_application_agreements_shortcode' );
