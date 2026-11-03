<?php
/**
 * Client hash known answer.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Identity;

use LightweightPlugins\TrustOrder\Identity\Hasher;
use PHPUnit\Framework\TestCase;

final class HasherTest extends TestCase {

	public function test_known_answer(): void {
		// Same constant as the Go TestClientHashKnownAnswer (computed with sha256sum).
		$this->assertSame( '643fef12886302f0503c1e0e25ca3ea9a540e43fb681af01fec129df7f912a4a', Hasher::client( 'email', 'a@b.hu' ) );
	}

	public function test_kind_separates_domains(): void {
		$this->assertNotSame( Hasher::client( 'email', 'x' ), Hasher::client( 'domain', 'x' ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/D', Hasher::client( 'phone', '' ) );
	}
}
