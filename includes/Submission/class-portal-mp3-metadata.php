<?php
/**
 * MP3 metadata removal: drop every tag block, keep audio frames byte-identical.
 *
 * Recordings carry the applicant's identity only in tags (ID3v2 at the start,
 * the end, or between joined recordings; ID3v1; APEv2; Lyrics3). Removing those
 * blocks gives the same anonymity as a re-encode, in milliseconds and without
 * quality loss, so long recordings never wait on the remote anonymizer inside
 * the upload request. An ID3v2 block inside the audio is removed only when its
 * whole header validates, so audio bytes that happen to spell "ID3" stay.
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
		 * Remove tag blocks until none remain.
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
				$bytes  = self::strip_embedded_id3v2( $bytes );
				$same   = $bytes === $before;
			} while ( ! $same );
			return $bytes;
		}

		/**
		 * Remove one leading ID3v2 tag (header, body, optional footer).
		 *
		 * Anything that opens with "ID3" is a tag, never audio, so it is removed
		 * without the strict header checks used for tags inside the file. A
		 * truncated tag is cut to the end of the file rather than kept.
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
		 * Remove every complete, valid ID3v2 tag that sits between audio frames.
		 *
		 * Kept spans are collected and joined once, so the work grows with the
		 * file size, not the file size times the number of tags.
		 *
		 * @param string $bytes Bytes.
		 * @return string
		 */
		private static function strip_embedded_id3v2( $bytes ) {
			// A tag after the first byte needs a full header; shorter buffers (including the
			// empty result of a truncated leading tag) have none, and strpos() rejects offset 1 on ''.
			if ( strlen( $bytes ) <= self::ID3V2_HEADER_BYTES ) {
				return $bytes;
			}
			$kept  = array();
			$start = 0;
			$at    = strpos( $bytes, 'ID3', 1 );
			while ( false !== $at ) {
				$size = self::id3v2_size_at( $bytes, $at, 'ID3' );
				if ( $size > 0 ) {
					$kept[] = substr( $bytes, $start, $at - $start );
					$start  = $at + $size;
					$at     = strpos( $bytes, 'ID3', $start );
					continue;
				}
				$at = strpos( $bytes, 'ID3', $at + 1 );
			}
			if ( 0 === $start ) {
				return $bytes;
			}
			$kept[] = substr( $bytes, $start );
			return implode( '', $kept );
		}

		/**
		 * Total ID3v2 tag length when a valid header (or footer) starts at $at and
		 * the whole tag lies inside $bytes, else 0.
		 *
		 * @param string $bytes  Bytes.
		 * @param int    $at     Offset of the 10-byte header or footer.
		 * @param string $marker 'ID3' for a header, '3DI' for a footer.
		 * @return int
		 */
		private static function id3v2_size_at( $bytes, $at, $marker ) {
			$head = (string) substr( $bytes, $at, self::ID3V2_HEADER_BYTES );
			if ( strlen( $head ) < self::ID3V2_HEADER_BYTES || 0 !== strpos( $head, $marker ) ) {
				return 0;
			}
			$version = ord( $head[3] );
			$flags   = ord( $head[5] );
			$valid   = $version >= 2 && $version <= 4 && 0 === ord( $head[4] ) && 0 === ( $flags & 0x0F )
				&& 0 === ( ord( $head[6] ) | ord( $head[7] ) | ord( $head[8] ) | ord( $head[9] ) ) >> 7;
			if ( ! $valid ) {
				return 0;
			}
			$size = self::ID3V2_HEADER_BYTES + self::syncsafe( substr( $head, 6, 4 ) );
			if ( $flags & self::ID3V2_FOOTER_FLAG ) {
				$size += self::ID3V2_HEADER_BYTES;
			}
			$room = '3DI' === $marker ? $at + self::ID3V2_HEADER_BYTES : strlen( $bytes ) - $at;
			return $size > $room ? 0 : $size;
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
			$footer = self::id3v2_size_at( $bytes, $len - self::ID3V2_HEADER_BYTES, '3DI' );
			if ( $footer > 0 ) {
				return (string) substr( $bytes, 0, $len - $footer );
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
