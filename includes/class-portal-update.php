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

		const REPO       = 'zachbornheimer/portal-builder';
		const UPDATE_URI = 'https://github.com/zachbornheimer/portal-builder';
		const ZIP_PREFIX = 'portal-builder-';

		/**
		 * @return void
		 */
		public static function init() {
			add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) );
			add_filter( 'plugins_api', array( __CLASS__, 'info' ), 10, 3 );
			add_filter( 'upgrader_source_selection', array( __CLASS__, 'keep_folder' ), 10, 4 );
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
		 * @param object              $transient WordPress update_plugins transient.
		 * @param string              $plugin    plugin_basename (folder/file.php).
		 * @param array<string,string> $offer    version/package/url.
		 * @return object
		 */
		public static function inject( $transient, $plugin, array $offer ) {
			if ( ! is_object( $transient ) ) {
				$transient = (object) array();
			}
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}
			$transient->response[ $plugin ] = (object) array(
				'slug'        => 'dragongate-portals',
				'plugin'      => $plugin,
				'new_version' => $offer['version'],
				'package'     => $offer['package'],
				'url'         => $offer['url'],
			);
			return $transient;
		}

		/**
		 * Keep the live folder name (portal-builder-0.0.4a) when the ZIP unpacks as portal-builder/.
		 *
		 * @param string $source       Unpacked path (trailing slash).
		 * @param string $wanted_name  Existing plugin directory basename.
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
		 * @param object $transient Update transient.
		 * @return object
		 */
		public static function check( $transient ) {
			if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
				return $transient;
			}
			$plugin = self::plugin_file();
			$have   = defined( 'PB_VERSION' ) ? PB_VERSION : '0.0.0';
			$offer  = self::offer_from_release( self::fetch_latest(), $have );
			if ( ! is_array( $offer ) ) {
				return $transient;
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
			if ( empty( $args->slug ) || 'dragongate-portals' !== $args->slug ) {
				return $result;
			}
			$release = self::fetch_latest();
			$version = self::semver( isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '' );
			return (object) array(
				'name'          => 'DragonGate Portals',
				'slug'          => 'dragongate-portals',
				'version'       => $version ? $version : ( defined( 'PB_VERSION' ) ? PB_VERSION : '' ),
				'download_link' => self::zip_url( $release ),
				'sections'      => array(
					'description' => 'Application portals that write Google Sheets and Drive. Updates come from GitHub Releases.',
				),
				'homepage'      => self::UPDATE_URI,
			);
		}

		/**
		 * @param string            $source        Unpacked source.
		 * @param string            $remote_source Remote package.
		 * @param object            $upgrader      Upgrader.
		 * @param array<string,mixed> $hook_extra  Extra.
		 * @return string
		 */
		public static function keep_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
			unset( $remote_source, $upgrader );
			$plugin = isset( $hook_extra['plugin'] ) ? (string) $hook_extra['plugin'] : self::plugin_file();
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
				return plugin_basename( dirname( __DIR__ ) . '/portal-builder.php' );
			}
			return 'portal-builder/portal-builder.php';
		}

		/**
		 * @return array<string,mixed>
		 */
		public static function fetch_latest() {
			$url = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';
			if ( ! function_exists( 'wp_remote_get' ) ) {
				return array();
			}
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 12,
					'headers' => array(
						'Accept'     => 'application/vnd.github+json',
						'User-Agent' => 'DragonGate-Portals',
					),
				)
			);
			if ( is_wp_error( $response ) ) {
				return array();
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				return array();
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			return is_array( $body ) ? $body : array();
		}
	}

	Portal_Update::init();
}
