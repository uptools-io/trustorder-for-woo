<?php
/**
 * Translated labels and the level badge for the admin.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Order;

/**
 * Text and markup only (no logic beyond lookups).
 */
final class Labels {

	private const ICONS = [
		'trusted' => 'dashicons-yes-alt',
		'unknown' => 'dashicons-marker',
		'risky'   => 'dashicons-flag',
		'blocked' => 'dashicons-dismiss',
	];

	/**
	 * Level label.
	 *
	 * @param string $level trusted|unknown|risky|blocked.
	 */
	public static function level( string $level ): string {
		switch ( $level ) {
			case 'trusted':
				return __( 'Trusted', 'trustorder-for-woo' );
			case 'unknown':
				return __( 'Unknown', 'trustorder-for-woo' );
			case 'risky':
				return __( 'Risky', 'trustorder-for-woo' );
			case 'blocked':
				return __( 'Blocked', 'trustorder-for-woo' );
		}

		return $level;
	}

	/**
	 * Reason label; unknown codes are shown as they are (escape on output).
	 *
	 * @param string $code Reason code.
	 */
	public static function reason( string $code ): string {
		$map = [
			'NEGATIVE_MULTI_ACCOUNT' => __( 'Refused orders at several shops', 'trustorder-for-woo' ),
			'NEGATIVE_RECENT'        => __( 'Recently refused an order', 'trustorder-for-woo' ),
			'LOW_SCORE'              => __( 'Low score', 'trustorder-for-woo' ),
			'DISPOSABLE_DOMAIN'      => __( 'Disposable e-mail address', 'trustorder-for-woo' ),
			'DOMAIN_NO_MX'           => __( 'E-mail domain cannot receive mail', 'trustorder-for-woo' ),
			'NO_HISTORY'             => __( 'No order history yet', 'trustorder-for-woo' ),
			'SHOP_ALLOW'             => __( 'Allowed by your exception list', 'trustorder-for-woo' ),
			'SHOP_DENY'              => __( 'Denied by your exception list', 'trustorder-for-woo' ),
		];

		return $map[ $code ] ?? $code;
	}

	/**
	 * Escaped badge HTML for a stored result.
	 *
	 * @param array<string, mixed> $result Stored `_trustorder_result`.
	 */
	public static function badge( array $result ): string {
		if ( ! empty( $result['fallback'] ) ) {
			return '<span class="trustorder-badge trustorder-level-none"><span class="dashicons dashicons-warning" aria-hidden="true"></span> '
				. esc_html__( 'Not checked (limit reached)', 'trustorder-for-woo' ) . '</span>';
		}
		if ( ! empty( $result['failed_open'] ) ) {
			return '<span class="trustorder-badge trustorder-level-none"><span class="dashicons dashicons-warning" aria-hidden="true"></span> '
				. esc_html__( 'Not checked', 'trustorder-for-woo' ) . '</span>';
		}
		$level = isset( $result['level'] ) && is_string( $result['level'] ) && isset( self::ICONS[ $result['level'] ] ) ? $result['level'] : 'unknown';
		$score = isset( $result['score'] ) && is_int( $result['score'] ) ? $result['score'] : 0;

		return '<span class="trustorder-badge trustorder-level-' . esc_attr( $level ) . '">'
			. '<span class="dashicons ' . esc_attr( self::ICONS[ $level ] ) . '" aria-hidden="true"></span> '
			. esc_html( self::level( $level ) ) . ' <span class="trustorder-score">' . esc_html( (string) $score ) . '</span></span>';
	}
}
