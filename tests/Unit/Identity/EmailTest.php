<?php
/**
 * E-mail edge cases (expectations taken from the Go reference).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Identity;

use LightweightPlugins\TrustOrder\Identity\Email;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function provideCases(): array {
		return [
			'nbsp and em space trimmed'   => [ "\u{00A0}a@b.hu\u{2003}", 'a@b.hu' ],
			'inner nbsp invalid'          => [ "a\u{00A0}@b.hu", null ],
			'inner NEL invalid'           => [ "a\u{0085}b@c.hu", null ],
			'zero width space kept'       => [ "a\u{200B}b@c.hu", "a\u{200B}b@c.hu" ],
			'control char invalid'        => [ "a\u{0001}b@c.hu", null ],
			'gmail dots only'             => [ '...@gmail.com', null ],
			'254 bytes ok'                => [ str_repeat( 'a', 249 ) . '@b.hu', str_repeat( 'a', 249 ) . '@b.hu' ],
			'255 bytes invalid'           => [ str_repeat( 'a', 250 ) . '@b.hu', null ],
			'uppercase IDN domain'        => [ 'x@ÜBER.de', 'x@xn--ber-goa.de' ],
			'decomposed local part -> NFC' => [ "e\u{0301}@b.hu", "\u{00E9}@b.hu" ],
		];
	}

	/**
	 * @dataProvider provideCases
	 *
	 * @param string      $raw  Input.
	 * @param string|null $want Expected norm.
	 */
	public function test_normalize( string $raw, ?string $want ): void {
		$res = Email::normalize( $raw );
		$this->assertSame( $want, null === $res ? null : $res['norm'] );
	}

	public function test_domain_is_returned_after_googlemail_mapping(): void {
		$this->assertSame(
			[
				'norm'   => 'foobar@gmail.com',
				'domain' => 'gmail.com',
			],
			Email::normalize( 'Foo.Bar+shop@GoogleMail.com' )
		);
	}

	public function test_invalid_utf8_is_rejected_without_warnings(): void {
		$errors = [];
		set_error_handler(
			static function ( int $no, string $msg ) use ( &$errors ): bool {
				$errors[] = $msg;
				return true;
			}
		);
		try {
			$this->assertNull( Email::normalize( "a\xFF@b.hu" ) );
			$this->assertNull( Email::normalize( "a@b\xC3.hu" ) );
			$this->assertNull( Email::normalize( "\xED\xA0\x80@b.hu" ) ); // UTF-16 surrogate.
		} finally {
			restore_error_handler();
		}
		$this->assertSame( [], $errors );
	}
}
