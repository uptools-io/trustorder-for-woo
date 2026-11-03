<?php
/**
 * Address edge cases (expectations taken from the Go reference).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Identity;

use LightweightPlugins\TrustOrder\Identity\Address;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string|null}>
	 */
	public static function provideCases(): array {
		return [
			'unicode spaces in cc and postcode' => [ " hu\u{00A0}", "1051\u{00A0}", 'Fő út 3', '', 'hu|1051|fo ut 3' ],
			'fullwidth postcode prefix invalid' => [ 'HU', 'ＨＵ-1051', 'Fő út 3', '', null ],
			'uppercase accented street'        => [ 'HU', '1051', 'FŐ ÚT 3/B', '', 'hu|1051|fo ut 3b' ],
			'number-first outside hu'          => [ 'DE', '10115', '56 Osok', '', 'de|10115|56 osok' ],
			'never number-first in hu'         => [ 'HU', '1051', '56 Osok', '', null ],
			'letter suffix joined'             => [ 'HU', '1051', 'Fő út 3 a', '', 'hu|1051|fo ut 3a' ],
			'roman floor letter dropped'       => [ 'HU', '1051', 'Fő út 3 x', '', 'hu|1051|fo ut 3' ],
			'line2 ignored for number-first'   => [ 'GB', 'SW1A 1AA', '10 Downing Street', 'Flat 3', 'gb|sw1a1aa|10 downing street' ],
			'invalid utf8 line1'               => [ 'HU', '1051', "F\xFF út 3", '', null ],
			'invalid utf8 line2 when used'     => [ 'HU', '1051', 'Fő út', "1\xFF", null ],
			'invalid utf8 line2 when unused'   => [ 'HU', '1051', 'Fő út 3', "1\xFF", 'hu|1051|fo ut 3' ],
			'invalid utf8 postcode'            => [ 'HU', "10\xFF51", 'Fő út 3', '', null ],
		];
	}

	/**
	 * @dataProvider provideCases
	 *
	 * @param string      $cc   Country.
	 * @param string      $pc   Postcode.
	 * @param string      $l1   Line 1.
	 * @param string      $l2   Line 2.
	 * @param string|null $want Expected.
	 */
	public function test_normalize( string $cc, string $pc, string $l1, string $l2, ?string $want ): void {
		$this->assertSame( $want, Address::normalize( $cc, $pc, $l1, $l2 ) );
	}
}
