<?php
/**
 * The real decisions seen in one checkout session.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Checkout;

/**
 * Used once the session's check budget is spent: a decision is reused only
 * for the very same identity; otherwise the strictest verdict of the session
 * applies (block > flag > allow), so junk identities cannot "wash" a blocked
 * buyer. While a budget unit is available, only rows younger than
 * ResultCache::TTL are reused (see get()'s max age). Rows of an older
 * CacheGeneration are ignored. Transient `trustorder_qs_` + md5(session key),
 * QuotaGuard::WINDOW; row = { d: decision, at: unix time, g: generation }.
 */
final class SessionMemory {

	public const PREFIX = 'trustorder_qs_';

	private const MAX_ENTRIES = 12;

	private const RANK = [
		Decision::ALLOW => 0,
		Decision::FLAG  => 1,
		Decision::BLOCK => 2,
	];

	/**
	 * Remember a real decision for an identity key.
	 *
	 * @param string   $session_key Session key.
	 * @param string   $key         Identity key (env, hashes, strictness).
	 * @param Decision $decision    Decision.
	 */
	public static function remember( string $session_key, string $key, Decision $decision ): void {
		if ( '' === $session_key || $decision->failedOpen() || $decision->isFallback() ) {
			return;
		}
		$all = self::all( $session_key );
		unset( $all[ $key ] );
		$all[ $key ] = [
			'd'  => $decision->toArray(),
			'at' => time(),
			'g'  => CacheGeneration::current(),
		];
		if ( count( $all ) > self::MAX_ENTRIES ) {
			$all = array_slice( $all, -self::MAX_ENTRIES, null, true );
		}
		set_transient( self::PREFIX . md5( $session_key ), $all, QuotaGuard::WINDOW );
	}

	/**
	 * The decision for exactly this identity key, if seen (and not older than
	 * $max_age seconds, when given).
	 *
	 * @param string   $session_key Session key.
	 * @param string   $key         Identity key.
	 * @param int|null $max_age     Maximum age in seconds, null = any age.
	 */
	public static function get( string $session_key, string $key, ?int $max_age = null ): ?Decision {
		$all = self::all( $session_key );
		if ( ! isset( $all[ $key ] ) ) {
			return null;
		}
		$row = self::row( $all[ $key ] );
		if ( null === $row || ( null !== $max_age && time() - $row['at'] >= $max_age ) ) {
			return null;
		}

		return $row['d'];
	}

	/**
	 * The strictest real decision of the session, if any.
	 *
	 * @param string $session_key Session key.
	 */
	public static function strictest( string $session_key ): ?Decision {
		$best = null;
		foreach ( self::all( $session_key ) as $raw ) {
			$row = self::row( $raw );
			$d   = null === $row ? null : $row['d'];
			if ( null !== $d && ! $d->isFallback() && ( null === $best || self::RANK[ $d->verdict() ] > self::RANK[ $best->verdict() ] ) ) {
				$best = $d;
			}
		}

		return $best;
	}

	/**
	 * A valid row of the current generation.
	 *
	 * @param mixed $raw Stored row.
	 * @return array{d: Decision, at: int}|null
	 */
	private static function row( $raw ): ?array {
		if ( ! is_array( $raw ) || ! is_array( $raw['d'] ?? null ) || ! is_int( $raw['at'] ?? null ) || CacheGeneration::current() !== ( $raw['g'] ?? null ) ) {
			return null;
		}
		$d = Decision::fromArray( $raw['d'] );

		return null === $d ? null : [
			'd'  => $d,
			'at' => $raw['at'],
		];
	}

	/**
	 * Stored rows.
	 *
	 * @param string $session_key Session key.
	 * @return array<string, mixed>
	 */
	private static function all( string $session_key ): array {
		if ( '' === $session_key ) {
			return [];
		}
		$v = get_transient( self::PREFIX . md5( $session_key ) );

		return is_array( $v ) ? $v : [];
	}
}
