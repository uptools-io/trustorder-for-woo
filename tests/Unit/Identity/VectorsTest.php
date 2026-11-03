<?php
/**
 * Conformance test: every vector of the shared protocol v1 fixture.
 *
 * The fixture is a byte-for-byte copy of
 * backend/internal/identity/testdata/vectors.json (CI compares them).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Identity;

use LightweightPlugins\TrustOrder\Identity\Address;
use LightweightPlugins\TrustOrder\Identity\Domain;
use LightweightPlugins\TrustOrder\Identity\Email;
use LightweightPlugins\TrustOrder\Identity\Hasher;
use LightweightPlugins\TrustOrder\Identity\Phone;
use PHPUnit\Framework\TestCase;

final class VectorsTest extends TestCase {

	/**
	 * Decoded fixture.
	 *
	 * @return list<array{kind: string, input: array<string, string>, ok: bool, norm: string, hash: string}>
	 */
	private static function vectors(): array {
		$raw = file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/vectors.json' );
		self::assertIsString( $raw );
		$data = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $data );

		return $data;
	}

	/**
	 * One data set per vector, named "<index> <kind>".
	 *
	 * @return array<string, array{0: array{kind: string, input: array<string, string>, ok: bool, norm: string, hash: string}}>
	 */
	public static function provideVectors(): array {
		$out = [];
		foreach ( self::vectors() as $i => $v ) {
			$out[ sprintf( '%03d %s', $i, $v['kind'] ) ] = [ $v ];
		}

		return $out;
	}

	public function test_fixture_has_every_kind(): void {
		$counts = [];
		foreach ( self::vectors() as $v ) {
			$counts[ $v['kind'] ] = ( $counts[ $v['kind'] ] ?? 0 ) + 1;
		}

		$this->assertGreaterThanOrEqual( 45, array_sum( $counts ) );
		foreach ( [ 'email', 'phone', 'address', 'domain', 'domain_suffixes' ] as $kind ) {
			$this->assertArrayHasKey( $kind, $counts );
		}
	}

	/**
	 * @dataProvider provideVectors
	 *
	 * @param array{kind: string, input: array<string, string>, ok: bool, norm: string, hash: string} $v Vector.
	 */
	public function test_vector( array $v ): void {
		$in = $v['input'];

		if ( 'domain_suffixes' === $v['kind'] ) {
			$suffixes = Domain::suffixes( $in['domain'] );
			$hashes   = array_map( static fn( string $s ): string => Hasher::client( 'domain', $s ), $suffixes );
			$this->assertSame( $v['norm'], implode( ',', $suffixes ) );
			$this->assertSame( $v['hash'], implode( ',', $hashes ) );
			return;
		}

		switch ( $v['kind'] ) {
			case 'email':
				$res  = Email::normalize( $in['raw'] );
				$norm = null === $res ? null : $res['norm'];
				break;
			case 'phone':
				$norm = Phone::normalize( $in['raw'], $in['region'] );
				break;
			case 'address':
				$norm = Address::normalize( $in['country'], $in['postcode'], $in['line1'], $in['line2'] );
				break;
			case 'domain':
				$norm = Domain::normalize( $in['domain'] );
				break;
			default:
				$this->fail( 'unknown kind ' . $v['kind'] );
		}

		if ( ! $v['ok'] ) {
			$this->assertNull( $norm, 'expected rejection' );
			return;
		}

		$this->assertSame( $v['norm'], $norm );
		$this->assertSame( $v['hash'], Hasher::client( $v['kind'], $norm ) );
	}
}
