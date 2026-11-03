<?php
/**
 * Short per-order lock shared by the signal job and the backfill.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Signals;

/**
 * `trustorder_lock_{order_id}` (not autoloaded), value = expiry time.
 * add_option() refuses an existing name, so two writers never send for the
 * same order at once; a lock older than TTL (a crashed writer) is taken over.
 * Approximate under a truly simultaneous insert, like every option-based
 * lock in WordPress; the signal calls are idempotent upserts either way.
 */
final class SignalLock {

	public const PREFIX = 'trustorder_lock_';
	public const TTL    = 60;

	/**
	 * Take the lock.
	 *
	 * @param int $order_id Order id.
	 */
	public static function acquire( int $order_id ): bool {
		$name = self::PREFIX . $order_id;
		if ( add_option( $name, time() + self::TTL, '', false ) ) {
			return true;
		}
		$until = get_option( $name );
		if ( is_numeric( $until ) && (int) $until >= time() ) {
			return false;
		}
		delete_option( $name );

		return add_option( $name, time() + self::TTL, '', false );
	}

	/**
	 * Release the lock.
	 *
	 * @param int $order_id Order id.
	 */
	public static function release( int $order_id ): void {
		delete_option( self::PREFIX . $order_id );
	}
}
