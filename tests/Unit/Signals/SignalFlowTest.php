<?php
/**
 * Status changes -> exactly the right signal calls (Review Focus 4).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Signals;

use LightweightPlugins\TrustOrder\Checkout\CacheGeneration;
use LightweightPlugins\TrustOrder\Checkout\IdentityBuilder;
use LightweightPlugins\TrustOrder\Signals\SignalJob;
use LightweightPlugins\TrustOrder\Signals\SignalRouter;
use LightweightPlugins\TrustOrder\Signals\SignalState;

final class SignalFlowTest extends SignalTestCase {

	public function test_completed_sends_delivered_with_hashes_only(): void {
		$o = $this->order();
		$this->transition( $o, 'completed' );

		$calls = $this->calls();
		$this->assertCount( 1, $calls );
		[ $method, $path, $body ] = $calls[0];
		$this->assertSame( 'PUT', $method );
		$this->assertSame( '/signals/42', $path );
		$h = IdentityBuilder::fromOrder( $o )->hashes();
		$this->assertSame(
			[
				'outcome'      => 'delivered',
				'email_hash'   => $h['email_hash'],
				'phone_hash'   => $h['phone_hash'],
				'address_hash' => $h['address_hash'],
				'occurred_at'  => '2026-10-01T10:00:00Z',
			],
			$body
		);
		$raw = (string) $GLOBALS['trustorder_test']['http'][0]['args']['body'];
		$this->assertStringNotContainsString( 'example.com', $raw );
		$this->assertStringNotContainsString( '1234567', $raw );
		$this->assertStringNotContainsString( 'email_domain_hashes', $raw );

		$s = $this->state( $o );
		$this->assertSame( [ 'delivered', 'test', 'sent' ], [ $s['outcome'], $s['env'], $s['status'] ] );
		$this->assertNotNull( $s['sent_at'] );
	}

	public function test_stored_checkout_hashes_are_used(): void {
		$o    = $this->order();
		$hash = str_repeat( 'a', 64 );
		$o->meta['_trustorder_hashes'] = (string) json_encode( [ 'email_hash' => $hash, 'phone_hash' => null, 'address_hash' => str_repeat( 'b', 64 ), 'email_domain_hashes' => [ str_repeat( 'c', 64 ) ] ] );

		$this->transition( $o, 'completed' );

		$body = $this->calls()[0][2];
		$this->assertSame( $hash, $body['email_hash'] );
		$this->assertArrayNotHasKey( 'phone_hash', $body );
		$this->assertSame( str_repeat( 'b', 64 ), $body['address_hash'] );
	}

	public function test_corrupt_stored_hashes_fall_back_to_the_order(): void {
		$o = $this->order();
		$o->meta['_trustorder_hashes'] = '{"email_hash":"buyer@example.com"}';

		$this->transition( $o, 'completed' );

		$this->assertSame( IdentityBuilder::fromOrder( $o )->hashes()['email_hash'], $this->calls()[0][2]['email_hash'] );
	}

	public function test_every_payment_method_gets_a_delivered_signal(): void {
		foreach ( [ 'cod', 'bacs', 'stripe' ] as $i => $method ) {
			$o = $this->order( [ 'id' => 100 + $i, 'payment_method' => $method ] );
			$this->transition( $o, 'completed' );
		}
		$this->assertSame( [ '/signals/100', '/signals/101', '/signals/102' ], array_column( $this->calls(), 1 ) );
	}

	public function test_refused_status_sends_refused_with_modified_date(): void {
		$o = $this->order( [ 'date_completed' => null ] );
		$this->transition( $o, 'to-refused' );

		$body = $this->calls()[0][2];
		$this->assertSame( 'refused', $body['outcome'] );
		$this->assertSame( '2026-10-02T11:00:00Z', $body['occurred_at'] );
	}

	public function test_a_sent_refusal_drops_the_cached_check_results(): void {
		$before = CacheGeneration::current();
		$this->transition( $this->order(), 'to-refused' );

		$this->assertSame( $before + 1, CacheGeneration::current() );
	}

	public function test_a_retract_drops_the_cached_check_results(): void {
		$o = $this->order();
		$this->transition( $o, 'to-refused' );
		$before = CacheGeneration::current();
		$this->transition( $o, 'processing' );

		$this->assertSame( $before + 1, CacheGeneration::current() );
	}

	public function test_a_failed_send_keeps_the_cached_check_results(): void {
		$before        = CacheGeneration::current();
		$this->answers = [ self::problem( 503, 'UNAVAILABLE' ) ];
		$this->transition( $this->order(), 'to-refused' );

		$this->assertSame( $before, CacheGeneration::current() );
	}

	public function test_completed_refunded_completed_sends_one_delivered(): void {
		$o = $this->order();
		$this->transition( $o, 'completed' );
		$this->transition( $o, 'refunded' );
		$this->transition( $o, 'completed' );

		$this->assertCount( 1, $this->calls() );
		$this->assertSame( 'PUT', $this->calls()[0][0] );
		$this->assertSame( 'delivered', $this->state( $o )['outcome'] );
	}

	public function test_refunded_does_not_retract_and_schedules_nothing(): void {
		$o = $this->order();
		$this->transition( $o, 'completed' );
		$GLOBALS['trustorder_test']['as'] = [];
		$this->transition( $o, 'refunded', false );

		$this->assertSame( [], $this->pending() );
	}

	public function test_refused_then_completed_sends_delivered(): void {
		$o = $this->order();
		$this->transition( $o, 'to-refused' );
		$this->transition( $o, 'completed' );

		$this->assertSame( [ 'refused', 'delivered' ], array_map( static fn( $c ) => $c[2]['outcome'], $this->calls() ) );
		$this->assertSame( [ 'PUT', 'PUT' ], array_column( $this->calls(), 0 ) );
		$this->assertSame( 'delivered', $this->state( $o )['outcome'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function retractStatuses(): array {
		return [
			'processing' => [ 'processing' ],
			'on-hold'    => [ 'on-hold' ],
			'pending'    => [ 'pending' ],
		];
	}

	/**
	 * @dataProvider retractStatuses
	 */
	public function test_completed_back_to_open_status_retracts( string $to ): void {
		$o = $this->order();
		$this->transition( $o, 'completed' );
		$this->transition( $o, $to );

		$this->assertSame( [ 'PUT', 'DELETE' ], array_column( $this->calls(), 0 ) );
		$this->assertSame( '/signals/42', $this->calls()[1][1] );
		$s = $this->state( $o );
		$this->assertNull( $s['outcome'] );
		$this->assertSame( SignalState::RETRACTED, $s['status'] );
	}

	public function test_refused_back_to_processing_retracts(): void {
		$o = $this->order();
		$this->transition( $o, 'to-refused' );
		$this->transition( $o, 'processing' );

		$this->assertSame( [ 'PUT', 'DELETE' ], array_column( $this->calls(), 0 ) );
	}

	public function test_completed_refunded_processing_retracts(): void {
		$o = $this->order();
		$this->transition( $o, 'completed' );
		$this->transition( $o, 'refunded' );
		$this->transition( $o, 'processing' );

		$this->assertSame( [ 'PUT', 'DELETE' ], array_column( $this->calls(), 0 ) );
	}

	public function test_delete_404_counts_as_retracted(): void {
		$o = $this->order();
		$this->transition( $o, 'completed' );
		$this->answers = [ self::problem( 404, 'NOT_FOUND' ) ];
		$this->transition( $o, 'processing' );

		$this->assertSame( SignalState::RETRACTED, $this->state( $o )['status'] );
		$this->assertSame( [], $this->pending() );
	}

	public function test_retracted_then_completed_sends_again(): void {
		$o = $this->order();
		$this->transition( $o, 'completed' );
		$this->transition( $o, 'processing' );
		$this->transition( $o, 'completed' );

		$this->assertSame( [ 'PUT', 'DELETE', 'PUT' ], array_column( $this->calls(), 0 ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function quietStatuses(): array {
		return [
			'cancelled' => [ 'cancelled' ],
			'failed'    => [ 'failed' ],
			'refunded'  => [ 'refunded' ],
			'trash'     => [ 'trash' ],
		];
	}

	/**
	 * @dataProvider quietStatuses
	 */
	public function test_other_statuses_schedule_nothing( string $to ): void {
		$o = $this->order();
		$this->transition( $o, $to );

		$this->assertSame( [], $GLOBALS['trustorder_test']['as'] );
		$this->assertSame( [], $this->calls() );
	}

	public function test_new_order_to_processing_schedules_nothing(): void {
		$o = $this->order( [ 'status' => 'pending' ] );
		$this->transition( $o, 'processing', false );

		$this->assertSame( [], $GLOBALS['trustorder_test']['as'] );
	}

	public function test_deleted_order_is_a_no_op(): void {
		$o = $this->order();
		$this->transition( $o, 'completed', false );
		unset( $GLOBALS['trustorder_test']['orders'][42] );
		$this->drain();

		$this->assertSame( [], $this->calls() );
		$this->assertArrayNotHasKey( 'woocommerce_before_delete_order', $GLOBALS['trustorder_test']['actions'] );
		$this->assertArrayNotHasKey( 'wp_trash_post', $GLOBALS['trustorder_test']['actions'] );
	}

	public function test_one_pending_action_per_order(): void {
		$o = $this->order();
		$this->transition( $o, 'completed', false );
		$this->transition( $o, 'processing', false );
		$this->transition( $o, 'completed', false );

		$pending = $this->pending();
		$this->assertCount( 1, $pending );
		$this->assertSame( [ 42 ], $pending[0]['args'] );
		$this->assertSame( 'trustorder', $pending[0]['group'] );
		$this->assertFalse( $pending[0]['unique'] );

		$this->drain();
		$this->assertSame( [ 'PUT' ], array_column( $this->calls(), 0 ), 'the job reads the final status' );
	}

	public function test_a_running_job_does_not_block_a_new_one(): void {
		$o = $this->order();
		$this->transition( $o, 'completed', false );
		$GLOBALS['trustorder_test']['as'][0]['status'] = 'in-progress';
		$this->transition( $o, 'processing', false );

		$this->assertCount( 1, $this->pending() );
	}

	public function test_no_key_sends_nothing(): void {
		\LightweightPlugins\TrustOrder\Settings::update( [ 'api_key' => '' ] );
		$o = $this->order();
		$this->transition( $o, 'completed' );

		$this->assertSame( [], $this->calls() );
		$this->assertSame( '', $o->meta['_trustorder_signal'] ?? '' );
	}

	public function test_no_email_is_skipped_without_a_request(): void {
		$o = $this->order( [ 'billing_email' => '' ] );
		$this->transition( $o, 'completed' );

		$this->assertSame( [], $this->calls() );
		$this->assertSame( SignalState::SKIPPED, $this->state( $o )['status'] );
	}

	public function test_env_switch_resends_to_the_new_env(): void {
		$o = $this->order();
		$this->transition( $o, 'completed' );
		\LightweightPlugins\TrustOrder\Settings::update( [ 'api_key' => self::LIVE_KEY ] );
		$this->transition( $o, 'completed' );

		$this->assertCount( 2, $this->calls() );
		$auth = $GLOBALS['trustorder_test']['http'][1]['args']['headers']['Authorization'];
		$this->assertSame( 'Bearer ' . self::LIVE_KEY, $auth );
		$this->assertSame( 'live', $this->state( $o )['env'] );
	}

	public function test_env_switch_retracts_in_the_new_env(): void {
		$o = $this->order();
		$this->transition( $o, 'completed' );
		\LightweightPlugins\TrustOrder\Settings::update( [ 'api_key' => self::LIVE_KEY ] );
		$this->transition( $o, 'processing' );

		$this->assertSame( [ 'PUT', 'DELETE' ], array_column( $this->calls(), 0 ) );
		$this->assertSame( 'Bearer ' . self::LIVE_KEY, $GLOBALS['trustorder_test']['http'][1]['args']['headers']['Authorization'] );
		$s = $this->state( $o );
		$this->assertSame( [ null, 'live', SignalState::RETRACTED ], [ $s['outcome'], $s['env'], $s['status'] ] );
	}

	public function test_live_test_live_retract_deletes_the_live_signal(): void {
		\LightweightPlugins\TrustOrder\Settings::update( [ 'api_key' => self::LIVE_KEY ] );
		$o = $this->order();
		$this->transition( $o, 'completed' );               // PUT live.
		\LightweightPlugins\TrustOrder\Settings::update( [ 'api_key' => self::TEST_KEY ] );
		$this->transition( $o, 'refunded' );
		$this->transition( $o, 'completed' );               // PUT test (the meta now says test).
		\LightweightPlugins\TrustOrder\Settings::update( [ 'api_key' => self::LIVE_KEY ] );
		$this->transition( $o, 'processing' );              // Must DELETE in live.

		$this->assertSame( [ 'PUT', 'PUT', 'DELETE' ], array_column( $this->calls(), 0 ) );
		$auth = array_map( static fn( $c ) => $c['args']['headers']['Authorization'], $GLOBALS['trustorder_test']['http'] );
		$this->assertSame( [ 'Bearer ' . self::LIVE_KEY, 'Bearer ' . self::TEST_KEY, 'Bearer ' . self::LIVE_KEY ], $auth );
		$this->assertSame( 'live', $this->state( $o )['env'] );
	}

	public function test_live_test_live_with_a_retract_in_test(): void {
		\LightweightPlugins\TrustOrder\Settings::update( [ 'api_key' => self::LIVE_KEY ] );
		$o = $this->order();
		$this->transition( $o, 'completed' );               // PUT live.
		\LightweightPlugins\TrustOrder\Settings::update( [ 'api_key' => self::TEST_KEY ] );
		$this->answers = [ self::problem( 404, 'NOT_FOUND' ) ];
		$this->transition( $o, 'processing' );              // DELETE test, 404 = fine.
		$this->answers = [];
		\LightweightPlugins\TrustOrder\Settings::update( [ 'api_key' => self::LIVE_KEY ] );
		$this->transition( $o, 'completed' );               // PUT live again.
		$this->transition( $o, 'processing' );              // DELETE live.

		$this->assertSame( [ 'PUT', 'DELETE', 'PUT', 'DELETE' ], array_column( $this->calls(), 0 ) );
		$s = $this->state( $o );
		$this->assertSame( [ null, 'live', SignalState::RETRACTED ], [ $s['outcome'], $s['env'], $s['status'] ] );
	}

	public function test_never_signalled_order_does_not_delete_after_a_switch(): void {
		$o = $this->order( [ 'status' => 'completed' ] );
		\LightweightPlugins\TrustOrder\Settings::update( [ 'api_key' => self::LIVE_KEY ] );
		$this->transition( $o, 'processing' );

		$this->assertSame( [], $this->calls() );
	}

	public function test_refused_occurred_at_ignores_an_old_completion_date(): void {
		$o = $this->order();
		$this->transition( $o, 'to-refused' );

		$body = $this->calls()[0][2];
		$this->assertSame( 'refused', $body['outcome'] );
		$this->assertSame( '2026-10-02T11:00:00Z', $body['occurred_at'], 'date_modified, not date_completed' );
	}

	public function test_refused_occurred_at_is_capped_at_now(): void {
		$o = $this->order( [ 'date_modified' => new \DateTimeImmutable( '+1 day' ) ] );
		$this->transition( $o, 'to-refused' );

		$this->assertLessThanOrEqual( time(), strtotime( $this->calls()[0][2]['occurred_at'] ) );
	}

	public function test_voided_is_final(): void {
		$o             = $this->order();
		$this->answers = [ self::http( 200, [ 'result' => 'voided' ] ) ];
		$this->transition( $o, 'to-refused' );
		$this->transition( $o, 'completed' );
		$this->transition( $o, 'processing' );

		$this->assertCount( 1, $this->calls() );
		$this->assertSame( SignalState::VOIDED, $this->state( $o )['status'] );
	}

	public function test_delete_409_marks_voided(): void {
		$o = $this->order();
		$this->transition( $o, 'completed' );
		$this->answers = [ self::problem( 409, 'INVALID_REQUEST' ) ];
		$this->transition( $o, 'processing' );

		$this->assertSame( SignalState::VOIDED, $this->state( $o )['status'] );
		$this->assertSame( [], $this->pending() );
	}

	public function test_handle_ignores_untrusted_args(): void {
		$this->order( [ 'status' => 'completed' ] );
		SignalJob::handle( [ 'x' ] );
		SignalJob::handle( '42; DROP' );
		SignalJob::handle( -1 );

		$this->assertSame( [], $this->calls() );
		SignalJob::handle( '42' );
		$this->assertCount( 1, $this->calls() );
	}

	public function test_router_is_wired_with_four_args(): void {
		$this->assertNotFalse( has_action( 'woocommerce_order_status_changed', [ SignalRouter::class, 'onStatusChanged' ] ) );
		$this->assertNotFalse( has_action( SignalRouter::HOOK, [ SignalJob::class, 'handle' ] ) );
		$this->assertSame( 4, $GLOBALS['trustorder_test']['actions']['woocommerce_order_status_changed'][10][0][1] );
	}
}
