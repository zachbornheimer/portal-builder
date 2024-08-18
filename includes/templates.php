<?php

// include shortcodes
require_once plugin_dir_path( __FILE__ ) . 'shortcodes.php';

// Redirect to the appropriate template based on theme type
// Load custom template for single portal post
function portal_template_redirect( $template ) {
	if ( is_singular( 'portal' ) ) {
		// Check if the theme is block-based or classic
		if ( wp_is_block_theme() ) {
			$block_template = plugin_dir_path( __FILE__ ) . '../templates/single-portal.block.php';
			if ( file_exists( $block_template ) ) {
				return $block_template;
			}
		} else {
			$plugin_template = plugin_dir_path( __FILE__ ) . 'templates/single-portal.php';
			if ( file_exists( $plugin_template ) ) {
				return $plugin_template;
			}
		}
	}
	return $template;
}
add_filter( 'template_include', 'portal_template_redirect' );

function register_portal_application_title_block() {
	register_block_type(
		'portal/application-title',
		array(
			'attributes'      => array(
				'align'  => array(
					'type'    => 'string',
					'default' => 'full',
				),
				'lock'   => array(
					'type'    => 'object',
					'default' => array(
						'move'   => false,
						'remove' => true,
					),
				),
				'layout' => array(
					'type'    => 'object',
					'default' => array(
						'type' => 'constrained',
					),
				),
			),
			'render_callback' => 'render_portal_application_title_block',
			'supports'        => array(
				'align' => array( 'full', 'wide' ),
			),
		)
	);
}
add_action( 'init', 'register_portal_application_title_block' );

function render_portal_application_title_block( $attributes, $content ) {
	// Get the guidelines URL from the post meta
	$guidelines_url = get_post_meta( get_the_ID(), '_portal_guidelines_url', true );

	// Get attributes
	$align     = isset( $attributes['align'] ) ? $attributes['align'] : 'full';
	$layout    = isset( $attributes['layout']['type'] ) ? $attributes['layout']['type'] : 'is-layout-constrained';
	$className = 'wp-block-group align' . esc_attr( $align ) . ' layout-' . esc_attr( $layout );

	$className = 'entry-content alignfull wp-block-post-content has-global-padding is-layout-constrained wp-block-post-content-is-layout-constrained';

	// Start the output buffer
	ob_start();
	?>
	<div class="<?php echo $className; ?>">
		<p><em>Application Form for:</em></p>
		<h2 style="text-align: center;"><?php echo esc_html( get_the_title() ); ?></h2>

		<?php if ( ! empty( $guidelines_url ) ) : ?>
			<div style="text-align: center; margin:0px;padding:0px;">
				<em><span style="font-size: 12px;">Guidelines available at:
						<a style="text-decoration: underline;" href="<?php echo esc_url( $guidelines_url ); ?>" target="_blank">
							<?php echo esc_html( $guidelines_url ); ?>
						</a></span></em>
			</div>
		<?php endif; ?>
	</div>
	<?php
	// Return the buffer content
	return ob_get_clean();
}

function register_portal_application_formstart_block() {
	register_block_type(
		'portal/application-formstart',
		array(
			'attributes'      => array(
				'align'  => array(
					'type'    => 'string',
					'default' => 'full',
				),
				'lock'   => array(
					'type'    => 'object',
					'default' => array(
						'move'   => false,
						'remove' => true,
					),
				),
				'layout' => array(
					'type'    => 'object',
					'default' => array(
						'type' => 'constrained',
					),
				),
				'nonce'  => array(
					'type'    => 'string',
					'default' => 'review_nonce',
				),
			),
			'render_callback' => 'render_portal_application_formstart_block',
			'supports'        => array(
				'align' => array( 'full', 'wide' ),
			),
		)
	);
}
add_action( 'init', 'register_portal_application_formstart_block' );

