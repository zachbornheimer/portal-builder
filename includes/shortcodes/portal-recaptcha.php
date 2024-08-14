<?php
function portal_recaptcha_shortcode() {
	$sitekey = get_option( 'pb_recaptcha_sitekey', '' );

	if ( empty( $sitekey ) ) {
		return '';
	}
	ob_start();
	?>
	<div class="g-recaptcha" data-sitekey="<?php echo $sitekey; ?>" data-callback="recaptchaVerified" data-expired-callback="recaptchaExpired"></div>

	<script>
		function recaptchaVerified() {
			// Mark the recaptcha as verified
			document.querySelector('.g-recaptcha').dataset.verified = 'true';
		}

		function recaptchaExpired() {
			// Mark the recaptcha as expired
			document.querySelector('.g-recaptcha').dataset.verified = 'false';
		}

		document.addEventListener('DOMContentLoaded', function() {
			var form = document.querySelector('.g-recaptcha').closest('form');
			var submitBtn = form.querySelector('[type="submit"]');

			form.addEventListener('submit', function(e) {
				// Check if the reCAPTCHA is completed
				var recaptchaWidget = document.querySelector('.g-recaptcha');
				var isRecaptchaVerified = recaptchaWidget.dataset.verified === 'true';

				if (!isRecaptchaVerified) {
					e.preventDefault(); // Prevent form submission

					// Highlight the reCAPTCHA in red for 3 seconds
					recaptchaWidget.style.border = '1px solid red';
					if (recaptchaWidget.getBoundingClientRect().top < 15) {
						recaptchaWidget.scrollIntoView({ behavior: 'smooth', block: 'start', inline: 'nearest' });
					}
					setTimeout(function() {
						recaptchaWidget.style.border = 'none';
					}, 3000);
				}
			});
		});
	</script>
	<?php
	return ob_get_clean();
}

add_shortcode( 'portal-recaptcha', 'portal_recaptcha_shortcode' );
