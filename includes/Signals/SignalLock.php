<?php
/**
 * Short per-order lock shared by the signal job and the backfill.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Signals;

/**
 * With a persistent object cache: `lock_{order_id}` in the `trustorder_locks`
 * group via wp_cache_add(), atomic in the cache backend and expiring after
 * TTL (a crashed writer's lock simply expires).
 *
 * Without one, `trustorder_lock_{order_id}` (not autoloaded), value = expiry
 * time. add_option() refuses an existing name, so two writers never send for
 * the same order at once; a lock older than TTL (a crashed writer) is taken
 * over. Approximate under a truly simultaneous insert, like every
 * option-based lock in WordPress; the signal calls are idempotent upserts
 * either way. (The non-persistent cache lives one request only, so it cannot
 * lock across requests: wp-includes/class-wp-object-cache.php:198, WP 7.1.4.)
 */
final class SignalLock {

	public const PREFIX = 'trustorder_lock_';
	public const TTL    = 60;

	public const CACHE_GROUP = 'trustorder_locks';

	/**
	 * Take the lock.
	 *
	 * @param int $order_id Order id.
	 */
	public static function acquire( int $order_id ): bool {
		if ( wp_using_ext_object_cache() ) {
			return (bool) wp_cache_add( 'lock_' . $order_id, time() + self::TTL, self::CACHE_GROUP, self::TTL );
		}
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
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( 'lock_' . $order_id, self::CACHE_GROUP );
			return;
		}
		delete_option( self::PREFIX . $order_id );
	}
}
