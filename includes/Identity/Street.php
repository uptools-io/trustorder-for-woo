<?php
/**
 * Street + house-number part of the address (protocol v1, section 4).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Identity;

/**
 * Port of streetPart/houseNumber/tokenize in backend/internal/identity/address.go.
 */
final class Street {

	/**
	 * "street ... number", or null when no house number is found.
	 *
	 * @param string $line         Address line(s).
	 * @param bool   $number_first Allow number-first formats (rule b; off for hu).
	 */
	public static function normalize( string $line, bool $number_first ): ?string {
		$toks = self::tokenize( $line );
		if ( null === $toks ) {
			return null;
		}
		if ( count( $toks ) >= 2 && self::isRoman( $toks[0] ) && ( 'ker' === $toks[1] || 'kerulet' === $toks[1] ) ) {
			$toks = array_slice( $toks, 2 ); // Budapest district prefix.
		}
		$toks = array_map( [ Address::class, 'streetType' ], $toks );

		$alpha = false;
		foreach ( $toks as $i => $t ) {
			if ( ! self::digitLeading( $t ) ) {
				$alpha = true;
				continue;
			}
			if ( $alpha ) {
				return self::houseNumber( $toks, $i );
			}
		}

		if ( $number_first && count( $toks ) >= 2 && self::digitLeading( $toks[0] ) ) {
			foreach ( array_slice( $toks, 1 ) as $t ) {
				if ( ! self::digitLeading( $t ) ) {
					return implode( ' ', $toks );
				}
			}
		}

		return null;
	}

	/**
	 * NFC -> NFD, drop Mn, simple lowercase, non [a-z0-9] -> space, split.
	 *
	 * @param string $s Input.
	 * @return list<string>|null
	 */
	private static function tokenize( string $s ): ?array {
		$nfc = Unicode::nfc( $s );
		$nfd = null === $nfc ? null : Unicode::nfd( $nfc );
		if ( null === $nfd ) {
			return null;
		}
		$bare  = (string) preg_replace( '/\p{Mn}/u', '', $nfd );
		$lower = Unicode::lower( $bare );
		if ( null === $lower ) {
			return null;
		}
		$spaced = (string) preg_replace( '/[^a-z0-9]+/', ' ', $lower );
		$parts  = explode( ' ', trim( $spaced, ' ' ) );

		return '' === $parts[0] ? [] : $parts;
	}

	/**
	 * Keep tokens up to the house number, plus a hrsz second part or a joined letter.
	 *
	 * @param string[] $toks Tokens.
	 * @param int      $i    House-number index (> 0).
	 *
	 * @phpstan-param list<string> $toks
	 */
	private static function houseNumber( array $toks, int $i ): string {
		$out  = array_slice( $toks, 0, $i + 1 );
		$next = $toks[ $i + 1 ] ?? null;
		if ( 'hrsz' === $toks[ $i - 1 ] ) {
			if ( null !== $next && 1 === preg_match( '/^[0-9]+$/D', $next ) ) {
				$out[] = $next;
			}
		} elseif ( null !== $next && self::joinsLetter( $toks, $i + 1 ) ) {
			$out[ $i ] .= $next;
		}

		return implode( ' ', $out );
	}

	/**
	 * A single-letter suffix (12 a -> 12a): not i/v/x, not before lph/lepcsohaz.
	 *
	 * @param string[] $toks Tokens.
	 * @param int      $i    Candidate index.
	 *
	 * @phpstan-param list<string> $toks
	 */
	private static function joinsLetter( array $toks, int $i ): bool {
		$t = $toks[ $i ];
		if ( 1 !== preg_match( '/^[a-z]$/D', $t ) || 'i' === $t || 'v' === $t || 'x' === $t ) {
			return false;
		}
		$after = $toks[ $i + 1 ] ?? null;

		return 'lph' !== $after && 'lepcsohaz' !== $after;
	}

	/**
	 * Token starts with an ASCII digit.
	 *
	 * @param string $t Token.
	 */
	private static function digitLeading( string $t ): bool {
		return $t[0] >= '0' && $t[0] <= '9';
	}

	/**
	 * Token consists of Roman numeral letters i, v, x only.
	 *
	 * @param string $t Token.
	 */
	private static function isRoman( string $t ): bool {
		return 1 === preg_match( '/^[ivx]+$/D', $t );
	}
}
