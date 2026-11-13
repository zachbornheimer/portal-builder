<?php
/**
 * Magic-byte file signatures for judge-facing anonymize replacements.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_File_Signature' ) ) {

	/**
	 * Declares whether bytes are a real PDF, JPEG, PNG, or MP3.
	 */
	class Portal_File_Signature {

		const TYPE_PDF  = 'application/pdf';
		const TYPE_JPEG = 'image/jpeg';
		const TYPE_PNG  = 'image/png';
		const TYPE_MP3  = 'audio/mpeg';

		const MAGIC_PDF  = '%PDF';
		const MAGIC_ID3  = 'ID3';
		const MAGIC_JPEG = "\xFF\xD8\xFF";
		const MAGIC_PNG  = "\x89PNG";

		const EXT_PDF  = 'pdf';
		const EXT_JPG  = 'jpg';
		const EXT_JPEG = 'jpeg';
		const EXT_PNG  = 'png';
		const EXT_MP3  = 'mp3';

		/**
		 * Best-effort type from bytes, then declared type, then filename.
		 *
		 * @param string      $bytes         File bytes.
		 * @param string      $filename      Original name.
		 * @param string|null $declared_type Optional declared MIME.
		 * @return string Empty when unknown.
		 */
		public static function sniff( $bytes, $filename = '', $declared_type = null ) {
			$from_bytes = self::type_from_bytes( $bytes );
			if ( '' !== $from_bytes ) {
				return $from_bytes;
			}
			$declared = self::normalize_type( $declared_type );
			if ( '' !== $declared ) {
				return $declared;
			}
			return self::type_from_filename( $filename );
		}

		/**
		 * Whether bytes match the expected type. audio/mpeg requires MP3 magic.
		 *
		 * @param string $bytes File bytes.
		 * @param string $type  Expected MIME or empty.
		 * @return bool
		 */
		public static function matches( $bytes, $type ) {
			$type = self::normalize_type( $type );
			if ( self::TYPE_PDF === $type ) {
				return self::is_pdf( $bytes );
			}
			if ( self::TYPE_JPEG === $type ) {
				return self::is_jpeg( $bytes );
			}
			if ( self::TYPE_PNG === $type ) {
				return self::is_png( $bytes );
			}
			if ( self::TYPE_MP3 === $type ) {
				return self::is_mp3( $bytes );
			}
			return '' !== self::type_from_bytes( $bytes );
		}

		/**
		 * @param mixed $type Raw type.
		 * @return string
		 */
		public static function normalize_type( $type ) {
			if ( ! is_string( $type ) ) {
				return '';
			}
			$normalized = strtolower( trim( $type ) );
			if ( 'image/jpg' === $normalized ) {
				return self::TYPE_JPEG;
			}
			return $normalized;
		}

		/**
		 * @param string $bytes File bytes.
		 * @return bool
		 */
		public static function is_pdf( $bytes ) {
			return 0 === strpos( $bytes, self::MAGIC_PDF );
		}

		/**
		 * @param string $bytes File bytes.
		 * @return bool
		 */
		public static function is_jpeg( $bytes ) {
			return 0 === strpos( $bytes, self::MAGIC_JPEG );
		}

		/**
		 * @param string $bytes File bytes.
		 * @return bool
		 */
		public static function is_png( $bytes ) {
			return 0 === strpos( $bytes, self::MAGIC_PNG );
		}

		/**
		 * ID3 tag or MPEG frame sync (0xFF and 0xE0 bits in the next byte).
		 *
		 * @param string $bytes File bytes.
		 * @return bool
		 */
		public static function is_mp3( $bytes ) {
			if ( 0 === strpos( $bytes, self::MAGIC_ID3 ) ) {
				return true;
			}
			return strlen( $bytes ) >= 2
				&& "\xFF" === $bytes[0]
				&& ( ord( $bytes[1] ) & 0xE0 ) === 0xE0;
		}

		/**
		 * @param string $bytes File bytes.
		 * @return string
		 */
		private static function type_from_bytes( $bytes ) {
			if ( self::is_pdf( $bytes ) ) {
				return self::TYPE_PDF;
			}
			if ( self::is_jpeg( $bytes ) ) {
				return self::TYPE_JPEG;
			}
			if ( self::is_png( $bytes ) ) {
				return self::TYPE_PNG;
			}
			if ( self::is_mp3( $bytes ) ) {
				return self::TYPE_MP3;
			}
			return '';
		}

		/**
		 * @param string $filename Name.
		 * @return string
		 */
		private static function type_from_filename( $filename ) {
			$ext = strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
			if ( self::EXT_PDF === $ext ) {
				return self::TYPE_PDF;
			}
			if ( self::EXT_JPG === $ext || self::EXT_JPEG === $ext ) {
				return self::TYPE_JPEG;
			}
			if ( self::EXT_PNG === $ext ) {
				return self::TYPE_PNG;
			}
			if ( self::EXT_MP3 === $ext ) {
				return self::TYPE_MP3;
			}
			return '';
		}
	}
}
