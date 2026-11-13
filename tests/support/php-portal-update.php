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
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
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

// keep_folder must only rename when THIS plugin is upgrading (Kadence outage class).
$upgrader  = (object) array();
$theme_dir = sys_get_temp_dir() . '/dg-keep-theme-' . bin2hex( random_bytes( 4 ) );
mkdir( $theme_dir );
mkdir( $theme_dir . '/kadence' );
file_put_contents( $theme_dir . '/kadence/style.css', "/* theme */\n" );
$theme_source = $theme_dir . '/kadence/';
$theme_kept   = Portal_Update::keep_folder( $theme_source, '', $upgrader, array( 'theme' => 'kadence' ) );
$theme_ok     = $theme_kept === $theme_source
	&& is_dir( $theme_dir . '/kadence' )
	&& ! is_dir( $theme_dir . '/portal-builder' )
	&& ! is_dir( $theme_dir . '/portal-builder-0.0.4a' );
array_map( 'unlink', glob( $theme_dir . '/kadence/*' ) ?: array() );
@rmdir( $theme_dir . '/kadence' );
@rmdir( $theme_dir );

$empty_source = '/tmp/unpacked-something/';
$empty_kept   = Portal_Update::keep_folder( $empty_source, '', $upgrader, array() );
$empty_ok     = $empty_kept === $empty_source;

$plugin_dir = sys_get_temp_dir() . '/dg-keep-plugin-' . bin2hex( random_bytes( 4 ) );
mkdir( $plugin_dir );
mkdir( $plugin_dir . '/portal-builder' );
file_put_contents( $plugin_dir . '/portal-builder/portal-builder.php', "<?php\n" );
$plugin_source = $plugin_dir . '/portal-builder/';
$plugin_kept   = Portal_Update::keep_folder(
	$plugin_source,
	'',
	$upgrader,
	array( 'plugin' => 'portal-builder-0.0.4a/portal-builder.php' )
);
$plugin_ok = basename( rtrim( (string) $plugin_kept, '/\\' ) ) === 'portal-builder-0.0.4a'
	&& is_dir( $plugin_dir . '/portal-builder-0.0.4a' )
	&& is_file( $plugin_dir . '/portal-builder-0.0.4a/portal-builder.php' );
array_map( 'unlink', glob( $plugin_dir . '/portal-builder-0.0.4a/*' ) ?: array() );
@rmdir( $plugin_dir . '/portal-builder-0.0.4a' );
@rmdir( $plugin_dir . '/portal-builder' );
@rmdir( $plugin_dir );

// relocate_legacy_folder: pure rename + active_plugins rewrite.
$relocate_dir = sys_get_temp_dir() . '/dg-relocate-' . bin2hex( random_bytes( 4 ) );
mkdir( $relocate_dir );
mkdir( $relocate_dir . '/portal-builder-0.0.4a' );
file_put_contents( $relocate_dir . '/portal-builder-0.0.4a/portal-builder.php', "<?php\n" );
$relocate = method_exists( 'Portal_Update', 'relocate_legacy_folder' )
	? Portal_Update::relocate_legacy_folder(
		$relocate_dir,
		'portal-builder-0.0.4a',
		array(
			'akismet/akismet.php',
			'portal-builder-0.0.4a/portal-builder.php',
			'hello.php',
		)
	)
	: array( 'ok' => false, 'active' => array(), 'folder' => '' );
$relocate_ok = ! empty( $relocate['ok'] )
	&& isset( $relocate['folder'] )
	&& 'dragongate-portals' === $relocate['folder']
	&& is_dir( $relocate_dir . '/dragongate-portals' )
	&& is_file( $relocate_dir . '/dragongate-portals/portal-builder.php' )
	&& ! is_dir( $relocate_dir . '/portal-builder-0.0.4a' )
	&& is_array( $relocate['active'] )
	&& in_array( 'dragongate-portals/portal-builder.php', $relocate['active'], true )
	&& ! in_array( 'portal-builder-0.0.4a/portal-builder.php', $relocate['active'], true )
	&& in_array( 'akismet/akismet.php', $relocate['active'], true );

// dest already exists → no-op, leave source and active list alone.
$clobber_dir = sys_get_temp_dir() . '/dg-relocate-clobber-' . bin2hex( random_bytes( 4 ) );
mkdir( $clobber_dir );
mkdir( $clobber_dir . '/portal-builder' );
mkdir( $clobber_dir . '/dragongate-portals' );
file_put_contents( $clobber_dir . '/portal-builder/portal-builder.php', "<?php // legacy\n" );
file_put_contents( $clobber_dir . '/dragongate-portals/portal-builder.php', "<?php // dest\n" );
$clobber_active = array( 'portal-builder/portal-builder.php' );
$clobber        = method_exists( 'Portal_Update', 'relocate_legacy_folder' )
	? Portal_Update::relocate_legacy_folder( $clobber_dir, 'portal-builder', $clobber_active )
	: array( 'ok' => true, 'active' => array(), 'folder' => 'x' );
$clobber_ok = empty( $clobber['ok'] )
	&& is_dir( $clobber_dir . '/portal-builder' )
	&& is_dir( $clobber_dir . '/dragongate-portals' )
	&& is_array( $clobber['active'] )
	&& $clobber['active'] === $clobber_active
	&& 'portal-builder' === (string) $clobber['folder'];
array_map( 'unlink', glob( $clobber_dir . '/portal-builder/*' ) ?: array() );
array_map( 'unlink', glob( $clobber_dir . '/dragongate-portals/*' ) ?: array() );
@rmdir( $clobber_dir . '/portal-builder' );
@rmdir( $clobber_dir . '/dragongate-portals' );
@rmdir( $clobber_dir );

// cleanup relocate success tree
array_map( 'unlink', glob( $relocate_dir . '/dragongate-portals/*' ) ?: array() );
@rmdir( $relocate_dir . '/dragongate-portals' );
@rmdir( $relocate_dir . '/portal-builder-0.0.4a' );
@rmdir( $relocate_dir );

$plugin_file_fallback = method_exists( 'Portal_Update', 'FOLDER' ) || defined( 'Portal_Update::FOLDER' )
	? ( new ReflectionClass( 'Portal_Update' ) )->getConstant( 'FOLDER' )
	: '';
// plugin_basename is mocked; re-check constant drives the advertised path.
$fallback_file = ( '' !== $plugin_file_fallback )
	? $plugin_file_fallback . '/portal-builder.php'
	: '';

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
		'themeUnchanged'  => $theme_ok,
		'emptyUnchanged'  => $empty_ok,
		'pluginRenamed'   => $plugin_ok,
		'relocateOk'      => $relocate_ok,
		'relocateNoClobber' => $clobber_ok,
		'folderConst'     => $plugin_file_fallback,
		'fallbackFile'    => $fallback_file,
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
