<?php
/**
 * Order annotation: hashes + result meta, flag note.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Order;

use LightweightPlugins\TrustOrder\Order\OrderResult;
use LightweightPlugins\TrustOrder\Tests\Unit\Checkout\CheckoutTestCase;

final class OrderResultTest extends CheckoutTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->onCheckout();
		OrderResult::register();
	}

	private function order( array $over = [] ): \WC_Order {
		$o           = new \WC_Order(
			$over + [
				'id'                 => 42,
				'total'              => '1990',
				'billing_email'      => 'buyer@example.com',
				'billing_phone'      => '+36301234567',
				'billing_country'    => 'HU',
				'shipping_country'   => 'HU',
				'shipping_postcode'  => '1051',
				'shipping_address_1' => 'Fő utca 3',
			]
		);
		$o->shipping = [ new \WC_Order_Item_Shipping( 'flat_rate' ) ];

		return $o;
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function hooks(): array {
		return [
			'classic' => [ 'woocommerce_checkout_order_created' ],
			'blocks'  => [ 'woocommerce_store_api_checkout_order_processed' ],
		];
	}

	/**
	 * @dataProvider hooks
	 */
	public function test_stores_hashes_and_result_on_both_checkouts( string $hook ): void {
		$this->answers = [ $this->verdict( 'allow', 'trusted', 90, [] ) ];
		$o             = $this->order();

		do_action( $hook, $o );

		$hashes = json_decode( $o->meta['_trustorder_hashes'], true );
		$this->assertSame( [ 'email_hash', 'phone_hash', 'address_hash', 'email_domain_hashes' ], array_keys( $hashes ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hashes['address_hash'] );

		$result = json_decode( $o->meta['_trustorder_result'], true );
		$this->assertSame( 'allow', $result['verdict'] );
		$this->assertSame( 'trusted', $result['level'] );
		$this->assertSame( 90, $result['score'] );
		$this->assertSame( 'test', $result['env'] );
		$this->assertSame( '01a1a17c-7e54-7032-95b4-ba478164249b', $result['check_id'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT/', $result['checked_at'] );
		$this->assertSame( [], $o->notes );
		$this->assertGreaterThan( 0, $o->saved );
	}

	public function test_flag_adds_a_private_note_without_hashes(): void {
		$this->answers = [ $this->verdict( 'flag', 'risky', 41, [ 'LOW_SCORE', 'NEGATIVE_RECENT' ] ) ];
		$o             = $this->order();

		OrderResult::onOrder( $o );

		$this->assertCount( 1, $o->notes );
		$note = $o->notes[0];
		$this->assertSame( 0, $note['customer'], 'private note' );
		$this->assertStringContainsString( '41', $note['note'] );
		$this->assertStringContainsString( 'LOW_SCORE', $note['note'] );
		foreach ( json_decode( $o->meta['_trustorder_hashes'], true ) as $h ) {
			foreach ( (array) $h as $one ) {
				if ( is_string( $one ) ) {
					$this->assertStringNotContainsString( $one, $note['note'] );
				}
			}
		}
		$this->assertSame( '', $o->get_status(), 'status is not changed' );
	}

	public function test_reuses_the_checkout_decision(): void {
		$this->answers = [ $this->verdict( 'block', 'blocked' ) ];
		$this->visibleGateways();

		OrderResult::onOrder( $this->order() );

		$this->assertSame( 1, $this->checks() );
	}

	public function test_zero_total_order_gets_hashes_but_no_check(): void {
		$o = $this->order( [ 'total' => '0' ] );

		OrderResult::onOrder( $o );

		$this->assertArrayHasKey( '_trustorder_hashes', $o->meta );
		$this->assertArrayNotHasKey( '_trustorder_result', $o->meta );
		$this->assertSame( 0, $this->checks() );
	}

	public function test_api_failure_never_breaks_the_order(): void {
		$this->answers = [ new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) ];
		$o             = $this->order();

		OrderResult::onOrder( $o );

		$result = json_decode( $o->meta['_trustorder_result'], true );
		$this->assertTrue( $result['failed_open'] );
		$this->assertSame( 'TIMEOUT', $result['error'] );
		$this->assertSame( [], $o->notes );
	}

	public function test_read_returns_stored_result(): void {
		$o = $this->order();
		$this->assertNull( OrderResult::read( $o ) );

		OrderResult::onOrder( $o );
		$this->assertSame( 'allow', OrderResult::read( $o )['verdict'] );

		$o->meta['_trustorder_result'] = '{broken';
		$this->assertNull( OrderResult::read( $o ) );
	}

	public function test_payment_retry_with_the_same_check_adds_no_second_note(): void {
		$this->answers = [ $this->verdict( 'flag', 'risky', 41, [ 'LOW_SCORE' ] ) ];
		$o             = $this->order();

		OrderResult::onOrder( $o );
		$first = $o->meta['_trustorder_result'];
		\LightweightPlugins\TrustOrder\Checkout\Checker::reset();
		OrderResult::onOrder( $o );

		$this->assertCount( 1, $o->notes );
		$this->assertSame( $first, $o->meta['_trustorder_result'], 'result and checked_at untouched' );
	}

	public function test_retry_with_a_different_verdict_updates_once_and_keeps_checked_at(): void {
		$o                             = $this->order();
		$o->meta['_trustorder_result'] = (string) json_encode(
			[
				'verdict'    => 'flag',
				'level'      => 'risky',
				'score'      => 41,
				'reasons'    => [ 'LOW_SCORE' ],
				'check_id'   => 'old-check',
				'env'        => 'test',
				'checked_at' => '2026-11-01T10:00:00+00:00',
			]
		);
		$this->answers = [ $this->verdict( 'block', 'blocked', 10 ) ];

		OrderResult::onOrder( $o );
		OrderResult::onOrder( $o );

		$r = json_decode( $o->meta['_trustorder_result'], true );
		$this->assertSame( 'block', $r['verdict'] );
		$this->assertSame( '2026-11-01T10:00:00+00:00', $r['checked_at'] );
		$this->assertArrayHasKey( 'updated_at', $r );
		$this->assertCount( 1, $o->notes );
	}

	public function test_fail_open_retry_never_replaces_a_real_result(): void {
		$o = $this->order();
		OrderResult::onOrder( $o );
		$first = $o->meta['_trustorder_result'];

		\LightweightPlugins\TrustOrder\Checkout\Checker::reset();
		foreach ( array_keys( $GLOBALS['trustorder_test']['transients'] ) as $n ) {
			unset( $GLOBALS['trustorder_test']['transients'][ $n ] );
		}
		$this->answers = [ new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) ];
		OrderResult::onOrder( $o );

		$this->assertSame( $first, $o->meta['_trustorder_result'] );
	}

	public function test_fallback_decision_is_stored_without_foreign_details(): void {
		for ( $i = 1; $i <= \LightweightPlugins\TrustOrder\Checkout\QuotaGuard::SUBMIT_MAX; $i++ ) {
			$this->assertNull( \LightweightPlugins\TrustOrder\Checkout\QuotaGuard::submit( 's:sess-1' ) );
		}
		$o = $this->order();

		OrderResult::onOrder( $o );

		$r = json_decode( $o->meta['_trustorder_result'], true );
		$this->assertSame( 'block', $r['verdict'] );
		$this->assertSame( 'SUBMIT_QUOTA', $r['fallback'] );
		$this->assertArrayNotHasKey( 'check_id', $r );
		$this->assertArrayNotHasKey( 'score', $r );
		$this->assertSame( [], $o->notes );
		$this->assertSame( 0, $this->checks() );
	}

	public function test_order_reuses_the_submit_decision_without_a_new_unit(): void {
		$this->answers = [ $this->verdict( 'block', 'blocked' ) ];
		\LightweightPlugins\TrustOrder\Checkout\Gatekeeper::enterSubmit();
		$this->visibleGateways();
		$used = $GLOBALS['trustorder_test']['transients'][ \LightweightPlugins\TrustOrder\Checkout\Counter::key( 'submit|s:sess-1', \LightweightPlugins\TrustOrder\Checkout\QuotaGuard::WINDOW ) ]['value'];

		OrderResult::onOrder( $this->order() );

		$this->assertSame( 1, $this->checks() );
		$this->assertSame( $used, $GLOBALS['trustorder_test']['transients'][ \LightweightPlugins\TrustOrder\Checkout\Counter::key( 'submit|s:sess-1', \LightweightPlugins\TrustOrder\Checkout\QuotaGuard::WINDOW ) ]['value'] );
	}

	public function test_real_result_replaces_a_stored_fallback_with_a_flag_note(): void {
		$o                             = $this->order();
		$o->meta['_trustorder_result'] = (string) json_encode( [ 'verdict' => 'block', 'fallback' => 'SUBMIT_QUOTA', 'env' => 'test', 'checked_at' => '2026-11-01T10:00:00+00:00' ] );
		$this->answers                 = [ $this->verdict( 'flag', 'risky', 40 ) ];

		OrderResult::onOrder( $o );

		$r = json_decode( $o->meta['_trustorder_result'], true );
		$this->assertSame( 'flag', $r['verdict'] );
		$this->assertArrayNotHasKey( 'fallback', $r );
		$this->assertCount( 1, $o->notes );
	}
}
