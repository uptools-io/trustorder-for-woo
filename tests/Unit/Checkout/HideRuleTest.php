<?php
/**
 * Hide rule: which gateways a decision removes, and when it must not.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Checkout;

use LightweightPlugins\TrustOrder\Api\Response;
use LightweightPlugins\TrustOrder\Checkout\Decision;
use LightweightPlugins\TrustOrder\Checkout\HideRule;
use LightweightPlugins\TrustOrder\Log;
use LightweightPlugins\TrustOrder\Settings;
use PHPUnit\Framework\TestCase;

final class HideRuleTest extends TestCase {

	protected function setUp(): void {
		trustorder_test_reset();
		HideRule::reset();
	}

	private static function decision( string $verdict ): Decision {
		return Decision::fromResponse(
			new Response(
				200,
				[
					'verdict'  => $verdict,
					'level'    => 'block' === $verdict ? 'blocked' : 'unknown',
					'score'    => 20,
					'reasons'  => [ 'LOW_SCORE' ],
					'check_id' => '01a1a17c-7e54-7032-95b4-ba478164249b',
				],
				null
			)
		);
	}

	/**
	 * @param array<string, mixed> $over Settings overrides.
	 * @return array<string, mixed>
	 */
	private static function settings( array $over = [] ): array {
		return $over + Settings::defaults();
	}

	public function test_allow_and_flag_hide_nothing(): void {
		$this->assertSame( [], HideRule::hidden( self::decision( 'allow' ), [ 'bacs', 'cod' ], self::settings() ) );
		$this->assertSame( [], HideRule::hidden( self::decision( 'flag' ), [ 'bacs', 'cod' ], self::settings() ) );
	}

	public function test_block_hides_the_configured_gateways(): void {
		$this->assertSame( [ 'cod' ], HideRule::hidden( self::decision( 'block' ), [ 'bacs', 'cod', 'stripe' ], self::settings() ) );
		$this->assertSame(
			[ 'cod', 'stripe' ],
			HideRule::hidden( self::decision( 'block' ), [ 'bacs', 'cod', 'stripe' ], self::settings( [ 'hidden_gateways' => [ 'cod', 'stripe' ] ] ) )
		);
	}

	public function test_mixed_case_gateway_ids_match_exactly(): void {
		$settings = self::settings( Settings::update( [ 'hidden_gateways' => [ 'MyPay_COD' ] ] ) );

		$this->assertSame( [ 'MyPay_COD' ], HideRule::hidden( self::decision( 'block' ), [ 'bacs', 'MyPay_COD' ], $settings ) );
		$this->assertSame( [], HideRule::hidden( self::decision( 'block' ), [ 'bacs', 'mypay_cod' ], $settings ) );
	}

	public function test_fail_open_decision_hides_nothing(): void {
		$this->assertSame( [], HideRule::hidden( Decision::failOpen( 'TIMEOUT' ), [ 'bacs', 'cod' ], self::settings() ) );
	}

	public function test_hidden_gateway_not_offered_is_a_no_op(): void {
		$this->assertSame( [], HideRule::hidden( self::decision( 'block' ), [ 'bacs' ], self::settings() ) );
		$this->assertFalse( get_option( Log::LAST_ERROR_OPTION ) );
	}

	public function test_only_gateway_left_would_be_hidden_fails_open_with_warning(): void {
		$hidden = HideRule::hidden( self::decision( 'block' ), [ 'cod' ], self::settings() );

		$this->assertSame( [], $hidden, 'the order must stay placeable' );
		$err = get_option( Log::LAST_ERROR_OPTION );
		$this->assertIsArray( $err );
		$this->assertSame( 'HIDE_WOULD_EMPTY', $err['code'] );
	}

	public function test_configured_fallback_not_visible_fails_open(): void {
		$s = self::settings( [ 'fallback_gateways' => [ 'bacs' ] ] );

		$this->assertSame( [], HideRule::hidden( self::decision( 'block' ), [ 'cod', 'stripe' ], $s ) );
		$this->assertSame( 'HIDE_WOULD_EMPTY', get_option( Log::LAST_ERROR_OPTION )['code'] );
	}

	public function test_configured_fallback_visible_allows_hiding(): void {
		$s = self::settings( [ 'fallback_gateways' => [ 'bacs' ] ] );

		$this->assertSame( [ 'cod' ], HideRule::hidden( self::decision( 'block' ), [ 'bacs', 'cod', 'stripe' ], $s ) );
	}

	public function test_fallback_that_is_itself_hidden_does_not_count(): void {
		$s = self::settings( [ 'hidden_gateways' => [ 'cod', 'bacs' ], 'fallback_gateways' => [ 'bacs' ] ] );

		$this->assertSame( [], HideRule::hidden( self::decision( 'block' ), [ 'bacs', 'cod', 'stripe' ], $s ) );
	}

	public function test_hide_would_empty_is_logged_once_per_request_and_ten_minutes(): void {
		HideRule::hidden( self::decision( 'block' ), [ 'cod' ], self::settings() );
		HideRule::hidden( self::decision( 'block' ), [ 'cod' ], self::settings() );
		$this->assertCount( 1, $GLOBALS['trustorder_test']['logs'] );

		HideRule::reset(); // next request
		HideRule::hidden( self::decision( 'block' ), [ 'cod' ], self::settings() );
		$this->assertCount( 1, $GLOBALS['trustorder_test']['logs'], '10-minute transient' );
		$this->assertSame( 600, $GLOBALS['trustorder_test']['transients']['trustorder_hwe']['ttl'] );

		unset( $GLOBALS['trustorder_test']['transients']['trustorder_hwe'] );
		HideRule::reset();
		HideRule::hidden( self::decision( 'block' ), [ 'cod' ], self::settings() );
		$this->assertCount( 2, $GLOBALS['trustorder_test']['logs'] );
	}
}
