<?php
/**
 * API key tests.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit;

use LightweightPlugins\TrustOrder\Api\ApiKey;
use PHPUnit\Framework\TestCase;

final class ApiKeyTest extends TestCase {

	public function test_env_from_prefix(): void {
		$this->assertSame( 'live', ApiKey::env( 'to_live_' . str_repeat( 'a', 32 ) ) );
		$this->assertSame( 'test', ApiKey::env( 'to_test_' . str_repeat( 'Z9', 16 ) ) );
	}

	public function test_env_null_for_invalid_keys(): void {
		$this->assertNull( ApiKey::env( '' ) );
		$this->assertNull( ApiKey::env( 'to_live_' . str_repeat( 'a', 31 ) ) );
		$this->assertNull( ApiKey::env( 'to_live_' . str_repeat( 'a', 33 ) ) );
		$this->assertNull( ApiKey::env( 'to_dev_' . str_repeat( 'a', 32 ) ) );
		$this->assertNull( ApiKey::env( 'TO_LIVE_' . str_repeat( 'a', 32 ) ) );
		$this->assertNull( ApiKey::env( 'to_live_' . str_repeat( 'a', 32 ) . "\n" ) );
		$this->assertNull( ApiKey::env( ' to_live_' . str_repeat( 'a', 32 ) ) );
	}

	public function test_is_valid(): void {
		$this->assertTrue( ApiKey::isValid( 'to_test_' . str_repeat( '0', 32 ) ) );
		$this->assertFalse( ApiKey::isValid( 'to_test_' . str_repeat( '_', 32 ) ) );
	}
}
