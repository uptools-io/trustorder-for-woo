<?php
/**
 * Where the gateway filter may act, and whether it may call the API.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Checkout;

use LightweightPlugins\TrustOrder\Settings;

/**
 * `woocommerce_available_payment_gateways` runs on many screens and several
 * times per request (includes/class-wc-payment-gateways.php:388, WC 11.2.1).
 *
 * - LIVE: checkout (classic page, `update_order_review`, place order, the
 *   block checkout page render) and the Store API routes the checkout block
 *   uses to change the buyer (`cart/update-customer`, `cart/select-shipping-rate`,
 *   `checkout`).
 * - PEEK: other Store API routes (mini cart, cart block): cached results only.
 * - ORDER_PAY: pay-for-order (classic `order-pay` endpoint, Store API
 *   `checkout/{id}`): the order's stored result, no API call.
 * - OFF: admin, nothing to pay, disabled, no cart.
 */
final class Scope {

	public const OFF  = 'off';
	public const PEEK = 'peek';
	public const LIVE = 'live';

	/**
	 * Pay for an existing order: only the order's stored result applies.
	 */
	public const ORDER_PAY = 'order_pay';

	private const LIVE_ROUTE  = '#^/wc/store(?:/v\d+)?/(?:checkout|cart/update-customer|cart/select-shipping-rate)$#i';
	private const PAY_ROUTE   = '#^/wc/store(?:/v\d+)?/checkout/(\d+)$#i';
	private const STORE_ROUTE = '#^/wc/store/#i';

	/**
	 * REST route being dispatched (also inside /batch and REST preloads).
	 *
	 * @var string|null
	 */
	private static ?string $route = null;

	/**
	 * Track the dispatched REST route.
	 */
	public static function register(): void {
		add_filter( 'rest_request_before_callbacks', [ self::class, 'enterRoute' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ self::class, 'leaveRoute' ] );
	}

	/**
	 * `rest_request_before_callbacks` (pass-through).
	 *
	 * @param mixed $response Response so far.
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  WP_REST_Request.
	 * @return mixed
	 */
	public static function enterRoute( $response, $handler = null, $request = null ) {
		self::$route = $request instanceof \WP_REST_Request ? self::normalise( (string) $request->get_route() ) : null;

		return $response;
	}

	/**
	 * `rest_request_after_callbacks` (pass-through).
	 *
	 * @param mixed $response Response.
	 * @return mixed
	 */
	public static function leaveRoute( $response ) {
		self::$route = null;

		return $response;
	}

	/**
	 * Override the tracked route (tests).
	 *
	 * @param string|null $route Route.
	 */
	public static function setRoute( ?string $route ): void {
		self::$route = null === $route ? null : self::normalise( $route );
	}

	/**
	 * WP REST matches routes case-insensitively (wp-includes/rest-api/class-wp-rest-server.php:1172, WP 7.1.4:
	 * `@^…$@i`), and a trailing slash must not slip past either, so the tracked route
	 * is lowercased and stripped of trailing slashes; the patterns are `i` too.
	 *
	 * @param string $route Route.
	 */
	private static function normalise( string $route ): string {
		$route = strtolower( $route );
		$trim  = rtrim( $route, '/' );

		return '' === $trim ? '/' : $trim;
	}

	/**
	 * Tracked route.
	 */
	public static function route(): ?string {
		return self::$route;
	}

	/**
	 * Order id being paid for (classic order-pay endpoint or Store API
	 * `checkout/{id}`), 0 otherwise.
	 */
	public static function orderPayId(): int {
		$m = [];
		if ( null !== self::$route && 1 === preg_match( self::PAY_ROUTE, self::$route, $m ) ) {
			return absint( $m[1] );
		}

		return self::isClassicOrderPay() ? absint( get_query_var( 'order-pay' ) ) : 0;
	}

	/**
	 * Pay-for-order request (classic endpoint or Store API `checkout/{id}`).
	 */
	private static function isOrderPay(): bool {
		return ( null !== self::$route && 1 === preg_match( self::PAY_ROUTE, self::$route ) ) || self::isClassicOrderPay();
	}

	/**
	 * Classic order-pay endpoint (page render and the pay form POST).
	 */
	private static function isClassicOrderPay(): bool {
		return is_wc_endpoint_url( 'order-pay' ) || ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() );
	}

	/**
	 * Current mode.
	 */
	public static function mode(): string {
		if ( ! Settings::get()['enabled'] || is_admin() ) {
			return self::OFF;
		}
		if ( self::isOrderPay() ) {
			return self::ORDER_PAY;
		}
		/**
		 * WC() members can be null outside a full frontend request (stubs say otherwise).
		 *
		 * @var mixed $wc
		 */
		$wc   = WC();
		$cart = is_object( $wc ) ? ( $wc->cart ?? null ) : null;
		if ( ! is_object( $cart ) || ! $cart->needs_payment() ) {
			return self::OFF;
		}

		$route = self::$route;
		if ( null !== $route && 1 === preg_match( self::LIVE_ROUTE, $route ) ) {
			return self::LIVE;
		}
		if ( is_checkout() ) {
			return self::LIVE;
		}

		return null !== $route && 1 === preg_match( self::STORE_ROUTE, $route ) ? self::PEEK : self::OFF;
	}
}
