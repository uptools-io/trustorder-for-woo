<?php
/**
 * Backfill: paging, resume, age cut-off, per-row results, per-env progress.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Signals;

use LightweightPlugins\TrustOrder\Settings;
use LightweightPlugins\TrustOrder\Signals\Backfill;
use LightweightPlugins\TrustOrder\Signals\OrderPager;
use LightweightPlugins\TrustOrder\Signals\SignalLock;
use LightweightPlugins\TrustOrder\Signals\SignalState;

final class BackfillTest extends SignalTestCase {

	/**
	 * Batch answer: every row `stored`, or per-ref overrides.
	 *
	 * @param array<string, array<string, string>> $over order_ref => row.
	 */
	private function batchAnswer( array $over = [] ): void {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $over ) {
				if ( false !== $pre || ! str_ends_with( (string) $url, '/signals/batch' ) ) {
					return $pre;
				}
				$rows = json_decode( (string) $args['body'], true )['signals'];
				$res  = [];
				foreach ( $rows as $r ) {
					$res[] = $over[ $r['order_ref'] ] ?? [ 'order_ref' => $r['order_ref'], 'result' => 'stored' ];
				}
				return self::http( 200, [ 'results' => $res ] );
			},
			5,
			3
		);
	}

	/**
	 * Create orders 1..$n with a status.
	 */
	private function orders( int $n, string $status = 'completed', int $from = 1 ): void {
		for ( $i = $from; $i < $from + $n; $i++ ) {
			$this->order( [ 'id' => $i, 'status' => $status, 'created_ts' => time() - 10 * DAY_IN_SECONDS ] );
		}
	}

	/**
	 * Batch calls: list of row lists.
	 *
	 * @return list<list<array<string, string>>>
	 */
	private function batches(): array {
		$out = [];
		foreach ( $this->calls() as $c ) {
			if ( '/signals/batch' === $c[1] ) {
				$out[] = $c[2]['signals'];
			}
		}
		return $out;
	}

	public function test_status_shape_before_start(): void {
		$this->assertSame(
			[ 'running' => false, 'processed' => 0, 'sent' => 0, 'failed' => 0, 'cursor' => 0, 'started_at' => null, 'finished_at' => null ],
			Backfill::status( 'test' )
		);
	}

	public function test_pages_of_200_by_id_until_done(): void {
		$this->batchAnswer();
		$this->orders( 450 );

		Backfill::start( 'test' );
		$this->assertCount( 1, $this->pending( Backfill::HOOK ) );
		$this->assertTrue( Backfill::status( 'test' )['running'] );
		$this->drain();

		$b = $this->batches();
		$this->assertSame( [ 200, 200, 50 ], array_map( 'count', $b ) );
		$this->assertSame( '1', $b[0][0]['order_ref'] );
		$this->assertSame( '201', $b[1][0]['order_ref'] );
		$this->assertSame( 'delivered', $b[0][0]['outcome'] );
		$this->assertArrayNotHasKey( 'email_domain_hashes', $b[0][0] );
		$s = Backfill::status( 'test' );
		$this->assertSame( [ false, 450, 450, 0, 450 ], [ $s['running'], $s['processed'], $s['sent'], $s['failed'], $s['cursor'] ] );
		$this->assertNotNull( $s['finished_at'] );
		$this->assertSame( SignalState::SENT, $this->state( $GLOBALS['trustorder_test']['orders'][7] )['status'] );
		foreach ( $GLOBALS['trustorder_test']['order_queries'] as $q ) {
			$this->assertSame( 200, $q['limit'] );
			$this->assertSame( 'ID', $q['orderby'] );
			$this->assertSame( 'ASC', $q['order'] );
			$this->assertSame( [ 'wc-completed', 'wc-to-refused' ], $q['status'] );
			$this->assertSame( 'shop_order', $q['type'] );
		}
		$this->assertFalse( get_option( 'trustorder_backfill_test' ) === false );
		$this->assertFalse( $GLOBALS['trustorder_test']['autoload']['trustorder_backfill_test'] ?? false );
	}

	public function test_only_completed_and_refused_and_the_right_outcome(): void {
		$this->batchAnswer();
		$this->orders( 2, 'completed', 1 );
		$this->orders( 2, 'to-refused', 3 );
		$this->orders( 2, 'processing', 5 );
		$this->orders( 1, 'refunded', 7 );

		Backfill::start( 'test' );
		$this->drain();

		$rows = $this->batches()[0];
		$this->assertSame( [ '1', '2', '3', '4' ], array_column( $rows, 'order_ref' ) );
		$this->assertSame( [ 'delivered', 'delivered', 'refused', 'refused' ], array_column( $rows, 'outcome' ) );
	}

	public function test_age_cut_off_is_1095_days(): void {
		$this->batchAnswer();
		$this->order( [ 'id' => 1, 'status' => 'completed', 'created_ts' => time() - 1096 * DAY_IN_SECONDS ] );
		$this->order( [ 'id' => 2, 'status' => 'completed', 'created_ts' => time() - 1094 * DAY_IN_SECONDS ] );

		Backfill::start( 'test' );
		$this->drain();

		$this->assertSame( [ '2' ], array_column( $this->batches()[0], 'order_ref' ) );
		$since = (int) substr( $GLOBALS['trustorder_test']['order_queries'][0]['date_created'], 2 );
		$this->assertEqualsWithDelta( time() - 1095 * DAY_IN_SECONDS, $since, 5 );
		$this->assertStringStartsWith( '>=', $GLOBALS['trustorder_test']['order_queries'][0]['date_created'] );
	}

	public function test_already_sent_and_voided_orders_are_not_resent(): void {
		$this->batchAnswer();
		$this->orders( 3 );
		$o1 = $GLOBALS['trustorder_test']['orders'][1];
		$o2 = $GLOBALS['trustorder_test']['orders'][2];
		SignalState::write( $o1, [ 'outcome' => 'delivered', 'env' => 'test', 'status' => 'sent' ] );
		SignalState::write( $o2, [ 'outcome' => 'refused', 'env' => 'test', 'status' => 'voided' ] );

		Backfill::start( 'test' );
		$this->drain();

		$this->assertSame( [ '3' ], array_column( $this->batches()[0], 'order_ref' ) );
		$this->assertSame( 3, Backfill::status( 'test' )['processed'] );
		$this->assertSame( 1, Backfill::status( 'test' )['sent'] );
	}

	public function test_row_results_voided_final_invalid_failed(): void {
		$this->batchAnswer(
			[
				'2' => [ 'order_ref' => '2', 'result' => 'voided' ],
				'3' => [ 'order_ref' => '3', 'error_code' => 'INVALID_REQUEST' ],
			]
		);
		$this->orders( 3 );

		Backfill::start( 'test' );
		$this->drain();

		$orders = $GLOBALS['trustorder_test']['orders'];
		$this->assertSame( SignalState::SENT, $this->state( $orders[1] )['status'] );
		$this->assertSame( SignalState::VOIDED, $this->state( $orders[2] )['status'] );
		$this->assertSame( SignalState::FAILED, $this->state( $orders[3] )['status'] );
		$this->assertSame( 'INVALID_REQUEST', $this->state( $orders[3] )['error'] );
		$s = Backfill::status( 'test' );
		$this->assertSame( [ 3, 1, 1 ], [ $s['processed'], $s['sent'], $s['failed'] ] );

		// A second run resends only the failed one, never the voided one.
		$GLOBALS['trustorder_test']['http'] = [];
		Backfill::start( 'test' );
		$this->drain();
		$this->assertSame( [ '3' ], array_column( $this->batches()[0], 'order_ref' ) );
	}

	public function test_internal_row_stops_the_page_there_and_resumes(): void {
		$first = true;
		add_filter(
			'pre_http_request',
			static function ( $pre, $args ) use ( &$first ) {
				$rows = json_decode( (string) $args['body'], true )['signals'];
				$res  = [];
				foreach ( $rows as $r ) {
					$internal = $first && (int) $r['order_ref'] >= 3;
					$res[]    = $internal ? [ 'order_ref' => $r['order_ref'], 'error_code' => 'INTERNAL' ] : [ 'order_ref' => $r['order_ref'], 'result' => 'stored' ];
				}
				$first = false;
				return self::http( 200, [ 'results' => $res ] );
			},
			5,
			2
		);
		$this->orders( 5 );

		Backfill::start( 'test' );
		$this->drain();

		$b = $this->batches();
		$this->assertSame( [ '1', '2', '3', '4', '5' ], array_column( $b[0], 'order_ref' ) );
		$this->assertSame( [ '3', '4', '5' ], array_column( $b[1], 'order_ref' ) );
		$s = Backfill::status( 'test' );
		$this->assertSame( [ false, 5, 5, 0, 5 ], [ $s['running'], $s['processed'], $s['sent'], $s['failed'], $s['cursor'] ] );
	}

	public function test_outage_retries_with_backoff_then_stops_and_resumes_from_the_cursor(): void {
		$this->batchAnswer();
		$this->orders( 250 );
		Backfill::start( 'test' );
		// First page goes through.
		$a = $this->pending( Backfill::HOOK )[0];
		$GLOBALS['trustorder_test']['as'][ array_key_last( $GLOBALS['trustorder_test']['as'] ) ]['status'] = 'complete';
		do_action( $a['hook'] );
		$this->assertSame( 200, Backfill::status( 'test' )['cursor'] );

		// Now the API is down.
		add_filter( 'pre_http_request', static fn() => self::problem( 503, 'INTERNAL' ), 1 );
		$delays = [];
		for ( $i = 0; $i < 6; $i++ ) {
			$p = $this->pending( Backfill::HOOK );
			if ( [] === $p ) {
				break;
			}
			foreach ( $GLOBALS['trustorder_test']['as'] as $k => $x ) {
				if ( 'pending' === $x['status'] ) {
					$GLOBALS['trustorder_test']['as'][ $k ]['status'] = 'complete';
				}
			}
			$now = time();
			do_action( Backfill::HOOK );
			$n = $this->pending( Backfill::HOOK );
			if ( [] !== $n ) {
				$delays[] = (int) round( ( $n[0]['time'] - $now ) / 60 );
			}
		}
		$this->assertSame( [ 1, 5, 30, 120 ], $delays );
		$s = Backfill::status( 'test' );
		$this->assertFalse( $s['running'] );
		$this->assertNull( $s['finished_at'] );
		$this->assertSame( 200, $s['cursor'] );

		// Back up: start() resumes from the cursor, counters kept.
		remove_all_filters_for_test( 'pre_http_request' );
		$this->batchAnswer();
		$GLOBALS['trustorder_test']['http'] = [];
		Backfill::start( 'test' );
		$this->drain();
		$this->assertSame( '201', $this->batches()[0][0]['order_ref'] );
		$s = Backfill::status( 'test' );
		$this->assertSame( [ 250, 250, 250 ], [ $s['processed'], $s['sent'], $s['cursor'] ] );
	}

	public function test_key_error_stops_without_advancing(): void {
		add_filter( 'pre_http_request', static fn() => self::problem( 401, 'UNAUTHORIZED' ), 1 );
		$this->orders( 3 );
		Backfill::start( 'test' );
		$this->drain();

		$s = Backfill::status( 'test' );
		$this->assertSame( [ false, 0, 0 ], [ $s['running'], $s['processed'], $s['cursor'] ] );
		$this->assertCount( 1, $this->batches() );
		$this->assertStringContainsString( 'UNAUTHORIZED', $this->logged() );
	}

	public function test_no_email_orders_are_skipped_without_a_row(): void {
		$this->batchAnswer();
		$this->orders( 2 );
		$GLOBALS['trustorder_test']['orders'][2]->set_billing_email( '' );

		Backfill::start( 'test' );
		$this->drain();

		$this->assertSame( [ '1' ], array_column( $this->batches()[0], 'order_ref' ) );
		$this->assertSame( SignalState::SKIPPED, $this->state( $GLOBALS['trustorder_test']['orders'][2] )['status'] );
		$this->assertSame( 2, Backfill::status( 'test' )['processed'] );
	}

	public function test_progress_is_per_env_and_only_for_the_current_key(): void {
		$this->batchAnswer();
		$this->orders( 2 );

		Backfill::start( 'live' ); // The key is a test key: ignored.
		$this->assertSame( [], $this->pending( Backfill::HOOK ) );
		$this->assertFalse( Backfill::status( 'live' )['running'] );

		Backfill::start( 'test' );
		$this->drain();
		$this->assertSame( 2, Backfill::status( 'test' )['sent'] );
		$this->assertSame( 0, Backfill::status( 'live' )['sent'] );

		Settings::update( [ 'api_key' => self::LIVE_KEY ] );
		$GLOBALS['trustorder_test']['http'] = [];
		Backfill::start( 'live' );
		$this->drain();
		$this->assertCount( 2, $this->batches()[0], 'live gets its own copy' );
		$this->assertSame( 2, Backfill::status( 'live' )['sent'] );
		$this->assertSame( 'Bearer ' . self::LIVE_KEY, $GLOBALS['trustorder_test']['http'][0]['args']['headers']['Authorization'] );
	}

	public function test_a_tick_after_a_key_switch_does_not_touch_the_other_env(): void {
		$this->batchAnswer();
		$this->orders( 250 );
		Backfill::start( 'test' );
		Settings::update( [ 'api_key' => self::LIVE_KEY ] );
		$this->drain();

		$this->assertSame( [], $this->batches() );
		$this->assertTrue( Backfill::status( 'test' )['running'], 'paused, resumable' );
		$this->assertSame( 0, Backfill::status( 'live' )['processed'] );
	}

	public function test_one_tick_action_at_a_time(): void {
		$this->batchAnswer();
		$this->orders( 3 );
		Backfill::start( 'test' );
		Backfill::start( 'test' );
		Backfill::start( 'test' );

		$this->assertCount( 1, $this->pending( Backfill::HOOK ) );
		$this->assertSame( [], $this->pending( Backfill::HOOK )[0]['args'] );
		$this->assertSame( 'trustorder', $this->pending( Backfill::HOOK )[0]['group'] );
	}

	public function test_finished_run_restarts_from_zero_but_skips_sent(): void {
		$this->batchAnswer();
		$this->orders( 2 );
		Backfill::start( 'test' );
		$this->drain();
		$GLOBALS['trustorder_test']['http'] = [];
		$this->orders( 1, 'completed', 3 );

		Backfill::start( 'test' );
		$this->drain();

		$this->assertSame( [ '3' ], array_column( $this->batches()[0], 'order_ref' ) );
		$s = Backfill::status( 'test' );
		$this->assertSame( [ 3, 1, 3 ], [ $s['processed'], $s['sent'], $s['cursor'] ] );
	}

	public function test_legacy_store_uses_the_posts_where_cursor(): void {
		$GLOBALS['trustorder_test']['hpos'] = false;
		$GLOBALS['wpdb']                    = new class() {
			/** @var string */
			public $posts = 'wp_posts';
			public function prepare( string $q, ...$args ): string {
				return vsprintf( $q, $args );
			}
		};
		$this->orders( 5 );

		$page = OrderPager::page( 3, 200, 0 );

		$this->assertSame( [ 4, 5 ], array_map( static fn( $o ) => $o->get_id(), $page ) );
		$q = $GLOBALS['trustorder_test']['order_queries'][0];
		$this->assertArrayNotHasKey( 'field_query', $q );
		$this->assertSame( 3, $q[ OrderPager::QUERY_VAR ] );
		$this->assertSame( ' AND wp_posts.ID > 3', OrderPager::postsWhere( '', new \TrustOrder_Test_WP_Query( [ OrderPager::QUERY_VAR => 3 ] ) ) );
		$this->assertSame( 'x', OrderPager::postsWhere( 'x', new \TrustOrder_Test_WP_Query( [] ) ), 'other queries untouched' );
		unset( $GLOBALS['wpdb'] );
	}

	public function test_hpos_store_uses_a_field_query(): void {
		$this->orders( 5 );
		$page = OrderPager::page( 3, 200, 0 );

		$this->assertSame( [ 4, 5 ], array_map( static fn( $o ) => $o->get_id(), $page ) );
		$this->assertSame( [ [ 'field' => 'id', 'value' => 3, 'compare' => '>', 'type' => 'NUMERIC' ] ], $GLOBALS['trustorder_test']['order_queries'][0]['field_query'] );
	}

	/**
	 * Run hook during the batch HTTP call.
	 */
	private function duringBatch( callable $fn ): void {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $fn ) {
				if ( str_ends_with( (string) $url, '/signals/batch' ) ) {
					$fn();
				}
				return $pre;
			},
			1,
			3
		);
	}

	public function test_status_change_during_the_call_is_recorded_and_reconciled(): void {
		$this->batchAnswer();
		$this->orders( 3 );
		$this->duringBatch(
			static function (): void {
				$GLOBALS['trustorder_test']['orders'][2]->set_status( 'processing' );
			}
		);

		Backfill::start( 'test' );
		$this->drain();

		$s = Backfill::status( 'test' );
		$this->assertSame( [ 3, 2 ], [ $s['processed'], $s['sent'] ], 'the changed row is not counted as sent' );
		$calls = $this->calls();
		$this->assertSame( [ 'POST', 'DELETE' ], array_column( $calls, 0 ), 'the signal job withdrew it' );
		$this->assertSame( '/signals/2', $calls[1][1] );
		$this->assertSame( SignalState::RETRACTED, $this->state( $GLOBALS['trustorder_test']['orders'][2] )['status'] );
	}

	public function test_fresh_status_is_read_before_building_rows(): void {
		$this->batchAnswer();
		$this->orders( 2 );
		$stale = $GLOBALS['trustorder_test']['orders'][2];
		$fresh = clone $stale;
		$fresh->set_status( 'to-refused' );
		$GLOBALS['trustorder_test']['orders'][2] = $fresh;
		$page = [ $GLOBALS['trustorder_test']['orders'][1], $stale ];

		$plan = \LightweightPlugins\TrustOrder\Signals\BackfillPlan::build( $page, 'test' );

		$this->assertSame( [ 'delivered', 'refused' ], array_column( $plan['rows'], 'outcome' ) );
		$this->assertSame( [ 1, 2 ], $plan['locked'] );
	}

	public function test_a_locked_order_is_left_to_its_signal_job(): void {
		$this->batchAnswer();
		$this->orders( 3 );
		$this->assertTrue( SignalLock::acquire( 2 ) );

		Backfill::start( 'test' );
		$this->drain();

		$this->assertSame( [ '1', '3' ], array_column( $this->batches()[0], 'order_ref' ) );
		$this->assertArrayHasKey( 'trustorder_lock_2', $GLOBALS['trustorder_test']['options'], 'not ours to release' );
		$this->assertArrayNotHasKey( 'trustorder_lock_1', $GLOBALS['trustorder_test']['options'] );
		$this->assertArrayNotHasKey( 'trustorder_lock_3', $GLOBALS['trustorder_test']['options'] );
	}

	public function test_locks_are_released_when_the_batch_fails(): void {
		add_filter( 'pre_http_request', static fn() => self::problem( 401, 'UNAUTHORIZED' ), 1 );
		$this->orders( 2 );
		Backfill::start( 'test' );
		$this->drain();

		$this->assertSame( [], array_filter( array_keys( $GLOBALS['trustorder_test']['options'] ), static fn( $k ) => str_starts_with( $k, 'trustorder_lock_' ) ) );
	}

	public function test_the_next_tick_is_scheduled_after_the_state_is_saved(): void {
		$this->batchAnswer();
		$this->orders( 250 );
		Backfill::start( 'test' );
		$this->drain();

		$ticks = array_values( array_filter( $GLOBALS['trustorder_test']['as'], static fn( $a ) => Backfill::HOOK === $a['hook'] ) );
		$this->assertCount( 2, $ticks );
		$this->assertSame( 200, $ticks[1]['options_at']['trustorder_backfill_test']['cursor'], 'saved before chaining' );
	}

	public function test_a_restart_during_a_tick_is_not_clobbered(): void {
		$this->batchAnswer();
		$this->orders( 250 );
		Backfill::start( 'test' );
		$this->duringBatch(
			static function (): void {
				$o = get_option( 'trustorder_backfill_test' );
				if ( 0 === $o['cursor'] && '2000-01-01T00:00:00Z' !== $o['started_at'] ) {
					update_option( 'trustorder_backfill_test', [ 'running' => true, 'cursor' => 0, 'started_at' => '2000-01-01T00:00:00Z' ] + $o, false );
				}
			}
		);
		$a = $this->pending( Backfill::HOOK )[0];
		$GLOBALS['trustorder_test']['as'][ array_key_last( $GLOBALS['trustorder_test']['as'] ) ]['status'] = 'complete';
		do_action( $a['hook'] );

		$s = Backfill::status( 'test' );
		$this->assertSame( '2000-01-01T00:00:00Z', $s['started_at'] );
		$this->assertSame( 0, $s['cursor'], 'the restarted run keeps its own cursor' );
		$this->assertSame( [], $this->pending( Backfill::HOOK ), 'no chained tick from the stale run' );
		$this->assertContains( [ 'trustorder_backfill_test', 'options' ], $GLOBALS['trustorder_test']['cache_deletes'] );
	}

	public function test_a_stop_during_a_tick_stays_stopped(): void {
		$this->batchAnswer();
		$this->orders( 250 );
		Backfill::start( 'test' );
		$this->duringBatch(
			static function (): void {
				$o            = get_option( 'trustorder_backfill_test' );
				$o['running'] = false;
				update_option( 'trustorder_backfill_test', $o, false );
			}
		);
		$this->drain();

		$s = Backfill::status( 'test' );
		$this->assertFalse( $s['running'] );
		$this->assertSame( 200, $s['cursor'], 'progress kept' );
		$this->assertSame( [], $this->pending( Backfill::HOOK ) );
	}
}
