<?php
/**
 * A complete e-mail (WordPress is_email() accepts it) that cannot be
 * normalised: local block fallback on both checkouts, stored on the order
 * and shown in the admin. An incomplete one behaves like an empty e-mail.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Checkout;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use LightweightPlugins\TrustOrder\Checkout\Checker;
use LightweightPlugins\TrustOrder\Checkout\ClassicCheckout;
use LightweightPlugins\TrustOrder\Checkout\Enforcer;
use LightweightPlugins\TrustOrder\Checkout\Scope;
use LightweightPlugins\TrustOrder\Order\OrderColumn;
use LightweightPlugins\TrustOrder\Order\OrderMetabox;
use LightweightPlugins\TrustOrder\Order\OrderResult;
use WP_Error;

final class UnusableEmailTest extends CheckoutTestCase {

	private const UNUSABLE = '...@gmail.com';

	public function test_browsing_the_classic_checkout_hides_cod(): void {
		$this->onCheckout( [ 'billing_email' => self::UNUSABLE ] );

		$this->assertSame( [ 'bacs' ], $this->visibleGateways() );
		$this->assertSame( 0, $this->checks() );
	}

	public function test_classic_review_post_data_hides_cod(): void {
		$this->onCheckout( [ 'billing_email' => '' ] );
		ClassicCheckout::register();
		do_action( 'woocommerce_checkout_update_order_review', 'billing_email=%2Bshop%40example.com&billing_phone=%2B36301234567' );

		$this->assertSame( [ 'bacs' ], $this->visibleGateways() );
		$this->assertSame( 0, $this->checks() );
	}

	public function test_an_empty_email_keeps_every_gateway(): void {
		$this->onCheckout( [ 'billing_email' => '' ] );

		$this->assertSame( [ 'bacs', 'cod' ], $this->visibleGateways() );
	}

	public function test_a_half_typed_email_keeps_every_gateway(): void {
		foreach ( [ 'abc', 'abc@', 'abc@gmail', '+x@' ] as $email ) {
			$this->onCheckout( [ 'billing_email' => $email ] );

			$this->assertSame( [ 'bacs', 'cod' ], $this->visibleGateways(), $email );
		}
		$this->assertSame( 0, $this->checks() );
	}

	public function test_a_complete_but_unusable_email_hides_cod(): void {
		$this->onCheckout( [ 'billing_email' => '+x@gmail.com' ] );

		$this->assertSame( [ 'bacs' ], $this->visibleGateways() );
		$this->assertSame( 0, $this->checks() );
	}

	public function test_store_api_place_order_with_a_half_typed_email_allows_cod(): void {
		$wc            = trustorder_test_wc( [ 'billing_email' => 'abc' ] + self::customer() );
		$wc->store_api = true;
		Scope::setRoute( '/wc/store/v1/checkout' );
		Enforcer::register();

		do_action( 'woocommerce_store_api_checkout_update_customer_from_request', WC()->customer, new \WP_REST_Request( 'POST', '/wc/store/v1/checkout', [ 'payment_method' => 'cod' ] ) );

		$this->assertSame( 0, $this->checks() );
	}

	public function test_classic_place_order_with_a_half_typed_email_allows_cod(): void {
		$this->onCheckout( [ 'billing_email' => 'abc' ] );
		Enforcer::register();
		do_action( 'woocommerce_checkout_process' );
		$errors = new WP_Error();

		do_action( 'woocommerce_after_checkout_validation', [ 'payment_method' => 'cod' ], $errors );

		$this->assertSame( [], $errors->get_error_codes() );
	}

	public function test_classic_place_order_refuses_cod(): void {
		$this->onCheckout( [ 'billing_email' => self::UNUSABLE ] );
		Enforcer::register();
		do_action( 'woocommerce_checkout_process' );
		$errors = new WP_Error();

		do_action( 'woocommerce_after_checkout_validation', [ 'payment_method' => 'cod' ], $errors );

		$this->assertSame( [ 'trustorder_payment_not_allowed' ], $errors->get_error_codes() );
		$this->assertSame( 0, $this->checks() );
	}

	public function test_store_api_place_order_refuses_cod_and_allows_the_fallback_gateway(): void {
		$wc            = trustorder_test_wc( [ 'billing_email' => self::UNUSABLE ] + self::customer() );
		$wc->store_api = true;
		Scope::setRoute( '/wc/store/v1/checkout' );
		Enforcer::register();

		do_action( 'woocommerce_store_api_checkout_update_customer_from_request', WC()->customer, new \WP_REST_Request( 'POST', '/wc/store/v1/checkout', [ 'payment_method' => 'bacs' ] ) );

		try {
			do_action( 'woocommerce_store_api_checkout_update_customer_from_request', WC()->customer, new \WP_REST_Request( 'POST', '/wc/store/v1/checkout', [ 'payment_method' => 'cod' ] ) );
			$this->fail( 'expected a RouteException' );
		} catch ( RouteException $e ) {
			$this->assertSame( 'trustorder_payment_not_allowed', $e->getErrorCode() );
		}
		$this->assertSame( 0, $this->checks() );
	}

	public function test_order_stores_the_fallback_and_the_admin_shows_it(): void {
		$this->onCheckout( [ 'billing_email' => self::UNUSABLE ] );
		$o           = new \WC_Order(
			[
				'id'               => 42,
				'total'            => '1990',
				'billing_email'    => self::UNUSABLE,
				'billing_phone'    => '+36301234567',
				'billing_country'  => 'HU',
				'shipping_country' => 'HU',
			]
		);
		$o->shipping = [ new \WC_Order_Item_Shipping( 'flat_rate' ) ];
		$GLOBALS['trustorder_test']['orders'][42] = $o;

		OrderResult::onOrder( $o );

		$r = json_decode( $o->meta['_trustorder_result'], true );
		$this->assertSame( 'block', $r['verdict'] );
		$this->assertSame( Checker::EMAIL_UNUSABLE, $r['fallback'] );
		$this->assertSame( [], $o->notes );

		OrderColumn::register();
		ob_start();
		do_action( 'manage_woocommerce_page_wc-orders_custom_column', 'trustorder', $o );
		$cell = (string) ob_get_clean();
		$this->assertStringContainsString( 'E-mail not usable', $cell );
		$this->assertStringNotContainsString( 'limit reached', $cell );

		ob_start();
		OrderMetabox::render( $o );
		$box = (string) ob_get_clean();
		$this->assertStringContainsString( 'E-mail not usable', $box );
		$this->assertStringNotContainsString( 'limit reached', $box );
		$this->assertStringContainsString( 'Risky payment methods stayed hidden', $box );
	}
}