function render_portal_application_formstart_block( $attributes, $content ) {
	if ( defined( 'PB_APPLICATION_SUBMITTED' ) && PB_APPLICATION_SUBMITTED ) {
		return;
	}
	if ( defined( 'PB_APPLICATION_DEADLINE_PASSED' ) && PB_APPLICATION_DEADLINE_PASSED ) {
		return;
	}
	// Get the guidelines URL from the post meta
	$guidelines_url = get_post_meta( get_the_ID(), '_portal_guidelines_url', true );

	// Get attributes
	$align     = isset( $attributes['align'] ) ? $attributes['align'] : 'full';
	$layout    = isset( $attributes['layout']['type'] ) ? $attributes['layout']['type'] : 'is-layout-constrained';
	$className = 'wp-block-group align' . esc_attr( $align ) . ' layout-' . esc_attr( $layout );
	$nonceName = isset( $attributes['nonce'] ) ? $attributes['nonce'] : 'review_nonce';
	// if last round had this nonce, change it to the next nonce, which is ready_to_submit_nonce (the last)
	if ( isset( $_POST['review_nonce'] ) ) {
		$nonceName = 'ready_to_submit_nonce';
	}

	$className = 'entry-content alignfull wp-block-post-content has-global-padding is-layout-constrained wp-block-post-content-is-layout-constrained';

	// Start the output buffer
	ob_start();
	?>
	<form action="" enctype="multipart/form-data" method="post">
		<input type="hidden" name="post_id" value="<?php echo get_the_ID(); ?>" />
	<?php
	wp_nonce_field( $nonceName, $nonceName );
	// Return the buffer content
	return ob_get_clean();
}

function register_portal_application_formend_block() {
	register_block_type(
		'portal/application-formend',
		array(
			'attributes'      => array(
				'align'  => array(
					'type'    => 'string',
					'default' => 'full',
				),
				'lock'   => array(
					'type'    => 'object',
					'default' => array(
						'move'   => false,
						'remove' => true,
					),
				),
				'layout' => array(
					'type'    => 'object',
					'default' => array(
						'type' => 'constrained',
					),
				),
			),
			'render_callback' => 'render_portal_application_formend_block',
			'supports'        => array(
				'align' => array( 'full', 'wide' ),
			),
		)
	);
}
add_action( 'init', 'register_portal_application_formend_block' );

function render_portal_application_formend_block( $attributes, $content ) {
	if ( defined( 'PB_APPLICATION_SUBMITTED' ) && PB_APPLICATION_SUBMITTED ) {
		return;
	}
	if ( defined( 'PB_APPLICATION_DEADLINE_PASSED' ) && PB_APPLICATION_DEADLINE_PASSED ) {
		return;
	}
	// Get the guidelines URL from the post meta
	$guidelines_url = get_post_meta( get_the_ID(), '_portal_guidelines_url', true );

	// Get attributes
	$align     = isset( $attributes['align'] ) ? $attributes['align'] : 'full';
	$layout    = isset( $attributes['layout']['type'] ) ? $attributes['layout']['type'] : 'is-layout-constrained';
	$className = 'wp-block-group align' . esc_attr( $align ) . ' layout-' . esc_attr( $layout );

	$className = 'entry-content alignfull wp-block-post-content has-global-padding is-layout-constrained wp-block-post-content-is-layout-constrained';

	// Start the output buffer
	ob_start();
	?>
	</form>
	<?php
	// Return the buffer content
	return ob_get_clean();
}


function register_portal_application_file_review_block() {
	register_block_type(
		'portal/application-file-review',
		array(
			'attributes'      => array(
				'align'  => array(
					'type'    => 'string',
					'default' => 'full',
				),
				'lock'   => array(
					'type'    => 'object',
					'default' => array(
						'move'   => false,
						'remove' => true,
					),
				),
				'layout' => array(
					'type'    => 'object',
					'default' => array(
						'type' => 'constrained',
					),
				),
				'nonce'  => array(
					'type'    => 'string',
					'default' => 'review_nonce',
				),
			),
			'render_callback' => 'render_portal_application_file_review_block',
			'supports'        => array(
				'align' => array( 'full', 'wide' ),
			),
		)
	);
}
add_action( 'init', 'register_portal_application_file_review_block' );

