<?php
/**
 * Pay for an existing order: the stored result applies, no API call.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Checkout;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use LightweightPlugins\TrustOrder\Checkout\Enforcer;
use LightweightPlugins\TrustOrder\Checkout\Scope;

final class OrderPayTest extends CheckoutTestCase {

	protected function setUp(): void {
		parent::setUp();
		Enforcer::register();
		trustorder_test_wc( self::customer( 'someone-else@example.com' ) );
		WC()->cart->p['needs_payment'] = false; // The cart is usually empty on order-pay.
	}

	private function order( ?string $verdict ): void {
		$o = new \WC_Order( [ 'id' => 88 ] );
		if ( null !== $verdict ) {
			$o->meta['_trustorder_result'] = (string) json_encode(
				[
					'verdict'    => $verdict,
					'level'      => 'block' === $verdict ? 'blocked' : 'trusted',
					'score'      => 10,
					'reasons'    => [ 'LOW_SCORE' ],
					'check_id'   => '01a1a17c-7e54-7032-95b4-ba478164249b',
					'env'        => 'test',
					'checked_at' => '2026-11-03T10:00:00+00:00',
				]
			);
		}
		$GLOBALS['trustorder_test']['orders'][88] = $o;
	}

	private function classicOrderPay(): void {
		$GLOBALS['trustorder_test']['endpoint']                = 'order-pay';
		$GLOBALS['trustorder_test']['checkout']                = true;
		$GLOBALS['trustorder_test']['query_vars']['order-pay'] = '88';
	}

	public function test_classic_order_pay_hides_by_stored_block(): void {
		$this->order( 'block' );
		$this->classicOrderPay();

		$this->assertSame( [ 'bacs' ], $this->visibleGateways() );
		$this->assertSame( 0, $this->checks() );
	}

	public function test_classic_order_pay_allow_or_no_result_hides_nothing(): void {
		$this->classicOrderPay();
		$this->order( null );
		$this->assertSame( [ 'bacs', 'cod' ], $this->visibleGateways() );

		$this->order( 'allow' );
		$this->assertSame( [ 'bacs', 'cod' ], $this->visibleGateways() );
		$this->assertSame( 0, $this->checks() );
	}

	public function test_order_pay_without_order_id_is_inert(): void {
		$GLOBALS['trustorder_test']['endpoint'] = 'order-pay';
		$GLOBALS['trustorder_test']['checkout'] = true;
		WC()->cart->p['needs_payment']          = true;

		$this->assertSame( [ 'bacs', 'cod' ], $this->visibleGateways() );
		$this->assertSame( 0, $this->checks() );
	}

	public function test_pay_action_with_hidden_gateway_adds_an_error_notice(): void {
		$this->order( 'block' );
		$this->classicOrderPay();
		$_POST['payment_method'] = 'cod';
		try {
			do_action( 'woocommerce_before_pay_action', $GLOBALS['trustorder_test']['orders'][88] );
		} finally {
			unset( $_POST['payment_method'] );
		}

		$this->assertSame( 'error', $GLOBALS['trustorder_test']['notices'][0]['type'] ?? null );
		$this->assertSame( 0, $this->checks() );
	}

	public function test_pay_action_with_allowed_gateway_passes(): void {
		$this->order( 'block' );
		$this->classicOrderPay();
		$_POST['payment_method'] = 'bacs';
		try {
			do_action( 'woocommerce_before_pay_action', $GLOBALS['trustorder_test']['orders'][88] );
		} finally {
			unset( $_POST['payment_method'] );
		}

		$this->assertArrayNotHasKey( 'notices', $GLOBALS['trustorder_test'] );
	}

	public function test_store_api_pay_for_order_is_enforced(): void {
		$this->order( 'block' );
		Scope::setRoute( '/wc/store/v1/checkout/88' );

		$this->assertSame( [ 'bacs' ], $this->visibleGateways() );
		$this->expectException( RouteException::class );
		do_action( 'woocommerce_store_api_checkout_update_customer_from_request', WC()->customer, new \WP_REST_Request( 'POST', '/wc/store/v1/checkout/88', [ 'payment_method' => 'cod', 'id' => 88 ] ) );
	}

	public function test_store_api_pay_for_unchecked_order_passes(): void {
		$this->order( null );
		Scope::setRoute( '/wc/store/v1/checkout/88' );

		do_action( 'woocommerce_store_api_checkout_update_customer_from_request', WC()->customer, new \WP_REST_Request( 'POST', '/wc/store/v1/checkout/88', [ 'payment_method' => 'cod', 'id' => 88 ] ) );
		$this->assertSame( 0, $this->checks() );
	}
}
