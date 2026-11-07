<?php
/**
 * Conventional-commit subjects → release notes markdown → HTML.
 *
 * @package DragonGate
 */

if ( ! class_exists( 'Portal_Release_Notes' ) ) {

	/**
	 * Pure notes builder — no WordPress calls.
	 */
	class Portal_Release_Notes {

		const SUBJECT_PATTERN = '/^(feat|fix|test|docs|perf|refactor|chore|ci|build|style)(?:\([^)]+\))?!?:\s*(.+)$/i';

		/**
		 * Group labels in display order.
		 *
		 * @var array<string,string>
		 */
		private static $type_groups = array(
			'feat'     => 'Features',
			'fix'      => 'Fixes',
			'test'     => 'Tests',
			'docs'     => 'Documentation',
			'perf'     => 'Performance',
			'refactor' => 'Refactors',
			'chore'    => 'Maintenance',
			'ci'       => 'Maintenance',
			'build'    => 'Maintenance',
		);

		/**
		 * Display order for non-empty groups.
		 *
		 * @var string[]
		 */
		private static $group_order = array(
			'Features',
			'Fixes',
			'Tests',
			'Documentation',
			'Performance',
			'Refactors',
			'Maintenance',
			'Other',
		);

		/**
		 * Build release notes markdown from conventional-commit subjects.
		 *
		 * @param string[] $subjects    Commit subjects (newest-first is fine).
		 * @param string   $version     Semver without leading v.
		 * @param string   $compare_url Optional GitHub compare URL.
		 * @return string
		 */
		public static function markdown_from_subjects( array $subjects, $version, $compare_url = '' ) {
			$groups = array();
			foreach ( $subjects as $subject ) {
				$subject = trim( (string) $subject );
				if ( '' === $subject ) {
					continue;
				}
				if ( preg_match( '/^chore\(release\):/i', $subject ) ) {
					continue;
				}
				$group = 'Other';
				$desc  = $subject;
				if ( preg_match( self::SUBJECT_PATTERN, $subject, $match ) ) {
					$type  = strtolower( $match[1] );
					$desc  = self::capitalize_first( (string) $match[2] );
					$group = isset( self::$type_groups[ $type ] ) ? self::$type_groups[ $type ] : 'Other';
				}
				if ( ! isset( $groups[ $group ] ) ) {
					$groups[ $group ] = array();
				}
				$groups[ $group ][] = $desc;
			}

			$lines = array( '## ' . (string) $version );
			foreach ( self::$group_order as $label ) {
				if ( empty( $groups[ $label ] ) ) {
					continue;
				}
				$lines[] = '';
				$lines[] = '### ' . $label;
				foreach ( $groups[ $label ] as $item ) {
					$lines[] = '- ' . $item;
				}
			}
			$compare_url = trim( (string) $compare_url );
			if ( '' !== $compare_url ) {
				$lines[] = '';
				$lines[] = '**Full Changelog**: ' . $compare_url;
			}
			return implode( "\n", $lines ) . "\n";
		}

		/**
		 * Convert a small markdown subset to HTML (no library).
		 *
		 * Handles headings, bold, links, lists, and paragraphs — enough for
		 * our notes and GitHub's "What's Changed" body style.
		 *
		 * @param string $markdown Source markdown.
		 * @return string
		 */
		public static function html_from_markdown( $markdown ) {
			$text = htmlspecialchars( (string) $markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
			$text = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text );
			$text = preg_replace(
				'/\[([^\]]+)\]\(([^)]+)\)/',
				'<a href="$2" rel="noopener noreferrer" target="_blank">$1</a>',
				$text
			);

			$lines    = preg_split( '/\R/', (string) $text );
			$out      = array();
			$in_list  = false;
			$para     = array();

			foreach ( $lines as $line ) {
				if ( preg_match( '/^### (.+)$/', $line, $match ) ) {
					self::flush_paragraph( $para, $out );
					self::close_list( $in_list, $out );
					$out[] = '<h4>' . $match[1] . '</h4>';
					continue;
				}
				if ( preg_match( '/^## (.+)$/', $line, $match ) ) {
					self::flush_paragraph( $para, $out );
					self::close_list( $in_list, $out );
					$out[] = '<h3>' . $match[1] . '</h3>';
					continue;
				}
				if ( preg_match( '/^[\-\*] (.+)$/', $line, $match ) ) {
					self::flush_paragraph( $para, $out );
					if ( ! $in_list ) {
						$out[]   = '<ul>';
						$in_list = true;
					}
					$out[] = '<li>' . $match[1] . '</li>';
					continue;
				}
				if ( '' === trim( $line ) ) {
					self::flush_paragraph( $para, $out );
					self::close_list( $in_list, $out );
					continue;
				}
				self::close_list( $in_list, $out );
				$para[] = $line;
			}
			self::flush_paragraph( $para, $out );
			self::close_list( $in_list, $out );

			return implode( "\n", $out );
		}

		/**
		 * @param string $text Description text.
		 * @return string
		 */
		private static function capitalize_first( $text ) {
			if ( '' === $text ) {
				return $text;
			}
			if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strtoupper' ) ) {
				return mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
			}
			return strtoupper( $text[0] ) . substr( $text, 1 );
		}

		/**
		 * @param string[] $para Accumulated paragraph lines.
		 * @param string[] $out  Output buffer.
		 * @return void
		 */
		private static function flush_paragraph( array &$para, array &$out ) {
			if ( ! $para ) {
				return;
			}
			$out[] = '<p>' . implode( ' ', $para ) . '</p>';
			$para  = array();
		}

		/**
		 * @param bool     $in_list Whether a list is open.
		 * @param string[] $out     Output buffer.
		 * @return void
		 */
		private static function close_list( &$in_list, array &$out ) {
			if ( ! $in_list ) {
				return;
			}
			$out[]   = '</ul>';
			$in_list = false;
		}
	}
}
