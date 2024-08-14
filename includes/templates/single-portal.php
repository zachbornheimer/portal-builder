<?php
get_header();

if ( have_posts() ) :
	while ( have_posts() ) :
		the_post(); ?>
		<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
			
			<header class="entry-header">
				<!-- Application Form Header -->
				<p><em>Application Form for:</em></p>
				
				<h1 class="entry-title"><?php the_title(); ?></h1>

				<!-- Guidelines Link (if available) -->
				<?php 
				$guidelines_url = get_post_meta( get_the_ID(), '_portal_guidelines_url', true ); 
				if ( ! empty( $guidelines_url ) ) :
					?>
					<div style="text-align: center;">
						<em><span style="font-size: 12px;">Guidelines available at: 
						<a style="text-decoration: underline;" href="<?php echo esc_url( $guidelines_url ); ?>">
							<?php echo esc_html( $guidelines_url ); ?>
						</a></span></em>
					</div>
				<?php endif; ?>
			</header>

			<div class="entry-content">
				<?php the_content(); ?>
			</div>

			<footer class="entry-footer">
				<span class="posted-on"><?php the_date(); ?></span>
				<span class="byline"><?php the_author(); ?></span>
			</footer>
		</article>
		<?php
	endwhile;
endif;

get_footer();
