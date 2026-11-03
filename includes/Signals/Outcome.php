<?php
/**
 * Order status -> outcome signal mapping.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Signals;

use LightweightPlugins\TrustOrder\Order\RefusedStatus;

/**
 * Every `completed` order is `delivered` (whatever the payment method); a
 * `refused` comes only from the custom „Nem vette át” status. Switching back
 * to processing / on-hold / pending retracts the signal; refunded, cancelled,
 * failed (and trash) change nothing.
 */
final class Outcome {

	public const DELIVERED = 'delivered';
	public const REFUSED   = 'refused';
	public const RETRACT   = 'retract';
	public const NONE      = 'none';

	private const RETRACT_STATUSES = [ 'processing', 'on-hold', 'pending' ];

	/**
	 * Desired signal for an order status (with or without the `wc-` prefix).
	 *
	 * @param string $status Order status.
	 */
	public static function forStatus( string $status ): string {
		$status = str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;

		if ( 'completed' === $status ) {
			return self::DELIVERED;
		}
		if ( RefusedStatus::SLUG === $status ) {
			return self::REFUSED;
		}

		return in_array( $status, self::RETRACT_STATUSES, true ) ? self::RETRACT : self::NONE;
	}

	/**
	 * Whether the outcome is one the API stores (delivered / refused).
	 *
	 * @param string $outcome Outcome.
	 */
	public static function isSignal( string $outcome ): bool {
		return self::DELIVERED === $outcome || self::REFUSED === $outcome;
	}
}
