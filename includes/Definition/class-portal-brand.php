<?php
/**
 * Host brand profile for the public application form.
 *
 * @package DragonGate
 */

declare(strict_types=1);

if ( ! class_exists( 'Portal_Brand' ) ) {

	/**
	 * Sanitize, built-in presets, and CSS variable remap. No second stylesheet.
	 */
	class Portal_Brand {

		const OPTION_DEFAULT  = 'dg_default_brand';
		const PRESET_PRODUCT  = 'product';
		const PRESET_CUSTOM   = 'custom';
		const PRESET_ISJAC    = 'isjac';
		const HEX_PATTERN     = '/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/';
		const LENGTH_PATTERN  = '/^[0-9]+px$/';
		const ISJAC_FONTS_URL = 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;900&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap';

		const COLOR_KEYS  = array( 'ink', 'ink800', 'ink600', 'paper', 'wash', 'accent', 'accentHover', 'eyebrow', 'rule', 'error', 'success' );
		const FONT_KEYS   = array( 'fontDisplay', 'fontUi', 'fontMono' );
		const URL_KEYS    = array( 'fontsUrl', 'logoUrl' );
		const LENGTH_KEYS = array( 'rPill', 'r2', 'r3' );

		const COLOR_VARS = array(
			'ink'         => array( '--ink' ),
			'ink800'      => array( '--ink-800' ),
			'ink600'      => array( '--ink-600', '--ink-400' ),
			'paper'       => array( '--paper', '--paper-50' ),
			'wash'        => array( '--paper-100', '--ember-100' ),
			'accent'      => array( '--ember', '--ember-flow' ),
			'accentHover' => array( '--ember-600' ),
			'eyebrow'     => array( '--brass' ),
			'rule'        => array( '--brass-200' ),
			'error'       => array( '--error' ),
			'success'     => array( '--success' ),
		);

		const FONT_VARS = array(
			'fontDisplay' => '--font-display',
			'fontUi'      => '--font-ui',
			'fontMono'    => '--font-mono',
		);

		const LENGTH_VARS = array(
			'rPill' => '--r-pill',
			'r2'    => '--r-2',
			'r3'    => '--r-3',
		);

		/**
		 * Built-in profile, or product (no override).
		 *
		 * @param string $name Preset id.
		 * @return array
		 */
		public static function preset( $name ) {
			if ( self::PRESET_ISJAC === $name ) {
				return array(
					'preset'      => self::PRESET_ISJAC,
					'ink'         => '#020726',
					'ink800'      => '#15264A',
					'ink600'      => '#666666',
					'paper'       => '#FFFAFC',
					'wash'        => '#F0F6FC',
					'accent'      => '#15526F',
					'accentHover' => '#3D6E93',
					'eyebrow'     => '#5384AA',
					'rule'        => '#DDE5EE',
					'error'       => '#B0000F',
					'success'     => '#1E7F4F',
					'fontDisplay' => '"DM Sans", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
					'fontUi'      => '"Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
					'fontMono'    => '"IBM Plex Mono", ui-monospace, "SF Mono", Menlo, monospace',
					'fontsUrl'    => self::ISJAC_FONTS_URL,
					'logoUrl'     => '',
					'hideLogo'    => true,
					'rPill'       => '8px',
					'r2'          => '6px',
					'r3'          => '12px',
				);
			}
			if ( self::PRESET_CUSTOM === $name ) {
				return array( 'preset' => self::PRESET_CUSTOM );
			}
			return array( 'preset' => self::PRESET_PRODUCT );
		}

		/**
		 * @param mixed $raw Posted or stored bag.
		 * @return array
		 */
		public static function sanitize( $raw ) {
			if ( ! is_array( $raw ) ) {
				return self::preset( self::PRESET_PRODUCT );
			}
			$preset = isset( $raw['preset'] ) ? (string) $raw['preset'] : self::PRESET_PRODUCT;
			$known  = array( self::PRESET_PRODUCT, self::PRESET_CUSTOM, self::PRESET_ISJAC );
			if ( ! in_array( $preset, $known, true ) ) {
				$preset = self::PRESET_PRODUCT;
			}
			if ( self::PRESET_PRODUCT === $preset ) {
				return self::preset( self::PRESET_PRODUCT );
			}
			$fields = self::sanitize_fields( $raw );
			if ( self::PRESET_ISJAC === $preset ) {
				$merged           = array_merge( self::preset( self::PRESET_ISJAC ), self::non_empty_fields( $fields ) );
				$merged['preset'] = self::PRESET_ISJAC;
				if ( array_key_exists( 'hideLogo', $raw ) ) {
					$merged['hideLogo'] = self::truthy( $raw['hideLogo'] );
				}
				return $merged;
			}
			$fields['preset']   = self::PRESET_CUSTOM;
			$fields['hideLogo'] = self::truthy( isset( $raw['hideLogo'] ) ? $raw['hideLogo'] : false );
			return $fields;
		}

		/**
		 * Host look (not product / empty) — emit extra CSS.
		 *
		 * @param mixed $brand Brand bag.
		 * @return bool
		 */
		public static function is_host( $brand ) {
			if ( ! is_array( $brand ) || array() === $brand ) {
				return false;
			}
			$preset = isset( $brand['preset'] ) ? (string) $brand['preset'] : '';
			return self::PRESET_PRODUCT !== $preset && '' !== $preset;
		}

		/**
		 * @param mixed $brand Brand bag.
		 * @return string
		 */
		public static function fonts_url( $brand ) {
			return self::url_field( self::sanitize( $brand ), 'fontsUrl' );
		}

		/**
		 * @param mixed $brand Brand bag.
		 * @return string
		 */
		public static function logo_url( $brand ) {
			return self::url_field( self::sanitize( $brand ), 'logoUrl' );
		}

		/**
		 * Host white-label may omit the product seal entirely.
		 *
		 * @param mixed $brand Brand bag.
		 * @return bool
		 */
		public static function hides_logo( $brand ) {
			$clean = self::sanitize( $brand );
			return ! empty( $clean['hideLogo'] );
		}

		/**
		 * Remap existing public-form variables. Empty when product / inactive.
		 *
		 * @param mixed $brand Brand bag.
		 * @return string
		 */
		public static function css( $brand ) {
			$brand = self::sanitize( $brand );
			if ( ! self::is_host( $brand ) ) {
				return '';
			}
			$vars = self::css_vars( $brand );
			if ( array() === $vars ) {
				return '';
			}
			$decls = '';
			foreach ( $vars as $prop => $value ) {
				$decls .= $prop . ':' . $value . ';';
			}
			$css = 'body.single-portal,body.dg-public-portal,.dg-packet{' . $decls . '}';
			if ( isset( $vars['--paper'] ) ) {
				$wash = isset( $vars['--paper-100'] ) ? $vars['--paper-100'] : $vars['--paper'];
				$css .= 'body.single-portal,body.dg-public-portal{background:radial-gradient(circle at top left,' . $wash . ',transparent 60%),radial-gradient(circle at bottom right,' . $wash . ',transparent 65%),' . $vars['--paper'] . ';background-attachment:fixed;}';
			}
			return $css;
		}

		private static function css_vars( array $brand ) {
			$vars = array();
			foreach ( self::COLOR_VARS as $key => $props ) {
				$hex = self::sanitize_hex( isset( $brand[ $key ] ) ? $brand[ $key ] : '' );
				if ( '' === $hex ) {
					continue;
				}
				foreach ( $props as $prop ) {
					$vars[ $prop ] = $hex;
				}
			}
			foreach ( self::FONT_VARS as $key => $prop ) {
				$stack = self::sanitize_font( isset( $brand[ $key ] ) ? $brand[ $key ] : '' );
				if ( '' !== $stack ) {
					$vars[ $prop ] = $stack;
				}
			}
			foreach ( self::LENGTH_VARS as $key => $prop ) {
				$length = self::sanitize_length( isset( $brand[ $key ] ) ? $brand[ $key ] : '' );
				if ( '' !== $length ) {
					$vars[ $prop ] = $length;
				}
			}
			if ( isset( $vars['--paper'], $vars['--ember'] ) ) {
				$vars['--focus-ring'] = '0 0 0 2px ' . $vars['--paper'] . ', 0 0 0 4px ' . $vars['--ember'];
			}
			return $vars;
		}

		private static function sanitize_fields( array $raw ) {
			$out = array();
			foreach ( self::COLOR_KEYS as $key ) {
				$out[ $key ] = self::sanitize_hex( isset( $raw[ $key ] ) ? $raw[ $key ] : '' );
			}
			foreach ( self::FONT_KEYS as $key ) {
				$out[ $key ] = self::sanitize_font( isset( $raw[ $key ] ) ? $raw[ $key ] : '' );
			}
			foreach ( self::URL_KEYS as $key ) {
				$out[ $key ] = self::sanitize_url( isset( $raw[ $key ] ) ? $raw[ $key ] : '' );
			}
			foreach ( self::LENGTH_KEYS as $key ) {
				$out[ $key ] = self::sanitize_length( isset( $raw[ $key ] ) ? $raw[ $key ] : '' );
			}
			return $out;
		}

		private static function non_empty_fields( array $fields ) {
			$out = array();
			foreach ( $fields as $key => $value ) {
				if ( is_string( $value ) && '' !== $value ) {
					$out[ $key ] = $value;
				}
			}
			return $out;
		}

		private static function url_field( $brand, $key ) {
			if ( ! is_array( $brand ) || ! isset( $brand[ $key ] ) ) {
				return '';
			}
			return self::sanitize_url( $brand[ $key ] );
		}

		private static function sanitize_hex( $value ) {
			if ( ! is_string( $value ) ) {
				return '';
			}
			$trimmed = trim( $value );
			return 1 === preg_match( self::HEX_PATTERN, $trimmed ) ? strtoupper( $trimmed ) : '';
		}

		private static function sanitize_font( $value ) {
			if ( ! is_string( $value ) ) {
				return '';
			}
			return trim( str_replace( array( '<', '>', '{', '}' ), '', $value ) );
		}

		private static function sanitize_url( $value ) {
			if ( ! is_string( $value ) ) {
				return '';
			}
			$trimmed = trim( $value );
			if ( '' === $trimmed ) {
				return '';
			}
			if ( function_exists( 'esc_url_raw' ) ) {
				return (string) esc_url_raw( $trimmed );
			}
			if ( 1 !== preg_match( '#^https?://#i', $trimmed ) ) {
				return '';
			}
			$clean = filter_var( $trimmed, FILTER_SANITIZE_URL );
			return is_string( $clean ) ? $clean : '';
		}

		private static function truthy( $value ) {
			if ( true === $value || 1 === $value || '1' === $value || 'true' === $value ) {
				return true;
			}
			return false;
		}

		private static function sanitize_length( $value ) {
			if ( ! is_string( $value ) ) {
				return '';
			}
			$trimmed = trim( $value );
			return 1 === preg_match( self::LENGTH_PATTERN, $trimmed ) ? $trimmed : '';
		}
	}
}
