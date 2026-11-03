<?php
/**
 * Budget exhaustion must never wash a blocked buyer; local caps fail open.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Checkout;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use LightweightPlugins\TrustOrder\Api\Client;
use LightweightPlugins\TrustOrder\Checkout\Checker;
use LightweightPlugins\TrustOrder\Checkout\ClassicCheckout;
use LightweightPlugins\TrustOrder\Checkout\Counter;
use LightweightPlugins\TrustOrder\Checkout\Enforcer;
use LightweightPlugins\TrustOrder\Checkout\QuotaGuard;
use LightweightPlugins\TrustOrder\Checkout\Scope;
use LightweightPlugins\TrustOrder\Identity\IdentitySet;
use LightweightPlugins\TrustOrder\Log;
use WP_Error;

final class QuotaBypassTest extends CheckoutTestCase {

	private const BLOCKED = 'blocked@example.com';

	protected function setUp(): void {
		parent::setUp();
		// Answer by e-mail hash: BLOCKED is blocked, everybody else allowed.
		$blocked = IdentitySet::fromOrderData( [ 'email' => self::BLOCKED ] )->toCheckBody()['email_hash'];
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( $blocked ) {
				$body = json_decode( (string) ( $args['body'] ?? '' ), true );
				return ( $body['email_hash'] ?? '' ) === $blocked ? $this->verdict( 'block', 'blocked', 10, [ 'LOW_SCORE' ] ) : $this->verdict( 'allow' );
			},
			20,
			2
		);
	}

	private function ids( string $email, array $extra = [] ): IdentitySet {
		return IdentitySet::fromOrderData( [ 'email' => $email ] + $extra );
	}

	private function checker( string $session = 's:abc' ): Checker {
		return new Checker( new Client( self::KEY, 'https://api.example.test/v1/trustorder' ), 'test', $session );
	}

	public function test_exhausted_browse_uses_strictest_seen_verdict_not_the_last_one(): void {
		$c = $this->checker();
		$this->assertSame( 'block', $c->decide( $this->ids( self::BLOCKED ), 'normal' )->verdict() );
		for ( $i = 1; $i <= 4; $i++ ) {
			$c->decide( $this->ids( "junk{$i}@example.com" ), 'normal' );
		}

		$d = $this->checker()->decide( $this->ids( 'junk5@example.com' ), 'normal' );

		$this->assertSame( 5, $this->checks() );
		$this->assertSame( 'block', $d->verdict(), 'strictest of the session, not the last (allow)' );
	}

	public function test_same_identity_is_reused_after_the_cache_expired(): void {
		$this->checker()->decide( $this->ids( self::BLOCKED ), 'normal' );
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->checker()->decide( $this->ids( "junk{$i}@example.com" ), 'normal' );
		}
		foreach ( array_keys( $GLOBALS['trustorder_test']['transients'] ) as $name ) {
			if ( str_starts_with( $name, 'trustorder_r_' ) ) {
				unset( $GLOBALS['trustorder_test']['transients'][ $name ] );
			}
		}

		$this->assertSame( 'allow', $this->checker()->decide( $this->ids( 'junk2@example.com' ), 'normal' )->verdict() );
		$this->assertSame( 5, $this->checks() );
	}

	public function test_junk_then_blocked_is_checked_at_submit(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->checker()->decide( $this->ids( "junk{$i}@example.com" ), 'normal' );
		}
		$browse = $this->checker()->decide( $this->ids( self::BLOCKED ), 'normal' );
		$this->assertSame( 'allow', $browse->verdict(), 'browsing: only allows were seen' );
		$this->assertSame( 5, $this->checks() );

		$submit = $this->checker()->decideForSubmit( $this->ids( self::BLOCKED ), 'normal' );

		$this->assertSame( 'block', $submit->verdict() );
		$this->assertSame( 6, $this->checks(), 'one real check from the submit budget' );
	}

	public function test_partial_identity_exhaustion_still_checks_the_final_identity(): void {
		$steps = [
			[],
			[ 'phone' => '+36301234567', 'billing_country' => 'HU' ],
			[ 'phone' => '+36301234568', 'billing_country' => 'HU' ],
			[ 'phone' => '+36301234569', 'billing_country' => 'HU' ],
			[ 'phone' => '+36301234560', 'billing_country' => 'HU' ],
		];
		foreach ( $steps as $extra ) {
			$this->checker()->decide( $this->ids( 'partial@example.com', $extra ), 'normal' );
		}
		$final = $this->ids( self::BLOCKED, [ 'phone' => '+36301234561', 'billing_country' => 'HU', 'needs_shipping' => true, 'shipping_country' => 'HU', 'shipping_postcode' => '1051', 'shipping_address_1' => 'Fő utca 3' ] );

		$this->assertSame( 'allow', $this->checker()->decide( $final, 'normal' )->verdict() );
		$this->assertSame( 'block', $this->checker()->decideForSubmit( $final, 'normal' )->verdict() );
		$this->assertSame( $final->toCheckBody()['address_hash'], $this->lastBody()['address_hash'] );
	}

	public function test_submit_budget_is_separate_and_capped(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->checker()->decide( $this->ids( "junk{$i}@example.com" ), 'normal' );
		}
		for ( $i = 1; $i <= QuotaGuard::SUBMIT_MAX; $i++ ) {
			$this->checker()->decideForSubmit( $this->ids( "submit{$i}@example.com" ), 'normal' );
		}
		$this->assertSame( 5 + QuotaGuard::SUBMIT_MAX, $this->checks() );

		$over = $this->checker()->decideForSubmit( $this->ids( 'unseen@example.com' ), 'normal' );
		$this->assertSame( 5 + QuotaGuard::SUBMIT_MAX, $this->checks() );
		$this->assertSame( 'block', $over->verdict(), 'spent submit budget: risky gateways stay hidden' );
		$this->assertFalse( $over->failedOpen() );
		$this->assertSame( QuotaGuard::SUBMIT_QUOTA, $over->fallbackCode() );

		$known = $this->checker()->decideForSubmit( $this->ids( 'submit2@example.com' ), 'normal' );
		$this->assertSame( 'allow', $known->verdict(), 'an identity checked before keeps its own verdict' );
	}

	public function test_submit_errors_still_fail_open(): void {
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ), 30 );

		$d = $this->checker()->decideForSubmit( $this->ids( self::BLOCKED ), 'normal' );

		$this->assertTrue( $d->failedOpen() );
	}

	public function test_ip_cap_across_rotating_sessions(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		add_filter( 'trustorder_ip_check_limit', static fn(): int => 3 );
		try {
			for ( $i = 1; $i <= 5; $i++ ) {
				$this->checker( "s:rotating{$i}" )->decide( $this->ids( "junk{$i}@example.com" ), 'normal' );
			}
			$this->assertSame( 3, $this->checks() );
			$this->assertSame( QuotaGuard::IP_QUOTA, get_option( Log::LAST_ERROR_OPTION )['code'] );
			foreach ( array_keys( $GLOBALS['trustorder_test']['transients'] ) as $name ) {
				$this->assertStringNotContainsString( '203.0.113.7', $name );
			}
		} finally {
			unset( $_SERVER['REMOTE_ADDR'] );
		}
	}

	public function test_shop_circuit_breaker_fails_open_without_calling(): void {
		add_filter( 'trustorder_hourly_check_limit', static fn(): int => 2 );

		for ( $i = 1; $i <= 4; $i++ ) {
			$d = $this->checker( "s:shop{$i}" )->decide( $this->ids( "junk{$i}@example.com" ), 'normal' );
		}

		$this->assertSame( 2, $this->checks() );
		$this->assertTrue( $d->failedOpen() );
		$this->assertSame( QuotaGuard::LOCAL_QUOTA, $d->errorCode() );
		$logs = array_filter( $GLOBALS['trustorder_test']['logs'], static fn( array $l ): bool => str_contains( $l['message'], 'LOCAL_QUOTA' ) || str_contains( (string) json_encode( $l['context'] ), 'LOCAL_QUOTA' ) );
		$this->assertCount( 1, $logs, 'logged once per request' );
	}

	public function test_classic_place_order_junk_then_blocked_is_rejected(): void {
		ClassicCheckout::register();
		Enforcer::register();
		$this->onCheckout( [ 'billing_email' => self::BLOCKED ] );
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->checker( 's:sess-1' )->decide( $this->ids( "junk{$i}@example.com" ), 'normal' );
		}
		$this->assertSame( [ 'bacs', 'cod' ], $this->visibleGateways(), 'browsing after exhaustion: allow seen only' );

		Checker::reset();
		do_action( 'woocommerce_checkout_process' );
		$errors = new WP_Error();
		$this->assertSame( [ 'bacs' ], $this->visibleGateways(), 'validate_checkout sees the submit decision' );
		do_action( 'woocommerce_after_checkout_validation', [ 'payment_method' => 'cod' ], $errors );

		$this->assertSame( [ 'trustorder_payment_not_allowed' ], $errors->get_error_codes() );
	}

	public function test_store_api_place_order_junk_then_blocked_is_rejected(): void {
		Enforcer::register();
		$wc            = trustorder_test_wc( self::customer( self::BLOCKED ) );
		$wc->store_api = true;
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->checker( 's:sess-1' )->decide( $this->ids( "junk{$i}@example.com" ), 'normal' );
		}
		Scope::setRoute( '/wc/store/v1/checkout' );

		$this->expectException( RouteException::class );
		do_action( 'woocommerce_store_api_checkout_update_customer_from_request', WC()->customer, new \WP_REST_Request( 'POST', '/wc/store/v1/checkout', [ 'payment_method' => 'cod' ] ) );
	}

	/**
	 * Store API place order (the update-customer hook) for an e-mail and gateway.
	 */
	private function storeApiSubmit( string $email, string $gateway ): ?string {
		Checker::reset();
		\LightweightPlugins\TrustOrder\Checkout\Gatekeeper::reset();
		$wc            = trustorder_test_wc( self::customer( $email ) );
		$wc->store_api = true;
		Scope::setRoute( '/wc/store/v1/checkout' );
		try {
			do_action( 'woocommerce_store_api_checkout_update_customer_from_request', WC()->customer, new \WP_REST_Request( 'POST', '/wc/store/v1/checkout', [ 'payment_method' => $gateway ] ) );
		} catch ( RouteException $e ) {
			return $e->getErrorCode();
		}

		return null;
	}

	public function test_three_junk_submits_then_blocked_cod_is_rejected(): void {
		Enforcer::register();
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->assertNull( $this->storeApiSubmit( "junk{$i}@example.com", 'bogus_gateway' ) );
		}
		$this->assertSame( 3, $this->checks() );

		$this->assertSame( 'trustorder_payment_not_allowed', $this->storeApiSubmit( self::BLOCKED, 'cod' ) );
		$this->assertSame( 3, $this->checks(), 'no 4th check: the spent budget hides COD' );
		$this->assertNull( $this->storeApiSubmit( self::BLOCKED, 'bacs' ), 'the buyer can still pay' );
	}

	public function test_submit_ip_cap_exhausted_unseen_identity_cod_rejected(): void {
		Enforcer::register();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		add_filter( 'trustorder_submit_ip_check_limit', static fn(): int => 2 );
		try {
			$this->checker( 's:a' )->decideForSubmit( $this->ids( 'x1@example.com' ), 'normal' );
			$this->checker( 's:b' )->decideForSubmit( $this->ids( 'x2@example.com' ), 'normal' );

			$this->assertSame( 'trustorder_payment_not_allowed', $this->storeApiSubmit( 'never-seen@example.com', 'cod' ) );
			$this->assertSame( 2, $this->checks() );
		} finally {
			unset( $_SERVER['REMOTE_ADDR'] );
		}
	}

	public function test_submit_ignores_browse_caps_and_the_breaker(): void {
		add_filter( 'trustorder_hourly_check_limit', static fn(): int => 1 );
		add_filter( 'trustorder_ip_check_limit', static fn(): int => 5 );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
		try {
			$this->checker( 's:a' )->decide( $this->ids( 'b1@example.com' ), 'normal' );
			$this->assertTrue( $this->checker( 's:b' )->decide( $this->ids( 'b2@example.com' ), 'normal' )->failedOpen() );
			$this->assertSame( 1, $this->checks() );

			$this->assertSame( 'block', $this->checker( 's:c' )->decideForSubmit( $this->ids( self::BLOCKED ), 'normal' )->verdict() );
			$this->assertSame( 2, $this->checks(), 'real submit check despite browse caps' );
			$this->assertTrue( QuotaGuard::breakerTripped() );
		} finally {
			unset( $_SERVER['REMOTE_ADDR'] );
		}
	}

	public function test_a_denying_cap_consumes_no_session_unit(): void {
		add_filter( 'trustorder_hourly_check_limit', static fn(): int => 0 );
		for ( $i = 1; $i <= 7; $i++ ) {
			$this->checker()->decide( $this->ids( "n{$i}@example.com" ), 'normal' );
		}
		remove_all_filters_for_test( 'trustorder_hourly_check_limit' );

		$this->assertFalse( $this->checker()->decide( $this->ids( 'fresh@example.com' ), 'normal' )->failedOpen(), 'session budget untouched' );
	}

	public function test_fallback_decision_carries_no_foreign_details(): void {
		$this->checker()->decide( $this->ids( self::BLOCKED ), 'normal' );
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->checker()->decide( $this->ids( "junk{$i}@example.com" ), 'normal' );
		}
		$d = $this->checker()->decide( $this->ids( 'other@example.com' ), 'normal' );

		$this->assertSame( 'block', $d->verdict() );
		$this->assertSame( QuotaGuard::SESSION_QUOTA, $d->fallbackCode() );
		$this->assertSame( '', $d->checkId() );
		$this->assertSame( 0, $d->score() );
		$this->assertSame( [], $d->reasons() );
		$this->assertSame( [ 'verdict' => 'block', 'fallback' => 'SESSION_QUOTA' ], $d->toArray() );
	}

	public function test_denial_rolls_back_atomically_with_an_object_cache(): void {
		$GLOBALS['trustorder_test']['ext_cache'] = true;
		add_filter( 'trustorder_hourly_check_limit', static fn(): int => 1 );
		$this->checker( 's:a' )->decide( $this->ids( 'a1@example.com' ), 'normal' );

		$d = $this->checker( 's:b' )->decide( $this->ids( 'b1@example.com' ), 'normal' );

		$this->assertTrue( $d->failedOpen() );
		$this->assertSame( 0, \LightweightPlugins\TrustOrder\Checkout\Counter::count( 's:b', QuotaGuard::WINDOW ), 'session unit given back' );
		$this->assertSame( 1, \LightweightPlugins\TrustOrder\Checkout\Counter::count( 'shop', QuotaGuard::SHOP_WINDOW ) );
		$this->assertContains( 'decr', array_column( $GLOBALS['trustorder_test']['cache_ops'], 0 ) );
		$this->assertSame( [], $GLOBALS['trustorder_test']['transients'] ? array_filter( array_keys( $GLOBALS['trustorder_test']['transients'] ), static fn( $n ) => str_starts_with( $n, 'trustorder_q_' ) ) : [] );
	}

	public function test_outage_refunds_at_most_three_units_per_session(): void {
		$outage = static fn() => new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
		add_filter( 'pre_http_request', $outage, 30 );
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->assertTrue( $this->checker()->decideForSubmit( $this->ids( self::BLOCKED ), 'normal' )->failedOpen() );
			$this->assertSame( 0, Counter::count( 'submit|s:abc', QuotaGuard::WINDOW ), "refund {$i}" );
		}
		$this->checker()->decideForSubmit( $this->ids( self::BLOCKED ), 'normal' );
		$this->assertSame( 1, Counter::count( 'submit|s:abc', QuotaGuard::WINDOW ), 'the 4th outage unit stays spent' );
		$this->assertSame( 4, $this->checks(), 'each retry gets a real check' );

		remove_all_filters_for_test( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			fn() => $this->verdict( 'block', 'blocked' )
		);
		$after = $this->checker()->decideForSubmit( $this->ids( self::BLOCKED ), 'normal' );
		$this->assertSame( 'block', $after->verdict() );
		$this->assertFalse( $after->isFallback(), 'a real check after the outage, not the budget fallback' );
	}

	/**
	 * @return array<string, array{0: int, 1: string, 2: bool}>
	 */
	public function submitFailures(): array {
		$problem = static fn( string $code ): string => (string) json_encode( [ 'code' => $code, 'title' => 'x' ] );

		return [
			'503 problem'      => [ 503, $problem( 'UNAVAILABLE' ), true ],
			'500 bad json'     => [ 500, '<html>oops</html>', true ],
			'502 empty'        => [ 502, '', true ],
			'401'              => [ 401, $problem( 'UNAUTHORIZED' ), false ],
			'402'              => [ 402, $problem( 'PAYMENT_REQUIRED' ), false ],
			'403'              => [ 403, $problem( 'FORBIDDEN' ), false ],
			'429'              => [ 429, $problem( 'QUOTA_EXCEEDED' ), false ],
			'400'              => [ 400, $problem( 'VALIDATION' ), false ],
			'404 bad json'     => [ 404, 'nope', false ],
			'200 bad response' => [ 200, (string) json_encode( [ 'verdict' => 'maybe' ] ), false ],
		];
	}

	/**
	 * @dataProvider submitFailures
	 */
	public function test_only_server_outages_refund_the_submit_unit( int $status, string $body, bool $refunded ): void {
		add_filter( 'pre_http_request', fn() => $this->http( $status, $body ), 30 );

		$d = $this->checker()->decideForSubmit( $this->ids( self::BLOCKED ), 'normal' );

		$this->assertTrue( $d->failedOpen() );
		$this->assertSame( $refunded ? 0 : 1, Counter::count( 'submit|s:abc', QuotaGuard::WINDOW ) );
	}

	public function test_network_error_refunds_the_submit_unit(): void {
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'http_request_failed', 'Could not resolve host' ), 30 );

		$this->assertSame( 'NETWORK', $this->checker()->decideForSubmit( $this->ids( self::BLOCKED ), 'normal' )->errorCode() );
		$this->assertSame( 0, Counter::count( 'submit|s:abc', QuotaGuard::WINDOW ) );
	}

	public function test_refund_hits_the_exact_keys_across_a_slot_boundary(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.40';
		$now = intdiv( 1_800_000_000, QuotaGuard::WINDOW ) * QuotaGuard::WINDOW + QuotaGuard::WINDOW - 1;
		Counter::setClock( static function () use ( &$now ): int {
			return $now;
		} );
		// The slot rolls over while the request is in flight.
		add_filter(
			'pre_http_request',
			static function () use ( &$now ) {
				++$now;
				return new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
			},
			30
		);
		try {
			$this->checker()->decideForSubmit( $this->ids( self::BLOCKED ), 'normal' );
			$this->assertSame( 0, Counter::count( 'submit|s:abc', QuotaGuard::WINDOW ), 'new slot not driven below zero' );

			--$now;
			$this->assertSame( 0, Counter::count( 'submit|s:abc', QuotaGuard::WINDOW ), 'old slot refunded' );
			$this->assertSame( 0, Counter::count( 'submit-ip|198.51.100.40', QuotaGuard::WINDOW ) );
		} finally {
			unset( $_SERVER['REMOTE_ADDR'] );
		}
	}

	public function test_failing_object_cache_still_enforces_the_submit_cap(): void {
		$GLOBALS['trustorder_test']['ext_cache']       = true;
		$GLOBALS['trustorder_test']['cache_incr_fail'] = true;
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->assertFalse( $this->checker()->decideForSubmit( $this->ids( "u{$i}@example.com" ), 'normal' )->isFallback(), "submit {$i}" );
		}

		$d = $this->checker()->decideForSubmit( $this->ids( 'u4@example.com' ), 'normal' );

		$this->assertSame( 3, $this->checks() );
		$this->assertSame( 'block', $d->verdict() );
		$this->assertSame( QuotaGuard::SUBMIT_QUOTA, $d->fallbackCode() );
		$this->assertSame( 3, Counter::count( 'submit|s:abc', QuotaGuard::WINDOW, true ), 'counted in the transient copy' );
		$this->assertSame( [], array_filter( $GLOBALS['trustorder_test']['logs'], static fn( array $l ): bool => str_contains( (string) json_encode( $l ), QuotaGuard::COUNTER_UNAVAILABLE ) ) );
	}

	public function test_refund_decrements_the_transient_copy(): void {
		$GLOBALS['trustorder_test']['ext_cache']       = true;
		$GLOBALS['trustorder_test']['cache_incr_fail'] = true;
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ), 30 );

		$this->assertTrue( $this->checker()->decideForSubmit( $this->ids( self::BLOCKED ), 'normal' )->failedOpen() );

		$this->assertSame( 0, Counter::count( 'submit|s:abc', QuotaGuard::WINDOW, true ) );
		$this->assertSame( 1, Counter::count( 'refund|s:abc', QuotaGuard::WINDOW, true ) );
		$this->assertNotContains( 'decr', array_column( $GLOBALS['trustorder_test']['cache_ops'], 0 ) );
	}

	public function test_counter_unavailable_fails_open_and_logs_once(): void {
		$GLOBALS['trustorder_test']['ext_cache']       = true;
		$GLOBALS['trustorder_test']['cache_incr_fail'] = true;
		$GLOBALS['trustorder_test']['transient_fail']  = true;
		$_SERVER['REMOTE_ADDR'] = '198.51.100.41';
		try {
			for ( $i = 1; $i <= 5; $i++ ) {
				$d = $this->checker()->decideForSubmit( $this->ids( "u{$i}@example.com" ), 'normal' );
				$this->assertFalse( $d->isFallback(), "submit {$i} is a real check" );
			}
			for ( $i = 1; $i <= 7; $i++ ) {
				$this->assertFalse( $this->checker()->decide( $this->ids( "v{$i}@example.com" ), 'normal' )->failedOpen() );
			}
			$this->assertSame( 12, $this->checks() );
			$this->assertNotContains( 'decr', array_column( $GLOBALS['trustorder_test']['cache_ops'], 0 ) );
			$logs = array_filter( $GLOBALS['trustorder_test']['logs'], static fn( array $l ): bool => str_contains( (string) json_encode( $l ), QuotaGuard::COUNTER_UNAVAILABLE ) );
			$this->assertCount( 1, $logs, 'logged once per request' );
		} finally {
			unset( $_SERVER['REMOTE_ADDR'] );
		}
	}

	public function test_network_refunds_are_capped_per_network(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.42';
		add_filter( 'trustorder_submit_ip_check_limit', static fn(): int => 50 );
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ), 30 );
		try {
			for ( $i = 1; $i <= 6; $i++ ) {
				$this->checker( "s:n{$i}" )->decideForSubmit( $this->ids( "n{$i}@example.com" ), 'normal' );
			}
			$this->assertSame( 0, Counter::count( 'submit-ip|198.51.100.42', QuotaGuard::WINDOW ), '6 network refunds' );

			$this->checker( 's:n7' )->decideForSubmit( $this->ids( 'n7@example.com' ), 'normal' );
			$this->assertSame( 1, Counter::count( 'submit-ip|198.51.100.42', QuotaGuard::WINDOW ), 'the 7th network unit stays spent' );
			$this->assertSame( 0, Counter::count( 'submit|s:n7', QuotaGuard::WINDOW ), 'the session unit is still refunded' );

			add_filter( 'trustorder_network_refund_limit', static fn(): int => 100 );
			$this->checker( 's:n8' )->decideForSubmit( $this->ids( 'n8@example.com' ), 'normal' );
			$this->assertSame( 1, Counter::count( 'submit-ip|198.51.100.42', QuotaGuard::WINDOW ), 'filterable' );
		} finally {
			unset( $_SERVER['REMOTE_ADDR'] );
		}
	}

	public function test_blocked_buyer_is_still_hidden_when_the_counter_errors(): void {
		$GLOBALS['trustorder_test']['ext_cache']       = true;
		$GLOBALS['trustorder_test']['cache_incr_fail'] = true;

		$d = $this->checker()->decideForSubmit( $this->ids( self::BLOCKED ), 'normal' );

		$this->assertSame( 'block', $d->verdict() );
		$this->assertFalse( $d->isFallback() );
	}

	public function test_private_proxy_address_has_no_network_cap(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
		add_filter( 'trustorder_ip_check_limit', static fn(): int => 1 );
		add_filter( 'trustorder_submit_ip_check_limit', static fn(): int => 1 );
		try {
			for ( $i = 1; $i <= 3; $i++ ) {
				$this->checker( "s:p{$i}" )->decide( $this->ids( "p{$i}@example.com" ), 'normal' );
				$this->checker( "s:q{$i}" )->decideForSubmit( $this->ids( "q{$i}@example.com" ), 'normal' );
			}
			$this->assertSame( 6, $this->checks() );
			$this->assertFalse( QuotaGuard::submitCapTripped() );
		} finally {
			unset( $_SERVER['REMOTE_ADDR'] );
		}
	}

	public function test_submit_network_trip_is_marked_once(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.30';
		add_filter( 'trustorder_submit_ip_check_limit', static fn(): int => 1 );
		try {
			$this->checker( 's:a' )->decideForSubmit( $this->ids( 'x1@example.com' ), 'normal' );
			$this->checker( 's:b' )->decideForSubmit( $this->ids( 'x2@example.com' ), 'normal' );
			$this->assertTrue( QuotaGuard::submitCapTripped() );
			$GLOBALS['trustorder_test']['transients'][ QuotaGuard::SUBMIT_IP_TRANSIENT ]['value'] = 123;

			QuotaGuard::reset();
			$this->checker( 's:c' )->decideForSubmit( $this->ids( 'x3@example.com' ), 'normal' );
			$this->assertSame( 123, get_transient( QuotaGuard::SUBMIT_IP_TRANSIENT ), 'not rewritten while set' );
		} finally {
			unset( $_SERVER['REMOTE_ADDR'] );
		}
	}
}
