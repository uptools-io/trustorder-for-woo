<?php
/**
 * Admin warnings when a local check cap tripped in the last hour.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Admin;

use LightweightPlugins\TrustOrder\Checkout\QuotaGuard;

/**
 * Two notices on WooCommerce / TrustOrder screens for `manage_woocommerce`
 * users: the shop-wide hourly breaker and the place-order network cap. Each
 * is dismissible per user and per trip (the dismissal stores the trip time).
 */
final class CapNotice {

	public const ACTION = 'trustorder_dismiss_cap_notice';

	private const META = 'trustorder_cap_notice_dismissed';

	/**
	 * Hook in.
	 */
	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'render' ] );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'dismiss' ] );
	}

	/**
	 * Notice texts by trip transient.
	 *
	 * @return array<string, string>
	 */
	private static function messages(): array {
		return [
			QuotaGuard::BREAKER_TRANSIENT   => __( 'TrustOrder reached its hourly check limit in the last hour. While the limit was reached, buyers not checked yet kept every payment method while filling in the checkout. If your shop really has this many checkouts, raise the limit with the trustorder_hourly_check_limit filter.', 'trustorder-for-woo' ),
			QuotaGuard::SUBMIT_IP_TRANSIENT => __( 'TrustOrder reached its place-order check limit for one visitor network in the last hour. For those orders the risky payment methods stayed hidden. If many buyers share one address (for example behind a proxy), set the real visitor address with the trustorder_client_ip filter, or raise the limit with trustorder_submit_ip_check_limit.', 'trustorder-for-woo' ),
		];
	}

	/**
	 * Print the active notices.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! Screen::relevant() ) {
			return;
		}
		$dismissed = get_user_meta( get_current_user_id(), self::META, true );
		$dismissed = is_array( $dismissed ) ? $dismissed : [];

		foreach ( self::messages() as $transient => $text ) {
			$at = get_transient( $transient );
			if ( ! is_numeric( $at ) || (int) ( $dismissed[ $transient ] ?? 0 ) >= (int) $at ) {
				continue;
			}
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&notice=' . rawurlencode( $transient ) ), self::ACTION );
			echo '<div class="notice notice-warning"><p>' . esc_html( $text ) . '</p><p><a href="' . esc_url( $url ) . '">'
				. esc_html__( 'Dismiss', 'trustorder-for-woo' ) . '</a></p></div>';
		}
	}

	/**
	 * Admin-post handler: remember the dismissal, go back.
	 */
	public static function dismiss(): void {
		self::recordDismissal();
		$back = wp_get_referer();
		wp_safe_redirect( false !== $back ? $back : admin_url() );
		exit;
	}

	/**
	 * Store the dismissal of the current trip (nonce + capability checked).
	 */
	public static function recordDismissal(): bool {
		$nonce  = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION ) || ! current_user_can( 'manage_woocommerce' ) || ! isset( self::messages()[ $notice ] ) ) {
			return false;
		}
		$at = get_transient( $notice );
		if ( ! is_numeric( $at ) ) {
			return false;
		}
		$user      = get_current_user_id();
		$dismissed = get_user_meta( $user, self::META, true );
		$dismissed = is_array( $dismissed ) ? $dismissed : [];

		$dismissed[ $notice ] = (int) $at;
		update_user_meta( $user, self::META, $dismissed );

		return true;
	}
}
