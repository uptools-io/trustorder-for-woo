<?php
/**
 * Gateway filter: scope, memoisation, Store API path without a PHP session.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Checkout;

use LightweightPlugins\TrustOrder\Checkout\Scope;
use LightweightPlugins\TrustOrder\Log;
use LightweightPlugins\TrustOrder\Settings;

final class GatekeeperTest extends CheckoutTestCase {

	public function test_block_hides_cod_on_checkout(): void {
		$this->onCheckout();
		$this->answers = [ $this->verdict( 'block', 'blocked' ) ];

		$this->assertSame( [ 'bacs' ], $this->visibleGateways() );
	}

	public function test_allow_keeps_everything(): void {
		$this->onCheckout();

		$this->assertSame( [ 'bacs', 'cod' ], $this->visibleGateways() );
		$this->assertSame( 1, $this->checks() );
	}

	public function test_many_filter_runs_one_request(): void {
		$this->onCheckout();
		$this->answers = [ $this->verdict( 'block', 'blocked' ) ];

		for ( $i = 0; $i < 6; $i++ ) {
			$this->assertSame( [ 'bacs' ], $this->visibleGateways() );
		}
		$this->assertSame( 1, $this->checks() );
	}

	public function test_api_failure_keeps_cod(): void {
		$this->onCheckout();
		$this->answers = [ new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) ];

		$this->assertSame( [ 'bacs', 'cod' ], $this->visibleGateways() );
	}

	/**
	 * @return array<string, array{0: callable}>
	 */
	public function outOfScope(): array {
		return [
			'not checkout' => [ static function (): void {
				$GLOBALS['trustorder_test']['checkout'] = false;
			} ],
			'admin'        => [ static function (): void {
				$GLOBALS['trustorder_test']['admin'] = true;
			} ],
			'order-pay'    => [ static function (): void {
				$GLOBALS['trustorder_test']['endpoint'] = 'order-pay';
			} ],
			'zero total'   => [ static function (): void {
				WC()->cart->p['needs_payment'] = false;
			} ],
			'disabled'     => [ static function (): void {
				Settings::update( [ 'enabled' => false ] );
			} ],
			'no cart'      => [ static function (): void {
				WC()->cart = null;
			} ],
			'block order-pay route' => [ static function (): void {
				$GLOBALS['trustorder_test']['checkout'] = false;
				Scope::setRoute( '/wc/store/v1/checkout/123' );
			} ],
			'other REST route' => [ static function (): void {
				$GLOBALS['trustorder_test']['checkout'] = false;
				Scope::setRoute( '/wp/v2/posts' );
			} ],
		];
	}

	/**
	 * @dataProvider outOfScope
	 */
	public function test_never_calls_the_api_out_of_scope( callable $arrange ): void {
		$this->onCheckout();
		$this->answers = [ $this->verdict( 'block', 'blocked' ) ];
		$arrange();

		$this->assertSame( [ 'bacs', 'cod' ], $this->visibleGateways() );
		$this->assertSame( 0, $this->checks() );
	}

	public function test_non_array_input_is_passed_through(): void {
		$this->onCheckout();

		$this->assertSame( 'junk', apply_filters( 'woocommerce_available_payment_gateways', 'junk' ) );
	}

	public function test_only_cod_offered_stays_visible_and_warns(): void {
		$wc                   = $this->onCheckout();
		$wc->payment_gateways = new \TrustOrder_Test_Gateways( [ 'cod' ] );
		$this->answers        = [ $this->verdict( 'block', 'blocked' ) ];

		$this->assertSame( [ 'cod' ], $this->visibleGateways() );
		$this->assertSame( 'HIDE_WOULD_EMPTY', get_option( Log::LAST_ERROR_OPTION )['code'] );
	}

	public function test_store_api_checkout_without_php_session_uses_cart_token(): void {
		$wc          = trustorder_test_wc( self::customer(), false );
		$wc->store_api = true;
		Scope::setRoute( '/wc/store/v1/cart/update-customer' );
		$_SERVER['HTTP_CART_TOKEN'] = 'token-one';
		$this->answers              = [ $this->verdict( 'block', 'blocked' ) ];

		try {
			$this->assertSame( [ 'bacs' ], $this->visibleGateways() );
			$this->assertSame( 1, $this->checks() );

			$names = array_keys( $GLOBALS['trustorder_test']['transients'] );
			$this->assertContains( \LightweightPlugins\TrustOrder\Checkout\Counter::key( 't:token-one', \LightweightPlugins\TrustOrder\Checkout\QuotaGuard::WINDOW ), $names, 'quota keyed by Cart-Token' );
			$this->assertNotEmpty( preg_grep( '/^trustorder_r_[0-9a-f]{32}$/', $names ), 'result cached in a transient' );
		} finally {
			unset( $_SERVER['HTTP_CART_TOKEN'] );
		}
	}

	public function test_store_api_session_customer_id_preferred_over_cart_token(): void {
		$wc            = trustorder_test_wc( self::customer() );
		$wc->store_api = true;
		Scope::setRoute( '/wc/store/v1/checkout' );
		$_SERVER['HTTP_CART_TOKEN'] = 'token-one';

		try {
			$this->visibleGateways();
			$this->assertContains( \LightweightPlugins\TrustOrder\Checkout\Counter::key( 's:sess-1', \LightweightPlugins\TrustOrder\Checkout\QuotaGuard::WINDOW ), array_keys( $GLOBALS['trustorder_test']['transients'] ) );
		} finally {
			unset( $_SERVER['HTTP_CART_TOKEN'] );
		}
	}

	public function test_store_api_cart_route_only_peeks(): void {
		$wc            = trustorder_test_wc( self::customer() );
		$wc->store_api = true;
		Scope::setRoute( '/wc/store/v1/cart' );
		$this->answers = [ $this->verdict( 'block', 'blocked' ) ];

		$this->assertSame( [ 'bacs', 'cod' ], $this->visibleGateways(), 'unknown buyer, no cached result: nothing hidden' );
		$this->assertSame( 0, $this->checks() );

		Scope::setRoute( '/wc/store/v1/cart/select-shipping-rate' );
		$this->assertSame( [ 'bacs' ], $this->visibleGateways(), 'shipping switch is live too' );

		\LightweightPlugins\TrustOrder\Checkout\Checker::reset();
		\LightweightPlugins\TrustOrder\Checkout\Gatekeeper::reset();
		Scope::setRoute( '/wc/store/v1/cart' );
		$this->assertSame( [ 'bacs' ], $this->visibleGateways(), 'cached result applied while peeking' );
		$this->assertSame( 1, $this->checks() );
	}

	public function test_route_capture_hooks(): void {
		Scope::register();
		$req = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' );

		apply_filters( 'rest_request_before_callbacks', null, [], $req );
		$this->assertSame( '/wc/store/v1/checkout', Scope::route() );

		apply_filters( 'rest_request_after_callbacks', null, [], $req );
		$this->assertNull( Scope::route() );
	}

	public function test_hidden_now_reports_the_hidden_list(): void {
		$this->onCheckout();
		$this->answers = [ $this->verdict( 'block', 'blocked' ) ];

		$this->assertSame( [ 'cod' ], \LightweightPlugins\TrustOrder\Checkout\Gatekeeper::hiddenNow() );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function routeVariants(): array {
		return [
			'upper checkout'       => [ '/wc/store/CHECKOUT', 'live' ],
			'upper v1 checkout'    => [ '/WC/Store/V1/Checkout', 'live' ],
			'trailing slash'       => [ '/wc/store/v1/checkout/', 'live' ],
			'upper update-customer' => [ '/wc/store/v1/CART/Update-Customer', 'live' ],
			'upper shipping rate'  => [ '/wc/store/v1/cart/SELECT-SHIPPING-RATE/', 'live' ],
			'upper pay route'      => [ '/WC/STORE/V1/CHECKOUT/88', 'order_pay' ],
			'pay route slash'      => [ '/wc/store/v1/checkout/88/', 'order_pay' ],
			'upper cart'           => [ '/WC/STORE/V1/CART', 'peek' ],
		];
	}

	/**
	 * @dataProvider routeVariants
	 */
	public function test_route_case_and_trailing_slash_cannot_bypass_scope( string $route, string $mode ): void {
		trustorder_test_wc( self::customer() );
		Scope::setRoute( $route );

		$this->assertSame( $mode, Scope::mode() );
	}

	public function test_route_capture_normalises(): void {
		Scope::register();
		apply_filters( 'rest_request_before_callbacks', null, [], new \WP_REST_Request( 'POST', '/WC/Store/V1/CHECKOUT/' ) );

		$this->assertSame( '/wc/store/v1/checkout', Scope::route() );
	}

	public function test_uppercase_checkout_route_still_enforced(): void {
		\LightweightPlugins\TrustOrder\Checkout\Enforcer::register();
		$wc            = trustorder_test_wc( self::customer() );
		$wc->store_api = true;
		Scope::setRoute( '/wc/store/v1/CHECKOUT' );
		$this->answers = [ $this->verdict( 'block', 'blocked' ) ];

		$this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );
		do_action( 'woocommerce_store_api_checkout_update_customer_from_request', WC()->customer, new \WP_REST_Request( 'POST', '/wc/store/v1/CHECKOUT', [ 'payment_method' => 'cod' ] ) );
	}
}
