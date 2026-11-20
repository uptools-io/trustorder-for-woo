<?php
/**
 * Admin warning when checkout visitors arrive from a private / local address.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Admin;

use LightweightPlugins\TrustOrder\Checkout\QuotaGuard;

/**
 * A proxy or load balancer in front of the shop hides the visitor address,
 * so the per-network check limits cannot apply. Shown on WooCommerce /
 * TrustOrder screens to `manage_woocommerce` users while a checkout check saw
 * such an address in the last day and the `trustorder_client_ip` filter is
 * not set. Not dismissible: it goes away once the filter is set.
 */
final class ProxyNotice {

	/**
	 * Hook in.
	 */
	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'render' ] );
	}

	/**
	 * Print the notice.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! Screen::relevant() ) {
			return;
		}
		if ( has_filter( 'trustorder_client_ip' ) || false === get_transient( QuotaGuard::LOCAL_CLIENT_TRANSIENT ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'TrustOrder sees a private or local address (for example 10.x.x.x or 127.0.0.1) for your checkout visitors, so a proxy or load balancer probably stands in front of the shop. The per-network check limits cannot tell these visitors apart. Set the real visitor address with the trustorder_client_ip filter, for example from the header your proxy sends.', 'trustorder-for-woo' )
			. '</p></div>';
	}
}
