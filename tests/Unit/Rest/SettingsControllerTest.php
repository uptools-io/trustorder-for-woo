<?php
/**
 * GET|POST /settings.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Rest;

use LightweightPlugins\TrustOrder\Settings;
use TrustOrder_Test_Shipping;
use WC_Payment_Gateway;

final class SettingsControllerTest extends RestTestCase {

	protected function setUp(): void {
		parent::setUp();
		$wc                        = trustorder_test_wc();
		$wc->payment_gateways->all = [
			'bacs'   => new WC_Payment_Gateway( 'bacs', 'yes', 'Bank transfer' ),
			'cod'    => new WC_Payment_Gateway( 'cod', 'yes', 'Cash on delivery <b>x</b>' ),
			'cheque' => new WC_Payment_Gateway( 'cheque', 'no', 'Check' ),
		];
		$wc->shipping_fake          = new TrustOrder_Test_Shipping();
		$wc->shipping_fake->methods = [
			'flat_rate'    => (object) [ 'id' => 'flat_rate', 'method_title' => 'Flat rate' ],
			'local_pickup' => (object) [ 'id' => 'local_pickup', 'method_title' => 'Local pickup' ],
		];
	}

	public function test_get_never_returns_the_key_only_its_prefix(): void {
		Settings::update( [ 'api_key' => self::LIVE_KEY ] );

		$data = $this->ok( $this->call( 'GET', '/settings' ) );

		$this->assertStringNotContainsString( self::LIVE_KEY, (string) json_encode( $data ) );
		$this->assertTrue( $data['settings']['has_key'] );
		$this->assertSame( substr( self::LIVE_KEY, 0, 12 ), $data['settings']['key_prefix'] );
		$this->assertSame( 'live', $data['settings']['env'] );
		$this->assertArrayNotHasKey( 'api_key', $data['settings'] );
		$this->assertSame( 'normal', $data['settings']['strictness'] );
		$this->assertSame( [ 'cod' ], $data['settings']['hidden_gateways'] );
		$this->assertTrue( $data['settings']['enabled'] );
		$this->assertTrue( $data['settings']['share_geo'] );
	}

	public function test_post_accepts_share_geo_true_and_false(): void {
		$data = $this->ok( $this->call( 'POST', '/settings', [ 'share_geo' => true ] ) );
		$this->assertTrue( $data['settings']['share_geo'] );
		$this->assertTrue( Settings::get()['share_geo'] );

		$data = $this->ok( $this->call( 'POST', '/settings', [ 'share_geo' => false ] ) );
		$this->assertFalse( $data['settings']['share_geo'] );
		$this->assertFalse( Settings::get()['share_geo'] );
	}

	public function test_get_without_key(): void {
		$data = $this->ok( $this->call( 'GET', '/settings' ) );

		$this->assertFalse( $data['settings']['has_key'] );
		$this->assertSame( '', $data['settings']['key_prefix'] );
		$this->assertNull( $data['settings']['env'] );
	}

	public function test_get_lists_enabled_gateways_and_keeps_selected_disabled_ones(): void {
		Settings::update( [ 'fallback_gateways' => [ 'cheque' ] ] );

		$data = $this->ok( $this->call( 'GET', '/settings' ) );

		$this->assertSame(
			[
				[ 'id' => 'bacs', 'title' => 'Bank transfer', 'enabled' => true ],
				[ 'id' => 'cod', 'title' => 'Cash on delivery x', 'enabled' => true ],
				[ 'id' => 'cheque', 'title' => 'Check', 'enabled' => false ],
			],
			$data['gateways']
		);
	}

	public function test_get_lists_only_installed_shipping_methods_and_names_the_two_pickups(): void {
		$GLOBALS['trustorder_test']['wc']->shipping_fake->methods['pickup_location'] = (object) [ 'id' => 'pickup_location', 'method_title' => 'Local pickup' ];

		$data = $this->ok( $this->call( 'GET', '/settings' ) );

		$this->assertSame( [ 'flat_rate', 'local_pickup', 'pickup_location' ], array_column( $data['shipping_methods'], 'id' ) );
		$this->assertSame( 'Local pickup (classic checkout)', $data['shipping_methods'][1]['title'] );
		$this->assertSame( 'Local pickup (block checkout)', $data['shipping_methods'][2]['title'] );
		// Stored but absent ids stay in the value.
		$this->assertContains( 'vp_pont', $data['settings']['pickup_methods'] );
	}

	public function test_post_rejects_unknown_gateway_ids_but_keeps_stored_ones(): void {
		update_option( Settings::OPTION, [ 'hidden_gateways' => [ 'cod', 'old_gw' ] ] );

		$this->err( $this->call( 'POST', '/settings', [ 'hidden_gateways' => [ 'cod', 'made_up' ] ] ), 'trustorder_invalid_list', 400 );
		$this->err( $this->call( 'POST', '/settings', [ 'fallback_gateways' => [ 'nope' ] ] ), 'trustorder_invalid_list', 400 );
		$this->assertSame( [ 'cod', 'old_gw' ], Settings::get()['hidden_gateways'] );

		$this->ok( $this->call( 'POST', '/settings', [ 'hidden_gateways' => [ 'cod', 'old_gw', 'cheque' ], 'fallback_gateways' => [ 'bacs' ] ] ) );
		$this->assertSame( [ 'cod', 'old_gw', 'cheque' ], Settings::get()['hidden_gateways'] );
	}

	public function test_mixed_case_gateway_ids_are_listed_and_saved_as_is(): void {
		$GLOBALS['trustorder_test']['wc']->payment_gateways->all['MyPay_COD'] = new WC_Payment_Gateway( 'MyPay_COD', 'yes', 'My COD' );

		$data = $this->ok( $this->call( 'GET', '/settings' ) );
		$this->assertContains( 'MyPay_COD', array_column( $data['gateways'], 'id' ) );

		$this->ok( $this->call( 'POST', '/settings', [ 'hidden_gateways' => [ 'MyPay_COD' ] ] ) );
		$this->assertSame( [ 'MyPay_COD' ], Settings::get()['hidden_gateways'] );
		$this->err( $this->call( 'POST', '/settings', [ 'hidden_gateways' => [ 'mypay_cod' ] ] ), 'trustorder_invalid_list', 400 );
	}

	public function test_post_rejects_a_gateway_both_hidden_and_fallback(): void {
		Settings::update( [ 'fallback_gateways' => [ 'bacs' ] ] );
		$before = get_option( Settings::OPTION );

		$this->err( $this->call( 'POST', '/settings', [ 'hidden_gateways' => [ 'cod', 'bacs' ] ] ), 'trustorder_invalid_list', 400 );
		$this->err( $this->call( 'POST', '/settings', [ 'hidden_gateways' => [ 'cod' ], 'fallback_gateways' => [ 'COD' ] ] ), 'trustorder_invalid_list', 400 );
		$this->assertSame( $before, get_option( Settings::OPTION ) );
	}

	public function test_a_legacy_conflict_does_not_block_unrelated_saves(): void {
		update_option( Settings::OPTION, [ 'hidden_gateways' => [ 'cod' ], 'fallback_gateways' => [ 'cod' ] ] );

		$this->ok( $this->call( 'POST', '/settings', [ 'strictness' => 'strict' ] ) );
		$this->assertSame( 'strict', Settings::get()['strictness'] );
		$this->ok( $this->call( 'POST', '/settings', [ 'enabled' => false ] ) );
		$this->ok( $this->call( 'POST', '/settings', [ 'pickup_methods' => [ 'flat_rate' ] ] ) );
	}

	public function test_the_conflict_message_names_the_gateway(): void {
		Settings::update( [ 'fallback_gateways' => [ 'bacs' ] ] );

		$res = $this->err( $this->call( 'POST', '/settings', [ 'hidden_gateways' => [ 'bacs' ] ] ), 'trustorder_invalid_list', 400 );
		$this->assertStringContainsString( 'bacs', $res->get_error_message() );
	}

	public function test_post_saves_fields_and_returns_the_masked_view(): void {
		$data = $this->ok(
			$this->call(
				'POST',
				'/settings',
				[
					'api_key'           => '  ' . self::TEST_KEY . ' ',
					'strictness'        => 'strict',
					'hidden_gateways'   => [ 'cod', ' cod ', '<cheque>' ],
					'fallback_gateways' => [ 'bacs' ],
					'pickup_methods'    => [ 'local_pickup' ],
					'enabled'           => false,
					'unknown'           => 'x',
				]
			)
		);

		$stored = Settings::get();
		$this->assertSame( self::TEST_KEY, $stored['api_key'] );
		$this->assertSame( 'strict', $stored['strictness'] );
		$this->assertSame( [ 'cod' ], $stored['hidden_gateways'] );
		$this->assertSame( [ 'bacs' ], $stored['fallback_gateways'] );
		$this->assertFalse( $stored['enabled'] );
		$this->assertArrayNotHasKey( 'unknown', get_option( Settings::OPTION ) );
		$this->assertSame( 'test', $data['settings']['env'] );
		$this->assertStringNotContainsString( self::TEST_KEY, (string) json_encode( $data ) );
	}

	public function test_post_without_api_key_keeps_the_stored_key(): void {
		Settings::update( [ 'api_key' => self::LIVE_KEY ] );

		$this->ok( $this->call( 'POST', '/settings', [ 'strictness' => 'lenient' ] ) );

		$this->assertSame( self::LIVE_KEY, Settings::get()['api_key'] );
	}

	public function test_post_empty_api_key_removes_the_key(): void {
		Settings::update( [ 'api_key' => self::LIVE_KEY ] );

		$data = $this->ok( $this->call( 'POST', '/settings', [ 'api_key' => '' ] ) );

		$this->assertSame( '', Settings::get()['api_key'] );
		$this->assertFalse( $data['settings']['has_key'] );
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function badInput(): array {
		return [
			'malformed key'      => [ [ 'api_key' => 'to_live_short' ], 'trustorder_invalid_key' ],
			'non-string key'     => [ [ 'api_key' => [ 'x' ] ], 'trustorder_invalid_key' ],
			'bad strictness'     => [ [ 'strictness' => 'paranoid' ], 'trustorder_invalid_strictness' ],
			'list not an array'  => [ [ 'hidden_gateways' => 'cod' ], 'trustorder_invalid_list' ],
			'enabled not a bool' => [ [ 'enabled' => 'maybe' ], 'trustorder_invalid_enabled' ],
			'share_geo "yes"'    => [ [ 'share_geo' => 'yes' ], 'trustorder_invalid_share_geo' ],
		];
	}

	/**
	 * @dataProvider badInput
	 *
	 * @param array<string, mixed> $params Params.
	 */
	public function test_post_rejects_bad_input_and_saves_nothing( array $params, string $code ): void {
		Settings::update( [ 'api_key' => self::LIVE_KEY, 'strictness' => 'strict' ] );
		$before = get_option( Settings::OPTION );

		$err = $this->err( $this->call( 'POST', '/settings', $params + [ 'enabled' => false ] ), $code, 400 );

		$this->assertSame( $before, get_option( Settings::OPTION ) );
		$this->assertStringNotContainsString( 'to_live_short', $err->get_error_message() );
	}
}