function render_portal_application_file_review_block( $attributes, $content ) {
	// Get the guidelines URL from the post meta

	if ( ! isset( $_POST['review_nonce'] ) ) {
		return;
	}

	$guidelines_url = get_post_meta( get_the_ID(), '_portal_guidelines_url', true );

	// Get attributes
	$align     = isset( $attributes['align'] ) ? $attributes['align'] : 'full';
	$layout    = isset( $attributes['layout']['type'] ) ? $attributes['layout']['type'] : 'is-layout-constrained';
	$className = 'wp-block-group align' . esc_attr( $align ) . ' layout-' . esc_attr( $layout );
	$nonceName = isset( $attributes['nonce'] ) ? $attributes['nonce'] : 'review_nonce';
	// if last round had this nonce, change it to the next nonce, which is ready_to_submit_nonce (the last)
	if ( isset( $_POST['review_nonce'] ) ) {
		$nonceName = 'ready_to_submit_nonce';
	}

	$className = 'entry-content alignfull wp-block-post-content has-global-padding is-layout-constrained wp-block-post-content-is-layout-constrained';

	// Start the output buffer
	ob_start();
	?>
	<div class="<?php echo $className; ?>">
		<fieldset style="margin-bottom:30px; ">
			<legend>Review Submitted Files</legend>
			<span class="instructions">Please review your files to ensure that they have been processed correctly. Each link opens in a new tab</span>
			<ul>
				<?php
				$file_handler = PB_FILE_HANDLER;
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
						echo '<li><a href="' . $url . '" target="_blank" data-display-label-override="1" data-display-label="' . $key . '">' . esc_html( $file_name ) . '</a></li>';
					}
				}
				?>
			</ul>
		</fieldset>
		<input type="hidden" name="eappId" value="<?php echo $_POST['eappId']; ?>" />
		<input type="hidden" name="efiles" value="<?php echo $_POST['efiles']; ?>" />
	</div>
	<?php

	// Return the buffer content
	return ob_get_clean();
}


function register_portal_application_upload_notes_block() {
	register_block_type(
		'portal/application-upload-notes',
		array(
			'attributes'      => array(
				'align'  => array(
					'type'    => 'string',
					'default' => 'full',
				),
				'lock'   => array(
					'type'    => 'object',
					'default' => array(
						'move'   => false,
						'remove' => true,
					),
				),
				'layout' => array(
					'type'    => 'object',
					'default' => array(
						'type' => 'constrained',
					),
				),
			),
			'render_callback' => 'render_portal_application_upload_notes_block',
			'supports'        => array(
				'align' => array( 'full', 'wide' ),
			),
		)
	);
}
add_action( 'init', 'register_portal_application_upload_notes_block' );

function render_portal_application_upload_notes_block( $attributes, $content ) {

	if ( defined( 'PB_APPLICATION_SUBMITTED' ) && PB_APPLICATION_SUBMITTED ) {
		return;
	}
	if ( defined( 'PB_APPLICATION_DEADLINE_PASSED' ) && PB_APPLICATION_DEADLINE_PASSED ) {
		return;
	}

	if ( isset( $_POST['review_nonce'] ) || isset( $_POST['ready_to_submit_nonce'] ) ) {
		return;
	}

	// Get the guidelines URL from the post meta
	$guidelines_url = get_post_meta( get_the_ID(), '_portal_guidelines_url', true );

	// Get attributes
	$align     = isset( $attributes['align'] ) ? $attributes['align'] : 'full';
	$layout    = isset( $attributes['layout']['type'] ) ? $attributes['layout']['type'] : 'is-layout-constrained';
	$className = 'wp-block-group align' . esc_attr( $align ) . ' layout-' . esc_attr( $layout );

	$className = 'entry-content alignfull wp-block-post-content has-global-padding is-layout-constrained wp-block-post-content-is-layout-constrained';

	// Start the output buffer
	ob_start();
	?>
	<div class="<?php echo $className; ?>">
		<div style="padding: 15px; background-color: rgba(155, 150, 150, 0.5); border-radius: 25px; margin-top: 20px; margin-bottom: 10px;">
			<h3 style="text-decoration: underline;">Note:</h3>
			Depending on the size of your uploaded files, it may take a while to upload your files. Please keep file uploads to under 32MB.
			<br /><br />
			"*" denotes Required Field. For the Call For Scores, all fields are required in the categories you choose.
			<br /><br />
			If you need help converting your files to pdf's visit: <a href="https://cloudconvert.com/anything-to-pdf">https://cloudconvert.com/anything-to-pdf</a><br />
			If you need help converting your files to mp3's visit: <a href="https://cloudconvert.com/anything-to-mp3">https://cloudconvert.com/anything-to-mp3</a>

		</div>
	</div>
	<?php
	// Return the buffer content
	return ob_get_clean();
}


