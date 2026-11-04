<?php
/**
 * Schedules the geo job when an order first gets paid or put on hold.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Geo;

use LightweightPlugins\TrustOrder\Api\ApiKey;
use LightweightPlugins\TrustOrder\Log;
use LightweightPlugins\TrustOrder\Settings;

/**
 * Decided once per order, on its first entry into a sending status (coming
 * from any other status): `_trustorder_geo_decided` is set whatever the
 * setting is, and one Action Scheduler job (`trustorder_geo`, args
 * `[order_id]`, group `trustorder`) is scheduled only when `share_geo` is on.
 * Nothing is ever sent retroactively: an order that was decided while the
 * setting was off, or that was already past its first entry, is never sent.
 * The job re-checks the setting when it runs.
 */
final class GeoRouter {

	public const HOOK  = 'trustorder_geo';
	public const GROUP = 'trustorder';

	/**
	 * Statuses that send the geo (the first one the order reaches).
	 */
	public const STATUSES = [ 'processing', 'on-hold', 'completed' ];

	/**
	 * Hook in.
	 */
	public static function register(): void {
		// includes/class-wc-order.php:478, 4 args, statuses without `wc-` (WC 11.2.1).
		add_action( 'woocommerce_order_status_changed', [ self::class, 'onStatusChanged' ], 10, 4 );
		add_action( self::HOOK, [ GeoJob::class, 'handle' ] );
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
			$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );
			if ( ! $order instanceof \WC_Order || ! self::isFirstEntry( (string) $from, (string) $to, $order ) ) {
				return;
			}
			$order->update_meta_data( GeoJob::DECIDED_META, '1' );
			$order->save_meta_data();
			if ( self::sharing() ) {
				self::schedule( $order->get_id() );
			}
		} catch ( \Throwable $e ) {
			Log::error( 'geo', [ 'code' => 'EXCEPTION' ] );
		}
	}

	/**
	 * Whether this change is the order's first entry into a sending status.
	 *
	 * @param string    $from  Old status.
	 * @param string    $to    New status.
	 * @param \WC_Order $order Order.
	 */
	public static function isFirstEntry( string $from, string $to, \WC_Order $order ): bool {
		return in_array( $to, self::STATUSES, true )
			&& ! in_array( $from, self::STATUSES, true )
			&& '1' !== $order->get_meta( GeoJob::DECIDED_META, true );
	}

	/**
	 * Whether sharing is on with a valid key.
	 */
	private static function sharing(): bool {
		$settings = Settings::get();

		return $settings['share_geo'] && null !== ApiKey::env( $settings['api_key'] );
	}

	/**
	 * Schedule the job unless one is already pending for the order (a running
	 * one does not block, so a job can schedule its own retry).
	 *
	 * @param int $order_id Order id.
	 * @param int $delay   Seconds from now.
	 */
	public static function schedule( int $order_id, int $delay = 0 ): bool {
		if ( $order_id <= 0 || ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}
		$args = [ $order_id ];
		if ( as_has_scheduled_action( self::HOOK, $args, self::GROUP ) && self::hasPending( $args ) ) {
			return false;
		}

		return as_schedule_single_action( time() + max( 0, $delay ), self::HOOK, $args, self::GROUP, false ) > 0;
	}

	/**
	 * Whether a pending (not running) action exists for these args; true when
	 * it cannot be told.
	 *
	 * @param array<int, int> $args Action args.
	 */
	private static function hasPending( array $args ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return true;
		}
		$actions = as_get_scheduled_actions(
			[
				'hook'     => self::HOOK,
				'args'     => $args,
				'group'    => self::GROUP,
				'status'   => 'pending',
				'per_page' => 1,
			],
			'ids'
		);

		return ! is_array( $actions ) || [] !== $actions;
	}
}
