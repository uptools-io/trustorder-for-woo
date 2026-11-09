<?php
/**
 * SignalLock: atomic object-cache lock with a persistent cache, option lock without.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Signals;

use LightweightPlugins\TrustOrder\Signals\SignalLock;
use PHPUnit\Framework\TestCase;

final class SignalLockTest extends TestCase {

	protected function setUp(): void {
		trustorder_test_reset();
	}

	public function test_with_a_persistent_object_cache_the_lock_is_an_atomic_cache_add(): void {
		$GLOBALS['trustorder_test']['ext_cache'] = true;

		$this->assertTrue( SignalLock::acquire( 42 ) );
		$this->assertFalse( SignalLock::acquire( 42 ), 'held' );
		$this->assertTrue( SignalLock::acquire( 43 ), 'another order' );

		$this->assertSame( [ 'add', 'lock_42', SignalLock::TTL ], $GLOBALS['trustorder_test']['cache_ops'][0] );
		$this->assertEqualsWithDelta( time() + SignalLock::TTL, $GLOBALS['trustorder_test']['cache'][ SignalLock::CACHE_GROUP ]['lock_42'], 2 );
		$this->assertSame( [], array_filter( array_keys( $GLOBALS['trustorder_test']['options'] ), static fn( $k ) => str_starts_with( $k, SignalLock::PREFIX ) ), 'no option row with a persistent cache' );

		SignalLock::release( 42 );

		$this->assertContains( [ 'lock_42', SignalLock::CACHE_GROUP ], $GLOBALS['trustorder_test']['cache_deletes'] );
		$this->assertTrue( SignalLock::acquire( 42 ), 'released' );
	}

	public function test_without_a_persistent_object_cache_the_option_lock_is_used(): void {
		$this->assertTrue( SignalLock::acquire( 42 ) );
		$this->assertFalse( SignalLock::acquire( 42 ) );
		$this->assertArrayHasKey( 'trustorder_lock_42', $GLOBALS['trustorder_test']['options'] );
		$this->assertFalse( $GLOBALS['trustorder_test']['autoload']['trustorder_lock_42'] );
		$this->assertArrayNotHasKey( 'cache_ops', $GLOBALS['trustorder_test'] );

		SignalLock::release( 42 );

		$this->assertArrayNotHasKey( 'trustorder_lock_42', $GLOBALS['trustorder_test']['options'] );
		$this->assertTrue( SignalLock::acquire( 42 ) );
	}

	public function test_without_a_persistent_object_cache_an_expired_lock_is_taken_over(): void {
		update_option( 'trustorder_lock_42', time() - 1, false );

		$this->assertTrue( SignalLock::acquire( 42 ) );
		$this->assertGreaterThanOrEqual( time(), $GLOBALS['trustorder_test']['options']['trustorder_lock_42'] );
	}
}
