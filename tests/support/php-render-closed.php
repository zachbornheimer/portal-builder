<?php
/**
 * CLI harness: compose a closed/preview public page from shipped units.
 *
 * Mirrors the_content (closed renderer) plus leftover shortcodes that still
 * sit after the_content on the legacy template path.
 *
 * Usage:
 *   php tests/support/php-render-closed.php <payload.json>
 *
 * Payload: { "mode": "deadline"|"force"|"preview" }
 * Prints JSON: { html, formstart, formend, agreements, notes }.
 */

// phpcs:disable
class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
}
function is_wp_error( $t ) {
	return $t instanceof WP_Error;
}
function wp_json_encode( $d ) {
	return json_encode( $d );
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_html__( $s, $domain = '' ) {
	return esc_html( $s );
}
function esc_url( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function get_permalink( $post_id = 0 ) {
	return 'https://example.test/portal/' . (int) $post_id . '/';
}
function wp_login_url( $redirect = '' ) {
	$base = 'https://example.test/wp-login.php';
	if ( '' === (string) $redirect ) {
		return $base;
	}
	return $base . '?redirect_to=' . rawurlencode( (string) $redirect );
}
function wp_kses_post( $s ) {
	return strip_tags( (string) $s, '<p><br><em><strong><a><span><ul><ol><li>' );
}
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function add_shortcode( ...$args ) {}
function do_shortcode( $s ) {
	return $s;
}
function plugins_url( $path = '', $plugin = '' ) {
	return 'https://example.test/wp-content/plugins/dragongate/' . ltrim( (string) $path, '/' );
}
function wp_unslash( $v ) {
	return $v;
}
function wp_nonce_field( ...$args ) {}
function get_option( $key, $default = false ) {
	if ( 'pb_legal_disclaimers' === $key ) {
		return json_encode( array() );
	}
	return $default;
}

if ( ! class_exists( 'Portal_Submission_Pipeline' ) ) {
	class Portal_Submission_Pipeline {
		const NONCE_ACTION = 'dg_definition_submit';
		const NONCE_FIELD  = 'dg_definition_nonce';
	}
}

$GLOBALS['dg_closed_harness'] = array(
	'post_id'  => 42,
	'title'    => 'Call for Scores 2027',
	'preview'  => false,
	'meta'     => array(),
	'post'     => null,
);

function is_singular( $type = '' ) {
	return 'portal' === $type || '' === $type;
}
function in_the_loop() {
	return true;
}
function is_main_query() {
	return true;
}
function is_preview() {
	return ! empty( $GLOBALS['dg_closed_harness']['preview'] );
}
function current_user_can( $cap, $post_id = 0 ) {
	return ! empty( $GLOBALS['dg_closed_harness']['preview'] );
}
function get_the_ID() {
	return (int) $GLOBALS['dg_closed_harness']['post_id'];
}
function get_queried_object_id() {
	return (int) $GLOBALS['dg_closed_harness']['post_id'];
}
function get_the_title( $post_id = 0 ) {
	return (string) $GLOBALS['dg_closed_harness']['title'];
}
function get_post( $post_id = 0 ) {
	return $GLOBALS['dg_closed_harness']['post'];
}
function get_post_meta( $post_id, $key, $single = false ) {
	$meta = $GLOBALS['dg_closed_harness']['meta'];
	if ( ! array_key_exists( $key, $meta ) ) {
		return $single ? '' : array();
	}
	return $meta[ $key ];
}

$repo_root = dirname( __DIR__, 2 );
require_once $repo_root . '/includes/Definition/class-portal-access.php';
require_once $repo_root . '/includes/Definition/class-portal-definition.php';
require_once $repo_root . '/includes/Definition/class-portal-open-state.php';
require_once $repo_root . '/includes/Definition/class-portal-site-defaults.php';
require_once $repo_root . '/includes/Definition/class-portal-definition-renderer.php';
require_once $repo_root . '/includes/Definition/class-portal-public-render.php';
require_once $repo_root . '/includes/shortcodes/portal-application-agreements.php';
require_once $repo_root . '/includes/shortcodes/portal-application-upload-notes.php';
require_once $repo_root . '/includes/shortcodes/pb_formstart.php';
require_once $repo_root . '/includes/shortcodes/pb_formend.php';

$path = $argv[1] ?? '';
if ( ! is_readable( $path ) ) {
	fwrite( STDERR, "unreadable $path\n" );
	exit( 2 );
}
$payload = json_decode( file_get_contents( $path ), true );
if ( ! is_array( $payload ) || empty( $payload['mode'] ) ) {
	fwrite( STDERR, "payload.mode required\n" );
	exit( 2 );
}

$mode    = (string) $payload['mode'];
$post_id = 42;
$preview = ( 'preview' === $mode );

if ( 0 === strpos( $mode, 'restricted' ) ) {
	$reason = isset( $payload['reason'] ) ? (string) $payload['reason'] : 'login';
	if ( false !== strpos( $mode, 'membership' ) ) {
		$reason = 'membership';
	}
	$definition = array(
		'version' => 1,
		'title'   => 'Call for Scores 2027',
		'fields'  => array(
			array(
				'id'       => 'piece',
				'type'     => 'short_text',
				'label'    => 'Piece Name',
				'required' => true,
			),
		),
		'access'  => isset( $payload['access'] ) && is_array( $payload['access'] )
			? $payload['access']
			: array( 'audience' => 'logged_in' ),
		'options' => isset( $payload['options'] ) && is_array( $payload['options'] )
			? $payload['options']
			: array(),
	);
	$html = Portal_Public_Render::render_restricted_message(
		$post_id,
		$definition,
		array(
			'allowed' => false,
			'reason'  => $reason,
		)
	);
	echo json_encode(
		array(
			'ok'        => true,
			'mode'      => $mode,
			'reason'    => $reason,
			'html'      => $html,
			'hasAccess' => false !== strpos( $html, 'dg-access' ),
		),
		JSON_UNESCAPED_SLASHES
	) . "\n";
	exit( 0 );
}

$publish = array(
	'deadline'    => null,
	'timezone'    => 'America/New_York',
	'forceClosed' => false,
);
if ( 'deadline' === $mode ) {
	$publish['deadline'] = '2020-01-01T00:00:00';
} elseif ( 'force' === $mode || 'preview' === $mode ) {
	$publish['forceClosed'] = true;
	$publish['deadline']    = '2030-12-31T23:59:59';
}

$definition = array(
	'version' => 1,
	'title'   => 'Call for Scores 2027',
	'fields'  => array(
		array(
			'id'       => 'piece',
			'type'     => 'short_text',
			'label'    => 'Piece Name',
			'required' => true,
		),
	),
	'publish' => $publish,
);

$validated = Portal_Definition::validate( $definition );
if ( is_wp_error( $validated ) ) {
	fwrite( STDERR, $validated->code . ': ' . $validated->message . "\n" );
	exit( 1 );
}

$GLOBALS['dg_closed_harness']['preview'] = $preview;
$GLOBALS['dg_closed_harness']['post']    = (object) array(
	'ID'          => $post_id,
	'post_type'   => 'portal',
	'post_status' => 'publish',
);
$GLOBALS['dg_closed_harness']['meta']    = array(
	Portal_Definition::META_KEY => Portal_Definition::to_json( $validated ),
);

if ( $preview ) {
	$_GET['preview'] = 'true';
}

Portal_Public_Render::prime_request_flags();

$show_form = Portal_Open_State::should_show_form( $post_id );
if ( $show_form ) {
	$body = Portal_Public_Render::filter_content( '' );
} else {
	$body = Portal_Public_Render::render_closed_message( $post_id );
}

$agreements = (string) pb_application_agreements_shortcode();
$notes      = (string) pb_application_upload_notes_shortcode();
$formstart  = (string) portal_application_formstart_shortcode();
$formend    = (string) portal_application_formend_shortcode();

$html = $body . $agreements . $notes;

$deadline = Portal_Public_Render::MSG_DEADLINE;
$closed   = Portal_Public_Render::MSG_CLOSED;

echo json_encode(
	array(
		'ok'             => true,
		'mode'           => $mode,
		'show_form'      => $show_form,
		'reason'         => Portal_Open_State::closed_reason( $post_id ),
		'html'           => $html,
		'body'           => $body,
		'agreements'     => $agreements,
		'notes'          => $notes,
		'formstart'      => $formstart,
		'formend'        => $formend,
		'deadline_count' => substr_count( $html, $deadline ),
		'has_closed'     => false !== strpos( $html, $closed ),
		'has_deadline'   => false !== strpos( $html, $deadline ),
		'has_form'       => false !== strpos( $html, 'data-dg-render="definition"' ),
		'has_preview'    => false !== strpos( $html, 'data-dg-preview="true"' ),
		'flags'          => array(
			'deadline_passed' => defined( 'PB_APPLICATION_DEADLINE_PASSED' ) && PB_APPLICATION_DEADLINE_PASSED,
			'closed'          => defined( 'PB_APPLICATION_CLOSED' ) && PB_APPLICATION_CLOSED,
		),
	),
	JSON_UNESCAPED_SLASHES
) . "\n";
exit( 0 );
