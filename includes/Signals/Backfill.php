<?php
/**
 * Backfill of historical outcome signals (POST /signals/batch).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Signals;

use LightweightPlugins\TrustOrder\Api\Client;
use LightweightPlugins\TrustOrder\Api\Response;
use LightweightPlugins\TrustOrder\Log;

/**
 * Completed and „Nem vette át” orders created within 1095 days, by id, 200
 * per tick, one batch call per tick. Progress is per env
 * (`trustorder_backfill_{env}`, not autoloaded) and only for the current
 * key's env. One chained Action Scheduler action at a time; a stopped run
 * (outage, 5 failed attempts, key error) resumes from its cursor on start().
 */
final class Backfill {

	public const HOOK         = 'trustorder_backfill';
	public const PAGE         = 200; // At most 500 rows per batch call (API limit).
	public const MAX_DAYS     = 1095;
	public const MAX_ATTEMPTS = 5;

	/**
	 * Hook in.
	 */
	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'tick' ] );
	}

	/**
	 * Start (or resume) the backfill for the current key's env.
	 *
	 * @param string $env live|test; ignored unless it is the current key's env.
	 */
	public static function start( string $env ): void {
		if ( SignalJob::env() !== $env ) {
			return;
		}
		$s = BackfillState::load( $env );
		if ( ! $s['running'] ) {
			if ( null === $s['started_at'] || null !== $s['finished_at'] ) {
				$s = [
					'processed'  => 0,
					'sent'       => 0,
					'failed'     => 0,
					'cursor'     => 0,
					'started_at' => SignalState::now(),
				] + $s;
			}
			$s['running']     = true;
			$s['finished_at'] = null;
			$s['attempts']    = 0;
			BackfillState::save( $env, $s );
		}
		BackfillSchedule::ensure( 0 );
	}

	/**
	 * Stop a running backfill (resumable from its cursor with start()).
	 *
	 * @param string $env live|test.
	 */
	public static function pause( string $env ): void {
		$s = BackfillState::load( $env );
		if ( $s['running'] ) {
			$s['running'] = false;
			BackfillState::save( $env, $s );
		}
	}

	/**
	 * Progress of one env.
	 *
	 * @param string $env live|test.
	 * @return array{running: bool, processed: int, sent: int, failed: int, cursor: int, started_at: string|null, finished_at: string|null}
	 */
	public static function status( string $env ): array {
		return BackfillState::status( $env );
	}

	/**
	 * One page, then chain the next one. Never throws.
	 */
	public static function tick(): void {
		$delay = self::step();
		if ( null !== $delay ) {
			BackfillSchedule::next( $delay ); // Only after the state is saved.
		}
	}

	/**
	 * One page without scheduling anything (the WP-CLI runner loops on it).
	 * Never throws.
	 *
	 * @return int|null Seconds until the next page is due, null when the run
	 *                  is finished, stopped or there is nothing to do.
	 */
	public static function step(): ?int {
		$env = SignalJob::env();
		if ( null === $env ) {
			return null;
		}
		$s = BackfillState::load( $env );
		if ( ! $s['running'] ) {
			return null;
		}
		try {
			$orders = OrderPager::page( $s['cursor'], self::PAGE, time() - self::MAX_DAYS * DAY_IN_SECONDS );
			$s      = self::process( $env, $s, $orders );
		} catch ( \Throwable $e ) {
			Log::error( 'backfill', [ 'code' => 'EXCEPTION' ] );
			$s = self::retry( $s, false );
		}
		$delay = isset( $s['next'] ) && is_int( $s['next'] ) ? $s['next'] : null;

		return self::merge( $env, $s ) ? $delay : null;
	}

	/**
	 * Save the tick's state over a freshly re-read copy: a run restarted in
	 * the meantime (different started_at) is left alone, and a run stopped
	 * in the meantime stays stopped.
	 *
	 * @param string               $env Env.
	 * @param array<string, mixed> $s   State after the tick.
	 * @return bool Whether the state was saved (and the run continues).
	 */
	private static function merge( string $env, array $s ): bool {
		wp_cache_delete( BackfillState::option( $env ), 'options' );
		$fresh = BackfillState::load( $env );
		if ( $fresh['started_at'] !== $s['started_at'] ) {
			return false;
		}
		$s['running'] = $s['running'] && $fresh['running'];
		BackfillState::save( $env, $s );

		return $s['running'];
	}

	/**
	 * Send one page and record the per-row results.
	 *
	 * @param string                $env    Env.
	 * @param array<string, mixed>  $s      State.
	 * @param array<int, \WC_Order> $orders Page.
	 * @return array<string, mixed>
	 */
	private static function process( string $env, array $s, array $orders ): array {
		$plan = BackfillPlan::build( $orders, $env );
		try {
			return self::send( $env, $s, $orders, $plan );
		} finally {
			foreach ( $plan['locked'] as $id ) {
				SignalLock::release( $id );
			}
		}
	}

	/**
	 * Batch call + per-row results (the plan's orders are locked).
	 *
	 * @param string                $env    Env.
	 * @param array<string, mixed>  $s      State.
	 * @param array<int, \WC_Order> $orders Page.
	 * @param array<string, mixed>  $plan   From BackfillPlan::build().
	 * @return array<string, mixed>
	 */
	private static function send( string $env, array $s, array $orders, array $plan ): array {
		$res = [];
		if ( [] !== $plan['rows'] ) {
			$resp = Client::fromSettings()->batchSignals( $plan['rows'] );
			if ( ! $resp->ok() ) {
				return self::batchFailed( $s, $resp );
			}
			$res = BackfillPlan::results( $resp->data() );
		}

		$stopped = false;
		foreach ( $plan['items'] as $item ) {
			$counted = BackfillPlan::apply( $item, $res, $env );
			if ( null === $counted ) {
				$stopped = true; // INTERNAL: this row and the rest are resent.
				break;
			}
			++$s['processed'];
			if ( '' !== $counted ) {
				++$s[ $counted ];
			}
			$s['cursor'] = $item['order']->get_id();
		}

		if ( $stopped ) {
			return self::retry( $s, true );
		}
		$s['attempts'] = 0;
		if ( count( $orders ) < self::PAGE ) {
			$s['running']     = false;
			$s['finished_at'] = SignalState::now();
			return $s;
		}
		$s['next'] = 0;

		return $s;
	}

	/**
	 * Whole batch failed: retry when retryable, else stop (resumable).
	 *
	 * @param array<string, mixed> $s    State.
	 * @param Response             $resp Response.
	 * @return array<string, mixed>
	 */
	private static function batchFailed( array $s, Response $resp ): array {
		if ( $resp->retryable() ) {
			return self::retry( $s, true );
		}
		Log::error(
			'backfill',
			[
				'code'   => SignalState::errorCode( $resp->errorCode(), $resp->status() ),
				'status' => $resp->status(),
			]
		);
		$s['running'] = false;

		return $s;
	}

	/**
	 * Schedule a retry with backoff, or stop after MAX_ATTEMPTS.
	 *
	 * @param array<string, mixed> $s        State.
	 * @param bool                 $retrying Whether to retry at all.
	 * @return array<string, mixed>
	 */
	private static function retry( array $s, bool $retrying ): array {
		$s['attempts'] = ( isset( $s['attempts'] ) && is_int( $s['attempts'] ) ? $s['attempts'] : 0 ) + 1;
		if ( ! $retrying || $s['attempts'] >= self::MAX_ATTEMPTS ) {
			$s['running'] = false;
			return $s;
		}
		$s['next'] = SignalJob::BACKOFF[ $s['attempts'] - 1 ];

		return $s;
	}
}
