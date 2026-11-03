<?php
/**
 * Client hash of protocol v1.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Identity;

/**
 * The client hash: sha256("trustorder:v1:" + kind + ":" + norm), 64 lowercase hex characters.
 */
final class Hasher {

	public const PREFIX = 'trustorder:v1:';

	/**
	 * Client hash of a normalised value.
	 *
	 * @param string $kind email, phone, address or domain.
	 * @param string $norm Normalised value.
	 */
	public static function client( string $kind, string $norm ): string {
		return hash( 'sha256', self::PREFIX . $kind . ':' . $norm );
	}
}
