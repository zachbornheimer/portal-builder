<?php
function portal_applicant_information_shortcode() {
	ob_start();
	?>
	<div class="portal-group">
		<fieldset>
			<legend>Your Information</legend>

			<div class="form-grid">
				<div class="form-group">
					<label for="sub_title" id="sub_title_label">Title*</label>
					<input style="width: auto;" maxlength="5" name="sub_title" id="sub_title" size="5" type="text" required aria-required="true" aria-labelledby="sub_title_label" autocomplete="honorific-prefix" />
				</div>

				<div class="form-group">
					<label for="sub_name" id="sub_name_label">Name*</label>
					<input name="sub_name" id="sub_name" type="text" required aria-required="true" aria-labelledby="sub_name_label" autocomplete="name" />
				</div>

				<div class="form-group">
					<label for="sub_email" id="sub_email_label">Email Address*</label>
					<input name="sub_email" id="sub_email" type="email" required aria-required="true" aria-labelledby="sub_email_label" autocomplete="email" />
				</div>

				<div class="form-group">
					<label for="sub_inst_affil" id="sub_inst_affil_label">Institutional Affiliation<br /><span class="caption">(if any)</span></label>
					<input name="sub_inst_affil" id="sub_inst_affil" type="text" aria-labelledby="sub_inst_affil_label" autocomplete="organization" />
				</div>

				<div class="form-group">
					<label for="sub_address_first_part" id="sub_address_first_part_label">Address (Home/Work)*</label>
					<input name="sub_address_first_part" id="sub_address_first_part" type="text" required aria-required="true" aria-labelledby="sub_address_first_part_label" autocomplete="street-address" />
				</div>

				<div class="form-group">
					<label for="sub_city" id="sub_city_label">City*</label>
					<input name="sub_city" id="sub_city" type="text" required aria-required="true" aria-labelledby="sub_city_label" autocomplete="address-level2" />
				</div>

				<div class="form-group">
					<label for="sub_country" id="sub_country_label">Country*</label>
					<select class="gds-cr" country-data-region-id="gds-cr-one" data-language="en" style="color: black;" name="sub_country" id="sub_country" required aria-required="true" aria-labelledby="sub_country_label" autocomplete="country"></select>
				</div>

				<div class="form-group">
					<label for="sub_state" id="sub_state_label">State/Region*</label>
					<select id="gds-cr-one" style="color: black;" name="sub_state" required aria-required="true" aria-labelledby="sub_state_label" autocomplete="address-level1" ></select>
				</div>

				<div class="form-group">
					<label for="sub_zip" id="sub_zip_label">Zip</label>
					<input name="sub_zip" id="sub_zip" type="text" aria-labelledby="sub_zip_label" autocomplete="postal-code" />
				</div>

				<div class="form-group">
					<label for="sub_phone" id="sub_phone_label">Phone*</label>
					<input name="sub_phone" id="sub_phone" type="tel" required aria-required="true" aria-labelledby="sub_phone_label" autocomplete="tel" />
				</div>
			</div>
		</fieldset>
	</div>
	<?php
	return ob_get_clean();
}

add_shortcode( 'portal-applicant-information', 'portal_applicant_information_shortcode' );
