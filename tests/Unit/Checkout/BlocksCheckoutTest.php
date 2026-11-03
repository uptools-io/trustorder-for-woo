<?php
/**
 * Block checkout (Store API): server-side enforcement.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Checkout;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use LightweightPlugins\TrustOrder\Checkout\Enforcer;
use LightweightPlugins\TrustOrder\Checkout\Scope;

final class BlocksCheckoutTest extends CheckoutTestCase {

	protected function setUp(): void {
		parent::setUp();
		$wc            = trustorder_test_wc( self::customer() );
		$wc->store_api = true;
		Scope::setRoute( '/wc/store/v1/checkout' );
		Enforcer::register();
	}

	public function test_no_cart_extension_data_is_exposed(): void {
		\LightweightPlugins\TrustOrder\Plugin::instance();
		$this->assertFalse( class_exists( 'LightweightPlugins\\TrustOrder\\Checkout\\BlocksCheckout' ) );
	}

	public function test_checkout_with_hidden_gateway_throws_route_exception(): void {
		$this->answers = [ $this->verdict( 'block', 'blocked' ) ];
		$request       = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout', [ 'payment_method' => 'cod' ] );

		try {
			do_action( 'woocommerce_store_api_checkout_update_customer_from_request', WC()->customer, $request );
			$this->fail( 'expected a RouteException' );
		} catch ( RouteException $e ) {
			$this->assertSame( 'trustorder_payment_not_allowed', $e->getErrorCode() );
			$this->assertSame( 400, $e->http_status_code );
			$this->assertNotSame( '', $e->getMessage() );
		}
	}

	public function test_checkout_with_allowed_gateway_passes(): void {
		$this->answers = [ $this->verdict( 'block', 'blocked' ) ];
		$request       = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout', [ 'payment_method' => 'bacs' ] );

		do_action( 'woocommerce_store_api_checkout_update_customer_from_request', WC()->customer, $request );
		$this->addToAssertionCount( 1 );
	}

	public function test_checkout_fails_open_when_the_api_is_down(): void {
		$this->answers = [ new \WP_Error( 'http_request_failed', 'Could not resolve host' ) ];
		$request       = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout', [ 'payment_method' => 'cod' ] );

		do_action( 'woocommerce_store_api_checkout_update_customer_from_request', WC()->customer, $request );
		$this->addToAssertionCount( 1 );
	}

	public function test_checkout_without_php_session_still_enforced(): void {
		$wc            = trustorder_test_wc( self::customer(), false );
		$wc->store_api = true;
		$_SERVER['HTTP_CART_TOKEN'] = 'tok';
		$this->answers = [ $this->verdict( 'block', 'blocked' ) ];
		$request       = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout', [ 'payment_method' => 'cod' ] );

		try {
			$this->expectException( RouteException::class );
			do_action( 'woocommerce_store_api_checkout_update_customer_from_request', WC()->customer, $request );
		} finally {
			unset( $_SERVER['HTTP_CART_TOKEN'] );
		}
	}
}
