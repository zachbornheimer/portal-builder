<?php
/**
 * [dg_portal_when] — a portal's real open/close instant from its definition.
 *
 * Usage:
 *   [dg_portal_when which="open|close" portal="25657" format="phrase|date|ymd"]
 *
 * - which:  "open" renders publish.launchAt, "close" renders publish.deadline.
 * - portal: portal post ID. Optional when rendered on a single portal page.
 * - format: "phrase" → Monday, August 17, 2026
 *           "date"   → 17 August 2026, 4:00 p.m. EDT
 *           "ymd"    → 2026-08-17
 *
 * The instant is formatted in the portal's timezone through the shared
 * applicant-facing formatter (Portal_Public_Render::format_when) — there is no
 * second date formatter. An unknown portal or an unconfigured date renders a
 * safe placeholder, never a PHP warning.
 *
 * @package DragonGate
 */

if ( ! function_exists( 'dg_portal_shortcode_portal_id' ) ) {
	/**
	 * Resolve the shortcode's portal attribute, falling back to the portal
	 * being viewed. Returns 0 when no portal applies.
	 *
	 * @param mixed $raw Portal attribute value.
	 * @return int
	 */
	function dg_portal_shortcode_portal_id( $raw ) {
		$id = (int) $raw;
		if ( $id <= 0 && function_exists( 'is_singular' ) && is_singular( 'portal' ) && function_exists( 'get_queried_object_id' ) ) {
			$id = (int) get_queried_object_id();
		}
		if ( $id <= 0 || ! function_exists( 'get_post' ) ) {
			return 0;
		}
		$post = get_post( $id );
		if ( ! $post || 'portal' !== $post->post_type ) {
			return 0;
		}
		return $id;
	}
}

if ( ! function_exists( 'dg_portal_when_shortcode' ) ) {
	/**
	 * Render the portal's open or close instant as plain text.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	function dg_portal_when_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'which'  => 'open',
				'portal' => '',
				'format' => 'phrase',
			),
			$atts,
			'dg_portal_when'
		);

		if ( ! class_exists( 'Portal_Open_State' ) || ! class_exists( 'Portal_Public_Render' ) ) {
			return '';
		}

		$portal_id = dg_portal_shortcode_portal_id( $atts['portal'] );
		if ( $portal_id <= 0 ) {
			return '';
		}

		$which   = 'close' === strtolower( trim( (string) $atts['which'] ) ) ? 'close' : 'open';
		$instant = Portal_Open_State::publish_instant( $portal_id, $which );
		if ( null === $instant['value'] ) {
			return 'date to be announced';
		}

		$format = strtolower( trim( (string) $atts['format'] ) );
		$style  = in_array( $format, array( 'phrase', 'date', 'ymd' ), true ) ? $format : 'phrase';

		$text = Portal_Public_Render::format_when( $instant['value'], $instant['timezone'], $style );
		if ( '' === $text ) {
			return 'date to be announced';
		}
		if ( 'date' === $style ) {
			$text .= ' ' . Portal_Public_Render::timezone_label( $instant['value'], $instant['timezone'] );
		}
		return $text;
	}
}
add_shortcode( 'dg_portal_when', 'dg_portal_when_shortcode' );
