<?php
/**
 * Window counters: transient and atomic object-cache paths.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Checkout;

use LightweightPlugins\TrustOrder\Checkout\Counter;
use PHPUnit\Framework\TestCase;

final class CounterTest extends TestCase {

	protected function setUp(): void {
		trustorder_test_reset();
		Counter::reset();
		Counter::setClock( static fn(): int => 1_800_000_100 );
	}

	protected function tearDown(): void {
		Counter::setClock( null );
	}

	public function test_transient_path(): void {
		Counter::incr( 'b', 1800 );
		Counter::incr( 'b', 1800 );

		$this->assertSame( 2, Counter::count( 'b', 1800 ) );
		$this->assertSame( 0, Counter::count( 'other', 1800 ) );
		$key = Counter::key( 'b', 1800 );
		$this->assertSame( 1800, $GLOBALS['trustorder_test']['transients'][ $key ]['ttl'] );
		$this->assertArrayNotHasKey( 'cache_ops', $GLOBALS['trustorder_test'] );
	}

	public function test_object_cache_path_is_add_then_incr(): void {
		$GLOBALS['trustorder_test']['ext_cache'] = true;

		Counter::incr( 'b', 1800 );
		Counter::incr( 'b', 1800 );
		Counter::incr( 'b', 1800 );

		$this->assertSame( 3, Counter::count( 'b', 1800 ) );
		$ops = array_column( $GLOBALS['trustorder_test']['cache_ops'], 0 );
		$this->assertSame( [ 'add', 'incr', 'add', 'incr', 'add', 'incr' ], $ops );
		$this->assertSame( 1800, $GLOBALS['trustorder_test']['cache_ops'][0][2] );
		$this->assertSame( [], $GLOBALS['trustorder_test']['transients'], 'no transient writes' );
	}

	public function test_incr_returns_the_new_count_and_decr_takes_back(): void {
		foreach ( [ false, true ] as $ext ) {
			trustorder_test_reset();
			$GLOBALS['trustorder_test']['ext_cache'] = $ext;
			$this->assertSame( 1, Counter::incr( 'b', 60 ) );
			$this->assertSame( 2, Counter::incr( 'b', 60 ) );
			Counter::decr( 'b', 60 );
			$this->assertSame( 1, Counter::count( 'b', 60 ) );
			Counter::decr( 'b', 60 );
			Counter::decr( 'b', 60 );
			$this->assertSame( 0, Counter::count( 'b', 60 ), 'never below zero' );
		}
	}

	public function test_new_window_slot_starts_from_zero(): void {
		$now = 1_800_000_100;
		Counter::setClock( static function () use ( &$now ): int {
			return $now;
		} );
		Counter::incr( 'b', 1800 );
		$now += 1800;

		$this->assertSame( 0, Counter::count( 'b', 1800 ) );
	}

	public function test_key_has_no_raw_bucket(): void {
		$this->assertStringNotContainsString( '203.0.113.7', Counter::key( 'ip|203.0.113.7', 1800 ) );
		$this->assertMatchesRegularExpression( '/^trustorder_q_[0-9a-f]{32}$/', Counter::key( 'x', 60 ) );
	}

	public function test_a_failing_object_cache_falls_back_to_the_transient_copy(): void {
		$GLOBALS['trustorder_test']['ext_cache']       = true;
		$GLOBALS['trustorder_test']['cache_incr_fail'] = true;
		$key = Counter::key( 'b', 60 );

		$t = false;
		$this->assertSame( 1, Counter::incrKey( $key, 60, $t ) );
		$this->assertTrue( $t, 'marked as the transient copy' );
		$this->assertSame( 2, Counter::incr( 'b', 60 ) );
		$this->assertSame( 2, Counter::count( 'b', 60, true ) );
		$this->assertSame( 60, $GLOBALS['trustorder_test']['transients'][ $key ]['ttl'] );
		$incrs = array_filter( $GLOBALS['trustorder_test']['cache_ops'], static fn( array $o ): bool => 'incr' === $o[0] );
		$this->assertCount( 1, $incrs, 'the failed key stays on the transient copy for the request' );

		Counter::decrKey( $key, 60, true );
		$this->assertSame( 1, Counter::count( 'b', 60, true ), 'decremented in the transient copy' );
		$this->assertNotContains( 'decr', array_column( $GLOBALS['trustorder_test']['cache_ops'], 0 ) );
	}

	public function test_unavailable_only_when_the_transient_fallback_fails_too(): void {
		$GLOBALS['trustorder_test']['ext_cache']       = true;
		$GLOBALS['trustorder_test']['cache_incr_fail'] = true;
		$GLOBALS['trustorder_test']['transient_fail']  = true;

		$this->assertNull( Counter::incr( 'b', 60 ) );
	}

	public function test_decr_key_targets_the_given_slot_and_skips_non_positive_values(): void {
		foreach ( [ false, true ] as $ext ) {
			trustorder_test_reset();
			$GLOBALS['trustorder_test']['ext_cache'] = $ext;
			$now = 1_800_000_100;
			Counter::setClock( static function () use ( &$now ): int {
				return $now;
			} );
			$old = Counter::key( 'b', 1800 );
			Counter::incrKey( $old, 1800 );
			$now += 1800;
			Counter::incr( 'b', 1800 );

			Counter::decrKey( $old, 1800 );
			$this->assertSame( 1, Counter::count( 'b', 1800 ), 'current slot untouched' );
			$GLOBALS['trustorder_test']['cache_ops'] = [];
			Counter::decrKey( $old, 1800 );
			Counter::decrKey( Counter::key( 'never', 1800 ), 1800 );
			$this->assertNotContains( 'decr', array_column( $GLOBALS['trustorder_test']['cache_ops'], 0 ), 'no decr at zero' );
			$now -= 1800;
			$this->assertSame( 0, Counter::count( 'b', 1800 ), 'old slot back to zero' );
		}
	}

	public function test_an_unchanged_transient_write_still_counts(): void {
		$GLOBALS['trustorder_test']['transient_same_write'] = true;

		$this->assertSame( 1, Counter::incr( 'b', 60 ), 'set_transient false but the value is stored' );
		$this->assertSame( 2, Counter::incr( 'b', 60 ) );
	}

	public function test_a_lower_read_back_is_unavailable(): void {
		$GLOBALS['trustorder_test']['transient_fail'] = true;
		$key = Counter::key( 'b', 60 );
		$GLOBALS['trustorder_test']['transients'][ $key ] = [ 'value' => 0, 'ttl' => 60 ];

		$this->assertNull( Counter::incr( 'b', 60 ) );
	}
}
