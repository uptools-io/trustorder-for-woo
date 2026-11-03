<?php
/**
 * Fixed-window counters for the local check caps.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Checkout;

/**
 * With a persistent object cache (wp_using_ext_object_cache()) the count is
 * atomic (wp_cache_add + wp_cache_incr / wp_cache_decr); a key whose
 * object-cache counter fails uses the transient copy for the rest of the
 * request. Without one it lives in a transient
 * and is approximate under concurrency (read-modify-write): parallel
 * requests can each pass the last free unit.
 *
 * The key is md5(bucket | window | window slot): no raw session id or IP,
 * and a new slot simply starts from zero.
 */
final class Counter {

	public const PREFIX = 'trustorder_q_';
	public const GROUP  = 'trustorder';

	/**
	 * Test clock.
	 *
	 * @var (callable(): int)|null
	 */
	private static $clock = null;

	/**
	 * Storage keys whose object-cache counter failed in this request; they
	 * use the transient copy until the request ends.
	 *
	 * @var array<string, true>
	 */
	private static array $degraded = [];

	/**
	 * Current count of a bucket.
	 *
	 * @param string $bucket    Bucket id.
	 * @param int    $window    Window (seconds).
	 * @param bool   $transient Read the transient copy (object-cache fallback).
	 */
	public static function count( string $bucket, int $window, bool $transient = false ): int {
		return self::read( self::key( $bucket, $window ), $transient );
	}

	/**
	 * Stored value of a key (0 when missing or non-numeric).
	 *
	 * @param string $key       Storage key.
	 * @param bool   $transient Transient copy even with an object cache.
	 */
	private static function read( string $key, bool $transient ): int {
		$v = wp_using_ext_object_cache() && ! $transient ? wp_cache_get( $key, self::GROUP ) : get_transient( $key );

		return is_numeric( $v ) ? (int) $v : 0;
	}

	/**
	 * Add one; returns the new count, or null when the counter is unavailable.
	 *
	 * Object cache: wp_cache_add( 0 ) then wp_cache_incr(), whose return value
	 * is the atomic result. When that gives a non-numeric value (e.g. a Redis
	 * drop-in swallowing an exception), the key falls back to the transient
	 * path for the rest of the request (approximate, but still enforced).
	 * Null only when the transient write fails too (a false set_transient()
	 * whose read-back already holds the new count is a success).
	 *
	 * @param string $bucket Bucket id.
	 * @param int    $window Window (seconds).
	 */
	public static function incr( string $bucket, int $window ): ?int {
		return self::incrKey( self::key( $bucket, $window ), $window );
	}

	/**
	 * Add one to a storage key (see incr()).
	 *
	 * @param string $key       Storage key from key().
	 * @param int    $window    Window (seconds).
	 * @param bool   $transient Out: the transient copy was used; pass it to decrKey().
	 */
	public static function incrKey( string $key, int $window, bool &$transient = false ): ?int {
		$transient = ! wp_using_ext_object_cache() || isset( self::$degraded[ $key ] );
		if ( ! $transient ) {
			wp_cache_add( $key, 0, self::GROUP, $window );
			$v = wp_cache_incr( $key, 1, self::GROUP );
			if ( is_numeric( $v ) ) {
				return (int) $v;
			}
			self::$degraded[ $key ] = true;
			$transient              = true;
		}
		$n = self::read( $key, true ) + 1;
		if ( set_transient( $key, $n, $window ) ) {
			return $n;
		}

		// WP also returns false for an unchanged value (a concurrent request
		// stored the same n): only a lower read-back means the write failed.
		return self::read( $key, true ) >= $n ? $n : null;
	}

	/**
	 * Take one back from the bucket's current slot.
	 *
	 * @param string $bucket Bucket id.
	 * @param int    $window Window (seconds).
	 */
	public static function decr( string $bucket, int $window ): void {
		self::decrKey( self::key( $bucket, $window ), $window );
	}

	/**
	 * Take one back from an exact storage key (the slot and the storage it
	 * was taken from); nothing when the stored value is not above zero.
	 *
	 * @param string $key       Storage key from key().
	 * @param int    $window    Window (seconds).
	 * @param bool   $transient The unit was taken from the transient copy.
	 */
	public static function decrKey( string $key, int $window, bool $transient = false ): void {
		$transient = $transient || ! wp_using_ext_object_cache();
		$n         = self::read( $key, $transient );
		if ( $n <= 0 ) {
			return;
		}
		if ( ! $transient ) {
			wp_cache_decr( $key, 1, self::GROUP );
			return;
		}
		set_transient( $key, $n - 1, $window );
	}

	/**
	 * Forget the per-request fallback state (tests, long-running processes).
	 */
	public static function reset(): void {
		self::$degraded = [];
	}

	/**
	 * Storage key of a bucket in the current window slot.
	 *
	 * @param string $bucket Bucket id.
	 * @param int    $window Window (seconds).
	 */
	public static function key( string $bucket, int $window ): string {
		$window = max( 1, $window );

		return self::PREFIX . md5( $bucket . '|' . $window . '|' . intdiv( self::now(), $window ) );
	}

	/**
	 * Override the clock (tests); null restores time().
	 *
	 * @param (callable(): int)|null $clock Clock.
	 */
	public static function setClock( ?callable $clock ): void {
		self::$clock = $clock;
	}

	/**
	 * Now.
	 */
	private static function now(): int {
		return null === self::$clock ? time() : (int) call_user_func( self::$clock );
	}
}
