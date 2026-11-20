<?php
/**
 * Phone normalisation (protocol v1, section 3).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Identity;

use LightweightPlugins\TrustOrder\Vendor\libphonenumber\NumberParseException;
use LightweightPlugins\TrustOrder\Vendor\libphonenumber\PhoneNumberFormat;
use LightweightPlugins\TrustOrder\Vendor\libphonenumber\PhoneNumberUtil;

/**
 * Port of backend/internal/identity/phone.go (libphonenumber, E.164), except
 * that `#` and `_` are removed first (the backend rejects them).
 */
final class Phone {

	private const FAKE_NSNS = [ '123456789', '1234567', '0123456789' ];

	/**
	 * E.164 form, or null when invalid.
	 *
	 * @param string $raw    Raw phone number.
	 * @param string $region Default region (ISO2), used without "+".
	 */
	public static function normalize( string $raw, string $region ): ?string {
		if ( ! Unicode::isUtf8( $raw ) || ! Unicode::isUtf8( $region ) ) {
			return null;
		}
		// WC_Validation::is_phone() also allows `#` and `_`; `_` carries no digit.
		// What follows a `#` is an extension when a valid number precedes it;
		// otherwise every `#` is dropped.
		$raw  = str_replace( '_', '', $raw );
		$hash = strpos( $raw, '#' );
		if ( false !== $hash ) {
			return self::parse( substr( $raw, 0, $hash ), $region ) ?? self::parse( str_replace( '#', '', $raw ), $region );
		}

		return self::parse( $raw, $region );
	}

	/**
	 * E.164 of a number without `#` or `_`, or null.
	 *
	 * @param string $raw    Raw phone number.
	 * @param string $region Default region (ISO2), used without "+".
	 */
	private static function parse( string $raw, string $region ): ?string {
		$s = Unicode::trim( $raw );
		if ( '' === $s || 1 !== preg_match( '#^[0-9+ \t\-/().]+$#D', $s ) ) {
			return null;
		}
		if ( str_starts_with( $s, '00' ) ) {
			$s = '+' . substr( $s, 2 );
		}

		$util = PhoneNumberUtil::getInstance();
		try {
			$num = $util->parse( $s, strtoupper( Unicode::trim( $region ) ) );
		} catch ( NumberParseException $e ) {
			return null;
		}
		if ( ! $util->isValidNumber( $num ) || self::isFake( $util->getNationalSignificantNumber( $num ) ) ) {
			return null;
		}

		return $util->format( $num, PhoneNumberFormat::E164 );
	}

	/**
	 * All-identical digits or a known fake national significant number.
	 *
	 * @param string $nsn National significant number.
	 */
	private static function isFake( string $nsn ): bool {
		if ( '' === $nsn || in_array( $nsn, self::FAKE_NSNS, true ) ) {
			return true;
		}

		return '' === trim( $nsn, $nsn[0] );
	}
}
