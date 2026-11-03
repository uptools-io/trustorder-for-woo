<?php
/**
 * E-mail domain normalisation (protocol v1, section 5).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Identity;

/**
 * IDNA UTS #46 (non-transitional, STD3, BiDi, ContextJ) like Go's
 * idna.Lookup.ToASCII, plus the protocol's length and label checks.
 */
final class Domain {

	private const MAX_LABEL    = 63;
	private const MAX_LENGTH   = 253;
	private const MAX_SUFFIXES = 5;

	/**
	 * Lowercase punycode form, or null when invalid.
	 *
	 * @param string $d Raw domain.
	 */
	public static function normalize( string $d ): ?string {
		$n = Unicode::nfc( $d );
		if ( null === $n ) {
			return null;
		}
		$n = Unicode::trim( $n );
		if ( '' === $n ) {
			return null;
		}

		$ascii = Unicode::full() ? self::idna( $n ) : self::asciiOnly( $n );
		if ( null === $ascii ) {
			return null;
		}
		$ascii = strtolower( $ascii );
		if ( ! str_contains( $ascii, '.' ) || strlen( $ascii ) > self::MAX_LENGTH ) {
			return null;
		}
		foreach ( explode( '.', $ascii ) as $label ) {
			if ( '' === $label || strlen( $label ) > self::MAX_LABEL ) {
				return null;
			}
		}

		return $ascii;
	}

	/**
	 * The domain and every parent with at least two labels, most specific
	 * first; with more than five, the five least specific ones.
	 *
	 * @param string $norm_domain Normalised domain.
	 * @return list<string>
	 */
	public static function suffixes( string $norm_domain ): array {
		$labels = explode( '.', $norm_domain );
		$out    = [];
		$count  = count( $labels );
		for ( $i = 0; $i < $count - 1; $i++ ) {
			$out[] = implode( '.', array_slice( $labels, $i ) );
		}

		return array_values( array_slice( $out, -self::MAX_SUFFIXES ) );
	}

	/**
	 * ICU UTS #46 ToASCII with the documented flags.
	 *
	 * One deliberate deviation from ICU, to match Go's idna.Lookup (the
	 * reference): the "hyphens in positions 3 and 4" rule. ICU counts code
	 * points; Go checks bytes 2 and 3 of the mapped Unicode label, and only
	 * when the label is longer than 4 bytes (x/net idna validateLabel). So
	 * ICU's HYPHEN_3_4 error is ignored and Go's byte rule applied instead.
	 *
	 * @param string $d NFC, trimmed domain.
	 */
	private static function idna( string $d ): ?string {
		$flags = IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ;
		$info  = [];
		idn_to_ascii( $d, $flags, INTL_IDNA_VARIANT_UTS46, $info );
		$ascii = self::idnaResult( $info );
		if ( null === $ascii ) {
			return null;
		}

		$info = [];
		idn_to_utf8( $ascii, $flags | IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46, $info );
		$unicode = self::idnaResult( $info );
		if ( null === $unicode ) {
			return null;
		}
		foreach ( explode( '.', $unicode ) as $label ) {
			if ( strlen( $label ) > 4 && '--' === substr( $label, 2, 2 ) ) {
				return null;
			}
		}

		return $ascii;
	}

	/**
	 * The converted string when ICU reported no error other than HYPHEN_3_4.
	 *
	 * @param mixed $info idn_to_* info array.
	 */
	private static function idnaResult( $info ): ?string {
		if ( ! is_array( $info ) || ! isset( $info['errors'], $info['result'] ) || ! is_string( $info['result'] ) ) {
			return null;
		}
		if ( 0 !== ( (int) $info['errors'] & ~IDNA_ERROR_HYPHEN_3_4 ) ) {
			return null;
		}

		return $info['result'];
	}

	/**
	 * Without intl: the UTS #46 result for plain ASCII LDH labels only.
	 * Punycode ("xn--") labels cannot be verified and are rejected, as is
	 * anything non-ASCII.
	 *
	 * @param string $d NFC, trimmed domain.
	 */
	private static function asciiOnly( string $d ): ?string {
		if ( ! Unicode::isAscii( $d ) ) {
			return null;
		}
		$d = strtolower( $d );
		foreach ( explode( '.', $d ) as $label ) {
			if ( '' === $label ) {
				continue; // Length/empty-label rules are applied by the caller.
			}
			if ( 1 !== preg_match( '/^[a-z0-9-]+$/D', $label )
				|| '-' === $label[0]
				|| '-' === substr( $label, -1 )
				|| '--' === substr( $label, 2, 2 ) ) {
				return null;
			}
		}

		return $d;
	}
}
