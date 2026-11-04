<?php
/**
 * A full refund withdraws a sent delivery; a refusal stays.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Signals;

use LightweightPlugins\TrustOrder\Checkout\CacheGeneration;
use LightweightPlugins\TrustOrder\Settings;
use LightweightPlugins\TrustOrder\Signals\SignalRouter;
use LightweightPlugins\TrustOrder\Signals\SignalState;

final class SignalRefundTest extends SignalTestCase {

	public function test_a_full_refund_retracts_a_sent_delivery(): void {
		$o = $this->order();
		$this->transition( $o, 'completed' );
		$before = CacheGeneration::current();
		$this->transition( $o, 'refunded' );

		$this->assertSame( [ 'PUT', 'DELETE' ], array_column( $this->calls(), 0 ) );
		$this->assertSame( '/signals/42', $this->calls()[1][1] );
		$s = $this->state( $o );
		$this->assertNull( $s['outcome'] );
		$this->assertSame( SignalState::RETRACTED, $s['status'] );
		$this->assertSame( $before + 1, CacheGeneration::current() );
	}

	public function test_a_refund_keeps_a_sent_refusal(): void {
		$o = $this->order();
		$this->transition( $o, 'to-refused' );
		$this->transition( $o, 'refunded' );

		$this->assertSame( [ 'PUT' ], array_column( $this->calls(), 0 ) );
		$s = $this->state( $o );
		$this->assertSame( [ 'refused', SignalState::SENT ], [ $s['outcome'], $s['status'] ] );
	}

	public function test_a_refund_without_a_signal_does_nothing(): void {
		$o = $this->order();
		$this->transition( $o, 'refunded', false );

		$this->assertSame( [], $GLOBALS['trustorder_test']['as'] );
		$this->assertSame( [], $this->calls() );
	}

	public function test_a_refund_delete_404_counts_as_retracted(): void {
		$o = $this->order();
		$this->transition( $o, 'completed' );
		$this->answers = [ self::problem( 404, 'NOT_FOUND' ) ];
		$this->transition( $o, 'refunded' );

		$this->assertSame( SignalState::RETRACTED, $this->state( $o )['status'] );
		$this->assertSame( [], $this->pending() );
	}

	public function test_refunded_back_to_completed_sends_delivered_again(): void {
		$o = $this->order();
		$this->transition( $o, 'completed' );
		$this->transition( $o, 'refunded' );
		$this->transition( $o, 'completed' );

		$this->assertSame( [ 'PUT', 'DELETE', 'PUT' ], array_column( $this->calls(), 0 ) );
		$this->assertSame( 'delivered', $this->calls()[2][2]['outcome'] );
		$this->assertSame( 'delivered', $this->state( $o )['outcome'] );
	}

	/**
	 * Pins the current behaviour: the live report is not reached (that needs the
	 * live key), yet the order is recorded as retracted in the new (test) env.
	 */
	public function test_a_refund_after_a_key_switch_sends_a_delete_under_the_new_key(): void {
		$o = $this->order();
		Settings::update( [ 'api_key' => self::LIVE_KEY ] );
		$this->transition( $o, 'completed' );
		Settings::update( [ 'api_key' => self::TEST_KEY ] );
		$this->transition( $o, 'refunded' );

		$http = $GLOBALS['trustorder_test']['http'];
		$this->assertSame( [ 'PUT', 'DELETE' ], array_column( $this->calls(), 0 ) );
		$this->assertSame( 'Bearer ' . self::LIVE_KEY, $http[0]['args']['headers']['Authorization'] ?? null );
		$this->assertSame( 'Bearer ' . self::TEST_KEY, $http[1]['args']['headers']['Authorization'] ?? null );
		$this->assertSame( '/signals/42', $this->calls()[1][1] );
		$s = $this->state( $o );
		$this->assertSame( [ 'test', null, SignalState::RETRACTED ], [ $s['env'], $s['outcome'], $s['status'] ] );
	}

	public function test_router_schedules_a_refund_only_over_a_sent_delivery(): void {
		$delivered = $this->order();
		$this->transition( $delivered, 'completed' );
		$refused = $this->order( [ 'id' => 43 ] );
		$this->transition( $refused, 'to-refused' );

		$this->assertTrue( SignalRouter::shouldSchedule( 'completed', 'refunded', $delivered ) );
		$this->assertFalse( SignalRouter::shouldSchedule( 'completed', 'refunded', $refused ) );
		$this->assertFalse( SignalRouter::shouldSchedule( 'completed', 'refunded', null ) );
	}
}