function register_portal_application_agreements_block() {
	register_block_type(
		'portal/application-agreements',
		array(
			'attributes'      => array(
				'align'  => array(
					'type'    => 'string',
					'default' => 'full',
				),
				'lock'   => array(
					'type'    => 'object',
					'default' => array(
						'move'   => false,
						'remove' => true,
					),
				),
				'layout' => array(
					'type'    => 'object',
					'default' => array(
						'type' => 'constrained',
					),
				),
			),
			'render_callback' => 'render_portal_application_agreements_block',
			'supports'        => array(
				'align' => array( 'full', 'wide' ),
			),
		)
	);
}
add_action( 'init', 'register_portal_application_agreements_block' );

function render_portal_application_agreements_block( $attributes, $content ) {
	if ( defined( 'PB_APPLICATION_SUBMITTED' ) && PB_APPLICATION_SUBMITTED ) {
		return;
	}
	if ( defined( 'PB_APPLICATION_DEADLINE_PASSED' ) && PB_APPLICATION_DEADLINE_PASSED ) {
		return;
	}

	$align     = isset( $attributes['align'] ) ? $attributes['align'] : 'full';
	$layout    = isset( $attributes['layout']['type'] ) ? $attributes['layout']['type'] : 'is-layout-constrained';
	$className = 'wp-block-group align' . esc_attr( $align ) . ' layout-' . esc_attr( $layout );
	$className = 'entry-content alignfull wp-block-post-content has-global-padding is-layout-constrained wp-block-post-content-is-layout-constrained';

	$disclaimers = json_decode( get_option( 'pb_legal_disclaimers', json_encode( array() ) ), true );
	# keep decoding until we get an array
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

	$disclaimers = $agreements;
	$readonly    = isset( $_POST['review_nonce'] ) ? ' disabled="disabled" ' : '';

	ob_start();
	?>
	<div class="<?php echo $className; ?>" style="margin-top:30px">
		<?php if ( $disclaimers ) : ?>
		<p class="row description">Please mark the statements below. To proceed with the application, all statements must be agreed to.</p>
		<?php endif; ?>
		<?php
		foreach ( $disclaimers as $index => $disclaimer ) :
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
			[portal-recaptcha] <?php endif; ?>
		&nbsp;
		<p class="sub_submit_container"><input class="btn btn-primary sub_submit" name="sub_submit" type="submit" value="<?php echo ( $readonly ? 'Submit' : 'Continue' ); ?>" /></p>
	</div>
		<?php

		return do_shortcode( ob_get_clean() );
}

function register_portal_application_information_block() {
	register_block_type(
		'portal/application-information',
		array(
			'attributes'      => array(
				'align'  => array(
					'type'    => 'string',
					'default' => 'full',
				),
				'lock'   => array(
					'type'    => 'object',
					'default' => array(
						'move'   => false,
						'remove' => true,
					),
				),
				'layout' => array(
					'type'    => 'object',
					'default' => array(
						'type' => 'constrained',
					),
				),
			),
			'render_callback' => 'render_portal_application_information_block',
			'supports'        => array(
				'align' => array( 'full', 'wide' ),
			),
		)
	);
}
add_action( 'init', 'register_portal_application_information_block' );

