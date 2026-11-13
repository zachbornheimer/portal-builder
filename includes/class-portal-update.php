<?php
/**
 * WordPress finds newer GitHub Releases and keeps the install folder.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Update' ) ) {

	/**
	 * Semver offer from GitHub → update_plugins transient.
	 */
	class Portal_Update {

		const REPO         = 'zachbornheimer/portal-builder';
		const UPDATE_URI   = 'https://github.com/zachbornheimer/portal-builder';
		const HOMEPAGE     = 'https://dragongateportals.com';
		const ZIP_PREFIX   = 'portal-builder-';
		const FOLDER       = 'dragongate-portals';
		const SLUG         = 'dragongate-portals';
		const NAME         = 'DragonGate Portals';
		const AUTHOR       = 'Z. Bornheimer (ZYSYS)';
		const AUTHOR_URI   = 'https://zysys.org/';
		const REQUIRES_WP  = '6.4';
		const REQUIRES_PHP = '8.0';
		const TESTED_FALLBACK = '6.8';
		const DESCRIPTION  = 'A plugin to build portals for accepting applications and managing submissions with Google Sheets and Google Drive integration.';
		const MAIN_FILE    = 'portal-builder.php';

		/**
		 * Prior install folder names that self-relocate to FOLDER on load.
		 *
		 * @var array<int,string>
		 */
		const LEGACY_FOLDERS = array( 'portal-builder', 'portal-builder-0.0.4a' );

		/**
		 * @return void
		 */
		public static function init() {
			add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) );
			add_filter( 'plugins_api', array( __CLASS__, 'info' ), 10, 3 );
			add_filter( 'upgrader_source_selection', array( __CLASS__, 'keep_folder' ), 10, 4 );
			add_filter( 'all_plugins', array( __CLASS__, 'plugin_icons' ) );
			add_action( 'init', array( __CLASS__, 'maybe_relocate_legacy_folder' ), 1 );
			add_action( 'admin_init', array( __CLASS__, 'maybe_relocate_legacy_folder' ), 1 );
		}

		/**
		 * Strip a leading v. Empty when the tag is not MAJOR.MINOR.PATCH.
		 *
		 * @param string $tag Git tag or header version.
		 * @return string
		 */
		public static function semver( $tag ) {
			$tag = trim( (string) $tag );
			if ( 0 === strpos( $tag, 'v' ) || 0 === strpos( $tag, 'V' ) ) {
				$tag = substr( $tag, 1 );
			}
			return preg_match( '/^\d+\.\d+\.\d+$/', $tag ) ? $tag : '';
		}

		/**
		 * @param string $installed Installed semver.
		 * @param string $candidate Candidate semver.
		 * @return bool
		 */
		public static function is_newer( $installed, $candidate ) {
			$have = self::semver( $installed );
			$want = self::semver( $candidate );
			if ( '' === $have || '' === $want ) {
				return false;
			}
			return version_compare( $want, $have, '>' );
		}

		/**
		 * First portal-builder-*.zip on a GitHub release payload.
		 *
		 * @param array<string,mixed> $release GitHub release JSON.
		 * @return array<string,string>|null
		 */
		public static function offer_from_release( array $release, $installed ) {
			if ( ! empty( $release['prerelease'] ) || ! empty( $release['draft'] ) ) {
				return null;
			}
			$version = self::semver( isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '' );
			if ( '' === $version || ! self::is_newer( $installed, $version ) ) {
				return null;
			}
			$package = self::zip_url( $release );
			if ( '' === $package ) {
				return null;
			}
			return array(
				'version' => $version,
				'package' => $package,
				'url'     => isset( $release['html_url'] ) ? (string) $release['html_url'] : self::UPDATE_URI . '/releases',
			);
		}

		/**
		 * @param array<string,mixed> $release Release JSON.
		 * @return string
		 */
		public static function zip_url( array $release ) {
			$assets = isset( $release['assets'] ) && is_array( $release['assets'] ) ? $release['assets'] : array();
			foreach ( $assets as $asset ) {
				if ( ! is_array( $asset ) ) {
					continue;
				}
				$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
				$url  = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
				if ( '' === $url ) {
					continue;
				}
				if ( 0 === strpos( $name, self::ZIP_PREFIX ) && substr( $name, -4 ) === '.zip' ) {
					return $url;
				}
			}
			return '';
		}

		/**
		 * Absolute icon URLs for the Updates and Plugins screens.
		 *
		 * @return array<string,string>
		 */
		public static function icons() {
			$one = self::asset_url( 'assets/icon-128x128.png' );
			$two = self::asset_url( 'assets/icon-256x256.png' );
			return array(
				'1x'      => $one,
				'2x'      => $two,
				'default' => $two,
			);
		}

		/**
		 * Running WP version with any -suffix stripped; fallback when WP is not loaded.
		 *
		 * @return string
		 */
		public static function tested_wp() {
			if ( isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) && '' !== $GLOBALS['wp_version'] ) {
				$version = $GLOBALS['wp_version'];
				$dash    = strpos( $version, '-' );
				if ( false !== $dash ) {
					$version = substr( $version, 0, $dash );
				}
				return $version;
			}
			return self::TESTED_FALLBACK;
		}

		/**
		 * @param object               $transient WordPress update_plugins transient.
		 * @param string               $plugin    plugin_basename (folder/file.php).
		 * @param array<string,string> $offer     version/package/url.
		 * @return object
		 */
		public static function inject( $transient, $plugin, array $offer ) {
			if ( ! is_object( $transient ) ) {
				$transient = (object) array();
			}
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}
			$transient->response[ $plugin ] = self::update_row( $plugin, $offer['version'], $offer['package'], $offer['url'] );
			return $transient;
		}

		/**
		 * Keep the live install folder when the ZIP unpacks under a different name.
		 *
		 * @param string $source      Unpacked path (trailing slash).
		 * @param string $wanted_name Existing plugin directory basename.
		 * @return string|\WP_Error
		 */
		public static function rename_source( $source, $wanted_name ) {
			$source = rtrim( (string) $source, '/\\' );
			$wanted = preg_replace( '/[^A-Za-z0-9._-]+/', '', (string) $wanted_name );
			if ( '' === $source || '' === $wanted || ! is_dir( $source ) ) {
				return $source . '/';
			}
			$current = basename( $source );
			if ( $current === $wanted ) {
				return $source . '/';
			}
			$parent = dirname( $source );
			$dest   = $parent . DIRECTORY_SEPARATOR . $wanted;
			if ( is_dir( $dest ) ) {
				return $source . '/';
			}
			if ( ! @rename( $source, $dest ) ) {
				return $source . '/';
			}
			return $dest . '/';
		}

		/**
		 * Pure: rename a legacy install folder to FOLDER and rewrite active_plugins.
		 *
		 * No-ops when current is already FOLDER, not a known legacy name, or dest exists.
		 *
		 * @param string        $plugins_dir    Absolute plugins directory.
		 * @param string        $current_folder Install folder basename.
		 * @param array<int,mixed> $active      active_plugins option value.
		 * @return array{ok:bool,active:array<int,mixed>,folder:string}
		 */
		public static function relocate_legacy_folder( $plugins_dir, $current_folder, array $active ) {
			$plugins_dir = rtrim( (string) $plugins_dir, '/\\' );
			$current     = (string) $current_folder;
			$result      = array(
				'ok'     => false,
				'active' => $active,
				'folder' => $current,
			);

			if ( '' === $plugins_dir || self::FOLDER === $current ) {
				return $result;
			}
			if ( ! in_array( $current, self::LEGACY_FOLDERS, true ) ) {
				return $result;
			}

			$dest = $plugins_dir . DIRECTORY_SEPARATOR . self::FOLDER;
			if ( is_dir( $dest ) ) {
				return $result;
			}

			$src = $plugins_dir . DIRECTORY_SEPARATOR . $current;
			if ( ! is_dir( $src ) ) {
				return $result;
			}
			if ( ! @rename( $src, $dest ) ) {
				return $result;
			}

			$from = $current . '/' . self::MAIN_FILE;
			$to   = self::FOLDER . '/' . self::MAIN_FILE;
			$next = array();
			foreach ( $active as $entry ) {
				if ( is_string( $entry ) && $entry === $from ) {
					$next[] = $to;
					continue;
				}
				$next[] = $entry;
			}

			return array(
				'ok'     => true,
				'active' => $next,
				'folder' => self::FOLDER,
			);
		}

		/**
		 * One-shot: move a live legacy folder to FOLDER and update active_plugins.
		 *
		 * @return void
		 */
		public static function maybe_relocate_legacy_folder() {
			static $done = false;
			if ( $done ) {
				return;
			}
			$done = true;

			if ( ! defined( 'WP_PLUGIN_DIR' ) || ! function_exists( 'update_option' ) ) {
				return;
			}

			$folder = dirname( self::plugin_file() );
			if ( ! in_array( $folder, self::LEGACY_FOLDERS, true ) ) {
				return;
			}

			$active = array();
			if ( function_exists( 'get_option' ) ) {
				$opt = get_option( 'active_plugins', array() );
				if ( is_array( $opt ) ) {
					$active = $opt;
				}
			}

			$result = self::relocate_legacy_folder( WP_PLUGIN_DIR, $folder, $active );
			if ( empty( $result['ok'] ) || ! is_array( $result['active'] ) ) {
				return;
			}
			update_option( 'active_plugins', $result['active'] );
		}

		/**
		 * @param object $transient Update transient.
		 * @return object
		 */
		public static function check( $transient ) {
			if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
				return $transient;
			}
			$plugin = self::plugin_file();
			$have   = defined( 'DG_VERSION' ) ? DG_VERSION : '0.0.0';
			$offer  = self::offer_from_release( self::fetch_latest(), $have );
			if ( ! is_array( $offer ) ) {
				return self::mark_current( $transient, $plugin, $have );
			}
			return self::inject( $transient, $plugin, $offer );
		}

		/**
		 * @param mixed  $result Default.
		 * @param string $action API action.
		 * @param object $args   Request.
		 * @return mixed
		 */
		public static function info( $result, $action, $args ) {
			if ( 'plugin_information' !== $action || ! is_object( $args ) ) {
				return $result;
			}
			if ( empty( $args->slug ) || self::SLUG !== $args->slug ) {
				return $result;
			}
			return self::info_from_release( self::fetch_latest() );
		}

		/**
		 * Build the plugins_api payload from a release array (pure; no network).
		 *
		 * @param array<string,mixed> $release GitHub release JSON.
		 * @return object
		 */
		public static function info_from_release( array $release ) {
			$version = self::semver( isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '' );
			if ( '' === $version ) {
				$version = defined( 'DG_VERSION' ) ? DG_VERSION : '';
			}
			$last_updated = '';
			if ( ! empty( $release['published_at'] ) ) {
				$last_updated = (string) $release['published_at'];
			} elseif ( ! empty( $release['created_at'] ) ) {
				$last_updated = (string) $release['created_at'];
			}

			return (object) array(
				'name'           => self::NAME,
				'slug'           => self::SLUG,
				'version'        => $version,
				'author'         => '<a href="' . self::escape_url( self::AUTHOR_URI ) . '">' . self::escape_html( self::AUTHOR ) . '</a>',
				'author_profile' => self::AUTHOR_URI,
				'requires'       => self::REQUIRES_WP,
				'tested'         => self::tested_wp(),
				'requires_php'   => self::REQUIRES_PHP,
				'last_updated'   => $last_updated,
				'homepage'       => self::HOMEPAGE,
				'download_link'  => self::zip_url( $release ),
				'sections'       => array(
					'description' => self::DESCRIPTION . ' Updates come from GitHub Releases.',
					'changelog'   => self::changelog_html( $release ),
				),
				'icons'          => self::icons(),
			);
		}

		/**
		 * Attach seal icons on the Plugins list row.
		 *
		 * @param array<string,array<string,mixed>> $plugins Installed plugins.
		 * @return array<string,array<string,mixed>>
		 */
		public static function plugin_icons( $plugins ) {
			if ( ! is_array( $plugins ) ) {
				return $plugins;
			}
			$file = self::plugin_file();
			if ( isset( $plugins[ $file ] ) && is_array( $plugins[ $file ] ) ) {
				$plugins[ $file ]['icons'] = self::icons();
			}
			return $plugins;
		}

		/**
		 * Only rename when THIS plugin is upgrading. Theme upgrades must pass through.
		 *
		 * @param string              $source        Unpacked source.
		 * @param string              $remote_source Remote package.
		 * @param object              $upgrader      Upgrader.
		 * @param array<string,mixed> $hook_extra    Extra.
		 * @return string
		 */
		public static function keep_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
			unset( $remote_source, $upgrader );
			if ( ! is_array( $hook_extra ) ) {
				return $source;
			}
			if ( ! empty( $hook_extra['theme'] ) ) {
				return $source;
			}
			$plugin = isset( $hook_extra['plugin'] ) ? (string) $hook_extra['plugin'] : '';
			if ( '' === $plugin || self::MAIN_FILE !== basename( $plugin ) ) {
				return $source;
			}
			$folder = dirname( $plugin );
			if ( '.' === $folder || '' === $folder ) {
				return $source;
			}
			return self::rename_source( $source, $folder );
		}

		/**
		 * @return string
		 */
		public static function plugin_file() {
			if ( function_exists( 'plugin_basename' ) ) {
				return plugin_basename( dirname( __DIR__ ) . '/' . self::MAIN_FILE );
			}
			return self::FOLDER . '/' . self::MAIN_FILE;
		}

		/**
		 * @return array<string,mixed>
		 */
		public static function fetch_latest() {
			$from_api = self::fetch_api();
			if ( $from_api ) {
				return $from_api;
			}
			return self::fetch_latest_redirect();
		}

		/**
		 * Build a release payload from a GitHub /releases/latest Location header.
		 *
		 * @param string $location Redirect URL.
		 * @return array<string,mixed>
		 */
		public static function release_from_location( $location ) {
			if ( ! preg_match( '#/releases/tag/(v?\d+\.\d+\.\d+)#', (string) $location, $match ) ) {
				return array();
			}
			$tag     = $match[1];
			$version = self::semver( $tag );
			if ( '' === $version ) {
				return array();
			}
			$prefixed = 'v' . $version;
			return array(
				'tag_name'   => $prefixed,
				'prerelease' => false,
				'draft'      => false,
				'html_url'   => 'https://github.com/' . self::REPO . '/releases/tag/' . $prefixed,
				'assets'     => array(
					array(
						'name'                 => self::ZIP_PREFIX . $version . '.zip',
						'browser_download_url' => 'https://github.com/' . self::REPO . '/releases/download/' . $prefixed . '/' . self::ZIP_PREFIX . $version . '.zip',
					),
				),
			);
		}

		/**
		 * @param string $plugin  plugin_basename.
		 * @param string $version Semver.
		 * @param string $package ZIP URL (empty when current).
		 * @param string $url     Release or repo URL.
		 * @return object
		 */
		private static function update_row( $plugin, $version, $package, $url ) {
			return (object) array(
				'slug'         => self::SLUG,
				'plugin'       => $plugin,
				'new_version'  => $version,
				'package'      => $package,
				'url'          => $url,
				'icons'        => self::icons(),
				'tested'       => self::tested_wp(),
				'requires'     => self::REQUIRES_WP,
				'requires_php' => self::REQUIRES_PHP,
			);
		}

		/**
		 * When no newer offer exists, still publish icons on no_update.
		 *
		 * @param object $transient Update transient.
		 * @param string $plugin    plugin_basename.
		 * @param string $version   Installed semver.
		 * @return object
		 */
		private static function mark_current( $transient, $plugin, $version ) {
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}
			$transient->no_update[ $plugin ] = self::update_row(
				$plugin,
				$version,
				'',
				self::UPDATE_URI . '/releases'
			);
			return $transient;
		}

		/**
		 * @param array<string,mixed> $release Release JSON.
		 * @return string
		 */
		private static function changelog_html( array $release ) {
			$body = isset( $release['body'] ) ? trim( (string) $release['body'] ) : '';
			if ( self::body_is_compare_only( $body ) ) {
				$body = '';
			}
			if ( '' !== $body && class_exists( 'Portal_Release_Notes' ) ) {
				return Portal_Release_Notes::html_from_markdown( $body );
			}
			$bundled = self::bundled_changelog();
			if ( '' !== $bundled && class_exists( 'Portal_Release_Notes' ) ) {
				return Portal_Release_Notes::html_from_markdown( $bundled );
			}
			$releases = self::UPDATE_URI . '/releases';
			return '<p>See <a href="' . self::escape_url( $releases ) . '" rel="noopener noreferrer" target="_blank">GitHub Releases</a> for the changelog.</p>';
		}

		/**
		 * @return string
		 */
		/**
		 * GitHub --generate-notes sometimes emits only a compare URL.
		 *
		 * @param string $body Release body.
		 * @return bool
		 */
		private static function body_is_compare_only( $body ) {
			if ( '' === $body ) {
				return true;
			}
			return (bool) preg_match( '/^\*\*Full Changelog\*\*:\s+\S+\s*$/s', $body );
		}

		private static function bundled_changelog() {
			$path = dirname( __DIR__ ) . '/CHANGELOG.md';
			if ( ! is_readable( $path ) ) {
				return '';
			}
			$contents = file_get_contents( $path );
			return is_string( $contents ) ? $contents : '';
		}

		/**
		 * @param string $relative Path under the plugin root.
		 * @return string
		 */
		private static function asset_url( $relative ) {
			$relative = ltrim( (string) $relative, '/' );
			if ( function_exists( 'plugins_url' ) ) {
				return plugins_url( $relative, dirname( __DIR__ ) . '/portal-builder.php' );
			}
			return 'https://raw.githubusercontent.com/' . self::REPO . '/main/' . $relative;
		}

		/**
		 * @return array<string,mixed>
		 */
		private static function fetch_api() {
			if ( ! function_exists( 'wp_remote_get' ) ) {
				return array();
			}
			$headers = array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'DragonGate-Portals',
			);
			$token   = self::github_token();
			if ( '' !== $token ) {
				$headers['Authorization'] = 'Bearer ' . $token;
			}
			$response = wp_remote_get(
				'https://api.github.com/repos/' . self::REPO . '/releases/latest',
				array(
					'timeout' => 12,
					'headers' => $headers,
				)
			);
			if ( is_wp_error( $response ) ) {
				return array();
			}
			if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return array();
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			return is_array( $body ) ? $body : array();
		}

		/**
		 * Shared-IP hosts (WP Engine) often 403 the unauthenticated API.
		 *
		 * @return array<string,mixed>
		 */
		private static function fetch_latest_redirect() {
			if ( ! function_exists( 'wp_remote_get' ) ) {
				return array();
			}
			$response = wp_remote_get(
				'https://github.com/' . self::REPO . '/releases/latest',
				array(
					'timeout'     => 12,
					'redirection' => 0,
					'headers'     => array( 'User-Agent' => 'DragonGate-Portals' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				return array();
			}
			$headers = wp_remote_retrieve_headers( $response );
			$loc     = '';
			if ( is_object( $headers ) && isset( $headers['location'] ) ) {
				$loc = (string) $headers['location'];
			} elseif ( is_array( $headers ) && isset( $headers['location'] ) ) {
				$loc = (string) $headers['location'];
			}
			return self::release_from_location( $loc );
		}

		/**
		 * @return string
		 */
		private static function github_token() {
			if ( defined( 'DG_GITHUB_TOKEN' ) && is_string( DG_GITHUB_TOKEN ) ) {
				return DG_GITHUB_TOKEN;
			}
			$legacy_define = str_replace( 'DG_', 'PB_', 'DG_GITHUB_TOKEN' );
			if ( defined( $legacy_define ) ) {
				$from_wp_config = constant( $legacy_define );
				if ( is_string( $from_wp_config ) ) {
					return $from_wp_config;
				}
			}
			if ( class_exists( 'Portal_Options' ) ) {
				$stored = Portal_Options::get( 'dg_github_token', '' );
				return is_string( $stored ) ? $stored : '';
			}
			return '';
		}

		/**
		 * @param string $url URL.
		 * @return string
		 */
		private static function escape_url( $url ) {
			if ( function_exists( 'esc_url' ) ) {
				return esc_url( $url );
			}
			return htmlspecialchars( (string) $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		}

		/**
		 * @param string $text Text.
		 * @return string
		 */
		private static function escape_html( $text ) {
			if ( function_exists( 'esc_html' ) ) {
				return esc_html( $text );
			}
			return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		}
	}

	Portal_Update::init();
}
