<?php
/**
 * Domain edge cases (expectations taken from the Go reference).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Identity;

use LightweightPlugins\TrustOrder\Identity\Domain;
use PHPUnit\Framework\TestCase;

final class DomainTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function provideCases(): array {
		return [
			// Go checks hyphens at bytes 2-3 of the Unicode label (len > 4), ICU at code points 3-4.
			'go byte rule rejects ü--bn'      => [ 'nn.ü--bn', null ],
			'go byte rule accepts aü--b-0'    => [ 'ü.aü--b-0', 'xn--tda.xn--a--b-0-3ya' ],
			'ascii hyphens 3-4'               => [ 'ab--c.com', null ],
			'leading hyphen'                  => [ '-a.com', null ],
			'trailing hyphen'                 => [ 'a-.com', null ],
			'trailing dot'                    => [ 'example.com.', null ],
			'empty label'                     => [ 'a..com', null ],
			'punycode kept'                   => [ 'xn--bcher-kva.de', 'xn--bcher-kva.de' ],
			'uppercase punycode'              => [ 'XN--BCHER-KVA.DE', 'xn--bcher-kva.de' ],
			'bidi violation'                  => [ "\u{0661}.com", null ],
			'ideographic full stop separator' => [ "ab.c\u{3002}hu", 'ab.c.hu' ],
			'253 chars ok'                    => [ self::longDomain( 253 ), self::longDomain( 253 ) ],
			'254 chars invalid'               => [ self::longDomain( 254 ), null ],
			'invalid utf8'                    => [ "b\xFFcher.de", null ],
		];
	}

	/**
	 * A domain of exactly $len characters made of 63-char labels.
	 *
	 * @param int $len Length.
	 */
	private static function longDomain( int $len ): string {
		$labels = [];
		$left   = $len;
		while ( $left > 0 ) {
			$take     = min( 63, $left - ( [] === $labels ? 0 : 1 ) );
			$labels[] = str_repeat( 'a', $take );
			$left    -= $take + ( count( $labels ) > 1 ? 1 : 0 );
		}

		return implode( '.', $labels );
	}

	/**
	 * @dataProvider provideCases
	 *
	 * @param string      $in   Input.
	 * @param string|null $want Expected.
	 */
	public function test_normalize( string $in, ?string $want ): void {
		$this->assertSame( $want, Domain::normalize( $in ) );
	}

	public function test_long_domain_helper_is_exact(): void {
		$this->assertSame( 253, strlen( self::longDomain( 253 ) ) );
		$this->assertSame( 254, strlen( self::longDomain( 254 ) ) );
	}

	public function test_suffixes(): void {
		$this->assertSame( [], Domain::suffixes( 'com' ) );
		$this->assertSame( [ 'mailinator.com' ], Domain::suffixes( 'mailinator.com' ) );
		$got = Domain::suffixes( '1.2.3.4.5.6.7.example.com' );
		$this->assertCount( 5, $got );
		$this->assertSame( 'example.com', $got[4] );
		$this->assertSame( '4.5.6.7.example.com', $got[0] );
	}
}
