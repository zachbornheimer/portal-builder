<?php
/**
 * MP3 metadata removal: drop every tag block, keep audio frames byte-identical.
 *
 * Recordings carry the applicant's identity only in tags (ID3v2, ID3v1, APEv2,
 * Lyrics3). Removing those blocks gives the same anonymity as a re-encode, in
 * milliseconds and without quality loss, so long recordings never wait on the
 * remote anonymizer inside the upload request.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Mp3_Metadata' ) ) {

	/**
	 * Pure byte transform over one MP3 buffer.
	 */
	class Portal_Mp3_Metadata {

		const ID3V2_HEADER_BYTES = 10;
		const ID3V2_FOOTER_FLAG  = 0x10;
		const ID3V1_BYTES        = 128;
		const ID3V1_EXT_BYTES    = 227;
		const APE_BLOCK_BYTES    = 32;
		const APE_HAS_HEADER     = 0x80000000;
		const LYRICS3_END        = 'LYRICS200';
		const LYRICS3_SIZE_BYTES = 6;
		const LYRICS3V1_BEGIN    = 'LYRICSBEGIN';
		const LYRICS3V1_END      = 'LYRICSEND';
		const LYRICS3V1_MAX      = 5100;

		/**
		 * Remove leading and trailing tag blocks until none remain.
		 *
		 * @param string $bytes MP3 file bytes.
		 * @return string Audio frames only.
		 */
		public static function strip( $bytes ) {
			$bytes = (string) $bytes;
			do {
				$before = $bytes;
				$bytes  = self::strip_leading_id3v2( $bytes );
				$bytes  = self::strip_trailing_block( $bytes );
				$same   = $bytes === $before;
			} while ( ! $same );
			return $bytes;
		}

		/**
		 * Remove one leading ID3v2 tag (header, body, optional footer).
		 *
		 * @param string $bytes Bytes.
		 * @return string
		 */
		private static function strip_leading_id3v2( $bytes ) {
			if ( strlen( $bytes ) < self::ID3V2_HEADER_BYTES || 0 !== strpos( $bytes, 'ID3' ) ) {
				return $bytes;
			}
			$size = self::ID3V2_HEADER_BYTES + self::syncsafe( substr( $bytes, 6, 4 ) );
			if ( ord( $bytes[5] ) & self::ID3V2_FOOTER_FLAG ) {
				$size += self::ID3V2_HEADER_BYTES;
			}
			return (string) substr( $bytes, min( $size, strlen( $bytes ) ) );
		}

		/**
		 * Remove one trailing tag block (ID3v1, ID3v1 extended, APEv2, Lyrics3 v1/v2).
		 *
		 * @param string $bytes Bytes.
		 * @return string
		 */
		private static function strip_trailing_block( $bytes ) {
			$len = strlen( $bytes );
			if ( $len >= self::ID3V1_EXT_BYTES && 'TAG+' === substr( $bytes, -self::ID3V1_EXT_BYTES, 4 ) ) {
				return (string) substr( $bytes, 0, $len - self::ID3V1_EXT_BYTES );
			}
			if ( $len >= self::ID3V1_BYTES && 'TAG' === substr( $bytes, -self::ID3V1_BYTES, 3 ) ) {
				return (string) substr( $bytes, 0, $len - self::ID3V1_BYTES );
			}
			if ( $len >= self::APE_BLOCK_BYTES && 'APETAGEX' === substr( $bytes, -self::APE_BLOCK_BYTES, 8 ) ) {
				return (string) substr( $bytes, 0, max( 0, $len - self::ape_size( substr( $bytes, -self::APE_BLOCK_BYTES ) ) ) );
			}
			return self::strip_lyrics3( $bytes );
		}

		/**
		 * Remove one trailing Lyrics3 block: v2 by its size field, v1 by its BEGIN marker.
		 *
		 * @param string $bytes Bytes.
		 * @return string
		 */
		private static function strip_lyrics3( $bytes ) {
			$len = strlen( $bytes );
			$end = strlen( self::LYRICS3_END );
			if ( $len >= $end + self::LYRICS3_SIZE_BYTES && self::LYRICS3_END === substr( $bytes, -$end ) ) {
				$body = (int) substr( $bytes, -( $end + self::LYRICS3_SIZE_BYTES ), self::LYRICS3_SIZE_BYTES );
				return (string) substr( $bytes, 0, max( 0, $len - $body - $end - self::LYRICS3_SIZE_BYTES ) );
			}
			$v1_end = strlen( self::LYRICS3V1_END );
			if ( $len < $v1_end || self::LYRICS3V1_END !== substr( $bytes, -$v1_end ) ) {
				return $bytes;
			}
			$span   = min( $len, strlen( self::LYRICS3V1_BEGIN ) + self::LYRICS3V1_MAX + $v1_end );
			$window = (string) substr( $bytes, -$span );
			$at     = strrpos( $window, self::LYRICS3V1_BEGIN );
			return false === $at ? $bytes : (string) substr( $bytes, 0, $len - $span + $at );
		}

		/**
		 * Total APEv2 bytes from its footer (items + footer, plus header when flagged).
		 *
		 * @param string $footer 32-byte APE footer.
		 * @return int
		 */
		private static function ape_size( $footer ) {
			$fields = unpack( 'Vsize/Vcount/Vflags', substr( $footer, 12, 12 ) );
			$size   = (int) $fields['size'];
			if ( ( (int) $fields['flags'] ) & self::APE_HAS_HEADER ) {
				$size += self::APE_BLOCK_BYTES;
			}
			return $size;
		}

		/**
		 * Decode a 28-bit ID3v2 syncsafe integer.
		 *
		 * @param string $four Four syncsafe bytes.
		 * @return int
		 */
		private static function syncsafe( $four ) {
			$n = 0;
			for ( $i = 0; $i < 4; $i++ ) {
				$n = ( $n << 7 ) | ( ord( $four[ $i ] ) & 0x7f );
			}
			return $n;
		}
	}
}
