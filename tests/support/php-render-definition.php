<?php
/**
 * CLI harness: render a definition JSON file without bootstrapping WordPress.
 * Polyfills escape helpers used by the renderer.
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
function wp_kses_post( $s ) {
	// Allow a small safe subset for static_html / disclaimer tests.
	return strip_tags( (string) $s, '<p><br><em><strong><a><span><ul><ol><li>' );
}

require_once dirname( __DIR__, 2 ) . '/includes/Definition/class-portal-definition.php';
require_once dirname( __DIR__, 2 ) . '/includes/Definition/class-portal-definition-renderer.php';

$path = $argv[1] ?? '';
if ( ! is_readable( $path ) ) {
	fwrite( STDERR, "unreadable $path\n" );
	exit( 2 );
}
$raw    = file_get_contents( $path );
$result = Portal_Definition::from_json( $raw );
if ( is_wp_error( $result ) ) {
	fwrite( STDERR, $result->code . ': ' . $result->message . "\n" );
	exit( 1 );
}

$html = Portal_Definition_Renderer::render( $result );
if ( '' === $html ) {
	fwrite( STDERR, "empty render\n" );
	exit( 1 );
}

$out = array(
	'ok'   => true,
	'html' => $html,
	'checks' => array(
		'data_dg_render' => false !== strpos( $html, 'data-dg-render="definition"' ),
		'title_of_work'  => false !== strpos( $html, 'Title of Work' ),
		'full_score'     => false !== strpos( $html, 'Full Score' ),
		'recording'      => false !== strpos( $html, 'Recording' ),
		'applicant_pack' => false !== strpos( $html, 'data-dg-field-type="applicant_pack"' ),
		'work_title_name'=> false !== strpos( $html, 'name="sub_work_title"' ),
		'score_accept'   => false !== strpos( $html, 'application/pdf' ),
	),
);
echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( 0 );
