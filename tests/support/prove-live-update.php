<?php
/**
 * One-shot: prove Portal_Update on a live WP. Run via `wp eval-file`.
 */
$release = array(
	'tag_name'   => 'v0.2.0',
	'prerelease' => false,
	'draft'      => false,
	'html_url'   => 'https://example.test/v0.2.0',
	'assets'     => array(
		array(
			'name'                 => 'portal-builder-0.2.0.zip',
			'browser_download_url' => 'https://example.test/portal-builder-0.2.0.zip',
		),
	),
);
$offer = Portal_Update::offer_from_release( $release, DG_VERSION );
$file  = Portal_Update::plugin_file();
$t     = Portal_Update::inject( (object) array(), $file, $offer );
$latest = Portal_Update::fetch_latest();
echo 'installed=' . DG_VERSION . "\n";
echo 'plugin=' . $file . "\n";
echo 'offer=' . ( is_array( $offer ) ? $offer['version'] : 'none' ) . "\n";
echo 'injected=' . ( isset( $t->response[ $file ] ) ? $t->response[ $file ]->new_version : 'no' ) . "\n";
echo 'github_latest=' . ( isset( $latest['tag_name'] ) ? $latest['tag_name'] : 'empty' ) . "\n";
echo 'github_zip=' . Portal_Update::zip_url( $latest ) . "\n";
echo 'same_ignored=' . ( null === Portal_Update::offer_from_release( $latest, DG_VERSION ) ? 'yes' : 'no' ) . "\n";
$loc = Portal_Update::release_from_location( 'https://github.com/zachbornheimer/portal-builder/releases/tag/v0.2.0' );
echo 'redirect_offer=' . ( is_array( Portal_Update::offer_from_release( $loc, DG_VERSION ) ) ? Portal_Update::offer_from_release( $loc, DG_VERSION )['version'] : 'none' ) . "\n";
