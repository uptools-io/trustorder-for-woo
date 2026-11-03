<?php
/**
 * Phone edge cases (expectations taken from the Go reference).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Identity;

use LightweightPlugins\TrustOrder\Identity\Phone;
use PHPUnit\Framework\TestCase;

final class PhoneTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string, 2: string|null}>
	 */
	public static function provideCases(): array {
		return [
			'unicode spaces trimmed'  => [ "\u{00A0}06 30 123 4567\u{3000}", 'HU', '+36301234567' ],
			'inner nbsp invalid'      => [ "06\u{00A0}30 123 4567", 'HU', null ],
			'extension invalid'       => [ '+36 30 123 4567 ext 12', 'HU', null ],
			'plus without region'     => [ '+36301234567', '', '+36301234567' ],
			'national without region' => [ '06301234567', '', null ],
			'region trimmed and upper' => [ '06 30 123 4567', ' hu ', '+36301234567' ],
			'all identical digits'    => [ '+36 99 999 9999', 'HU', null ],
			'invalid utf8'            => [ "06 30 123 4567\xFF", 'HU', null ],
			'invalid utf8 region'     => [ '06 30 123 4567', "H\xFF", null ],
		];
	}

	/**
	 * @dataProvider provideCases
	 *
	 * @param string      $raw    Input.
	 * @param string      $region Region.
	 * @param string|null $want   Expected.
	 */
	public function test_normalize( string $raw, string $region, ?string $want ): void {
		$this->assertSame( $want, Phone::normalize( $raw, $region ) );
	}

	public function test_overlong_input_is_rejected(): void {
		$this->assertNull( Phone::normalize( '+36' . str_repeat( '1', 300 ), 'HU' ) );
	}
}
