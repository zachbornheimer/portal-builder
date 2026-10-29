<?php
/**
 * [dg_portal_status] — a status chip that tells the truth at render time.
 *
 * Usage:
 *   [dg_portal_status portal="25657"]
 *
 * Renders one of: "Open now", "Opens on <date>", "Closed on <date>", or
 * "Closed", derived from the portal's own publish configuration at the moment
 * the page is rendered. The portal attribute is optional on a single portal
 * page. Unknown portals render nothing (never a PHP warning).
 *
 * @package DragonGate
 */

if ( ! function_exists( 'dg_portal_status_shortcode' ) ) {
	/**
	 * Render the portal's current open/closed truth as a chip.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	function dg_portal_status_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'portal' => '',
			),
			$atts,
			'dg_portal_status'
		);

		if ( ! class_exists( 'Portal_Open_State' ) || ! class_exists( 'Portal_Public_Render' ) ) {
			return '';
		}

		$portal_id = dg_portal_shortcode_portal_id( $atts['portal'] );
		if ( $portal_id <= 0 ) {
			return '';
		}

		if ( Portal_Open_State::is_open( $portal_id ) ) {
			return dg_portal_status_chip( 'open', 'Open now' );
		}

		$reason = Portal_Open_State::closed_reason( $portal_id );
		if ( 'launch' === $reason ) {
			$launch = Portal_Open_State::publish_instant( $portal_id, 'open' );
			$date   = Portal_Public_Render::format_when( $launch['value'], $launch['timezone'], 'phrase' );
			if ( '' !== $date ) {
				return dg_portal_status_chip( 'opens', 'Opens on ' . $date );
			}
		}

		$close = Portal_Open_State::publish_instant( $portal_id, 'close' );
		$date  = Portal_Public_Render::format_when( $close['value'], $close['timezone'], 'phrase' );
		if ( '' !== $date ) {
			return dg_portal_status_chip( 'closed', 'Closed on ' . $date );
		}
		return dg_portal_status_chip( 'closed', 'Closed' );
	}
}

if ( ! function_exists( 'dg_portal_status_chip' ) ) {
	/**
	 * Chip markup. Shares the cfsn chip look on the guidelines page.
	 *
	 * @param string $state open|opens|closed.
	 * @param string $text  Chip copy.
	 * @return string
	 */
	function dg_portal_status_chip( $state, $text ) {
		return sprintf(
			'<span class="cfsn-chip cfsn-chip--ink dg-portal-status" data-dg-status="%1$s" data-testid="dg-portal-status">%2$s</span>',
			esc_attr( $state ),
			esc_html( $text )
		);
	}
}
add_shortcode( 'dg_portal_status', 'dg_portal_status_shortcode' );
