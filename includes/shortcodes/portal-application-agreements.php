<?php

function dg_application_agreements_shortcode() {
	if ( defined( 'DG_APPLICATION_SUBMITTED' ) && DG_APPLICATION_SUBMITTED ) {
		return '';
	}
	if ( class_exists( 'Portal_Public_Render' ) && Portal_Public_Render::leftover_shortcode_is_silent() ) {
		return '';
	}

	$post_id           = (int) get_the_ID();
	$definition_portal = class_exists( 'Portal_Definition' ) && is_array( Portal_Definition::load_for_post( $post_id ) );

	$disclaimers = json_decode( Portal_Options::get( 'dg_legal_disclaimers', json_encode( array() ) ), true );

	// Decode the disclaimers until it's an array
	while ( ! is_array( $disclaimers ) ) {
		$disclaimers = json_decode( $disclaimers, true );
	}

	$agreements = array();
	foreach ( $disclaimers as $disclaimer ) {
		if ( $disclaimer[0] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- display of prior POST only.
			$agreements[ $disclaimer[0] ] = array(
				'text'    => $disclaimer[1],
				'checked' => $_POST[ 'sub_agreement_' . $disclaimer[0] ] ?? false,
			);
		}
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$readonly = ( ! $definition_portal && isset( $_POST['review_nonce'] ) ) ? ' disabled="disabled" ' : '';
	// Definition portals submit in one step; legacy uses Continue → Submit.
	$button_label = ( $definition_portal || $readonly ) ? 'Submit' : 'Continue';

	ob_start();
	?>
	<div style="margin-top:30px">
		<?php if ( $agreements ) : ?>
		<p class="row description">Please mark the statements below. To proceed with the application, all statements must be agreed to.</p>
		<?php endif; ?>
		<?php
		foreach ( $agreements as $index => $disclaimer ) :
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$checked = isset( $_POST[ 'sub_agreement_' . $index ] ) ? 'checked="checked"' : '';
			?>
			<p class="row">
				<input required id="sub_agreement_<?php echo esc_attr( (string) $index ); ?>" name="sub_agreement_<?php echo esc_attr( (string) $index ); ?>" type="checkbox" <?php echo $checked . ' ' . $readonly; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
				<label for="sub_agreement_<?php echo esc_attr( (string) $index ); ?>"><?php echo esc_html( $disclaimer['text'] ); ?></label>
			</p>
			<?php
		endforeach;
		?>

		<?php if ( ! $readonly ) : ?>
			[portal-recaptcha]
		<?php endif; ?>
		&nbsp;
		<p class="sub_submit_container"><input class="btn btn-primary sub_submit" name="sub_submit" type="submit" value="<?php echo esc_attr( $button_label ); ?>" /></p>
	</div>
	<?php

	return do_shortcode( ob_get_clean() );
}
	add_shortcode( 'portal-application-agreements', 'dg_application_agreements_shortcode' );
