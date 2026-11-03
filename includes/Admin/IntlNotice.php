<?php
/**
 * Admin warning when intl or mbstring is missing.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Admin;

/**
 * Without intl (Normalizer, idn_to_ascii) or mbstring's simple lowercasing,
 * identities with non-ASCII characters are not hashed, and an order with such
 * an e-mail is not checked at all. Shown on WooCommerce / TrustOrder screens
 * to shop managers, dismissible per user.
 */
final class IntlNotice {

	public const ACTION = 'trustorder_dismiss_intl_notice';

	private const META = 'trustorder_intl_notice_dismissed';

	/**
	 * Hook the notice and its dismiss handler when support is incomplete.
	 *
	 * @param bool $full_unicode Result of Identity\Unicode::full().
	 */
	public static function register( bool $full_unicode ): void {
		if ( $full_unicode ) {
			return;
		}
		add_action( 'admin_notices', [ self::class, 'render' ] );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'dismiss' ] );
	}

	/**
	 * Print the notice.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! Screen::relevant() ) {
			return;
		}
		if ( '1' === get_user_meta( get_current_user_id(), self::META, true ) ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );

		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'TrustOrder: the PHP intl or mbstring extension is missing, so e-mail and shipping addresses with accented or other non-ASCII characters are not checked. Ask your host to enable intl and mbstring.', 'trustorder-for-woo' )
			. '</p><p><a href="' . esc_url( $url ) . '">'
			. esc_html__( 'Dismiss', 'trustorder-for-woo' )
			. '</a></p></div>';
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
	 * Store the dismissal for the current user (nonce + capability checked).
	 */
	public static function recordDismissal(): bool {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}
		update_user_meta( get_current_user_id(), self::META, '1' );

		return true;
	}
}