function render_portal_application_information_block( $attributes, $content ) {
	// Get the guidelines URL from the post meta
	$guidelines_url = get_post_meta( get_the_ID(), '_portal_guidelines_url', true );

	// Get attributes
	$align     = isset( $attributes['align'] ) ? $attributes['align'] : 'full';
	$layout    = isset( $attributes['layout']['type'] ) ? $attributes['layout']['type'] : 'is-layout-constrained';
	$className = 'wp-block-group align' . esc_attr( $align ) . ' layout-' . esc_attr( $layout );

	$className = 'entry-content alignfull wp-block-post-content has-global-padding is-layout-constrained wp-block-post-content-is-layout-constrained';




	// Start the output buffer
	ob_start();
	?>
	<div class="<?php echo $className; ?>">
		[portal-applicant-information]
	</div>
	<?php
	// Return the buffer content
	return do_shortcode( ob_get_clean() );
}


// Function to get the portal or global script URL
function get_portal_county_region_script_url( $post_id ) {
	// Get the portal-specific script URL
	$portal_script_url = get_post_meta( $post_id, '_portal_county_region_script', true );

	// If the portal-specific script is empty, fallback to the global setting
	if ( empty( $portal_script_url ) ) {
		$portal_script_url = get_option( 'pb_county_region_script', '' );
	}

	return esc_url( $portal_script_url );
}

// Function to enqueue the script for classic themes
function enqueue_portal_script_classic() {
	if ( is_singular( 'portal' ) ) {
		global $post;
		$script_url = get_portal_county_region_script_url( $post->ID );

		if ( ! empty( $script_url ) ) {
			echo '<script type="text/javascript" src="' . $script_url . '" defer></script>';
		}
	}
}
add_action( 'wp_head', 'enqueue_portal_script_classic' );

// Function to enqueue the script for block themes
function enqueue_portal_county_region_script() {
	if ( is_singular( 'portal' ) ) {
		global $post;
		$script_url = get_portal_county_region_script_url( $post->ID );

		if ( ! empty( $script_url ) ) {
			wp_enqueue_script( 'portal-county-region-script', $script_url, array( 'jquery', 'jquery-ui-core' ), null, true );
		}
	}
}
add_action( 'wp_enqueue_scripts', 'enqueue_portal_county_region_script' );

// enqueue recaptcha
function enqueue_recaptcha_script() {
	if ( is_singular( 'portal' ) ) {
		global $post;
		$script_url = 'https://www.google.com/recaptcha/api.js';

		if ( ! empty( $script_url ) ) {
			wp_enqueue_script( 'recaptcha', $script_url, array(), null, true );
		}
	}
}
add_action( 'wp_enqueue_scripts', 'enqueue_recaptcha_script' );

// // Conditionally enqueue the appropriate script loader
// function conditional_enqueue_portal_script() {
//     if ( wp_is_block_theme() ) {
//         remove_action( 'wp_head', 'enqueue_portal_script_classic' );
//         add_action( 'wp_enqueue_scripts', 'enqueue_portal_script_block' );
//     }
// }
// add_action( 'after_setup_theme', 'conditional_enqueue_portal_script' );



function portal_enqueue_styles() {
	// Check if we are on a single portal post
	if ( is_singular( 'portal' ) ) {
		wp_enqueue_style( 'portal-styles', plugins_url( '../assets/portal.css', __FILE__ ) );

		// enqueue portal.js
		wp_enqueue_script( 'portal-js', plugins_url( '../assets/portal.js', __FILE__ ), array( 'jquery' ), null, true );
	}
}
add_action( 'wp_enqueue_scripts', 'portal_enqueue_styles' );
