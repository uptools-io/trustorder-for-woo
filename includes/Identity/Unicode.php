<?php
/**
 * Unicode primitives of the hash protocol (Go reference semantics).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Identity;

/**
 * NFC/NFD, White_Space trimming and simple lowercasing, matching Go's
 * norm.NFC, unicode.IsSpace and unicode.ToLower.
 *
 * Without intl (Normalizer, idn_to_ascii) or native mbstring only ASCII input
 * is handled; anything else yields null so the identity is skipped.
 */
final class Unicode {

	/**
	 * The Unicode White_Space property (Go unicode.IsSpace), as a PCRE class.
	 */
	public const SPACE_CLASS = '[\x{09}-\x{0D}\x{20}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';

	/**
	 * Whether the full (non-ASCII) protocol can run on this PHP.
	 */
	public static function full(): bool {
		return function_exists( 'normalizer_normalize' )
			&& function_exists( 'idn_to_ascii' )
			&& function_exists( 'mb_convert_case' )
			&& defined( 'MB_CASE_LOWER_SIMPLE' );
	}

	/**
	 * Whether $s is pure ASCII.
	 *
	 * @param string $s Input.
	 */
	public static function isAscii( string $s ): bool {
		return 1 !== preg_match( '/[\x80-\xFF]/', $s );
	}

	/**
	 * Whether $s is valid UTF-8 (works without mbstring).
	 *
	 * @param string $s Input.
	 */
	public static function isUtf8( string $s ): bool {
		return 1 === preg_match( '//u', $s );
	}

	/**
	 * Unicode NFC; null for invalid UTF-8 or non-ASCII input without intl.
	 *
	 * @param string $s Input.
	 */
	public static function nfc( string $s ): ?string {
		return self::normalize( $s, 'NFC' );
	}

	/**
	 * Unicode NFD; null for invalid UTF-8 or non-ASCII input without intl.
	 *
	 * @param string $s Input.
	 */
	public static function nfd( string $s ): ?string {
		return self::normalize( $s, 'NFD' );
	}

	/**
	 * Trim White_Space from both ends (input must be valid UTF-8).
	 *
	 * @param string $s Input.
	 */
	public static function trim( string $s ): string {
		$out = preg_replace( '/^' . self::SPACE_CLASS . '+|' . self::SPACE_CLASS . '+$/u', '', $s );

		return is_string( $out ) ? $out : '';
	}

	/**
	 * Whether $s contains any White_Space character.
	 *
	 * @param string $s Input (valid UTF-8).
	 */
	public static function hasSpace( string $s ): bool {
		return 1 === preg_match( '/' . self::SPACE_CLASS . '/u', $s );
	}

	/**
	 * Remove every White_Space character.
	 *
	 * @param string $s Input (valid UTF-8).
	 */
	public static function stripSpace( string $s ): string {
		$out = preg_replace( '/' . self::SPACE_CLASS . '/u', '', $s );

		return is_string( $out ) ? $out : '';
	}

	/**
	 * Simple (1:1) Unicode lowercase mapping; null when unavailable.
	 *
	 * @param string $s Input (valid UTF-8).
	 */
	public static function lower( string $s ): ?string {
		if ( self::asciiApartFromSpace( $s ) ) {
			// White_Space characters have no lowercase mapping; A-Z only, locale-independent.
			return strtr( $s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
		}
		if ( ! self::full() ) {
			return null;
		}

		return mb_convert_case( $s, MB_CASE_LOWER_SIMPLE, 'UTF-8' );
	}

	/**
	 * NFC + trim + simple lowercase: the common prologue of every field.
	 *
	 * @param string $s Raw input.
	 */
	public static function fold( string $s ): ?string {
		$n = self::nfc( $s );

		return null === $n ? null : self::lower( self::trim( $n ) );
	}

	/**
	 * Whether every non-ASCII character of $s (valid UTF-8) is White_Space.
	 *
	 * @param string $s Input.
	 */
	private static function asciiApartFromSpace( string $s ): bool {
		return self::isAscii( $s ) || ( self::isUtf8( $s ) && self::isAscii( self::stripSpace( $s ) ) );
	}

	/**
	 * Run a normalisation form.
	 *
	 * @param string $s    Input.
	 * @param string $form 'NFC' or 'NFD'.
	 */
	private static function normalize( string $s, string $form ): ?string {
		if ( ! self::isUtf8( $s ) ) {
			return null;
		}
		if ( self::isAscii( $s ) ) {
			return $s;
		}
		if ( ! self::full() ) {
			// Without intl, ASCII plus White_Space only (e.g. an NBSP-padded
			// e-mail). NFC/NFD would leave these characters alone except
			// U+2000/U+2001 -> U+2002/U+2003, which are White_Space as well, and
			// every caller treats White_Space the same (trim, reject or split).
			return self::asciiApartFromSpace( $s ) ? $s : null;
		}
		$out = normalizer_normalize( $s, 'NFC' === $form ? \Normalizer::FORM_C : \Normalizer::FORM_D );

		return is_string( $out ) ? $out : null;
	}
}
