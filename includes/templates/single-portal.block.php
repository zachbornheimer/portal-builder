<!-- wp:template-part {"slug":"header"} /-->

<!-- wp:group {"tagName":"div","align":"wide"} -->
<div class="wp-block-group alignwide">

	<!-- wp:paragraph -->
	<p><em>Application Form for:</em></p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"textAlign":"center","level":2} -->
	<h2 class="has-text-align-center"><?php echo esc_html( get_the_title() ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:html -->
	<div style="text-align: center;">
		<em><span style="font-size: 12px;">Guidelines available at: 
		<a style="text-decoration: underline;" href="<?php echo esc_url( get_post_meta( get_the_ID(), '_portal_guidelines_url', true ) ); ?>">
			<?php echo esc_html( get_post_meta( get_the_ID(), '_portal_guidelines_url', true ) ); ?>
		</a></span></em>
	</div>
	<!-- /wp:html -->

	<!-- wp:post-content /-->

</div>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer"} /-->
