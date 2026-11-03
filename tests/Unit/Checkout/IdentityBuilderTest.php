<?php
/**
 * Identity from WooCommerce objects; pickup / virtual orders skip the address.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Checkout;

use LightweightPlugins\TrustOrder\Checkout\IdentityBuilder;
use LightweightPlugins\TrustOrder\Checkout\PickupRule;
use LightweightPlugins\TrustOrder\Settings;
use PHPUnit\Framework\TestCase;

final class IdentityBuilderTest extends TestCase {

	protected function setUp(): void {
		trustorder_test_reset();
	}

	/**
	 * @return array<string, string>
	 */
	private static function fields(): array {
		return [
			'billing_email'      => 'buyer@example.com',
			'billing_phone'      => '06 30 123 4567',
			'billing_country'    => 'HU',
			'shipping_country'   => 'HU',
			'shipping_postcode'  => '1051',
			'shipping_address_1' => 'Fő utca 3',
			'shipping_address_2' => '',
		];
	}

	public function test_pickup_methods_from_settings_and_woocommerce(): void {
		$this->assertTrue( PickupRule::skipAddress( [ 'vp_pont' ] ) );
		$this->assertTrue( PickupRule::skipAddress( [ 'pickup_location' ] ) );
		$this->assertTrue( PickupRule::skipAddress( [ 'legacy_local_pickup' ] ), 'WooCommerce default list' );
		$this->assertFalse( PickupRule::skipAddress( [ 'flat_rate' ] ) );
		$this->assertFalse( PickupRule::skipAddress( [] ) );

		add_filter( 'woocommerce_local_pickup_methods', static fn( array $m ): array => array_merge( $m, [ 'my_locker' ] ) );
		$this->assertTrue( PickupRule::skipAddress( [ 'my_locker' ] ) );

		Settings::update( [ 'pickup_methods' => [ 'foxpost' ] ] );
		$this->assertTrue( PickupRule::skipAddress( [ 'foxpost' ] ) );
		$this->assertFalse( PickupRule::skipAddress( [ 'vp_pont' ] ), 'settings replace the defaults' );
	}

	public function test_skip_filter_overrides(): void {
		add_filter( 'trustorder_skip_address_hash', static fn( bool $skip, array $ids ): bool => in_array( 'flat_rate', $ids, true ), 10, 2 );

		$this->assertTrue( PickupRule::skipAddress( [ 'flat_rate' ] ) );
		$this->assertFalse( PickupRule::skipAddress( [ 'local_pickup' ] ) );
	}

	public function test_customer_identity_with_and_without_pickup(): void {
		$c = new \WC_Customer( self::fields() );

		$home   = IdentityBuilder::fromCustomer( $c, true, [ 'flat_rate' ] )->hashes();
		$pickup = IdentityBuilder::fromCustomer( $c, true, [ 'local_pickup' ] )->hashes();
		$virt   = IdentityBuilder::fromCustomer( $c, false, [] )->hashes();

		$this->assertNotNull( $home['address_hash'] );
		$this->assertNotNull( $home['phone_hash'] );
		$this->assertNull( $pickup['address_hash'] );
		$this->assertNull( $virt['address_hash'] );
		$this->assertSame( $home['email_hash'], $pickup['email_hash'] );
	}

	public function test_order_identity_needs_shipping_fallback_by_shipping_lines(): void {
		$o = new \WC_Order( self::fields() );
		$this->assertNull( IdentityBuilder::fromOrder( $o )->hashes()['address_hash'], 'no shipping line: virtual' );

		$o->shipping = [ new \WC_Order_Item_Shipping( 'flat_rate' ) ];
		$this->assertNotNull( IdentityBuilder::fromOrder( $o )->hashes()['address_hash'] );

		$o->shipping = [ new \WC_Order_Item_Shipping( 'vp_pont' ) ];
		$this->assertNull( IdentityBuilder::fromOrder( $o )->hashes()['address_hash'], 'parcel point: no address' );
	}

	public function test_order_identity_uses_needs_shipping_when_available(): void {
		$o           = new \TrustOrder_Test_Order_99( self::fields() + [ 'needs_shipping' => false ] );
		$o->shipping = [ new \WC_Order_Item_Shipping( 'flat_rate' ) ];
		$this->assertNull( IdentityBuilder::fromOrder( $o )->hashes()['address_hash'] );

		$o->p['needs_shipping'] = true;
		$this->assertNotNull( IdentityBuilder::fromOrder( $o )->hashes()['address_hash'] );
	}

	public function test_order_without_shipping_address_uses_billing(): void {
		$f = self::fields();
		unset( $f['shipping_postcode'], $f['shipping_address_1'], $f['shipping_country'] );
		$f += [ 'billing_postcode' => '1051', 'billing_address_1' => 'Fő utca 3' ];
		$o           = new \WC_Order( $f );
		$o->shipping = [ new \WC_Order_Item_Shipping( 'flat_rate' ) ];

		$c = new \WC_Customer( self::fields() );
		$this->assertSame(
			IdentityBuilder::fromCustomer( $c, true, [ 'flat_rate' ] )->hashes(),
			IdentityBuilder::fromOrder( $o )->hashes()
		);
	}
}
