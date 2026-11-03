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
 * Port of backend/internal/identity/phone.go (libphonenumber, E.164).
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
