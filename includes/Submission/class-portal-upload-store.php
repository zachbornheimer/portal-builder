<?php
/**
 * Upload roots, deny files, and TTL purge for staged/tmp files.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Upload_Store' ) ) {

	/**
	 * Resolves tmp/store/staged dirs under the uploads basedir.
	 */
	class Portal_Upload_Store {

		const TMP_DIR_NAME    = 'dg-tmp';
		const STORE_DIR_NAME  = 'dg-store';
		const STAGED_DIR_NAME = 'dg-staged';

		const CLEANUP_HOOK        = 'dg_purge_staged_uploads';
		const CLEANUP_RECURRENCE  = 'daily';
		const CLEANUP_TTL_SECONDS = 86400;

		const DENY_FILENAME  = '.htaccess';
		const INDEX_FILENAME = 'index.php';

		const LEGACY_TMP_NAME = 'tmp-uploads';

		/**
		 * Injectable clock returning unix seconds.
		 *
		 * @var callable|null
		 */
		private static $clock = null;

		/**
		 * Injectable uploads basedir.
		 *
		 * @var string|null
		 */
		private static $basedir = null;

		/**
		 * Override the clock used for TTL math and scheduling.
		 *
		 * @param callable|null $clock Clock returning unix seconds.
		 * @return void
		 */
		public static function set_clock( $clock ) {
			self::$clock = is_callable( $clock ) ? $clock : null;
		}

		/**
		 * Override the uploads basedir (CLI tests).
		 *
		 * @param string|null $dir Uploads basedir override.
		 * @return void
		 */
		public static function set_basedir( $dir ) {
			self::$basedir = is_string( $dir ) && '' !== $dir ? rtrim( $dir, '/\\' ) : null;
		}

		/**
		 * Absolute tmp-upload root.
		 *
		 * @return string
		 */
		public static function tmp_dir() {
			return self::ensure_root( self::join_basedir( self::TMP_DIR_NAME ) );
		}

		/**
		 * Absolute permanent-store root.
		 *
		 * @return string
		 */
		public static function store_dir() {
			return self::ensure_root( self::join_basedir( self::STORE_DIR_NAME ) );
		}

		/**
		 * Absolute definition-staging root.
		 *
		 * @return string
		 */
		public static function staged_dir() {
			$name = class_exists( 'Portal_Staged_File' )
				? Portal_Staged_File::WP_STAGED_DIR
				: self::STAGED_DIR_NAME;
			return self::ensure_root( self::join_basedir( $name ) );
		}

		/**
		 * Create tmp, permanent, and staged roots with deny files.
		 *
		 * @return void
		 */
		public static function ensure_plugin_roots() {
			self::tmp_dir();
			self::store_dir();
			self::staged_dir();
			self::data_dir( 'dg-logs' );
			self::data_dir( 'dg-packets' );
		}

		/**
		 * Register the daily purge event if it is not already scheduled.
		 *
		 * @return void
		 */
		public static function schedule_cleanup() {
			if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
				return;
			}
			if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
				wp_schedule_event( self::now(), self::CLEANUP_RECURRENCE, self::CLEANUP_HOOK );
			}
		}

		/**
		 * Clear the daily purge event.
		 *
		 * @return void
		 */
		public static function unschedule_cleanup() {
			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( self::CLEANUP_HOOK );
			}
		}

		/**
		 * Delete files older than the TTL in staging/tmp roots only.
		 *
		 * @param int|null          $now   Unix now (tests).
		 * @param int|null          $ttl   Seconds (tests).
		 * @param string[]|null     $roots Directories to walk (tests).
		 * @param Portal_Files|null $files Filesystem facade.
		 * @return void
		 */
		public static function purge_expired( $now = null, $ttl = null, $roots = null, $files = null ) {
			$now    = null === $now ? self::now() : (int) $now;
			$ttl    = null === $ttl ? self::ttl_seconds() : (int) $ttl;
			$files  = $files instanceof Portal_Files ? $files : new Portal_Files();
			$roots  = is_array( $roots ) ? $roots : self::purge_roots();
			$cutoff = $now - max( 0, $ttl );
			foreach ( $roots as $root ) {
				self::purge_tree( $files, (string) $root, $cutoff, true );
			}
		}

		/**
		 * Staging/tmp roots only — never the permanent store, Drive, or Sheet.
		 *
		 * Also walks a leftover ABSPATH/tmp-uploads tree when that dir still exists.
		 *
		 * @return string[]
		 */
		public static function purge_roots() {
			$roots = array(
				self::staged_dir(),
				self::tmp_dir(),
			);
			if ( defined( 'ABSPATH' ) ) {
				$legacy = rtrim( (string) ABSPATH, '/\\' ) . DIRECTORY_SEPARATOR . self::LEGACY_TMP_NAME;
				if ( self::is_new_root( $legacy, $roots ) ) {
					$roots[] = $legacy;
				}
			}
			if ( defined( 'DG_TMP_UPLOADS_DIR' ) ) {
				$current = rtrim( (string) DG_TMP_UPLOADS_DIR, '/\\' );
				if ( self::is_new_root( $current, $roots ) ) {
					$roots[] = $current;
				}
			}
			return $roots;
		}

		/**
		 * Named 24h default, overridable via DG_STAGED_CLEANUP_TTL.
		 *
		 * @return int
		 */
		public static function ttl_seconds() {
			if ( defined( 'DG_STAGED_CLEANUP_TTL' ) ) {
				return (int) DG_STAGED_CLEANUP_TTL;
			}
			return self::CLEANUP_TTL_SECONDS;
		}

		/**
		 * Uploads basedir (injected, wp_upload_dir, or sys temp).
		 *
		 * @return string
		 */
		public static function basedir() {
			if ( is_string( self::$basedir ) && '' !== self::$basedir ) {
				return self::$basedir;
			}
			if ( function_exists( 'wp_upload_dir' ) ) {
				$upload = wp_upload_dir();
				if ( is_array( $upload ) && ! empty( $upload['basedir'] ) ) {
					return rtrim( (string) $upload['basedir'], '/\\' );
				}
			}
			return rtrim( sys_get_temp_dir(), '/\\' );
		}

		/**
		 * Current unix time.
		 *
		 * @return int
		 */
		private static function now() {
			if ( is_callable( self::$clock ) ) {
				return (int) call_user_func( self::$clock );
			}
			return time();
		}

		/**
		 * Join a child name onto the uploads basedir.
		 *
		 * @param string $name Child of the uploads basedir.
		 * @return string
		 */
		private static function join_basedir( $name ) {
			return self::basedir() . DIRECTORY_SEPARATOR . $name;
		}

		/**
		 * Create a root and write deny files beside it.
		 *
		 * @param string $dir Absolute directory.
		 * @return string
		 */
		private static function ensure_root( $dir ) {
			$files = new Portal_Files();
			$files->mkdir( $dir );
			self::write_deny( $files, $dir );
			return $dir;
		}

		/**
		 * Whether an explicit test-only base is active.
		 *
		 * @return bool
		 */
		public static function base_is_test_injection() {
			return is_string( self::$basedir ) && '' !== self::$basedir;
		}

		/**
		 * Stable per-site token for protected store directories.
		 *
		 * @return string
		 */
		public static function site_segment() {
			$salt   = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : getenv( 'HOME' );
			$digest = hash( 'sha256', (string) $salt );
			return substr( $digest, 0, 16 );
		}

		/**
		 * Resolve one protected operator-data directory, never returning an unverified path.
		 *
		 * Candidate roots are tried in order and one is accepted only after its store
		 * directory exists and a probe file round-trips:
		 *   1. DG_DATA_ROOT, the explicit operator choice.
		 *   2. The `dg_data_dir` filter.
		 *   3. dirname(ABSPATH)/dg-data-<segment>, off the document root.
		 *   4. <uploads>/dg-<segment>, which is web-served.
		 *
		 * Each skipped candidate and the root finally used are written to error_log.
		 * Prefer 1-3: write_deny() emits Apache stubs only, so nginx serves candidate 4
		 * regardless of them.
		 *
		 * @param string            $name Store name (`dg-logs`, `dg-packets`, `mail`).
		 * @param Portal_Files|null $files FS.
		 * @return string
		 */
		public static function data_dir( $name, $files = null ) {
			$name    = (string) $name;
			$files   = $files instanceof Portal_Files ? $files : new Portal_Files();
			$segment = self::site_segment();
			$skipped = false;

			foreach ( self::data_roots( $name, $segment ) as $candidate ) {
				$dir = self::prepare_data_dir( $candidate['root'], $name, $files );
				if ( '' === $dir ) {
					$skipped = true;
					self::log_fallback( sprintf( '%s %s cannot be created or written for %s; trying the next root.', $candidate['label'], $candidate['root'], $name ) );
					continue;
				}
				if ( $skipped ) {
					$note = empty( $candidate['web'] ) ? '' : ' It is web-served and the deny stubs are Apache-only, so nginx will serve it; define DG_DATA_ROOT as a writable off-docroot path.';
					self::log_fallback( sprintf( 'Using %s for %s.%s', $dir, $name, $note ) );
				}
				return $dir;
			}
			self::log_fallback( sprintf( 'No usable data root for %s; its rows are not being persisted.', $name ) );
			return $files->join( self::join_basedir( 'dg-' . $segment ), $name );
		}

		/**
		 * Ordered candidate roots for a store: label, root, and web flag.
		 *
		 * @param string $name    Store name.
		 * @param string $segment Site segment.
		 * @return array<int, array{label: string, root: string, web?: bool}>
		 */
		private static function data_roots( $name, $segment ) {
			$roots = array();
			if ( defined( 'DG_DATA_ROOT' ) && '' !== (string) constant( 'DG_DATA_ROOT' ) ) {
				$roots[] = array(
					'label' => 'DG_DATA_ROOT',
					'root'  => (string) constant( 'DG_DATA_ROOT' ),
				);
			}
			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( 'dg_data_dir', null, $name, $segment );
				if ( is_string( $filtered ) && '' !== trim( $filtered ) ) {
					$roots[] = array(
						'label' => 'dg_data_dir filter',
						'root'  => $filtered,
					);
				}
			}
			if ( defined( 'ABSPATH' ) ) {
				$roots[] = array(
					'label' => 'Off-docroot root',
					'root'  => dirname( rtrim( (string) ABSPATH, '/\\' ) ) . DIRECTORY_SEPARATOR . 'dg-data-' . $segment,
				);
			}
			$roots[] = array(
				'label' => 'Uploads root',
				'root'  => self::join_basedir( 'dg-' . $segment ),
				'web'   => true,
			);
			return $roots;
		}

		/**
		 * Create, verify, and protect a store under a data root.
		 *
		 * @param string       $root  Data root.
		 * @param string       $name  Store name.
		 * @param Portal_Files $files FS.
		 * @return string Store directory, or '' when it cannot be created and written.
		 */
		private static function prepare_data_dir( $root, $name, Portal_Files $files ) {
			$dir = $files->join( rtrim( (string) $root, '/\\' ), $name );
			$files->mkdir( $dir );
			if ( ! self::probe_writable( $dir, $files ) ) {
				return '';
			}
			self::write_deny( $files, $dir );
			self::migrate_legacy_store( $name, $dir, $files );
			return $dir;
		}

		/**
		 * Record a data-root decision where operators can read it.
		 *
		 * @param string $message Decision text.
		 * @return void
		 */
		private static function log_fallback( $message ) {
			error_log( 'Portal Data Store: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operators must see why a store root was skipped.
		}

		/**
		 * Copy legacy web-root rows into the protected root, then remove originals.
		 *
		 * A source is retained whenever every byte cannot be verified in place.
		 *
		 * @param string       $name  Store name.
		 * @param string       $dest  Protected store.
		 * @param Portal_Files $files FS.
		 * @return void
		 */
		private static function migrate_legacy_store( $name, $dest, Portal_Files $files ) {
			$legacy = self::legacy_dir( $name );
			if ( '' === $legacy || rtrim( $legacy, '/\\' ) === rtrim( $dest, '/\\' ) || ! $files->is_dir( $legacy ) ) {
				return;
			}
			foreach ( $files->list_names( $legacy ) as $entry ) {
				$source = $files->join( $legacy, $entry );
				if ( ! $files->is_file( $source ) ) {
					continue;
				}
				$target = $files->join( $dest, $entry );
				$bytes  = $files->read_text( $source );
				if ( $files->exists( $target ) && $files->read_text( $target ) !== $bytes ) {
					continue;
				}
				$files->write( $target, $bytes );
				if ( $files->read_text( $target ) !== $bytes ) {
					continue;
				}
				$files->remove( $source );
			}
			if ( empty( $files->list_names( $legacy ) ) ) {
				$files->remove( $legacy );
			}
		}

		/**
		 * Legacy web-root directory for mail/log/packet stores.
		 *
		 * @param string $name Store name.
		 * @return string
		 */
		private static function legacy_dir( $name ) {
			if ( 'mail' === $name ) {
				return class_exists( 'Portal_Test_Mode' )
					? Portal_Test_Mode::artifact_dir() . DIRECTORY_SEPARATOR . Portal_Mailer::ARTIFACT_DIR_NAME
					: '';
			}
			return self::basedir() . DIRECTORY_SEPARATOR . $name;
		}

		/**
		 * Prove a directory can be written and read back by using a transient probe file.
		 *
		 * @param string            $dir   Directory to probe (created when absent).
		 * @param Portal_Files|null $files FS.
		 * @return bool
		 */
		private static function probe_writable( $dir, Portal_Files $files ) {
			try {
				$files->mkdir( $dir );
				$probe = $files->join( $dir, '.dg-write-probe-' . self::site_segment() );
				$files->write( $probe, 'ok' );
				$ok = 'ok' === $files->read_text( $probe );
				$files->remove( $probe );
				return $ok;
			} catch ( Exception $e ) {
				return false;
			}
		}

		/**
		 * Public reusable Apache deny + empty index so directories are not listable.
		 *
		 * @param Portal_Files $files Facade.
		 * @param string       $dir   Directory.
		 * @return void
		 */
		public static function write_deny( Portal_Files $files, $dir ) {
			$htaccess = $files->join( $dir, self::DENY_FILENAME );
			if ( ! $files->exists( $htaccess ) ) {
				$files->write( $htaccess, self::deny_htaccess() );
			}
			$index = $files->join( $dir, self::INDEX_FILENAME );
			if ( ! $files->exists( $index ) ) {
				$files->write( $index, self::deny_index() );
			}
		}

		/**
		 * Apache 2.4 + 2.2 deny body.
		 *
		 * @return string
		 */
		private static function deny_htaccess() {
			return "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";
		}

		/**
		 * Blank directory index.
		 *
		 * @return string
		 */
		private static function deny_index() {
			return "<?php\n// Silence is golden.\n";
		}

		/**
		 * Walk a root and forget expired files (and then-empty dirs).
		 *
		 * @param Portal_Files $files   Facade.
		 * @param string       $path    File or directory.
		 * @param int          $cutoff  Delete files with mtime strictly before this.
		 * @param bool         $is_root Do not remove the walk root.
		 * @return void
		 */
		private static function purge_tree( Portal_Files $files, $path, $cutoff, $is_root ) {
			if ( ! $files->exists( $path ) ) {
				return;
			}
			if ( $files->is_file( $path ) ) {
				self::maybe_forget_file( $files, $path, $cutoff );
				return;
			}
			if ( ! $files->is_dir( $path ) ) {
				return;
			}
			foreach ( $files->list_names( $path ) as $name ) {
				self::purge_tree( $files, $files->join( $path, $name ), $cutoff, false );
			}
			if ( ! $is_root && empty( $files->list_names( $path ) ) ) {
				$files->remove( $path );
			}
		}

		/**
		 * Forget one expired file; leave deny/index in place.
		 *
		 * @param Portal_Files $files  Facade.
		 * @param string       $path   File.
		 * @param int          $cutoff Cutoff unix time.
		 * @return void
		 */
		private static function maybe_forget_file( Portal_Files $files, $path, $cutoff ) {
			$base = basename( $path );
			if ( self::DENY_FILENAME === $base || self::INDEX_FILENAME === $base ) {
				return;
			}
			if ( $files->mtime( $path ) < $cutoff ) {
				$files->remove( $path );
			}
		}

		/**
		 * Whether candidate is not already in the root list.
		 *
		 * @param string   $candidate Path.
		 * @param string[] $roots     Known roots.
		 * @return bool
		 */
		private static function is_new_root( $candidate, array $roots ) {
			if ( '' === $candidate ) {
				return false;
			}
			foreach ( $roots as $root ) {
				if ( rtrim( (string) $root, '/\\' ) === $candidate ) {
					return false;
				}
			}
			return true;
		}
	}
}
