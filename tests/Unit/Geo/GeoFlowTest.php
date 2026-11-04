<?php
/**
 * Opt-in geo sharing: when it is scheduled, what is sent, retry and give-up.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Geo;

use LightweightPlugins\TrustOrder\Geo\GeoJob;
use LightweightPlugins\TrustOrder\Geo\GeoRouter;
use LightweightPlugins\TrustOrder\Settings;
use LightweightPlugins\TrustOrder\Tests\Unit\Signals\SignalTestCase;
use WP_Error;

final class GeoFlowTest extends SignalTestCase {

	protected function setUp(): void {
		parent::setUp();
		GeoRouter::register();
		Settings::update( [ 'share_geo' => true ] );
	}

	/**
	 * POST /geo calls so far (decoded bodies).
	 *
	 * @return list<mixed>
	 */
	private function geoCalls(): array {
		$out = [];
		foreach ( $this->calls() as $c ) {
			if ( '/geo' === $c[1] ) {
				$this->assertSame( 'POST', $c[0] );
				$out[] = $c[2];
			}
		}
		return $out;
	}

	/**
	 * Every geo action ever scheduled (any status).
	 *
	 * @return list<array<string, mixed>>
	 */
	private function geoActions(): array {
		return array_values( array_filter( $GLOBALS['trustorder_test']['as'], static fn( array $a ): bool => GeoRouter::HOOK === $a['hook'] ) );
	}

	/**
	 * Run the first pending geo action once.
	 */
	private function runGeoOnce(): bool {
		foreach ( $GLOBALS['trustorder_test']['as'] as $i => $a ) {
			if ( GeoRouter::HOOK === $a['hook'] && 'pending' === $a['status'] ) {
				$GLOBALS['trustorder_test']['as'][ $i ]['status'] = 'in-progress';
				do_action( $a['hook'], ...$a['args'] );
				$GLOBALS['trustorder_test']['as'][ $i ]['status'] = 'complete';
				return true;
			}
		}
		return false;
	}

	public function test_fresh_install_shares_without_touching_the_setting(): void {
		$stored = get_option( Settings::OPTION, [] );
		unset( $stored['share_geo'] );
		update_option( Settings::OPTION, $stored );
		$this->transition( $this->order( [ 'status' => 'pending' ] ), 'processing' );

		$this->assertCount( 1, $this->geoActions() );
		$this->assertSame( [ [ 'country' => 'HU', 'postcode' => '1051' ] ], $this->geoCalls() );
	}

	public function test_setting_off_schedules_nothing(): void {
		Settings::update( [ 'share_geo' => false ] );
		$this->transition( $this->order( [ 'status' => 'pending' ] ), 'processing' );

		$this->assertSame( [], $this->geoActions() );
		$this->assertSame( [], $this->geoCalls() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function sendingStatuses(): array {
		return [
			'processing' => [ 'processing' ],
			'on-hold'    => [ 'on-hold' ],
			'completed'  => [ 'completed' ],
		];
	}

	/**
	 * @dataProvider sendingStatuses
	 */
	public function test_first_paid_status_sends_exactly_country_and_postcode( string $to ): void {
		$o = $this->order( [ 'status' => 'pending', 'billing_postcode' => '6720', 'billing_country' => 'AT' ] );
		$this->transition( $o, $to );

		$this->assertCount( 1, $this->geoActions() );
		$this->assertSame( [ GeoRouter::GROUP, [ 42 ] ], [ $this->geoActions()[0]['group'], $this->geoActions()[0]['args'] ] );
		$this->assertSame( [ [ 'country' => 'HU', 'postcode' => '1051' ] ], $this->geoCalls() );
		$raw = array_values( array_filter( $GLOBALS['trustorder_test']['http'], static fn( array $c ): bool => str_ends_with( $c['url'], '/geo' ) ) );
		$this->assertSame( '{"country":"HU","postcode":"1051"}', $raw[0]['args']['body'] );
		$this->assertSame( '1', $o->get_meta( GeoJob::META ) );
		$this->assertArrayNotHasKey( GeoJob::ATTEMPTS_META, $o->meta );
	}

	public function test_a_later_status_after_a_sent_geo_schedules_nothing(): void {
		$o = $this->order( [ 'status' => 'pending' ] );
		$this->transition( $o, 'processing' );
		$this->transition( $o, 'completed' );
		$this->transition( $o, 'on-hold' );

		$this->assertCount( 1, $this->geoActions() );
		$this->assertCount( 1, $this->geoCalls() );
	}

	public function test_billing_is_used_when_shipping_has_no_postcode_or_country(): void {
		$a = $this->order( [ 'status' => 'pending', 'shipping_postcode' => '', 'billing_country' => 'hu', 'billing_postcode' => ' 6720 ' ] );
		$this->transition( $a, 'processing' );
		$b = $this->order( [ 'id' => 43, 'status' => 'pending', 'shipping_country' => '', 'billing_country' => 'SK', 'billing_postcode' => '811 01' ] );
		$this->transition( $b, 'processing' );

		$this->assertSame(
			[
				[ 'country' => 'HU', 'postcode' => '6720' ],
				[ 'country' => 'SK', 'postcode' => '81101' ],
			],
			$this->geoCalls()
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function unusablePostcodes(): array {
		return [
			'n/a'              => [ 'n/a' ],
			'with a city name' => [ '1051 Budapest' ],
			'slash'            => [ '10/51' ],
			'too long'         => [ '12345678901' ],
			'only dashes'      => [ '--' ],
		];
	}

	/**
	 * @dataProvider unusablePostcodes
	 */
	public function test_a_postcode_the_backend_would_refuse_is_skipped_silently( string $postcode ): void {
		$o = $this->order( [ 'status' => 'pending', 'shipping_postcode' => $postcode, 'billing_postcode' => '' ] );
		$this->transition( $o, 'processing' );

		$this->assertSame( [], $this->geoCalls() );
		$this->assertSame( [], $this->pending( GeoRouter::HOOK ) );
		$this->assertSame( '', $o->get_meta( GeoJob::META ) );
		$this->assertArrayNotHasKey( GeoJob::ATTEMPTS_META, $o->meta );
		$this->assertArrayNotHasKey( 'trustorder_last_error', $GLOBALS['trustorder_test']['options'] );
		$this->assertSame( [], $GLOBALS['trustorder_test']['logs'] );
	}

	public function test_the_postcode_is_sent_normalised(): void {
		$o = $this->order( [ 'status' => 'pending', 'shipping_postcode' => 'H-1051' ] );
		$this->transition( $o, 'processing' );

		$this->assertSame( [ [ 'country' => 'HU', 'postcode' => '1051' ] ], $this->geoCalls() );
	}

	public function test_missing_postcode_or_country_sends_nothing_and_does_not_retry(): void {
		$o = $this->order( [ 'status' => 'pending', 'shipping_postcode' => '', 'billing_country' => 'HU', 'billing_postcode' => '' ] );
		$this->transition( $o, 'processing' );
		$p = $this->order( [ 'id' => 43, 'status' => 'pending', 'shipping_country' => 'X1', 'billing_country' => '', 'billing_postcode' => '1051' ] );
		$this->transition( $p, 'processing' );

		$this->assertSame( [], $this->geoCalls() );
		$this->assertSame( [], $this->pending( GeoRouter::HOOK ) );
		$this->assertSame( '', $o->get_meta( GeoJob::META ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function nonSending(): array {
		return [
			'checkout-draft → pending' => [ 'checkout-draft', 'pending' ],
			'pending → failed'         => [ 'pending', 'failed' ],
			'pending → cancelled'      => [ 'pending', 'cancelled' ],
		];
	}

	/**
	 * @dataProvider nonSending
	 */
	public function test_other_transitions_schedule_nothing( string $from, string $to ): void {
		$this->transition( $this->order( [ 'status' => $from ] ), $to );

		$this->assertSame( [], $this->geoActions() );
	}

	public function test_503_retries_with_backoff_then_succeeds(): void {
		$this->answers = [ self::problem( 503, 'INTERNAL' ), self::http( 204, '' ) ];
		$o             = $this->order( [ 'status' => 'pending' ] );
		$this->transition( $o, 'processing', false );

		$ran = time();
		$this->assertTrue( $this->runGeoOnce() );
		$this->assertSame( '', $o->get_meta( GeoJob::META ) );
		$this->assertSame( 1, (int) $o->get_meta( GeoJob::ATTEMPTS_META ) );
		$next = $this->pending( GeoRouter::HOOK );
		$this->assertCount( 1, $next );
		$this->assertSame( 1, (int) round( ( $next[0]['time'] - $ran ) / 60 ) );

		$this->assertTrue( $this->runGeoOnce() );
		$this->assertSame( '1', $o->get_meta( GeoJob::META ) );
		$this->assertArrayNotHasKey( GeoJob::ATTEMPTS_META, $o->meta );
		$this->assertCount( 2, $this->geoCalls() );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public function retryable(): array {
		return [
			'429'     => [ self::problem( 429, 'RATE_LIMITED' ), 'RATE_LIMITED' ],
			'503'     => [ self::problem( 503, 'INTERNAL' ), 'INTERNAL' ],
			'network' => [ new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' ), 'NETWORK' ],
		];
	}

	/**
	 * @dataProvider retryable
	 */
	public function test_gives_up_after_the_retry_budget_and_logs_without_postcode( $answer, string $code ): void {
		$this->answers = [ $answer ];
		$o             = $this->order( [ 'status' => 'pending' ] );
		$this->transition( $o, 'processing', false );

		$delays = [];
		while ( true ) {
			$ran = time();
			$this->assertTrue( $this->runGeoOnce() );
			$next = $this->pending( GeoRouter::HOOK );
			if ( [] === $next ) {
				break;
			}
			$delays[] = (int) round( ( $next[0]['time'] - $ran ) / 60 );
		}

		$this->assertSame( [ 1, 5, 30, 120 ], $delays );
		$this->assertCount( 5, $this->geoCalls() );
		$this->assertSame( '', $o->get_meta( GeoJob::META ) );
		$this->assertArrayNotHasKey( GeoJob::ATTEMPTS_META, $o->meta );
		$this->assertStringContainsString( 'TrustOrder error: geo code=' . $code, $this->logged() );
		$this->assertStringNotContainsString( '1051', $this->logged() );
	}

	/**
	 * @return array<string, array{0: int, 1: string}>
	 */
	public function fatal(): array {
		return [
			'400' => [ 400, 'INVALID_REQUEST' ],
			'401' => [ 401, 'UNAUTHORIZED' ],
			'403' => [ 403, 'FORBIDDEN' ],
		];
	}

	/**
	 * @dataProvider fatal
	 */
	public function test_client_errors_give_up_at_once_with_the_code( int $status, string $code ): void {
		$this->answers = [ self::problem( $status, $code ) ];
		$o             = $this->order( [ 'status' => 'pending' ] );
		$this->transition( $o, 'processing' );

		$this->assertCount( 1, $this->geoCalls() );
		$this->assertSame( [], $this->pending( GeoRouter::HOOK ) );
		$this->assertSame( '', $o->get_meta( GeoJob::META ) );
		$this->assertArrayNotHasKey( GeoJob::ATTEMPTS_META, $o->meta );
		$this->assertStringContainsString( 'TrustOrder error: geo code=' . $code, $this->logged() );
		$this->assertStringNotContainsString( '1051', $this->logged() );
	}

	public function test_setting_turned_off_before_the_job_runs_sends_nothing(): void {
		$o = $this->order( [ 'status' => 'pending' ] );
		$this->transition( $o, 'processing', false );
		Settings::update( [ 'share_geo' => false ] );
		$this->drain();

		$this->assertSame( [], $this->geoCalls() );
		$this->assertSame( '', $o->get_meta( GeoJob::META ) );
	}

	public function test_no_api_key_sends_nothing(): void {
		Settings::update( [ 'api_key' => '' ] );
		$this->transition( $this->order( [ 'status' => 'pending' ] ), 'processing' );

		$this->assertSame( [], $this->geoCalls() );
	}

	public function test_key_removed_before_the_job_runs_sends_nothing(): void {
		$this->transition( $this->order( [ 'status' => 'pending' ] ), 'processing', false );
		update_option( Settings::OPTION, [ 'api_key' => 'not-a-key', 'share_geo' => true ] );
		$this->drain();

		$this->assertSame( [], $this->geoCalls() );
	}

	public function test_untrusted_job_args_do_nothing(): void {
		$this->order( [ 'status' => 'processing' ] );
		GeoJob::handle( 'abc' );
		GeoJob::handle( [ 42 ] );
		GeoJob::handle( 0 );

		$this->assertSame( [], $this->geoCalls() );
	}

	public function test_off_at_first_entry_stays_unsent_after_switching_on(): void {
		Settings::update( [ 'share_geo' => false ] );
		$o = $this->order( [ 'status' => 'pending' ] );
		$this->transition( $o, 'processing' );
		$this->assertSame( '1', $o->get_meta( GeoJob::DECIDED_META ) );
		Settings::update( [ 'share_geo' => true ] );
		$this->transition( $o, 'completed' );

		$this->assertSame( [], $this->geoActions() );
		$this->assertSame( [], $this->geoCalls() );
	}

	public function test_an_order_already_past_its_first_entry_is_never_sent(): void {
		$o = $this->order( [ 'status' => 'on-hold' ] ); // No metas: an order from before the update.
		$this->transition( $o, 'processing' );
		$this->transition( $o, 'completed' );

		$this->assertSame( [], $this->geoActions() );
		$this->assertSame( [], $this->geoCalls() );
	}

	public function test_a_decided_off_order_is_not_sent_when_it_comes_back_from_cancelled(): void {
		Settings::update( [ 'share_geo' => false ] );
		$o = $this->order( [ 'status' => 'pending' ] );
		$this->transition( $o, 'processing' );
		$this->transition( $o, 'cancelled' );
		Settings::update( [ 'share_geo' => true ] );
		$this->transition( $o, 'processing' );

		$this->assertSame( [], $this->geoActions() );
		$this->assertSame( [], $this->geoCalls() );
	}

	public function test_the_normal_first_entry_is_sent_once_and_marked_decided(): void {
		$o = $this->order( [ 'status' => 'pending' ] );
		$this->transition( $o, 'processing' );
		$this->transition( $o, 'cancelled' );
		$this->transition( $o, 'processing' );

		$this->assertSame( '1', $o->get_meta( GeoJob::DECIDED_META ) );
		$this->assertCount( 1, $this->geoActions() );
		$this->assertCount( 1, $this->geoCalls() );
	}

	/**
	 * @return array<string, array{0: int}>
	 */
	public function serverErrors(): array {
		return [
			'500' => [ 500 ],
			'502' => [ 502 ],
			'504' => [ 504 ],
		];
	}

	/**
	 * @dataProvider serverErrors
	 */
	public function test_any_5xx_schedules_a_retry( int $status ): void {
		$this->answers = [ self::problem( $status, 'INTERNAL' ) ];
		$o             = $this->order( [ 'status' => 'pending' ] );
		$this->transition( $o, 'processing', false );
		$this->assertTrue( $this->runGeoOnce() );

		$this->assertCount( 1, $this->pending( GeoRouter::HOOK ) );
		$this->assertSame( 1, (int) $o->get_meta( GeoJob::ATTEMPTS_META ) );
		$this->assertSame( '', $o->get_meta( GeoJob::META ) );
	}
}
