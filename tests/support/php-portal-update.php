<?php
/**
 * Drive Portal_Update without WordPress.
 *
 * Usage: php tests/support/php-portal-update.php
 */
// phpcs:disable
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}

$repo = dirname( __DIR__, 2 );
require_once $repo . '/includes/class-portal-update.php';

$release_newer = array(
	'tag_name'   => 'v0.2.0',
	'prerelease' => false,
	'draft'      => false,
	'html_url'   => 'https://github.com/zachbornheimer/portal-builder/releases/tag/v0.2.0',
	'assets'     => array(
		array(
			'name'                 => 'portal-builder-0.2.0.zip',
			'browser_download_url' => 'https://github.com/zachbornheimer/portal-builder/releases/download/v0.2.0/portal-builder-0.2.0.zip',
		),
	),
);

$release_same = $release_newer;
$release_same['tag_name'] = 'v0.1.0';
$release_same['assets'][0]['name'] = 'portal-builder-0.1.0.zip';

$release_pre = $release_newer;
$release_pre['prerelease'] = true;

$release_rolling = $release_newer;
$release_rolling['tag_name'] = 'rolling-release-26-08-16-abc';

$offer = Portal_Update::offer_from_release( $release_newer, '0.1.0' );
$same  = Portal_Update::offer_from_release( $release_same, '0.1.0' );
$pre   = Portal_Update::offer_from_release( $release_pre, '0.1.0' );
$roll  = Portal_Update::offer_from_release( $release_rolling, '0.1.0' );

$transient = Portal_Update::inject(
	(object) array( 'checked' => array( 'portal-builder-0.0.4a/portal-builder.php' => '0.1.0' ) ),
	'portal-builder-0.0.4a/portal-builder.php',
	$offer
);

$dir = sys_get_temp_dir() . '/dg-update-' . bin2hex( random_bytes( 4 ) );
mkdir( $dir );
mkdir( $dir . '/portal-builder' );
file_put_contents( $dir . '/portal-builder/portal-builder.php', "<?php\n" );
$renamed = Portal_Update::rename_source( $dir . '/portal-builder', 'portal-builder-0.0.4a' );
$kept    = is_dir( $dir . '/portal-builder-0.0.4a' ) && is_file( $dir . '/portal-builder-0.0.4a/portal-builder.php' );
array_map( 'unlink', glob( $dir . '/portal-builder-0.0.4a/*' ) ?: array() );
@rmdir( $dir . '/portal-builder-0.0.4a' );
@rmdir( $dir . '/portal-builder' );
@rmdir( $dir );

echo json_encode(
	array(
		'newer'        => is_array( $offer ) && '0.2.0' === $offer['version'],
		'packageZip'   => is_array( $offer ) && false !== strpos( $offer['package'], 'portal-builder-0.2.0.zip' ),
		'sameIgnored'  => null === $same,
		'preIgnored'   => null === $pre,
		'rollIgnored'  => null === $roll,
		'injected'     => isset( $transient->response['portal-builder-0.0.4a/portal-builder.php'] ),
		'newVersion'   => isset( $transient->response['portal-builder-0.0.4a/portal-builder.php'] )
			? $transient->response['portal-builder-0.0.4a/portal-builder.php']->new_version
			: '',
		'folderKept'   => $kept,
		'renamedPath'  => basename( rtrim( (string) $renamed, '/\\' ) ),
		'semverV'      => Portal_Update::semver( 'v0.1.0' ),
		'notNewer'     => ! Portal_Update::is_newer( '0.1.0', '0.1.0' ),
		'fromLocation' => Portal_Update::release_from_location(
			'https://github.com/zachbornheimer/portal-builder/releases/tag/v0.2.0'
		),
	)
) . "\n";
