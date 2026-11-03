<?php
/**
 * API key format and environment.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Api;

/**
 * TrustOrder API keys look like `to_live_<32 alnum>` or `to_test_<32 alnum>`.
 */
final class ApiKey {

	/**
	 * Full key pattern; the capture group is the environment.
	 */
	public const PATTERN = '/^to_(live|test)_[0-9A-Za-z]{32}$/D';

	/**
	 * Environment of a key.
	 *
	 * @param string $key API key (not trimmed here).
	 * @return string|null `live`, `test`, or null if the key is malformed.
	 */
	public static function env( string $key ): ?string {
		if ( 1 !== preg_match( self::PATTERN, $key, $m ) ) {
			return null;
		}

		return $m[1];
	}

	/**
	 * Whether the key is well-formed.
	 *
	 * @param string $key API key.
	 * @return bool
	 */
	public static function isValid( string $key ): bool {
		return null !== self::env( $key );
	}
}
