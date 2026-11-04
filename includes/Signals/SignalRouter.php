<?php
/**
 * Schedules the outcome-signal job on order status changes.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Signals;

use LightweightPlugins\TrustOrder\Log;

/**
 * One Action Scheduler job per order (`trustorder_signal`, args `[order_id]`,
 * group `trustorder`); the job reads the order's CURRENT status when it runs,
 * so a ping-pong of status changes collapses into the right final call.
 *
 * Order deletion and trash are deliberately not hooked: a deleted order's
 * signal stays on the backend (it is still a real past outcome, and expires
 * by the backend's retention rule); a job that runs after deletion finds no
 * order and does nothing. Trash/untrash, if WooCommerce reports them as a
 * status change, map to "none" / the restored status.
 */
final class SignalRouter {

	public const HOOK  = 'trustorder_signal';
	public const GROUP = 'trustorder';

	/**
	 * A pending action due later than this (seconds) is replaced by a new status change.
	 */
	public const REPLACE_AFTER = 60;

	/**
	 * Hook in.
	 */
	public static function register(): void {
		// includes/class-wc-order.php:478, 4 args, statuses without `wc-` (WC 11.2.1).
		add_action( 'woocommerce_order_status_changed', [ self::class, 'onStatusChanged' ], 10, 4 );
		add_action( self::HOOK, [ SignalJob::class, 'handle' ] );
	}

	/**
	 * Status changed. Never throws.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $from    Old status.
	 * @param mixed $to      New status.
	 * @param mixed $order   WC_Order.
	 */
	public static function onStatusChanged( $order_id, $from, $to, $order = null ): void {
		try {
			if ( self::shouldSchedule( (string) $from, (string) $to, $order instanceof \WC_Order ? $order : null ) ) {
				self::schedule( (int) $order_id );
			}
		} catch ( \Throwable $e ) {
			Log::error( 'signal', [ 'code' => 'EXCEPTION' ] );
		}
	}

	/**
	 * `→ completed` and `→ to-refused` always; `→ processing|on-hold|pending`
	 * only when a signal may exist (coming from completed / to-refused, or
	 * the order holds a sent signal, e.g. completed → cancelled → processing);
	 * `→ refunded` only when the refund retracts (a delivery, not a refusal).
	 * Everything else (cancelled, failed…) schedules nothing.
	 *
	 * @param string         $from  Old status.
	 * @param string         $to    New status.
	 * @param \WC_Order|null $order Order.
	 */
	public static function shouldSchedule( string $from, string $to, ?\WC_Order $order ): bool {
		$want = Outcome::forStatus( $to );
		if ( Outcome::isSignal( $want ) ) {
			return true;
		}
		if ( Outcome::REFUND === $want ) {
			return null !== $order && Outcome::RETRACT === Outcome::onRefund( SignalState::read( $order ) );
		}
		if ( Outcome::RETRACT !== $want ) {
			return false;
		}
		if ( Outcome::isSignal( Outcome::forStatus( $from ) ) ) {
			return true;
		}

		return null !== $order && null !== SignalState::read( $order )['outcome'];
	}

	/**
	 * Schedule the job unless one is already pending for the order. A job
	 * that is only running (not pending) does not block: it may have read
	 * the status before this change. `$unique` stays false (Action Scheduler
	 * < 4.0 ignores args for uniqueness); the pending check dedups instead.
	 *
	 * @param int $order_id Order id.
	 * @param int $delay   Seconds from now.
	 */
	public static function schedule( int $order_id, int $delay = 0 ): bool {
		if ( $order_id <= 0 || ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}
		$args = [ $order_id ];
		if ( as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			$due = self::pendingTime( $args );
			if ( null !== $due ) {
				// A newer status change must not wait for a far-off retry; the
				// attempts counter lives in the order meta, so it is kept.
				if ( 0 !== $delay || $due <= time() + self::REPLACE_AFTER || ! function_exists( 'as_unschedule_action' ) ) {
					return false;
				}
				as_unschedule_action( self::HOOK, $args, self::GROUP );
			}
		}

		return as_schedule_single_action( time() + max( 0, $delay ), self::HOOK, $args, self::GROUP, false ) > 0;
	}

	/**
	 * When the pending (not running) action for these args is due; null when
	 * there is none, 0 when it cannot be told (treated as due now).
	 *
	 * @param array<int, int> $args Action args.
	 */
	private static function pendingTime( array $args ): ?int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}
		$actions = as_get_scheduled_actions(
			[
				'hook'     => self::HOOK,
				'args'     => $args,
				'group'    => self::GROUP,
				'status'   => 'pending',
				'per_page' => 1,
			]
		);
		if ( ! is_array( $actions ) || [] === $actions ) {
			return null;
		}
		$action   = reset( $actions );
		$schedule = is_object( $action ) && method_exists( $action, 'get_schedule' ) ? $action->get_schedule() : null;
		$date     = is_object( $schedule ) && method_exists( $schedule, 'get_date' ) ? $schedule->get_date() : null;

		return $date instanceof \DateTimeInterface ? $date->getTimestamp() : 0;
	}
}
