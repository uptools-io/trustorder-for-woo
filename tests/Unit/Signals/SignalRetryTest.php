<?php
/**
 * Retry with backoff on retryable failures only; nothing sensitive in logs.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Signals;

use LightweightPlugins\TrustOrder\Signals\SignalJob;
use LightweightPlugins\TrustOrder\Signals\SignalState;
use WP_Error;

final class SignalRetryTest extends SignalTestCase {

	/**
	 * Run the pending job once (as the AS runner would).
	 */
	private function runOnce(): ?int {
		foreach ( $GLOBALS['trustorder_test']['as'] as $i => $a ) {
			if ( 'pending' === $a['status'] ) {
				$GLOBALS['trustorder_test']['as'][ $i ]['status'] = 'in-progress';
				do_action( $a['hook'], ...$a['args'] );
				$GLOBALS['trustorder_test']['as'][ $i ]['status'] = 'complete';
				return (int) $a['time'];
			}
		}
		return null;
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function retryable(): array {
		return [
			'429'     => [ self::problem( 429, 'RATE_LIMITED' ) ],
			'503'     => [ self::problem( 503, 'INTERNAL' ) ],
			'timeout' => [ new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) ],
			'network' => [ new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' ) ],
		];
	}

	/**
	 * @dataProvider retryable
	 */
	public function test_backoff_1_5_30_120_minutes_then_failed( $answer ): void {
		$this->answers = [ $answer ];
		$o             = $this->order( [ 'status' => 'completed' ] );
		\LightweightPlugins\TrustOrder\Signals\SignalRouter::schedule( 42 );

		$delays = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$ran   = time();
			$this->assertNotNull( $this->runOnce() );
			$next  = $this->pending();
			if ( [] === $next ) {
				break;
			}
			$delays[] = (int) round( ( $next[0]['time'] - $ran ) / 60 );
			$this->assertSame( SignalState::PENDING, $this->state( $o )['status'] );
			$this->assertSame( $i + 1, $this->state( $o )['attempts'] );
		}

		$this->assertSame( [ 1, 5, 30, 120 ], $delays );
		$this->assertCount( 5, $this->calls(), 'five attempts in total' );
		$s = $this->state( $o );
		$this->assertSame( SignalState::FAILED, $s['status'] );
		$this->assertNull( $s['outcome'] );
		$this->assertSame( [], $this->pending() );
	}

	/**
	 * @return array<string, array{0: int, 1: string}>
	 */
	public function fatal(): array {
		return [
			'400' => [ 400, 'INVALID_REQUEST' ],
			'401' => [ 401, 'UNAUTHORIZED' ],
			'403' => [ 403, 'FORBIDDEN' ],
			'413' => [ 413, 'PAYLOAD_TOO_LARGE' ],
		];
	}

	/**
	 * @dataProvider fatal
	 */
	public function test_non_retryable_fails_at_once_with_the_code( int $status, string $code ): void {
		$this->answers = [ self::problem( $status, $code ) ];
		$o             = $this->order();
		$this->transition( $o, 'completed' );

		$s = $this->state( $o );
		$this->assertSame( SignalState::FAILED, $s['status'] );
		$this->assertSame( $code, $s['error'] );
		$this->assertCount( 1, $this->calls() );
		$this->assertSame( [], $this->pending() );
		$this->assertStringContainsString( 'signal', $this->logged() );
	}

	public function test_retry_reuses_occurred_at_and_succeeds(): void {
		$this->answers = [ self::problem( 503, 'INTERNAL' ), self::http( 200, [ 'result' => 'stored' ] ) ];
		$o             = $this->order( [ 'date_completed' => null ] );
		$this->transition( $o, 'to-refused', false );
		$this->runOnce();
		$o->set_date_modified( new \DateTimeImmutable( '2026-10-05T00:00:00Z' ) ); // Meta write bumped it.
		$this->runOnce();

		$calls = $this->calls();
		$this->assertSame( $calls[0][2]['occurred_at'], $calls[1][2]['occurred_at'] );
		$this->assertSame( SignalState::SENT, $this->state( $o )['status'] );
		$this->assertSame( 0, $this->state( $o )['attempts'] );
	}

	public function test_pending_retry_is_cancelled_by_a_retract(): void {
		$this->answers = [ self::problem( 503, 'INTERNAL' ) ];
		$o             = $this->order();
		$this->transition( $o, 'completed', false );
		$this->runOnce();
		$o->set_status( 'processing' );
		$this->runOnce(); // The scheduled retry now reads "processing".

		$this->assertCount( 1, $this->calls(), 'no DELETE: nothing was stored' );
		$this->assertSame( SignalState::RETRACTED, $this->state( $o )['status'] );
		$this->assertSame( [], $this->pending() );
	}

	public function test_a_pending_delivery_retried_after_refund_sends_delete_not_put(): void {
		$this->answers = [ self::problem( 503, 'INTERNAL' ), self::http( 204, '' ) ];
		$o             = $this->order();
		$this->transition( $o, 'completed', false );
		$this->runOnce(); // PUT -> 503, retry pending (the response may have been lost).
		$this->assertSame( SignalState::PENDING, $this->state( $o )['status'] );
		$o->set_status( 'refunded' );
		$this->runOnce(); // The scheduled retry now reads "refunded".

		$this->assertSame( [ 'PUT', 'DELETE' ], array_column( $this->calls(), 0 ) );
		$this->assertSame( SignalState::RETRACTED, $this->state( $o )['status'] );
		$this->assertSame( [], $this->pending() );
	}

	public function test_a_failed_refund_delete_is_retried_as_a_delete(): void {
		$this->answers = [ self::problem( 503, 'INTERNAL' ), self::problem( 503, 'INTERNAL' ), self::http( 204, '' ) ];
		$o             = $this->order();
		$this->transition( $o, 'completed', false );
		$this->runOnce(); // PUT -> 503.
		$o->set_status( 'refunded' );
		$this->runOnce(); // DELETE -> 503, retry pending.
		$this->assertSame( SignalState::PENDING, $this->state( $o )['status'] );
		$this->runOnce(); // DELETE again.

		$this->assertSame( [ 'PUT', 'DELETE', 'DELETE' ], array_column( $this->calls(), 0 ) );
		$this->assertSame( SignalState::RETRACTED, $this->state( $o )['status'] );
	}

	public function test_a_refund_keeps_a_refusal_under_a_pending_delivery(): void {
		$o = $this->order();
		$this->transition( $o, 'to-refused' );
		$this->answers = [ self::problem( 503, 'INTERNAL' ) ];
		$this->transition( $o, 'completed', false );
		$this->runOnce(); // PUT delivered -> 503, retry pending, refusal still held.
		$this->assertSame( [ 'refused', SignalState::PENDING ], [ $this->state( $o )['outcome'], $this->state( $o )['status'] ] );
		$this->transition( $o, 'refunded', false );
		$this->runOnce(); // The scheduled retry now reads "refunded".

		$this->assertSame( [ 'PUT', 'PUT' ], array_column( $this->calls(), 0 ), 'no DELETE, no second delivery PUT' );
		$this->assertSame( 'refused', $this->state( $o )['outcome'] );
		$this->assertSame( [], $this->pending() );
	}

	public function test_a_pending_retract_over_a_refusal_continues_after_a_refund(): void {
		$o = $this->order();
		$this->transition( $o, 'to-refused' );
		$this->answers = [ self::problem( 503, 'INTERNAL' ), self::http( 204, '' ) ];
		$this->transition( $o, 'processing', false );
		$this->runOnce(); // DELETE -> 503, retry pending.
		$this->transition( $o, 'refunded', false );
		$this->runOnce(); // DELETE again.

		$this->assertSame( [ 'PUT', 'DELETE', 'DELETE' ], array_column( $this->calls(), 0 ) );
		$s = $this->state( $o );
		$this->assertSame( [ null, SignalState::RETRACTED ], [ $s['outcome'], $s['status'] ] );
	}

	public function test_failed_retract_keeps_the_held_outcome(): void {
		$o = $this->order();
		$this->transition( $o, 'completed' );
		$this->answers = [ self::problem( 401, 'UNAUTHORIZED' ) ];
		$this->transition( $o, 'processing' );

		$s = $this->state( $o );
		$this->assertSame( 'delivered', $s['outcome'] );
		$this->assertSame( SignalState::FAILED, $s['status'] );
	}

	public function test_logs_carry_no_identity_hashes_key_or_ref(): void {
		$this->answers = [ self::problem( 400, 'INVALID_REQUEST' ) ];
		$o             = $this->order( [ 'id' => 987654 ] );
		$this->transition( $o, 'completed' );

		$log  = $this->logged();
		$hash = \LightweightPlugins\TrustOrder\Checkout\IdentityBuilder::fromOrder( $o )->hashes();
		foreach ( [ self::EMAIL, 'example.com', '1234567', self::TEST_KEY, '987654', (string) $hash['email_hash'], (string) $hash['phone_hash'] ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $log );
		}
		$this->assertStringNotContainsString( '987654', (string) $o->meta['_trustorder_signal'] );
		$this->assertStringNotContainsString( 'example', (string) $o->meta['_trustorder_signal'] );
	}

	public function test_exception_is_logged_and_swallowed(): void {
		$o = $this->order( [ 'status' => 'completed' ] );
		add_filter(
			'pre_http_request',
			static function () {
				throw new \RuntimeException( 'buyer@example.com' );
			},
			1
		);
		SignalJob::run( 42 );

		$this->assertStringContainsString( 'EXCEPTION', $this->logged() );
		$this->assertStringNotContainsString( 'buyer', $this->logged() );
	}

	public function test_unsafe_api_error_code_is_not_stored(): void {
		$this->answers = [ self::http( 400, [ 'status' => 400, 'code' => 'bad code <script>' ] ) ];
		$o             = $this->order();
		$this->transition( $o, 'completed' );

		$this->assertSame( 'HTTP_400', $this->state( $o )['error'] );
	}

	public function test_a_pending_delete_retry_is_not_cancelled(): void {
		$o = $this->order();
		$this->transition( $o, 'completed' );
		$this->answers = [ self::problem( 503, 'INTERNAL' ), self::http( 204, '' ) ];
		$this->transition( $o, 'processing', false );
		$this->runOnce(); // DELETE -> 503, retry pending.
		$this->assertSame( SignalState::PENDING, $this->state( $o )['status'] );
		$this->runOnce(); // DELETE again.

		$this->assertSame( [ 'PUT', 'DELETE', 'DELETE' ], array_column( $this->calls(), 0 ) );
		$this->assertSame( SignalState::RETRACTED, $this->state( $o )['status'] );
	}

	public function test_a_far_retry_is_replaced_by_a_newer_status_change_keeping_attempts(): void {
		$this->answers = [ self::problem( 503, 'INTERNAL' ) ];
		$o             = $this->order();
		$this->transition( $o, 'completed', false );
		$this->runOnce(); // Attempt 1 -> +60 s.
		$this->runOnce(); // Attempt 2 -> +300 s.
		$this->assertSame( 2, $this->state( $o )['attempts'] );
		$far = $this->pending()[0]['time'];
		$this->assertGreaterThan( time() + 60, $far );

		$this->transition( $o, 'refunded', false );
		$this->transition( $o, 'completed', false );

		$pending = $this->pending();
		$this->assertCount( 1, $pending );
		$this->assertLessThanOrEqual( time(), $pending[0]['time'], 'due now' );
		$this->runOnce();
		$this->assertSame( 3, $this->state( $o )['attempts'], 'the counter is kept' );
	}

	public function test_a_near_retry_is_not_replaced(): void {
		$this->answers = [ self::problem( 503, 'INTERNAL' ) ];
		$o             = $this->order();
		$this->transition( $o, 'completed', false );
		$this->runOnce(); // +60 s.
		$due = $this->pending()[0]['time'];

		$this->transition( $o, 'refunded', false );
		$this->transition( $o, 'completed', false );

		$this->assertCount( 1, $this->pending() );
		$this->assertSame( $due, $this->pending()[0]['time'] );
	}

	public function test_a_locked_order_is_retried_later_without_a_call(): void {
		$this->order( [ 'status' => 'completed' ] );
		$this->assertTrue( \LightweightPlugins\TrustOrder\Signals\SignalLock::acquire( 42 ) );

		SignalJob::run( 42 );

		$this->assertSame( [], $this->calls() );
		$p = $this->pending();
		$this->assertCount( 1, $p );
		$this->assertEqualsWithDelta( time() + 60, $p[0]['time'], 2 );
	}

	public function test_the_lock_is_released_and_an_expired_lock_is_taken_over(): void {
		$this->order( [ 'status' => 'completed' ] );
		update_option( 'trustorder_lock_42', time() - 1, false );

		SignalJob::run( 42 );

		$this->assertCount( 1, $this->calls() );
		$this->assertArrayNotHasKey( 'trustorder_lock_42', $GLOBALS['trustorder_test']['options'] );
	}
}
