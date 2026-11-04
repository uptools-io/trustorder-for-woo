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
 * to processing / on-hold / pending retracts the signal. A full refund
 * (`refunded`) retracts a sent delivery; a refusal stays. Cancelled, failed
 * (and trash) change nothing.
 */
final class Outcome {

	public const DELIVERED = 'delivered';
	public const REFUSED   = 'refused';
	public const RETRACT   = 'retract';
	public const REFUND    = 'refund';
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
		if ( 'refunded' === $status ) {
			return self::REFUND;
		}

		return in_array( $status, self::RETRACT_STATUSES, true ) ? self::RETRACT : self::NONE;
	}

	/**
	 * What a refund means for an order's signal state: RETRACT when a delivery
	 * was sent, may have been stored (a pending PUT whose response was lost)
	 * or is being retracted (a pending DELETE retry, which keeps going even
	 * over a refusal: the merchant asked for that retract before the refund).
	 * NONE otherwise: a sent refusal is never withdrawn by a refund, not even
	 * when a delivery PUT over it is pending (that PUT is not sent either).
	 * `outcome` is what the state's env holds; the retract path handles env.
	 *
	 * @param array<string, mixed> $state State from SignalState::read().
	 */
	public static function onRefund( array $state ): string {
		if ( self::DELIVERED === $state['outcome'] ) {
			return self::RETRACT;
		}
		if ( SignalState::PENDING !== $state['status'] ) {
			return self::NONE;
		}
		if ( self::RETRACT === $state['target'] ) {
			return self::RETRACT;
		}

		return self::DELIVERED === $state['target'] && self::REFUSED !== $state['outcome'] ? self::RETRACT : self::NONE;
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
