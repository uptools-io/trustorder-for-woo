<?php
/**
 * Cross-request cache of /check results.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Checkout;

/**
 * Transient per (env, cache generation, identity hashes, strictness), 10
 * minutes; see CacheGeneration. Transients
 * work without a PHP/WC session, so Store API requests share it too. Only
 * real verdicts are cached; fail-open results are retried next request.
 */
final class ResultCache {

	public const PREFIX = 'trustorder_r_';
	public const TTL    = 600;

	/**
	 * Transient name (no identity in it, only an md5 of hashes).
	 *
	 * @param string $env        live|test.
	 * @param string $cache_key  IdentitySet::cacheKey().
	 * @param string $strictness Strictness.
	 */
	public static function key( string $env, string $cache_key, string $strictness ): string {
		return self::PREFIX . md5( $env . '|' . CacheGeneration::current() . '|' . $cache_key . '|' . $strictness );
	}

	/**
	 * Cached decision or null.
	 *
	 * @param string $env        live|test.
	 * @param string $cache_key  IdentitySet::cacheKey().
	 * @param string $strictness Strictness.
	 */
	public static function get( string $env, string $cache_key, string $strictness ): ?Decision {
		$v = get_transient( self::key( $env, $cache_key, $strictness ) );
		if ( ! is_array( $v ) ) {
			return null;
		}
		$d = Decision::fromArray( $v );

		return null === $d || $d->failedOpen() || $d->isFallback() ? null : $d;
	}

	/**
	 * Store a real (not failed-open) decision.
	 *
	 * @param string   $env        live|test.
	 * @param string   $cache_key  IdentitySet::cacheKey().
	 * @param string   $strictness Strictness.
	 * @param Decision $decision   Decision.
	 */
	public static function put( string $env, string $cache_key, string $strictness, Decision $decision ): void {
		if ( $decision->failedOpen() || $decision->isFallback() ) {
			return;
		}
		set_transient( self::key( $env, $cache_key, $strictness ), $decision->toArray(), self::TTL );
	}
}
