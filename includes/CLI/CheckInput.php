<?php
/**
 * Identity from the `wp trustorder check` arguments.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\CLI;

use LightweightPlugins\TrustOrder\Identity\IdentitySet;
use LightweightPlugins\TrustOrder\Rest\IdentityInput;
use LightweightPlugins\TrustOrder\Settings;

/**
 * Validates the typed arguments and hashes them through IdentitySet, the
 * same normalisation the checkout uses. Error messages never echo the input.
 */
final class CheckInput {

	/**
	 * The identity to check.
	 *
	 * @param array<string, mixed> $assoc Associative args: email, phone, country, postcode, address.
	 */
	public static function identity( array $assoc ): IdentitySet {
		$text    = static function ( string $key ) use ( $assoc ): string {
			$value = $assoc[ $key ] ?? '';
			if ( ! is_string( $value ) ) {
				Output::fail( sprintf( '--%s must be a text value.', $key ) );
			}

			return trim( $value );
		};
		$country = strtoupper( $text( 'country' ) );
		if ( '' !== $country && 1 !== preg_match( '/^[A-Z]{2}$/D', $country ) ) {
			Output::fail( '--country must be a two-letter country code.' );
		}
		$country = '' !== $country ? $country : IdentityInput::shopCountry();
		$ids     = IdentitySet::fromOrderData(
			[
				'email'              => $text( 'email' ),
				'phone'              => $text( 'phone' ),
				'billing_country'    => $country,
				'shipping_country'   => $country,
				'shipping_postcode'  => $text( 'postcode' ),
				'shipping_address_1' => $text( 'address' ),
				'needs_shipping'     => '' !== $text( 'address' ),
			]
		);
		if ( ! $ids->hasEmail() ) {
			Output::fail( 'A valid --email is required.' );
		}

		return $ids;
	}

	/**
	 * The strictness: the argument, else the saved one.
	 *
	 * @param array<string, mixed> $assoc Associative args.
	 */
	public static function strictness( array $assoc ): string {
		$given = $assoc['strictness'] ?? null;
		if ( null === $given ) {
			return Settings::get()['strictness'];
		}
		if ( ! is_string( $given ) || ! in_array( $given, Settings::STRICTNESS, true ) ) {
			Output::fail( 'Unknown strictness. Use lenient, normal or strict.' );
		}

		return $given;
	}
}
