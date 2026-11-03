<?php
/**
 * Address normalisation (protocol v1, section 4).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Identity;

/**
 * Port of backend/internal/identity/address.go: "cc|postcode|street number".
 */
final class Address {

	private const STREET_TYPES = [
		'u'         => 'utca',
		'utca'      => 'utca',
		'ut'        => 'ut',
		'krt'       => 'korut',
		'korut'     => 'korut',
		'ter'       => 'ter',
		'tr'        => 'ter',
		'rkp'       => 'rakpart',
		'rakpart'   => 'rakpart',
		'stny'      => 'setany',
		'setany'    => 'setany',
		'lakotelep' => 'lakotelep',
		'ltp'       => 'lakotelep',
	];

	/**
	 * Normalised address, or null when the country, postcode or house number is missing.
	 *
	 * @param string $country  ISO2 country code.
	 * @param string $postcode Postcode.
	 * @param string $line1    Address line 1.
	 * @param string $line2    Address line 2.
	 */
	public static function normalize( string $country, string $postcode, string $line1, string $line2 ): ?string {
		$cc = Unicode::fold( $country );
		if ( null === $cc || 1 !== preg_match( '/^[a-z]{2}$/D', $cc ) ) {
			return null;
		}
		$pc = self::postcode( $postcode, $cc );
		if ( null === $pc ) {
			return null;
		}
		$number_first = 'hu' !== $cc;
		// Street::normalize() rejects invalid UTF-8; line2 counts only when line1
		// has no house number (as in Go), so only then is it checked.
		$street = Street::normalize( $line1, $number_first );
		if ( null === $street ) {
			if ( ! Unicode::isUtf8( $line2 ) ) {
				return null;
			}
			if ( '' !== Unicode::trim( $line2 ) ) {
				$street = Street::normalize( $line1 . ' ' . $line2, $number_first );
			}
		}

		return null === $street ? null : $cc . '|' . $pc . '|' . $street;
	}

	/**
	 * The street-type mapping (u -> utca, krt -> korut, ...).
	 *
	 * @param string $token Token.
	 */
	public static function streetType( string $token ): string {
		return self::STREET_TYPES[ $token ] ?? $token;
	}

	/**
	 * Postcode: no whitespace, lowercase, trailing dots and "<cc>-" / "h-" prefix dropped.
	 *
	 * @param string $postcode Raw postcode.
	 * @param string $cc       Normalised country code.
	 */
	private static function postcode( string $postcode, string $cc ): ?string {
		$n = Unicode::nfc( $postcode );
		if ( null === $n ) {
			return null;
		}
		$s = Unicode::lower( Unicode::stripSpace( $n ) );
		if ( null === $s ) {
			return null;
		}
		$s = rtrim( $s, '.' );
		if ( str_starts_with( $s, $cc . '-' ) ) {
			$s = substr( $s, 3 );
		} elseif ( 'hu' === $cc && str_starts_with( $s, 'h-' ) ) {
			$s = substr( $s, 2 );
		}
		if ( 1 !== preg_match( '/^[a-z0-9-]+$/D', $s ) || 1 !== preg_match( '/[a-z0-9]/', $s ) ) {
			return null;
		}

		return $s;
	}
}
