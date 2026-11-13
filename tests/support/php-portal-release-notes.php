<?php
/**
 * Drive Portal_Release_Notes without WordPress.
 *
 * Usage: php tests/support/php-portal-release-notes.php
 */
// phpcs:disable
$repo = dirname( __DIR__, 2 );
require_once $repo . '/includes/class-portal-release-notes.php';

$subjects = array(
	'chore(release): 0.1.7',
	'feat: let signed-in administrators see members portals',
	'fix: show every site disclosure on the definition form',
	'random commit without type',
);

$md = Portal_Release_Notes::markdown_from_subjects(
	$subjects,
	'0.1.7',
	'https://github.com/zachbornheimer/portal-builder/compare/v0.1.6...v0.1.7'
);
$html = Portal_Release_Notes::html_from_markdown( $md );

$github_style = "## What's Changed\n\n* feat: something useful by @dev in #1\n\n**Full Changelog**: https://example.com/compare/a...b\n";
$github_html  = Portal_Release_Notes::html_from_markdown( $github_style );

echo json_encode(
	array(
		'mdHasFeatures'   => false !== strpos( $md, '### Features' ),
		'mdHasAdmins'     => false !== strpos( $md, 'administrators' ),
		'mdHasFixes'      => false !== strpos( $md, '### Fixes' ),
		'mdSkipsRelease'  => false === strpos( $md, 'chore(release)' ),
		'mdHasCompare'    => false !== strpos( $md, 'v0.1.6...v0.1.7' ),
		'mdHasOther'      => false !== strpos( $md, '### Other' ),
		'htmlHasHeading'  => ( false !== strpos( $html, '<h3>' ) ) || ( false !== strpos( $html, '<h4>' ) ),
		'htmlHasListItem' => false !== strpos( $html, '<li>' ) && false !== strpos( $html, 'administrators' ),
		'htmlHasStrong'   => false !== strpos( $html, '<strong>' ),
		'githubHasList'   => false !== strpos( $github_html, '<li>' ),
		'githubHasH3'     => false !== strpos( $github_html, '<h3>' ),
	)
) . "\n";
