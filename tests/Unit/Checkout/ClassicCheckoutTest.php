<?php
/**
 * Classic (shortcode) checkout: refresh fields, post_data identity, enforcement.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Checkout;

use LightweightPlugins\TrustOrder\Checkout\ClassicCheckout;
use LightweightPlugins\TrustOrder\Checkout\Enforcer;
use LightweightPlugins\TrustOrder\Identity\IdentitySet;
use WP_Error;

final class ClassicCheckoutTest extends CheckoutTestCase {

	protected function setUp(): void {
		parent::setUp();
		ClassicCheckout::register();
		Enforcer::register();
	}

	public function test_email_and_phone_refresh_the_order_review(): void {
		$fields = [
			'billing' => [
				'billing_email' => [ 'class' => [ 'form-row-wide' ] ],
				'billing_phone' => [],
				'billing_city'  => [ 'class' => [ 'form-row-wide' ] ],
			],
		];

		$out = apply_filters( 'woocommerce_checkout_fields', $fields );

		$this->assertSame( [ 'form-row-wide', 'update_totals_on_change' ], $out['billing']['billing_email']['class'] );
		$this->assertSame( [ 'update_totals_on_change' ], $out['billing']['billing_phone']['class'] );
		$this->assertSame( [ 'form-row-wide' ], $out['billing']['billing_city']['class'] );
		$this->assertSame( $out, apply_filters( 'woocommerce_checkout_fields', $out ), 'idempotent' );
	}

	public function test_update_order_review_reads_post_data_not_the_stale_customer(): void {
		$this->onCheckout( [ 'billing_email' => 'old@example.com' ] );
		$post = http_build_query(
			[
				'billing_email'      => ' Blocked@Example.com ',
				'billing_phone'      => '+36 30 123 4567',
				'billing_country'    => 'HU',
				'billing_postcode'   => '1051',
				'billing_address_1'  => 'Fő utca 3',
				'shipping_postcode'  => '9999',
				'shipping_address_1' => 'Másik utca 99',
			]
		);
		do_action( 'woocommerce_checkout_update_order_review', $post );

		$this->visibleGateways();

		$expected = IdentitySet::fromOrderData(
			[
				'email'              => 'Blocked@Example.com',
				'phone'              => '+36 30 123 4567',
				'billing_country'    => 'HU',
				'shipping_country'   => 'HU',
				'shipping_postcode'  => '1051',
				'shipping_address_1' => 'Fő utca 3',
				'needs_shipping'     => true,
			]
		)->toCheckBody();
		$body     = $this->lastBody();
		$this->assertSame( $expected['email_hash'], $body['email_hash'] );
		$this->assertSame( $expected['address_hash'], $body['address_hash'], 'unchecked ship-to-different: shipping = billing' );
		$this->assertSame( $expected['phone_hash'], $body['phone_hash'] );
	}

	public function test_ship_to_different_address_uses_the_shipping_fields(): void {
		$this->onCheckout();
		$post = http_build_query(
			[
				'billing_email'             => 'buyer@example.com',
				'billing_country'           => 'HU',
				'billing_postcode'          => '1051',
				'billing_address_1'         => 'Fő utca 3',
				'ship_to_different_address' => '1',
				'shipping_country'          => 'HU',
				'shipping_postcode'         => '6720',
				'shipping_address_1'        => 'Kárász utca 5',
			]
		);
		do_action( 'woocommerce_checkout_update_order_review', $post );

		$this->visibleGateways();

		$expected = IdentitySet::fromOrderData(
			[
				'email'              => 'buyer@example.com',
				'billing_country'    => 'HU',
				'shipping_country'   => 'HU',
				'shipping_postcode'  => '6720',
				'shipping_address_1' => 'Kárász utca 5',
				'needs_shipping'     => true,
			]
		)->toCheckBody();
		$this->assertSame( $expected['address_hash'], $this->lastBody()['address_hash'] );
	}

	public function test_post_data_with_markup_is_sanitised_like_woocommerce(): void {
		$this->onCheckout();
		do_action( 'woocommerce_checkout_update_order_review', 'billing_email=<b>buyer@example.com</b>&billing_country=HU' );

		$this->visibleGateways();

		$expected = IdentitySet::fromOrderData( [ 'email' => 'buyer@example.com' ] )->toCheckBody();
		$this->assertSame( $expected['email_hash'], $this->lastBody()['email_hash'] );
	}

	public function test_post_data_without_email_sends_nothing(): void {
		$this->onCheckout();
		do_action( 'woocommerce_checkout_update_order_review', 'billing_phone=%2B36301234567' );

		$this->assertSame( [ 'bacs', 'cod' ], $this->visibleGateways() );
		$this->assertSame( 0, $this->checks() );
	}

	public function test_place_order_with_hidden_gateway_gets_our_error_instead_of_the_generic_one(): void {
		$this->onCheckout();
		$this->answers = [ $this->verdict( 'block', 'blocked' ) ];
		$errors        = new WP_Error();
		$errors->add( 'payment', 'Invalid payment method.' );

		do_action( 'woocommerce_after_checkout_validation', [ 'payment_method' => 'cod' ], $errors );

		$this->assertSame( [ 'trustorder_payment_not_allowed' ], $errors->get_error_codes() );
	}

	public function test_place_order_with_allowed_gateway_passes(): void {
		$this->onCheckout();
		$this->answers = [ $this->verdict( 'block', 'blocked' ) ];
		$errors        = new WP_Error();

		do_action( 'woocommerce_after_checkout_validation', [ 'payment_method' => 'bacs' ], $errors );

		$this->assertSame( [], $errors->get_error_codes() );
	}

	public function test_place_order_fails_open_on_api_error(): void {
		$this->onCheckout();
		$this->answers = [ $this->http( 503, '{"code":"INTERNAL"}' ) ];
		$errors        = new WP_Error();

		do_action( 'woocommerce_after_checkout_validation', [ 'payment_method' => 'cod' ], $errors );

		$this->assertSame( [], $errors->get_error_codes() );
	}

	public function test_place_order_keeps_other_payment_errors(): void {
		$this->onCheckout();
		$errors = new WP_Error();
		$errors->add( 'payment', 'Invalid payment method.' );

		do_action( 'woocommerce_after_checkout_validation', [ 'payment_method' => 'nonexistent' ], $errors );

		$this->assertSame( [ 'payment' ], $errors->get_error_codes() );
	}
}
