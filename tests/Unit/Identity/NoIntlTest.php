<?php
/**
 * Without intl: ASCII identities still hash, non-ASCII ones are skipped,
 * no PHP warnings, and no request body without an e-mail.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Identity;

use PHPUnit\Framework\TestCase;

final class NoIntlTest extends TestCase {

	/**
	 * Probe output.
	 *
	 * @return array<string, mixed>
	 */
	private function probe(): array {
		$probe = dirname( __DIR__, 2 ) . '/Support/no-intl-probe.php';
		$cmd   = escapeshellarg( PHP_BINARY ) . ' -d disable_functions=idn_to_ascii,normalizer_normalize ' . escapeshellarg( $probe ) . ' 2>&1';
		$out   = (string) shell_exec( $cmd );
		$data  = json_decode( $out, true );
		$this->assertIsArray( $data, $out );

		return $data;
	}

	public function test_ascii_works_and_non_ascii_is_skipped_without_intl(): void {
		$r = $this->probe();

		$this->assertFalse( $r['full'] );
		$this->assertSame( [], $r['errors'] );

		$this->assertSame( 'johndoe@gmail.com', $r['email_ascii'] );
		$this->assertSame( 'a@b.hu', $r['email_nbsp'], 'White_Space is trimmed before the ASCII gate' );
		$this->assertNull( $r['email_inner_sp'] );
		$this->assertSame( 'example.com', $r['domain_nbsp'] );
		$this->assertSame( 'hu|1051|kossuth utca 3', $r['address_nbsp'] );
		$this->assertNull( $r['email_idn'] );
		$this->assertNull( $r['email_local_utf'] );

		$this->assertSame( 'example.com', $r['domain_ascii'] );
		$this->assertNull( $r['domain_puny'], 'punycode cannot be verified without intl' );
		$this->assertNull( $r['domain_under'] );
		$this->assertNull( $r['domain_hyphen'] );

		$this->assertSame( '+36301234567', $r['phone'] );
		$this->assertSame( 'hu|1051|kossuth utca 3b', $r['address_ascii'] );
		$this->assertNull( $r['address_utf'] );

		$this->assertSame( [ 'email_hash', 'email_domain_hashes', 'phone_hash' ], $r['set_body_keys'] );
		$this->assertSame( [], $r['set_utf_email'], 'no request body without e-mail' );
	}
}
