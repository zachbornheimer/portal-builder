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
if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path, $file = '' ) {
		unset( $file );
		return 'https://example.test/wp-content/plugins/portal-builder/' . ltrim( (string) $path, '/' );
	}
}
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		unset( $file );
		return 'portal-builder/portal-builder.php';
	}
}

$repo = dirname( __DIR__, 2 );
require_once $repo . '/includes/class-portal-release-notes.php';
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

$injected = isset( $transient->response['portal-builder-0.0.4a/portal-builder.php'] )
	? $transient->response['portal-builder-0.0.4a/portal-builder.php']
	: null;

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

$release_info = array(
	'tag_name'     => 'v0.1.7',
	'prerelease'   => false,
	'draft'        => false,
	'html_url'     => 'https://github.com/zachbornheimer/portal-builder/releases/tag/v0.1.7',
	'published_at' => '2026-08-16T12:00:00Z',
	'body'         => "## 0.1.7\n\n### Features\n- Let signed-in administrators see members portals\n\n**Full Changelog**: https://github.com/zachbornheimer/portal-builder/compare/v0.1.6...v0.1.7\n",
	'assets'       => array(
		array(
			'name'                 => 'portal-builder-0.1.7.zip',
			'browser_download_url' => 'https://github.com/zachbornheimer/portal-builder/releases/download/v0.1.7/portal-builder-0.1.7.zip',
		),
	),
);

$info = method_exists( 'Portal_Update', 'info_from_release' )
	? Portal_Update::info_from_release( $release_info )
	: null;

$thin = $release_info;
$thin['body'] = '**Full Changelog**: https://github.com/zachbornheimer/portal-builder/compare/v0.1.6...v0.1.7';
$thin_info = method_exists( 'Portal_Update', 'info_from_release' )
	? Portal_Update::info_from_release( $thin )
	: null;
$thin_changelog = ( is_object( $thin_info ) && isset( $thin_info->sections['changelog'] ) )
	? (string) $thin_info->sections['changelog']
	: '';

$icon_1x = ( is_object( $injected ) && isset( $injected->icons['1x'] ) ) ? (string) $injected->icons['1x'] : '';
$icon_default = ( is_object( $injected ) && isset( $injected->icons['default'] ) ) ? (string) $injected->icons['default'] : '';

echo json_encode(
	array(
		'newer'        => is_array( $offer ) && '0.2.0' === $offer['version'],
		'packageZip'   => is_array( $offer ) && false !== strpos( $offer['package'], 'portal-builder-0.2.0.zip' ),
		'sameIgnored'  => null === $same,
		'preIgnored'   => null === $pre,
		'rollIgnored'  => null === $roll,
		'injected'     => null !== $injected,
		'newVersion'   => is_object( $injected ) ? (string) $injected->new_version : '',
		'folderKept'   => $kept,
		'renamedPath'  => basename( rtrim( (string) $renamed, '/\\' ) ),
		'semverV'      => Portal_Update::semver( 'v0.1.0' ),
		'notNewer'     => ! Portal_Update::is_newer( '0.1.0', '0.1.0' ),
		'fromLocation' => Portal_Update::release_from_location(
			'https://github.com/zachbornheimer/portal-builder/releases/tag/v0.2.0'
		),
		'hasIcons'     => is_object( $injected ) && isset( $injected->icons ) && is_array( $injected->icons ),
		'icon1x'       => $icon_1x,
		'iconDefault'  => $icon_default,
		'iconSeal'     => ( false !== strpos( $icon_1x, 'icon-128' ) ) || ( false !== strpos( $icon_1x, 'sidebar-icon' ) ),
		'injectTested' => is_object( $injected ) && ! empty( $injected->tested ),
		'injectRequires' => is_object( $injected ) && isset( $injected->requires ) && '6.4' === (string) $injected->requires,
		'injectPhp'    => is_object( $injected ) && isset( $injected->requires_php ) && '8.0' === (string) $injected->requires_php,
		'infoOk'       => is_object( $info ),
		'infoAuthor'   => is_object( $info ) && ! empty( $info->author ),
		'infoRequires' => is_object( $info ) && isset( $info->requires ) && '6.4' === (string) $info->requires,
		'infoPhp'      => is_object( $info ) && isset( $info->requires_php ) && '8.0' === (string) $info->requires_php,
		'infoTested'   => is_object( $info ) && ! empty( $info->tested ),
		'infoIcons'    => is_object( $info ) && isset( $info->icons['1x'] ) && false !== strpos( (string) $info->icons['1x'], 'icon-128' ),
		'infoChangelog'=> is_object( $info ) && isset( $info->sections['changelog'] ) && is_string( $info->sections['changelog'] ) && '' !== $info->sections['changelog'],
		'infoFeatures' => is_object( $info ) && isset( $info->sections['changelog'] ) && false !== strpos( (string) $info->sections['changelog'], 'administrators' ),
		'infoDesc'     => is_object( $info ) && isset( $info->sections['description'] ) && false !== strpos( (string) $info->sections['description'], 'Google Sheets' ),
		'infoUpdated'  => is_object( $info ) && isset( $info->last_updated ) && '2026-08-16T12:00:00Z' === (string) $info->last_updated,
		'thinFallsBack' => '' !== $thin_changelog && false !== strpos( $thin_changelog, 'administrators' ),
	)
) . "\n";
