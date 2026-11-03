<?php
/**
 * Checker tests: memo, transient cache, per-session quota, fail-open.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Checkout;

use LightweightPlugins\TrustOrder\Api\Client;
use LightweightPlugins\TrustOrder\Checkout\CacheGeneration;
use LightweightPlugins\TrustOrder\Checkout\Checker;
use LightweightPlugins\TrustOrder\Checkout\Counter;
use LightweightPlugins\TrustOrder\Checkout\QuotaGuard;
use LightweightPlugins\TrustOrder\Checkout\ResultCache;
use LightweightPlugins\TrustOrder\Identity\IdentitySet;
use LightweightPlugins\TrustOrder\Identity\MxCache;
use WP_Error;

final class CheckerTest extends CheckoutTestCase {

	private function ids( string $email = 'buyer@example.com' ): IdentitySet {
		return IdentitySet::fromOrderData( [ 'email' => $email, 'phone' => '+36301234567', 'billing_country' => 'HU' ] );
	}

	private function checker( string $session = 's:abc' ): Checker {
		return new Checker( new Client( self::KEY, 'https://api.example.test/v1/trustorder' ), 'test', $session );
	}

	public function test_request_body_has_identity_strictness_and_unknown_mx(): void {
		$this->answers = [ $this->verdict( 'block', 'blocked', 10 ) ];

		$d = $this->checker()->decide( $this->ids(), 'strict' );

		$this->assertSame( 'block', $d->verdict() );
		$body = $this->lastBody();
		$this->assertSame( $this->ids()->toCheckBody()['email_hash'], $body['email_hash'] );
		$this->assertSame( 'strict', $body['strictness'] );
		$this->assertArrayHasKey( 'email_domain_mx', $body );
		$this->assertNull( $body['email_domain_mx'] );
		$this->assertCount( 1, $GLOBALS['trustorder_test']['as'], 'unknown MX schedules a lookup' );
		$this->assertSame( MxCache::HOOK, $GLOBALS['trustorder_test']['as'][0]['hook'] );
	}

	public function test_known_mx_is_sent(): void {
		set_transient( 'trustorder_mx_' . hash( 'sha256', 'example.com' ), 'no', 3600 );

		$this->checker()->decide( $this->ids(), 'normal' );

		$this->assertFalse( $this->lastBody()['email_domain_mx'] );
		$this->assertSame( [], $GLOBALS['trustorder_test']['as'] );
	}

	public function test_memo_within_request(): void {
		$c = $this->checker();
		$c->decide( $this->ids(), 'normal' );
		$c->decide( $this->ids(), 'normal' );
		$c->decide( $this->ids(), 'normal' );

		$this->assertSame( 1, $this->checks() );
	}

	public function test_failures_are_memoised_too(): void {
		$this->answers = [ new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) ];
		$c             = $this->checker();

		$this->assertTrue( $c->decide( $this->ids(), 'normal' )->failedOpen() );
		$this->assertTrue( $c->decide( $this->ids(), 'normal' )->failedOpen() );
		$this->assertSame( 1, $this->checks(), 'a timeout must not be retried in the same request' );
	}

	public function test_transient_cache_across_requests(): void {
		$this->answers = [ $this->verdict( 'flag', 'risky', 40 ) ];
		$this->checker()->decide( $this->ids(), 'normal' );

		$d = $this->checker( 's:other' )->decide( $this->ids(), 'normal' );

		$this->assertSame( 'flag', $d->verdict() );
		$this->assertSame( 1, $this->checks() );

		$key = ResultCache::key( 'test', $this->ids()->cacheKey(), 'normal' );
		$this->assertSame( 'trustorder_r_' . md5( 'test|0|' . $this->ids()->cacheKey() . '|normal' ), $key );
		$this->assertSame( 600, $GLOBALS['trustorder_test']['transients'][ $key ]['ttl'] );
	}

	public function test_cache_key_includes_strictness_and_env(): void {
		$this->checker()->decide( $this->ids(), 'normal' );
		$this->checker()->decide( $this->ids(), 'strict' );
		( new Checker( new Client( self::KEY, 'https://api.example.test/v1/trustorder' ), 'live', 's:abc' ) )->decide( $this->ids(), 'normal' );

		$this->assertSame( 3, $this->checks() );
	}

	public function test_fail_open_results_are_not_cached(): void {
		$this->answers = [ $this->http( 503, '{"code":"INTERNAL"}' ), $this->verdict( 'block', 'blocked' ) ];
		$this->assertTrue( $this->checker()->decide( $this->ids(), 'normal' )->failedOpen() );

		$this->assertSame( 'block', $this->checker()->decide( $this->ids(), 'normal' )->verdict() );
		$this->assertSame( 2, $this->checks() );
	}

	public function test_quota_five_checks_per_session_then_last_result(): void {
		$this->answers = [ $this->verdict( 'allow' ), $this->verdict( 'allow' ), $this->verdict( 'allow' ), $this->verdict( 'allow' ), $this->verdict( 'block', 'blocked' ) ];

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->checker()->decide( $this->ids( "buyer{$i}@example.com" ), 'normal' );
		}
		$sixth = $this->checker()->decide( $this->ids( 'buyer6@example.com' ), 'normal' );

		$this->assertSame( QuotaGuard::MAX, $this->checks() );
		$this->assertSame( 'block', $sixth->verdict(), 'the last result of the session is reused' );

		$other = $this->checker( 's:fresh' )->decide( $this->ids( 'buyer7@example.com' ), 'normal' );
		$this->assertFalse( $other->failedOpen(), 'quota is per session' );
		$this->assertSame( 6, $this->checks() );
	}

	/**
	 * Drop the cross-request result cache, keep the session memory.
	 */
	private function dropResultCache(): void {
		foreach ( array_keys( $GLOBALS['trustorder_test']['transients'] ) as $name ) {
			if ( str_starts_with( $name, 'trustorder_r_' ) ) {
				unset( $GLOBALS['trustorder_test']['transients'][ $name ] );
			}
		}
	}

	/**
	 * Age every session-memory row by the given seconds.
	 */
	private function ageSessionMemory( int $seconds ): void {
		foreach ( $GLOBALS['trustorder_test']['transients'] as $name => $t ) {
			if ( str_starts_with( $name, 'trustorder_qs_' ) ) {
				foreach ( $t['value'] as $k => $row ) {
					$GLOBALS['trustorder_test']['transients'][ $name ]['value'][ $k ]['at'] = $row['at'] - $seconds;
				}
			}
		}
	}

	public function test_session_memory_younger_than_the_cache_ttl_is_reused(): void {
		$this->answers = [ $this->verdict( 'block', 'blocked', 10 ), $this->verdict( 'allow' ) ];
		$this->checker()->decide( $this->ids(), 'normal' );
		$this->dropResultCache();
		$this->ageSessionMemory( ResultCache::TTL - 30 );

		$this->assertSame( 'block', $this->checker()->decide( $this->ids(), 'normal' )->verdict() );
		$this->assertSame( 1, $this->checks() );
	}

	public function test_older_session_memory_is_rechecked_while_a_unit_is_left(): void {
		$this->answers = [ $this->verdict( 'block', 'blocked', 10 ), $this->verdict( 'allow' ) ];
		$this->checker()->decide( $this->ids(), 'normal' );
		$this->dropResultCache();
		$this->ageSessionMemory( ResultCache::TTL );

		$this->assertSame( 'allow', $this->checker()->decide( $this->ids(), 'normal' )->verdict() );
		$this->assertSame( 2, $this->checks() );
	}

	public function test_older_session_memory_is_still_reused_when_the_budget_is_spent(): void {
		$this->answers = [ $this->verdict( 'block', 'blocked', 10 ) ];
		$this->checker()->decide( $this->ids(), 'normal' );
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->assertNull( QuotaGuard::browse( 's:abc' ) );
		}
		$this->dropResultCache();
		$this->ageSessionMemory( ResultCache::TTL + 60 );

		$d = $this->checker()->decide( $this->ids(), 'normal' );

		$this->assertSame( 'block', $d->verdict() );
		$this->assertFalse( $d->isFallback() );
		$this->assertSame( 1, $this->checks() );
	}

	public function test_new_cache_generation_ignores_cached_and_remembered_results(): void {
		$this->answers = [ $this->verdict( 'block', 'blocked', 10 ), $this->verdict( 'allow' ) ];
		$this->checker()->decide( $this->ids(), 'normal' );

		CacheGeneration::bump();

		$this->assertSame( 'allow', $this->checker()->decide( $this->ids(), 'normal' )->verdict() );
		$this->assertSame( 2, $this->checks() );
	}

	public function test_new_cache_generation_drops_the_strictest_fallback(): void {
		$this->answers = [ $this->verdict( 'block', 'blocked', 10 ) ];
		$this->checker()->decide( $this->ids(), 'normal' );
		for ( $i = 1; $i <= 4; $i++ ) {
			QuotaGuard::browse( 's:abc' );
		}
		CacheGeneration::bump();

		$d = $this->checker()->decide( $this->ids( 'other@example.com' ), 'normal' );

		$this->assertTrue( $d->failedOpen(), 'a blocked verdict from before the exception change is not applied' );
		$this->assertSame( 1, $this->checks() );
	}

	public function test_quota_exhausted_without_last_result_fails_open(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertNull( QuotaGuard::browse( 's:abc' ) );
		}

		$d = $this->checker()->decide( $this->ids(), 'normal' );

		$this->assertTrue( $d->failedOpen() );
		$this->assertSame( 'SESSION_QUOTA', $d->errorCode() );
		$this->assertSame( 0, $this->checks() );
	}

	public function test_quota_window_expires(): void {
		$now = 1_800_000_000;
		Counter::setClock( static function () use ( &$now ): int {
			return $now;
		} );
		for ( $i = 1; $i <= 5; $i++ ) {
			QuotaGuard::browse( 's:abc' );
		}
		$this->assertSame( QuotaGuard::SESSION_QUOTA, QuotaGuard::browse( 's:abc' ) );

		$now += QuotaGuard::WINDOW;
		$this->assertNull( QuotaGuard::browse( 's:abc' ) );
	}

	public function test_quota_transient_name_has_no_raw_session_id(): void {
		QuotaGuard::browse( 's:secret-session' );

		foreach ( array_keys( $GLOBALS['trustorder_test']['transients'] ) as $name ) {
			$this->assertStringNotContainsString( 'secret', $name );
		}
	}

	public function test_empty_session_key_fails_open_without_request(): void {
		$d = $this->checker( '' )->decide( $this->ids(), 'normal' );

		$this->assertTrue( $d->failedOpen() );
		$this->assertSame( 0, $this->checks() );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public function errors(): array {
		$http = static fn( int $c, string $b ): array => [
			'headers'  => [],
			'body'     => $b,
			'response' => [ 'code' => $c, 'message' => '' ],
		];

		return [
			'network'      => [ new WP_Error( 'http_request_failed', 'Could not resolve host' ), 'NETWORK' ],
			'timeout'      => [ new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ), 'TIMEOUT' ],
			'429 quota'    => [ $http( 429, '{"code":"QUOTA_EXCEEDED"}' ), 'QUOTA_EXCEEDED' ],
			'429 rate'     => [ $http( 429, '{"code":"RATE_LIMITED"}' ), 'RATE_LIMITED' ],
			'503'          => [ $http( 503, '{"code":"INTERNAL"}' ), 'INTERNAL' ],
			'500 no json'  => [ $http( 500, 'oops' ), 'BAD_JSON' ],
			'401'          => [ $http( 401, '{"code":"UNAUTHORIZED"}' ), 'UNAUTHORIZED' ],
			'403'          => [ $http( 403, '{"code":"FORBIDDEN"}' ), 'FORBIDDEN' ],
			'400'          => [ $http( 400, '{"code":"INVALID_REQUEST"}' ), 'INVALID_REQUEST' ],
			'200 bad json' => [ $http( 200, '<html>' ), 'BAD_JSON' ],
			'200 bad body' => [ $http( 200, '{"verdict":"maybe"}' ), 'BAD_RESPONSE' ],
		];
	}

	/**
	 * @dataProvider errors
	 * @param mixed $answer Stubbed response.
	 */
	public function test_every_error_class_fails_open( $answer, string $code ): void {
		$this->answers = [ $answer ];

		$d = $this->checker()->decide( $this->ids(), 'normal' );

		$this->assertTrue( $d->failedOpen() );
		$this->assertSame( 'allow', $d->verdict() );
		$this->assertSame( $code, $d->errorCode() );
	}

	public function test_exception_inside_fails_open(): void {
		add_filter(
			'pre_http_request',
			static function () {
				throw new \RuntimeException( 'boom' );
			},
			5
		);

		$d = $this->checker()->decide( $this->ids(), 'normal' );

		$this->assertTrue( $d->failedOpen() );
		$this->assertSame( 'EXCEPTION', $d->errorCode() );
	}

	public function test_no_email_sends_nothing(): void {
		$d = $this->checker()->decide( IdentitySet::fromOrderData( [ 'email' => 'not-an-email', 'phone' => '+36301234567' ] ), 'normal' );

		$this->assertTrue( $d->failedOpen() );
		$this->assertSame( 'NO_EMAIL', $d->errorCode() );
		$this->assertSame( 0, $this->checks() );
	}

	public function test_no_env_sends_nothing(): void {
		$d = ( new Checker( new Client( '', 'https://api.example.test/v1/trustorder' ), '', 's:abc' ) )->decide( $this->ids(), 'normal' );

		$this->assertTrue( $d->failedOpen() );
		$this->assertSame( 'NO_KEY', $d->errorCode() );
		$this->assertSame( 0, $this->checks() );
	}

	public function test_peek_never_calls_the_api(): void {
		$this->assertNull( $this->checker()->peek( $this->ids(), 'normal' ) );
		$this->assertSame( 0, $this->checks() );

		$this->answers = [ $this->verdict( 'block', 'blocked' ) ];
		$this->checker()->decide( $this->ids(), 'normal' );
		$peeked = $this->checker( 's:x' )->peek( $this->ids(), 'normal' );

		$this->assertNotNull( $peeked );
		$this->assertSame( 'block', $peeked->verdict() );
		$this->assertSame( 1, $this->checks() );
	}

	public function test_current_uses_settings_env(): void {
		trustorder_test_wc();
		$this->assertSame( 'test', Checker::current()->env() );
		$this->assertSame( Checker::current(), Checker::current() );
	}
}
