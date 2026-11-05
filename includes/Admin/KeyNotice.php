<?php
/**
 * Order list warning when no API key is set.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Admin;

use LightweightPlugins\TrustOrder\Settings;

/**
 * Without a key no buyer is checked and the order list has no TrustOrder
 * column; this notice on the order list (HPOS and legacy) says why.
 */
final class KeyNotice {

	/**
	 * Order list screen ids (HPOS, legacy).
	 */
	private const SCREENS = [ 'woocommerce_page_wc-orders', 'edit-shop_order' ];

	/**
	 * Hook in.
	 */
	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'render' ] );
	}

	/**
	 * Whether a valid API key is set (saved or from the constant).
	 */
	public static function hasKey(): bool {
		return '' !== Settings::get()['api_key'];
	}

	/**
	 * Print the warning on the order list.
	 */
	public static function render(): void {
		if ( self::hasKey() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen || ! in_array( (string) $screen->id, self::SCREENS, true ) ) {
			return;
		}
		$url = admin_url( 'admin.php?page=' . AdminPage::SLUG );
		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'TrustOrder: no API key is set, so buyers are not checked and the COD check column is hidden.', 'trustorder-for-woo' )
			. ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Set up the API key', 'trustorder-for-woo' ) . '</a></p></div>';
	}
}
