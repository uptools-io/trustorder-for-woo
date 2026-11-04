<?php
/**
 * Generation salt of the checkout result caches.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Checkout;

/**
 * A shop exception added or removed, or an outcome signal sent or withdrawn,
 * changes what /check answers, so the cached results (ResultCache,
 * SessionMemory) of the old generation must not be reused. Bumping the counter changes every cache key at once.
 * Non-autoloaded option `trustorder_cache_gen`.
 */
final class CacheGeneration {

	public const OPTION = 'trustorder_cache_gen';

	/**
	 * Current generation (0 before the first change).
	 */
	public static function current(): int {
		$v = get_option( self::OPTION, 0 );

		return is_numeric( $v ) ? max( 0, (int) $v ) : 0;
	}

	/**
	 * Start a new generation (after an exception change).
	 */
	public static function bump(): void {
		update_option( self::OPTION, self::current() + 1, false );
	}
}
