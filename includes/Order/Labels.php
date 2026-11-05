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
	 * Pill colours per level (inline, so the order screens need no
	 * stylesheet). Text on background, WCAG contrast: new/trusted 5.9:1,
	 * unknown 8.8:1, risky 6.3:1, blocked 6.6:1.
	 */
	private const PILL = 'display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-weight:600;line-height:20px;';

	/**
	 * Dashicon sized to the text line, so icon and label sit on one line.
	 */
	private const ICON_STYLE = 'width:16px;height:16px;font-size:16px;line-height:1';

	private const COLORS = [
		'new'     => 'background:#e7f5e9;color:#1a6b2c',
		'trusted' => 'background:#e7f5e9;color:#1a6b2c',
		'unknown' => 'background:#f0f0f1;color:#3c434a',
		'risky'   => 'background:#fcf0e1;color:#8a4600',
		'blocked' => 'background:#fbe7e7;color:#a1191d',
		'none'    => 'background:#f0f0f1;color:#3c434a',
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
				return __( 'Limited history', 'trustorder-for-woo' );
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
			'NO_HISTORY'             => __( 'First-time customer', 'trustorder-for-woo' ),
			'SHOP_ALLOW'             => __( 'Allowed by your exception list', 'trustorder-for-woo' ),
			'SHOP_DENY'              => __( 'Denied by your exception list', 'trustorder-for-woo' ),
		];

		return $map[ $code ] ?? $code;
	}

	/**
	 * Whether the service has no history at all for this buyer: an "unknown"
	 * level with the NO_HISTORY reason (a buyer with some history but too few
	 * deliveries to be trusted stays "unknown").
	 *
	 * @param array<string, mixed> $result Stored `_trustorder_result`.
	 */
	public static function isNewBuyer( array $result ): bool {
		return 'unknown' === ( $result['level'] ?? null )
			&& is_array( $result['reasons'] ?? null )
			&& in_array( 'NO_HISTORY', $result['reasons'], true );
	}

	/**
	 * Escaped badge HTML for a stored result.
	 *
	 * @param array<string, mixed> $result Stored `_trustorder_result`.
	 */
	public static function badge( array $result ): string {
		if ( ! empty( $result['fallback'] ) ) {
			return '<span class="trustorder-badge trustorder-level-none" style="' . esc_attr( self::style( 'none' ) ) . '">' . self::icon( 'dashicons-warning' )
				. esc_html__( 'Not checked (limit reached)', 'trustorder-for-woo' ) . '</span>';
		}
		if ( ! empty( $result['failed_open'] ) ) {
			return '<span class="trustorder-badge trustorder-level-none" style="' . esc_attr( self::style( 'none' ) ) . '">' . self::icon( 'dashicons-warning' )
				. esc_html__( 'Not checked', 'trustorder-for-woo' ) . '</span>';
		}
		if ( self::isNewBuyer( $result ) ) {
			return '<span class="trustorder-badge trustorder-level-new" style="' . esc_attr( self::style( 'new' ) ) . '">'
				. self::icon( 'dashicons-admin-users' )
				. esc_html__( 'New customer', 'trustorder-for-woo' ) . '</span>';
		}
		$level = isset( $result['level'] ) && is_string( $result['level'] ) && isset( self::ICONS[ $result['level'] ] ) ? $result['level'] : 'unknown';
		// A result imported without a score (e.g. from an earlier tool) shows the level only.
		$score = isset( $result['score'] ) && is_int( $result['score'] )
			? ' <span class="trustorder-score">' . esc_html( (string) $result['score'] ) . '</span>'
			: '';

		return '<span class="trustorder-badge trustorder-level-' . esc_attr( $level ) . '" style="' . esc_attr( self::style( $level ) ) . '">'
			. self::icon( self::ICONS[ $level ] )
			. esc_html( self::level( $level ) ) . $score . '</span>';
	}

	/**
	 * Inline pill style for a level key of COLORS.
	 *
	 * @param string $key Level or "new".
	 */
	private static function style( string $key ): string {
		return self::PILL . self::COLORS[ $key ];
	}

	/**
	 * Escaped dashicon span, aligned with the label.
	 *
	 * @param string $name Dashicon class.
	 */
	private static function icon( string $name ): string {
		return '<span class="dashicons ' . esc_attr( $name ) . '" style="' . esc_attr( self::ICON_STYLE ) . '" aria-hidden="true"></span>';
	}
}
