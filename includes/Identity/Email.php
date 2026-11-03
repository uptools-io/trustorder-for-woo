<?php
/**
 * E-mail normalisation (protocol v1, section 2).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Identity;

/**
 * Port of backend/internal/identity/email.go.
 */
final class Email {

	private const MAX_LENGTH = 254;

	/**
	 * Normalised address and its punycode domain, or null when invalid.
	 *
	 * @param string $raw Raw e-mail address.
	 * @return array{norm: string, domain: string}|null
	 */
	public static function normalize( string $raw ): ?array {
		$s = Unicode::fold( $raw );
		if ( null === $s || Unicode::hasSpace( $s ) || 1 !== substr_count( $s, '@' ) ) {
			return null;
		}
		[ $local, $domain ] = explode( '@', $s, 2 );
		if ( 1 === preg_match( '/[\x{00}-\x{1F}\x{7F}-\x{9F}]/u', $local ) ) {
			return null; // Go unicode.IsControl: C0, DEL and C1.
		}
		$domain = Domain::normalize( $domain );
		if ( null === $domain ) {
			return null;
		}

		$plus = strpos( $local, '+' );
		if ( false !== $plus ) {
			$local = substr( $local, 0, $plus );
		}
		if ( 'googlemail.com' === $domain ) {
			$domain = 'gmail.com';
		}
		if ( 'gmail.com' === $domain ) {
			$local = str_replace( '.', '', $local );
		}
		if ( '' === $local ) {
			return null;
		}
		$out = $local . '@' . $domain;
		if ( strlen( $out ) > self::MAX_LENGTH ) {
			return null;
		}

		return [
			'norm'   => $out,
			'domain' => $domain,
		];
	}
}
