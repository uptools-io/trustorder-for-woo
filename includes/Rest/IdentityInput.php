<?php
/**
 * Raw identity input of the exceptions screen, hashed.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Rest;

use LightweightPlugins\TrustOrder\Identity\IdentitySet;
use WP_Error;

/**
 * Turns the raw e-mail / phone / address the shop manager typed into the
 * protocol v1 client hash, through the same normalisation as the checkout.
 * The raw value is used only inside this call: never stored, logged or
 * returned.
 */
final class IdentityInput {

	public const KINDS = [ 'email', 'phone', 'address' ];

	/**
	 * Kind and hash, or a 400 error.
	 *
	 * @param array<string, mixed> $in Params: kind, value (email/phone), country, postcode, address_1, address_2.
	 * @return array{kind: string, hash: string}|WP_Error
	 */
	public static function hash( array $in ) {
		$kind = $in['kind'] ?? null;
		if ( ! is_string( $kind ) || ! in_array( $kind, self::KINDS, true ) ) {
			return ApiError::invalid( 'trustorder_invalid_kind', __( 'Choose e-mail, phone or address.', 'trustorder-for-woo' ) );
		}
		$hash = IdentitySet::fromOrderData( self::orderData( $kind, $in ) )->hashes()[ $kind . '_hash' ];
		if ( null === $hash ) {
			return ApiError::invalid( 'trustorder_invalid_identity', self::invalidMessage( $kind ) );
		}

		return [
			'kind' => $kind,
			'hash' => $hash,
		];
	}

	/**
	 * Order-data shape IdentitySet expects, for one kind only.
	 *
	 * @param string               $kind Kind.
	 * @param array<string, mixed> $in   Params.
	 * @return array<string, mixed>
	 */
	private static function orderData( string $kind, array $in ): array {
		$s       = static fn( string $k ): string => isset( $in[ $k ] ) && is_string( $in[ $k ] ) ? $in[ $k ] : '';
		$country = '' !== trim( $s( 'country' ) ) ? $s( 'country' ) : self::shopCountry();

		switch ( $kind ) {
			case 'email':
				return [ 'email' => $s( 'value' ) ];
			case 'phone':
				return [
					'phone'           => $s( 'value' ),
					'billing_country' => $country,
				];
			default:
				return [
					'needs_shipping'     => true,
					'shipping_country'   => $country,
					'shipping_postcode'  => $s( 'postcode' ),
					'shipping_address_1' => $s( 'address_1' ),
					'shipping_address_2' => $s( 'address_2' ),
				];
		}
	}

	/**
	 * The shop's base country (ISO2) from the WooCommerce setting.
	 */
	public static function shopCountry(): string {
		$base = get_option( 'woocommerce_default_country', '' );

		return is_string( $base ) ? explode( ':', $base )[0] : '';
	}

	/**
	 * Error text per kind (never echoes the value).
	 *
	 * @param string $kind Kind.
	 */
	private static function invalidMessage( string $kind ): string {
		switch ( $kind ) {
			case 'email':
				return __( 'This e-mail address is not valid.', 'trustorder-for-woo' );
			case 'phone':
				return __( 'This phone number is not valid for the chosen country.', 'trustorder-for-woo' );
			default:
				return __( 'The address needs a country, a postcode and a house number.', 'trustorder-for-woo' );
		}
	}
}
