<?php
/**
 * Test-mode detection and artifact directory resolution.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Test_Mode' ) ) {

	/**
	 * When enabled, Sheet / Drive / Mail facades write under artifactDir.
	 *
	 * Enable via (first match wins):
	 * - env DG_TEST_MODE=1
	 * - constant DG_TEST_MODE truthy
	 * - filter `dg_test_mode`
	 * - WP option `dg_test_mode` (when WordPress is loaded)
	 */
	class Portal_Test_Mode {

		const ENV_FLAG           = 'DG_TEST_MODE';
		const ENV_ARTIFACT_DIR   = 'DG_ARTIFACT_DIR';
		const CONSTANT_FLAG      = 'DG_TEST_MODE';
		const OPTION_KEY         = 'dg_test_mode';
		const FILTER_NAME        = 'dg_test_mode';
		const FILTER_ARTIFACT    = 'dg_artifact_dir';
		const DEFAULT_ARTIFACT   = 'tests/.artifacts';
		const TRUTHY_VALUES      = array( '1', 'true', 'yes', 'on' );

		/**
		 * Whether test mode is active.
		 *
		 * @return bool
		 */
		public static function is_enabled() {
			$env = getenv( self::ENV_FLAG );
			if ( false !== $env && self::is_truthy( $env ) ) {
				return true;
			}

			if ( defined( self::CONSTANT_FLAG ) && constant( self::CONSTANT_FLAG ) ) {
				return true;
			}

			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( self::FILTER_NAME, null );
				if ( null !== $filtered ) {
					return (bool) $filtered;
				}
			}

			if ( function_exists( 'get_option' ) ) {
				$option = get_option( self::OPTION_KEY, null );
				if ( null !== $option && '' !== $option ) {
					return self::is_truthy( $option );
				}
			}

			return false;
		}

		/**
		 * Absolute artifact directory for mock Sheet / Drive / Mail writes.
		 *
		 * @param string|null $repo_root Optional repo root (CLI harness).
		 * @return string
		 */
		public static function artifact_dir( $repo_root = null ) {
			$env = getenv( self::ENV_ARTIFACT_DIR );
			if ( is_string( $env ) && '' !== trim( $env ) ) {
				return self::resolve_path( trim( $env ), $repo_root );
			}

			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( self::FILTER_ARTIFACT, null );
				if ( is_string( $filtered ) && '' !== trim( $filtered ) ) {
					return self::resolve_path( trim( $filtered ), $repo_root );
				}
			}

			$relative = self::DEFAULT_ARTIFACT;
			return self::resolve_path( $relative, $repo_root );
		}

		/**
		 * @param mixed $value Candidate flag value.
		 * @return bool
		 */
		private static function is_truthy( $value ) {
			if ( true === $value || 1 === $value ) {
				return true;
			}
			if ( is_string( $value ) ) {
				return in_array( strtolower( trim( $value ) ), self::TRUTHY_VALUES, true );
			}
			return false;
		}

		/**
		 * @param string      $path      Absolute or relative path.
		 * @param string|null $repo_root Base for relative paths.
		 * @return string
		 */
		private static function resolve_path( $path, $repo_root ) {
			if ( self::is_absolute( $path ) ) {
				return $path;
			}
			$base = is_string( $repo_root ) && '' !== $repo_root
				? $repo_root
				: self::default_repo_root();
			return rtrim( $base, "/\\" ) . DIRECTORY_SEPARATOR . ltrim( $path, "/\\" );
		}

		/**
		 * @param string $path Path.
		 * @return bool
		 */
		private static function is_absolute( $path ) {
			if ( '' === $path ) {
				return false;
			}
			if ( '/' === $path[0] || '\\' === $path[0] ) {
				return true;
			}
			// Windows drive letter.
			return (bool) preg_match( '/^[A-Za-z]:[\\\\\\/]/', $path );
		}

		/**
		 * Plugin / repo root: two levels up from includes/Submission/.
		 *
		 * @return string
		 */
		private static function default_repo_root() {
			return dirname( __DIR__, 2 );
		}
	}
}
