<?php
/**
 * Who "the session" is for the per-session quota.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Checkout;

/**
 * The WC session's customer id (cookie session or the Store API's
 * Cart-Token session handler alike), else the raw Cart-Token header, else ''.
 *
 * Cart-Token: read by WooCommerce in CartTokenUtils::get_request_cart_token()
 * from HTTP_CART_TOKEN (src/StoreApi/Utilities/CartTokenUtils.php:40, WC 11.2.1).
 */
final class SessionKey {

	/**
	 * Current request's session key ('' when there is none).
	 */
	public static function current(): string {
		/**
		 * WC() members can be null outside a full frontend request (stubs say otherwise).
		 *
		 * @var mixed $wc
		 */
		$wc = function_exists( 'WC' ) ? WC() : null;
		if ( is_object( $wc ) && isset( $wc->session ) && is_object( $wc->session ) && method_exists( $wc->session, 'get_customer_id' ) ) {
			$id = (string) $wc->session->get_customer_id();
			if ( '' !== $id ) {
				return 's:' . $id;
			}
		}

		$token = isset( $_SERVER['HTTP_CART_TOKEN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CART_TOKEN'] ) ) : '';

		return '' === $token ? '' : 't:' . $token;
	}
}
