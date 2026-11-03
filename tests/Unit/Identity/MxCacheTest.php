<?php
/**
 * MX cache: transient read, background lookup scheduling, handler.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Identity;

use LightweightPlugins\TrustOrder\Identity\MxCache;
use PHPUnit\Framework\TestCase;

final class MxCacheTest extends TestCase {

	protected function setUp(): void {
		trustorder_test_reset();
	}

	/**
	 * Deterministic DNS: domains in $up resolve, everything else does not.
	 *
	 * @param array<string, bool> $up Domain => resolves.
	 */
	private function fakeDns( array $up ): void {
		add_filter( 'trustorder_pre_mx_lookup', static fn( $pre, string $domain ): bool => $up[ $domain ] ?? false, 10, 2 );
	}

	/**
	 * Schedule then run the queued job, like Action Scheduler would.
	 *
	 * @param string $domain Domain.
	 */
	private function scheduleAndRun( string $domain ): void {
		MxCache::schedule( $domain );
		$as = $GLOBALS['trustorder_test']['as'];
		$this->assertNotEmpty( $as );
		MxCache::lookup( ...end( $as )['args'] );
	}

	/**
	 * Transient by name prefix.
	 *
	 * @param string $prefix Prefix.
	 * @return array<string, array{value: mixed, ttl: int}>
	 */
	private function transients( string $prefix ): array {
		return array_filter(
			$GLOBALS['trustorder_test']['transients'],
			static fn( string $k ): bool => str_starts_with( $k, $prefix ),
			ARRAY_FILTER_USE_KEY
		);
	}

	public function test_unknown_domain_is_null(): void {
		$this->assertNull( MxCache::get( 'example.com' ) );
	}

	public function test_each_domain_gets_its_own_action_and_duplicates_are_skipped(): void {
		MxCache::schedule( 'example.com' );
		MxCache::schedule( 'example.org' );
		MxCache::schedule( 'example.com' );

		$as = $GLOBALS['trustorder_test']['as'];
		$this->assertCount( 2, $as );
		foreach ( $as as $a ) {
			$this->assertSame( 'trustorder_mx_lookup', $a['hook'] );
			$this->assertSame( 'trustorder', $a['group'] );
			$this->assertFalse( $a['unique'], 'AS < 4.0 would match hook+group only' );
		}
		$this->assertSame( [ hash( 'sha256', 'example.com' ) ], $as[0]['args'] );
		$this->assertSame( [ hash( 'sha256', 'example.org' ) ], $as[1]['args'] );
	}

	public function test_action_args_and_transient_names_contain_no_domain(): void {
		MxCache::schedule( 'example.com' );

		$this->assertStringNotContainsString( 'example', serialize( $GLOBALS['trustorder_test']['as'] ) );
		$this->assertStringNotContainsString( 'example', implode( ',', array_keys( $GLOBALS['trustorder_test']['transients'] ) ) );
		$park = $this->transients( 'trustorder_mxq_' );
		$this->assertCount( 1, $park );
		$this->assertSame( HOUR_IN_SECONDS, array_values( $park )[0]['ttl'] );
	}

	public function test_lookup_caches_positive_for_a_day_and_drops_the_parked_domain(): void {
		$this->fakeDns( [ 'example.com' => true ] );
		$this->scheduleAndRun( 'example.com' );

		$this->assertTrue( MxCache::get( 'example.com' ) );
		$this->assertSame( DAY_IN_SECONDS, array_values( $this->transients( 'trustorder_mx_' ) )[0]['ttl'] );
		$this->assertSame( [], $this->transients( 'trustorder_mxq_' ) );
	}

	public function test_no_mx_is_cached_for_an_hour_when_the_canary_resolves(): void {
		$this->fakeDns( [ 'gmail.com' => true ] );
		$this->scheduleAndRun( 'no-mx.example' );

		$this->assertFalse( MxCache::get( 'no-mx.example' ) );
		$this->assertSame( HOUR_IN_SECONDS, array_values( $this->transients( 'trustorder_mx_' ) )[0]['ttl'] );
	}

	public function test_dns_failure_writes_nothing(): void {
		$this->fakeDns( [] );
		$this->scheduleAndRun( 'example.com' );

		$this->assertNull( MxCache::get( 'example.com' ) );
		$this->assertSame( [], $GLOBALS['trustorder_test']['transients'] );
		$this->assertSame( 'DNS_UNAVAILABLE', $GLOBALS['trustorder_test']['logs'][0]['context']['code'] ?? null );
		$this->assertStringNotContainsString( 'example', serialize( $GLOBALS['trustorder_test']['logs'] ) );
	}

	public function test_canary_domain_is_filterable(): void {
		add_filter( 'trustorder_mx_canary', static fn(): string => 'canary.test' );
		$this->fakeDns( [ 'canary.test' => true ] );
		$this->scheduleAndRun( 'no-mx.example' );

		$this->assertFalse( MxCache::get( 'no-mx.example' ) );
	}

	public function test_lookup_ignores_bad_or_unknown_args(): void {
		$this->fakeDns( [ 'example.com' => true ] );
		MxCache::lookup( 'example.com' );
		MxCache::lookup( [ 'x' ] );
		MxCache::lookup( hash( 'sha256', 'never-parked.example' ) );
		// A parked value that does not hash to its key is ignored.
		set_transient( 'trustorder_mxq_' . hash( 'sha256', 'a.example' ), 'example.com', HOUR_IN_SECONDS );
		MxCache::lookup( hash( 'sha256', 'a.example' ) );

		$this->assertSame( [], $this->transients( 'trustorder_mx_' ) );
	}

	public function test_schedule_skips_cached_and_invalid_domains(): void {
		$this->fakeDns( [ 'example.com' => true ] );
		$this->scheduleAndRun( 'example.com' );
		$GLOBALS['trustorder_test']['as'] = [];

		MxCache::schedule( 'example.com' );
		MxCache::schedule( 'not a domain' );
		MxCache::schedule( 'EXAMPLE.org' );

		$this->assertSame( [], $GLOBALS['trustorder_test']['as'] );
	}

	public function test_register_hooks_the_handler(): void {
		MxCache::register();

		$this->assertSame( 10, has_action( 'trustorder_mx_lookup', [ MxCache::class, 'lookup' ] ) );
	}
}
