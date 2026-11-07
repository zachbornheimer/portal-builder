<?php
/**
 * Offline stand-ins for the prefixed Google API classes.
 *
 * Declared only when the real vendor-prefixed/ tree is absent, so harnesses
 * run on a checkout that has not run `composer install`. The names are the
 * Strauss-prefixed ones the plugin imports (DragonGate\Vendor\Google\...).
 */

namespace DragonGate\Vendor\Google;

if ( ! class_exists( __NAMESPACE__ . '\\Client' ) ) {
	class Client {
		/** @var array<string,mixed> */
		protected $token = array();

		public function __construct() {}

		public function setApplicationName( $name ) {
			unset( $name );
		}

		public function setScopes( $scopes ) {
			unset( $scopes );
		}

		public function setAccessType( $type ) {
			unset( $type );
		}

		public function setAuthConfig( $config ) {
			unset( $config );
		}

		public function setAccessToken( $token ) {
			$decoded     = is_string( $token ) ? json_decode( $token, true ) : $token;
			$this->token = is_array( $decoded ) ? $decoded : array( 'access_token' => (string) $token );
		}

		public function getAccessToken() {
			return $this->token;
		}

		public function getRefreshToken() {
			return isset( $this->token['refresh_token'] ) ? (string) $this->token['refresh_token'] : null;
		}

		public function isAccessTokenExpired() {
			return true;
		}

		public function fetchAccessTokenWithRefreshToken( $refresh_token = null ) {
			unset( $refresh_token );
			return $this->token;
		}
	}
}

namespace DragonGate\Vendor\Google\Service;

if ( ! class_exists( __NAMESPACE__ . '\\Sheets' ) ) {
	class Sheets {
		const SPREADSHEETS = 'https://www.googleapis.com/auth/spreadsheets';

		public function __construct( $client = null ) {
			unset( $client );
		}
	}
}

if ( ! class_exists( __NAMESPACE__ . '\\Drive' ) ) {
	class Drive {
		const DRIVE = 'https://www.googleapis.com/auth/drive';

		public function __construct( $client = null ) {
			unset( $client );
		}
	}
}

namespace DragonGate\Vendor\Google\Service\Drive;

if ( ! class_exists( __NAMESPACE__ . '\\DriveFile' ) ) {
	class DriveFile {
		/** @var array<string,mixed> */
		private $meta;

		public function __construct( array $meta = array() ) {
			$this->meta = $meta;
		}

		public function getName() {
			return isset( $this->meta['name'] ) ? (string) $this->meta['name'] : null;
		}
	}
}
