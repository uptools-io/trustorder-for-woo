<?php
/**
 * Settings model tests.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit;

use LightweightPlugins\TrustOrder\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	private const LIVE_KEY = 'to_live_0123456789abcdefABCDEF0123456789';

	protected function setUp(): void {
		trustorder_test_reset();
	}

	public function test_defaults_when_nothing_is_stored(): void {
		$s = Settings::get();

		$this->assertSame( '', $s['api_key'] );
		$this->assertSame( 'normal', $s['strictness'] );
		$this->assertSame( [ 'cod' ], $s['hidden_gateways'] );
		$this->assertSame( [], $s['fallback_gateways'] );
		$this->assertTrue( $s['enabled'] );
		$this->assertContains( 'local_pickup', $s['pickup_methods'] );
		$this->assertContains( 'pickup_location', $s['pickup_methods'] );
		$this->assertSame( Settings::DEFAULT_PICKUP_METHODS, $s['pickup_methods'] );
	}

	public function test_update_stores_valid_values_in_one_autoloaded_option(): void {
		$s = Settings::update(
			[
				'api_key'           => '  ' . self::LIVE_KEY . " \n",
				'strictness'        => 'strict',
				'hidden_gateways'   => [ 'cod', 'bacs' ],
				'fallback_gateways' => [ 'stripe' ],
				'pickup_methods'    => [ 'local_pickup' ],
				'enabled'           => false,
			]
		);

		$this->assertSame( self::LIVE_KEY, $s['api_key'] );
		$this->assertSame( 'strict', $s['strictness'] );
		$this->assertSame( [ 'cod', 'bacs' ], $s['hidden_gateways'] );
		$this->assertSame( [ 'stripe' ], $s['fallback_gateways'] );
		$this->assertSame( [ 'local_pickup' ], $s['pickup_methods'] );
		$this->assertFalse( $s['enabled'] );
		$this->assertSame( $s, Settings::get() );
		$this->assertSame( $s, $GLOBALS['trustorder_test']['options'][ Settings::OPTION ] );
		$this->assertTrue( $GLOBALS['trustorder_test']['autoload'][ Settings::OPTION ] );
	}

	public function test_invalid_strictness_falls_back_to_normal(): void {
		Settings::update( [ 'strictness' => 'strict' ] );

		$this->assertSame( 'normal', Settings::update( [ 'strictness' => 'paranoid' ] )['strictness'] );
		$this->assertSame( 'normal', Settings::update( [ 'strictness' => [ 'strict' ] ] )['strictness'] );
	}

	/**
	 * @dataProvider bad_keys
	 */
	public function test_bad_key_is_rejected_and_previous_key_kept( $bad ): void {
		Settings::update( [ 'api_key' => self::LIVE_KEY ] );

		$this->assertSame( self::LIVE_KEY, Settings::update( [ 'api_key' => $bad ] )['api_key'] );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function bad_keys(): array {
		return [
			'wrong prefix'   => [ 'to_prod_0123456789abcdefABCDEF0123456789' ],
			'too short'      => [ 'to_live_0123456789abcdefABCDEF012345678' ],
			'too long'       => [ 'to_live_0123456789abcdefABCDEF01234567890' ],
			'bad char'       => [ 'to_live_0123456789abcdefABCDEF012345678-' ],
			'inner space'    => [ 'to_live_0123456789abcdef ABCDEF012345678' ],
			'not a string'   => [ [ self::LIVE_KEY ] ],
			'trailing junk'  => [ self::LIVE_KEY . "\nx" ],
		];
	}

	public function test_empty_key_clears_the_key(): void {
		Settings::update( [ 'api_key' => self::LIVE_KEY ] );

		$this->assertSame( '', Settings::update( [ 'api_key' => '   ' ] )['api_key'] );
	}

	public function test_gateway_and_method_ids_are_validated_and_keep_their_case(): void {
		$s = Settings::update(
			[
				'hidden_gateways'   => [ ' COD ', '<b>bacs</b>', '', 'cod', 7, [ 'x' ], 'COD' ],
				'fallback_gateways' => [ 'Stripe-CC', 'pay pal', 'ppcp-gateway' ],
				'pickup_methods'    => [ 'Local_Pickup', '../evil', 'vp:pont' ],
			]
		);

		$this->assertSame( [ 'COD', 'cod', '7' ], $s['hidden_gateways'], 'ids are case-sensitive, like WooCommerce' );
		$this->assertSame( [ 'Stripe-CC', 'ppcp-gateway' ], $s['fallback_gateways'] );
		$this->assertSame( [ 'Local_Pickup' ], $s['pickup_methods'] );
	}

	public function test_partial_update_keeps_other_fields_and_ignores_unknown_keys(): void {
		Settings::update( [ 'strictness' => 'lenient', 'hidden_gateways' => [ 'bacs' ] ] );
		$s = Settings::update( [ 'enabled' => '0', 'evil' => 'x' ] );

		$this->assertSame( 'lenient', $s['strictness'] );
		$this->assertSame( [ 'bacs' ], $s['hidden_gateways'] );
		$this->assertFalse( $s['enabled'] );
		$this->assertArrayNotHasKey( 'evil', $s );
	}

	public function test_non_array_list_keeps_previous_value(): void {
		Settings::update( [ 'hidden_gateways' => [ 'bacs' ] ] );

		$this->assertSame( [ 'bacs' ], Settings::update( [ 'hidden_gateways' => 'cod' ] )['hidden_gateways'] );
	}

	public function test_corrupt_stored_option_is_sanitised_on_read(): void {
		$GLOBALS['trustorder_test']['options'][ Settings::OPTION ] = [
			'api_key'         => 'garbage',
			'strictness'      => 'weird',
			'hidden_gateways' => 'cod',
			'enabled'         => 'yes',
		];

		$s = Settings::get();
		$this->assertSame( '', $s['api_key'] );
		$this->assertSame( 'normal', $s['strictness'] );
		$this->assertSame( [ 'cod' ], $s['hidden_gateways'] );
		$this->assertTrue( $s['enabled'] );

		$GLOBALS['trustorder_test']['options'][ Settings::OPTION ] = 'not an array';
		$this->assertSame( Settings::defaults(), Settings::get() );
	}
}
