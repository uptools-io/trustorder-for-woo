<?php
/**
 * Shop-wide browsing back-off after an API outage: no browsing /check for
 * 60 s, same decision as an outage, no unit spent. Place order never backs off.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Checkout;

use LightweightPlugins\TrustOrder\Api\Client;
use LightweightPlugins\TrustOrder\Checkout\Checker;
use LightweightPlugins\TrustOrder\Checkout\Counter;
use LightweightPlugins\TrustOrder\Checkout\Decision;
use LightweightPlugins\TrustOrder\Checkout\QuotaGuard;
use LightweightPlugins\TrustOrder\Identity\IdentitySet;
use WP_Error;

final class ApiBackoffTest extends CheckoutTestCase {

	private function ids( string $email = 'buyer@example.com' ): IdentitySet {
		return IdentitySet::fromOrderData( [ 'email' => $email, 'phone' => '+36301234567', 'billing_country' => 'HU' ] );
	}

	private function checker( string $session = 's:abc', string $env = 'test' ): Checker {
		return new Checker( new Client( self::KEY, 'https://api.example.test/v1/trustorder' ), $env, $session );
	}

	private function flag( string $env = 'test' ): mixed {
		return $GLOBALS['trustorder_test']['transients'][ 'trustorder_api_down_' . $env ] ?? null;
	}

	private function down( string $env = 'test' ): void {
		set_transient( Checker::apiDown( $env ), time(), 60 );
	}

	/**
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public function answers(): array {
		$http = static fn( int $c, string $b ): array => [
			'headers'  => [],
			'body'     => $b,
			'response' => [ 'code' => $c, 'message' => '' ],
		];

		return [
			'timeout'      => [ new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ), true ],
			'network'      => [ new WP_Error( 'http_request_failed', 'Could not resolve host' ), true ],
			'503'          => [ $http( 503, '{"code":"INTERNAL"}' ), true ],
			'500 no json'  => [ $http( 500, 'oops' ), true ],
			'401'          => [ $http( 401, '{"code":"UNAUTHORIZED"}' ), false ],
			'429'          => [ $http( 429, '{"code":"RATE_LIMITED"}' ), false ],
			'200 bad body' => [ $http( 200, '{"verdict":"maybe"}' ), false ],
		];
	}

	/**
	 * @dataProvider answers
	 * @param mixed $answer Stubbed response.
	 */
	public function test_only_an_outage_sets_the_flag_for_60_seconds( $answer, bool $outage ): void {
		$this->answers = [ $answer ];

		$this->checker()->decide( $this->ids(), 'normal' );

		if ( $outage ) {
			$this->assertNotNull( $this->flag() );
			$this->assertSame( 60, $this->flag()['ttl'] );
		} else {
			$this->assertNull( $this->flag() );
		}
	}

	public function test_browsing_during_the_back_off_makes_no_call_and_spends_nothing(): void {
		$this->answers = [ new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) ];
		$this->checker( 's:first' )->decide( $this->ids( 'first@example.com' ), 'normal' );
		$this->assertSame( 1, $this->checks() );

		$d = $this->checker()->decide( $this->ids(), 'normal' );

		$this->assertSame( 1, $this->checks(), 'no /check while the API is down' );
		$this->assertTrue( $d->failedOpen() );
		$this->assertSame( 'TIMEOUT', $d->errorCode() );
		$this->assertSame( 0, Counter::count( 's:abc', QuotaGuard::WINDOW ), 'no browsing unit spent' );
		$this->assertSame( 1, Counter::count( 'shop', QuotaGuard::SHOP_WINDOW ), 'only the first (real) check counted' );
	}

	public function test_place_order_during_the_back_off_still_checks_and_spends_like_today(): void {
		$this->down();
		$this->answers = [ $this->verdict( 'block', 'blocked' ) ];

		$d = $this->checker()->decideForSubmit( $this->ids(), 'normal' );

		$this->assertSame( 1, $this->checks(), 'a real place-order check' );
		$this->assertSame( 'block', $d->verdict() );
		$this->assertSame( 1, Counter::count( 'submit|s:abc', QuotaGuard::WINDOW ), 'the unit is spent' );
		$this->assertNull( $this->flag(), 'a real answer clears the flag' );
	}

	public function test_place_order_outage_during_the_back_off_is_refunded_within_the_bound(): void {
		$this->down();
		$this->answers = [ new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) ];

		for ( $i = 1; $i <= QuotaGuard::REFUND_MAX; $i++ ) {
			$this->assertTrue( $this->checker()->decideForSubmit( $this->ids(), 'normal' )->failedOpen() );
			$this->assertSame( 0, Counter::count( 'submit|s:abc', QuotaGuard::WINDOW ), "refund {$i}" );
		}
		$this->checker()->decideForSubmit( $this->ids(), 'normal' );

		$this->assertSame( QuotaGuard::REFUND_MAX + 1, $this->checks(), 'every place order is a real check' );
		$this->assertSame( 1, Counter::count( 'submit|s:abc', QuotaGuard::WINDOW ), 'past REFUND_MAX the unit stays spent' );
		$this->assertNotNull( $this->flag() );
	}

	public function test_an_outage_while_flagged_does_not_rewrite_the_flag(): void {
		set_transient( Checker::apiDown( 'test' ), 12345, 60 );
		$this->answers = [ new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) ];

		$this->checker()->decideForSubmit( $this->ids(), 'normal' );

		$this->assertSame( 1, $this->checks() );
		$this->assertSame( 12345, $this->flag()['value'], 'no option write per failing request' );
	}

	public function test_a_browse_back_off_in_the_same_request_does_not_answer_the_place_order(): void {
		$this->down();
		$this->answers = [ $this->verdict( 'block', 'blocked' ) ];
		$c             = $this->checker();

		$this->assertTrue( $c->decide( $this->ids(), 'normal' )->failedOpen() );
		$this->assertSame( 0, $this->checks() );
		$this->assertNull( $c->peek( $this->ids(), 'normal' ), 'nothing a place order would reuse' );

		$this->assertSame( 'block', $c->decideForSubmit( $this->ids(), 'normal' )->verdict() );
		$this->assertSame( 1, $this->checks() );
	}

	public function test_the_flag_is_per_env(): void {
		$this->answers = [ new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ), $this->verdict( 'flag', 'risky' ) ];
		$this->checker()->decide( $this->ids( 'first@example.com' ), 'normal' );
		$this->assertNotNull( $this->flag( 'test' ) );
		$this->assertNull( $this->flag( 'live' ) );

		$live = $this->checker( 's:abc', 'live' )->decide( $this->ids(), 'normal' );

		$this->assertSame( 2, $this->checks(), 'a test-key outage does not hold back live' );
		$this->assertSame( 'flag', $live->verdict() );
		$this->assertNotNull( $this->flag( 'test' ), 'a live answer does not clear the test flag' );
	}

	public function test_a_spent_place_order_budget_keeps_its_block_fallback_during_the_back_off(): void {
		for ( $i = 1; $i <= QuotaGuard::SUBMIT_MAX; $i++ ) {
			$this->checker()->decideForSubmit( $this->ids( "buyer{$i}@example.com" ), 'normal' );
		}
		$this->down();

		$d = $this->checker()->decideForSubmit( $this->ids( 'late@example.com' ), 'normal' );

		$this->assertSame( QuotaGuard::SUBMIT_MAX, $this->checks() );
		$this->assertTrue( $d->isFallback(), 'exactly what the spent budget gives without an outage' );
		$this->assertSame( Decision::BLOCK, $d->verdict() );
		$this->assertSame( QuotaGuard::SUBMIT_QUOTA, $d->fallbackCode() );
	}

	public function test_a_spent_browsing_budget_keeps_its_fallback_during_the_back_off(): void {
		for ( $i = 1; $i <= QuotaGuard::MAX; $i++ ) {
			$this->checker()->decide( $this->ids( "buyer{$i}@example.com" ), 'normal' );
		}
		$this->down();

		$d = $this->checker()->decide( $this->ids( 'late@example.com' ), 'normal' );

		$this->assertSame( QuotaGuard::MAX, $this->checks() );
		$this->assertTrue( $d->isFallback() );
		$this->assertSame( QuotaGuard::SESSION_QUOTA, $d->fallbackCode() );
	}

	public function test_known_results_are_still_served_during_the_back_off(): void {
		$this->answers = [ $this->verdict( 'block', 'blocked' ) ];
		$this->checker()->decide( $this->ids(), 'normal' );
		$this->down();

		$d = $this->checker( 's:other' )->decide( $this->ids(), 'normal' );

		$this->assertSame( 'block', $d->verdict(), 'the cached verdict, not a fail-open' );
		$this->assertSame( 1, $this->checks() );
	}

	public function test_the_first_non_outage_response_clears_the_flag(): void {
		// Another request flags an outage while this one is already calling.
		add_filter(
			'pre_http_request',
			static function ( $pre ) {
				set_transient( Checker::apiDown( 'test' ), time(), 60 );
				return $pre;
			},
			5
		);
		$this->answers = [ $this->verdict( 'allow' ) ];

		$d = $this->checker()->decide( $this->ids(), 'normal' );

		$this->assertFalse( $d->failedOpen() );
		$this->assertNull( $this->flag() );
	}

	public function test_after_the_flag_expires_the_api_is_asked_again(): void {
		$this->answers = [ new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ), $this->verdict( 'flag', 'risky' ) ];
		$this->checker()->decide( $this->ids( 'first@example.com' ), 'normal' );
		delete_transient( Checker::apiDown( 'test' ) ); // 60 s later.

		$d = $this->checker()->decide( $this->ids(), 'normal' );

		$this->assertSame( 2, $this->checks() );
		$this->assertSame( 'flag', $d->verdict() );
	}
}
