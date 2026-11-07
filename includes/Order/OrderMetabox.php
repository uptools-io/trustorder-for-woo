<?php
/**
 * TrustOrder box on the order edit screen (HPOS and legacy).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Order;

use LightweightPlugins\TrustOrder\Settings;

/**
 * `add_meta_boxes` fires on both editors; HPOS passes a WC_Order, legacy a
 * WP_Post (src/Internal/Admin/Orders/Edit.php:170, WC 11.2.1).
 */
final class OrderMetabox {

	/**
	 * Hook in.
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', [ self::class, 'add' ] );
	}

	/**
	 * Add the box on both order screens: the current one from
	 * wc_get_page_screen_id() (includes/admin/wc-admin-functions.php:76; HPOS
	 * `woocommerce_page_wc-orders` or `admin_page_wc-orders`) and legacy `shop_order`.
	 */
	public static function add(): void {
		if ( Settings::silent() ) {
			return;
		}
		$current = function_exists( 'wc_get_page_screen_id' ) ? (string) wc_get_page_screen_id( 'shop-order' ) : '';
		foreach ( array_unique( array_filter( [ $current, 'shop_order' ] ) ) as $screen ) {
			add_meta_box( 'trustorder', __( 'TrustOrder', 'trustorder-for-woo' ), [ self::class, 'render' ], $screen, 'side', 'default' );
		}
	}

	/**
	 * Box content.
	 *
	 * @param mixed $post_or_order WC_Order (HPOS) or WP_Post (legacy).
	 */
	public static function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$result = OrderResult::read( $order );
		if ( null === $result ) {
			echo '<p>' . esc_html__( 'This order was not checked.', 'trustorder-for-woo' ) . '</p>';
			return;
		}

		echo '<p>' . Labels::badge( $result ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Labels::badge() escapes every part.

		if ( ! empty( $result['fallback'] ) ) {
			$code    = is_string( $result['fallback'] ) ? $result['fallback'] : '';
			$blocked = 'block' === ( $result['verdict'] ?? '' );
			echo '<p>' . esc_html(
				$blocked
					/* translators: %s: limit code */
					? sprintf( __( 'Risky payment methods stayed hidden (not checked: check limit reached, %s).', 'trustorder-for-woo' ), $code )
					/* translators: %s: limit code */
					: sprintf( __( 'Not checked: check limit reached (%s); every payment method stayed available.', 'trustorder-for-woo' ), $code )
			) . '</p>';
			return;
		}
		if ( ! empty( $result['failed_open'] ) ) {
			$code = isset( $result['error'] ) && is_string( $result['error'] ) ? $result['error'] : '';
			/* translators: %s: error code */
			echo '<p>' . esc_html( sprintf( __( 'The check did not run (%s); the order was accepted.', 'trustorder-for-woo' ), $code ) ) . '</p>';
			return;
		}

		$reasons = isset( $result['reasons'] ) && is_array( $result['reasons'] ) ? $result['reasons'] : [];
		if ( [] !== $reasons ) {
			echo '<ul class="trustorder-reasons">';
			foreach ( array_filter( $reasons, 'is_string' ) as $reason ) {
				echo '<li>' . esc_html( Labels::reason( $reason ) ) . '</li>';
			}
			echo '</ul>';
		}

		$check_id = isset( $result['check_id'] ) && is_string( $result['check_id'] ) ? $result['check_id'] : '';
		if ( '' !== $check_id ) {
			echo '<p class="description">' . esc_html__( 'Check id:', 'trustorder-for-woo' ) . ' <code>' . esc_html( $check_id ) . '</code></p>';
		}
	}
}
