<?php
/**
 * wp trustorder backfill start|status|run.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\CLI;

use LightweightPlugins\TrustOrder\CLI\BackfillCommand;
use LightweightPlugins\TrustOrder\Settings;
use LightweightPlugins\TrustOrder\Signals\Backfill;
use LightweightPlugins\TrustOrder\Signals\SignalLock;
use WP_CLI;

final class BackfillCommandTest extends CliTestCase {

	private function batchAnswer(): void {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				if ( false !== $pre || ! str_ends_with( (string) $url, '/signals/batch' ) ) {
					return $pre;
				}
				$res = [];
				foreach ( json_decode( (string) $args['body'], true )['signals'] as $r ) {
					$res[] = [ 'order_ref' => $r['order_ref'], 'result' => 'stored' ];
				}
				return self::http( 200, [ 'results' => $res ] );
			},
			5,
			3
		);
	}

	private function orders( int $n ): void {
		for ( $i = 1; $i <= $n; $i++ ) {
			$this->order( [ 'id' => $i, 'status' => 'completed', 'created_ts' => time() - 10 * DAY_IN_SECONDS ] );
		}
	}

	private function batches(): int {
		return count( array_filter( $this->calls(), static fn( array $c ): bool => '/signals/batch' === $c[1] ) );
	}

	public function test_start_runs_the_shared_start_for_the_key_env(): void {
		$this->assertSame( 0, $this->exec( fn() => ( new BackfillCommand() )->start( [], [] ) ) );

		$this->assertTrue( Backfill::status( 'test' )['running'] );
		$this->assertFalse( Backfill::status( 'live' )['running'] );
		$this->assertCount( 1, $this->pending( Backfill::HOOK ) );
		$this->assertSame( [ 'live' => 'no', 'test' => 'yes' ], array_column( $this->table(), 'running', 'env' ) );
	}

	public function test_start_accepts_the_matching_env_only(): void {
		$this->assertSame( 1, $this->exec( fn() => ( new BackfillCommand() )->start( [], [ 'env' => 'live' ] ) ) );
		$this->assertFalse( Backfill::status( 'live' )['running'] );
		$this->assertFalse( Backfill::status( 'test' )['running'] );
		$this->assertSame( 1, $this->exec( fn() => ( new BackfillCommand() )->start( [], [ 'env' => 'staging' ] ) ) );
		$this->assertSame( [], $this->pending( Backfill::HOOK ) );

		$this->assertSame( 0, $this->exec( fn() => ( new BackfillCommand() )->start( [], [ 'env' => 'test' ] ) ) );
		$this->assertTrue( Backfill::status( 'test' )['running'] );
	}

	public function test_start_without_a_key_fails(): void {
		Settings::update( [ 'api_key' => '' ] );

		$this->assertSame( 1, $this->exec( fn() => ( new BackfillCommand() )->start( [], [] ) ) );
		$this->assertSame( [], $this->pending( Backfill::HOOK ) );
	}

	public function test_status_shows_both_envs(): void {
		$this->assertSame( 0, $this->exec( fn() => ( new BackfillCommand() )->status( [], [] ) ) );
		$this->assertSame( [ 'live', 'test' ], array_column( $this->table(), 'env' ) );

		$this->assertSame( 0, $this->exec( fn() => ( new BackfillCommand() )->status( [], [ 'format' => 'json' ] ) ) );
		$data = $this->json();
		$this->assertSame( 'test', $data['env'] );
		$this->assertSame( Backfill::status( 'live' ), $data['live'] );
	}

	public function test_run_processes_every_page_synchronously_with_a_progress_bar(): void {
		$this->batchAnswer();
		$this->orders( 450 );
		( new BackfillCommand() )->start( [], [] );

		$this->assertSame( 0, $this->exec( fn() => ( new BackfillCommand() )->run( [], [] ) ) );

		$s = Backfill::status( 'test' );
		$this->assertFalse( $s['running'] );
		$this->assertNotNull( $s['finished_at'] );
		$this->assertSame( [ 450, 450, 0 ], [ $s['processed'], $s['sent'], $s['failed'] ] );
		$this->assertSame( 3, $this->batches() );
		$this->assertSame( [], $this->pending( Backfill::HOOK ), 'no background tick is left over' );
		$this->assertCount( 1, WP_CLI::$bars );
		$this->assertSame( 450, WP_CLI::$bars[0]->total );
		$this->assertSame( 450, WP_CLI::$bars[0]->ticks );
		$this->assertTrue( WP_CLI::$bars[0]->finished );
		$this->assertNotSame( [], WP_CLI::$successes );
	}

	public function test_run_takes_over_from_the_background_tick(): void {
		$this->batchAnswer();
		$this->orders( 10 );
		( new BackfillCommand() )->start( [], [] );
		$this->assertCount( 1, $this->pending( Backfill::HOOK ) );

		$this->exec( fn() => ( new BackfillCommand() )->run( [], [] ) );
		$this->drain(); // The Action Scheduler runner arrives late: nothing is left to do.

		$this->assertSame( 1, $this->batches() );
		$this->assertSame( 10, Backfill::status( 'test' )['sent'] );
	}

	public function test_run_with_a_limit_pauses_after_the_page_that_reaches_it(): void {
		$this->batchAnswer();
		$this->orders( 450 );
		( new BackfillCommand() )->start( [], [] );

		$this->assertSame( 0, $this->exec( fn() => ( new BackfillCommand() )->run( [], [ 'limit' => '200' ] ) ) );

		$s = Backfill::status( 'test' );
		$this->assertSame( 200, $s['processed'] );
		$this->assertFalse( $s['running'] );
		$this->assertNull( $s['finished_at'], 'paused, not finished' );
		$this->assertSame( 1, $this->batches() );
		$this->assertSame( [], $this->pending( Backfill::HOOK ) );
		$this->assertSame( 200, WP_CLI::$bars[0]->total );

		( new BackfillCommand() )->start( [], [] );
		$this->exec( fn() => ( new BackfillCommand() )->run( [], [] ) );
		$this->assertSame( [ 450, 450 ], [ Backfill::status( 'test' )['processed'], Backfill::status( 'test' )['sent'] ] );
		$this->assertSame( 3, $this->batches(), 'resumed from the cursor: no page twice' );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function badLimits(): array {
		return [ 'zero' => [ '0' ], 'negative' => [ '-5' ], 'text' => [ 'many' ], 'float' => [ '1.5' ], 'array' => [ [ 3 ] ] ];
	}

	/**
	 * @dataProvider badLimits
	 * @param mixed $limit Value.
	 */
	public function test_run_validates_the_limit_before_touching_anything( $limit ): void {
		$this->batchAnswer();
		$this->orders( 5 );
		( new BackfillCommand() )->start( [], [] );

		$this->assertSame( 1, $this->exec( fn() => ( new BackfillCommand() )->run( [], [ 'limit' => $limit ] ) ) );

		$this->assertSame( [], $this->calls() );
		$this->assertTrue( Backfill::status( 'test' )['running'] );
		$this->assertCount( 1, $this->pending( Backfill::HOOK ) );
	}

	public function test_run_needs_a_started_backfill_and_a_key(): void {
		$this->assertSame( 1, $this->exec( fn() => ( new BackfillCommand() )->run( [], [] ) ) );
		$this->assertSame( [], $this->calls() );

		Settings::update( [ 'api_key' => '' ] );
		$this->assertSame( 1, $this->exec( fn() => ( new BackfillCommand() )->run( [], [] ) ) );
	}

	public function test_run_leaves_a_locked_order_to_its_signal_job(): void {
		$this->batchAnswer();
		$this->orders( 3 );
		$this->assertTrue( SignalLock::acquire( 2 ) );
		( new BackfillCommand() )->start( [], [] );

		$this->exec( fn() => ( new BackfillCommand() )->run( [], [] ) );

		$rows = array_column( $this->calls()[0][2]['signals'], 'order_ref' );
		$this->assertSame( [ '1', '3' ], $rows );
		$this->assertArrayHasKey( 'trustorder_lock_2', $GLOBALS['trustorder_test']['options'], 'not ours to release' );
	}

	public function test_run_stops_with_the_error_code_when_the_service_refuses(): void {
		$this->answers = [ self::problem( 401, 'UNAUTHORIZED' ) ];
		$this->orders( 5 );
		( new BackfillCommand() )->start( [], [] );

		$this->assertSame( 1, $this->exec( fn() => ( new BackfillCommand() )->run( [], [] ) ) );

		$this->assertSame( 1, count( $this->calls() ) );
		$this->assertFalse( Backfill::status( 'test' )['running'] );
		$this->assertSame( 0, Backfill::status( 'test' )['processed'] );
		$this->assertStringNotContainsString( self::TEST_KEY, $this->output() );
		$this->assertSame( [], $this->pending( Backfill::HOOK ) );
	}

	public function test_run_hands_a_backoff_back_to_the_background_runner(): void {
		$this->answers = [ self::problem( 503, 'UNAVAILABLE' ) ];
		$this->orders( 5 );
		( new BackfillCommand() )->start( [], [] );

		$this->assertSame( 0, $this->exec( fn() => ( new BackfillCommand() )->run( [], [] ) ) );

		$this->assertSame( 1, count( $this->calls() ), 'no hammering a service that is down' );
		$this->assertTrue( Backfill::status( 'test' )['running'] );
		$this->assertCount( 1, $this->pending( Backfill::HOOK ) );
		$this->assertNotSame( [], WP_CLI::$warnings );
	}

	public function test_run_reports_a_concurrent_restart_as_restarted_not_stopped(): void {
		$this->batchAnswer();
		$this->orders( 450 );
		( new BackfillCommand() )->start( [], [] );
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				if ( false === $pre && str_ends_with( (string) $url, '/signals/batch' ) ) {
					// Another process finishes and restarts the run while this page is in flight.
					$s                = get_option( 'trustorder_backfill_test' );
					$s['started_at']  = '2026-11-03T23:59:59Z';
					update_option( 'trustorder_backfill_test', $s, false );
				}
				return $pre;
			},
			4,
			3
		);

		$this->assertSame( 1, $this->exec( fn() => ( new BackfillCommand() )->run( [], [] ) ) );

		$this->assertSame( [ 'BACKFILL_RESTARTED' ], WP_CLI::$errors );
	}
}
