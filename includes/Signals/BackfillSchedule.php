<?php
/**
 * Action Scheduler side of the backfill.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Signals;

/**
 * One chained `trustorder_backfill` action at a time.
 */
final class BackfillSchedule {

	/**
	 * Chain the next tick (the running tick itself is not "pending").
	 *
	 * @param int $delay Seconds.
	 */
	public static function next( int $delay ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, Backfill::HOOK, [], SignalRouter::GROUP, false );
		}
	}

	/**
	 * Chain a tick unless one is already pending or running.
	 *
	 * @param int $delay Seconds.
	 */
	public static function ensure( int $delay ): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( Backfill::HOOK, [], SignalRouter::GROUP ) ) {
			self::next( $delay );
		}
	}

	/**
	 * Drop the pending ticks (a synchronous runner takes over).
	 */
	public static function cancel(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Backfill::HOOK, [], SignalRouter::GROUP );
		}
	}
}
