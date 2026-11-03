<?php
/**
 * GET /connection, POST /connection/test.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Rest;

use LightweightPlugins\TrustOrder\Checkout\QuotaGuard;
use LightweightPlugins\TrustOrder\Log;
use LightweightPlugins\TrustOrder\Settings;
use WP_Error;

final class ConnectionControllerTest extends RestTestCase {

	private const ME = [
		'shop_id'           => 12,
		'shop_name'         => 'Példa <b>bolt</b>',
		'domain'            => 'pelda.hu',
		'env'               => 'test',
		'account_status'    => 'pending',
		'daily_check_quota' => 5000,
		'checks_today'      => 132,
		'extra'             => 'dropped',
	];

	public function test_test_without_key_makes_no_request(): void {
		$this->err( $this->call( 'POST', '/connection/test' ), 'trustorder_no_key', 400 );
		$this->assertSame( [], $this->requests() );
	}

	public function test_test_returns_the_allow_listed_me_fields(): void {
		Settings::update( [ 'api_key' => self::TEST_KEY ] );
		$this->answers = [ self::http( 200, self::ME ) ];

		$data = $this->ok( $this->call( 'POST', '/connection/test' ) );

		$this->assertStringEndsWith( '/me', $this->requests()[0]['url'] );
		$this->assertSame(
			[
				'shop_name'         => 'Példa bolt',
				'domain'            => 'pelda.hu',
				'env'               => 'test',
				'account_status'    => 'pending',
				'daily_check_quota' => 5000,
				'checks_today'      => 132,
			],
			$data['me']
		);
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT/', $data['tested_at'] );
	}

	/**
	 * @return array<string, array{array<string, mixed>|WP_Error, string, int}>
	 */
	public static function failures(): array {
		$problem = static fn( int $s, string $c ): array => self::http( $s, [ 'type' => 'about:blank', 'title' => 't', 'status' => $s, 'detail' => 'buyer@example.com', 'code' => $c ] );

		return [
			'unauthorized' => [ $problem( 401, 'UNAUTHORIZED' ), 'UNAUTHORIZED', 401 ],
			'forbidden'    => [ $problem( 403, 'FORBIDDEN' ), 'FORBIDDEN', 403 ],
			'rate limited' => [ $problem( 429, 'RATE_LIMITED' ), 'RATE_LIMITED', 429 ],
			'timeout'      => [ new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ), 'TIMEOUT', 0 ],
			'bad json'     => [ self::http( 200, 'oops' ), 'BAD_JSON', 200 ],
		];
	}

	/**
	 * @dataProvider failures
	 *
	 * @param array<string, mixed>|WP_Error $answer Answer.
	 */
	public function test_test_passes_api_errors_through_as_a_502_with_the_code( $answer, string $code, int $status ): void {
		Settings::update( [ 'api_key' => self::TEST_KEY ] );
		$this->answers = [ $answer ];

		$err  = $this->err( $this->call( 'POST', '/connection/test' ), 'trustorder_api_error', 502 );
		$data = $err->get_error_data();

		$this->assertSame( $code, $data['api_code'] );
		$this->assertSame( $status, $data['api_status'] );
		$this->assertStringNotContainsString( 'buyer@example.com', (string) json_encode( [ $err->get_error_message(), $data ] ) );
		$this->assertStringNotContainsString( self::TEST_KEY, (string) json_encode( [ $err->get_error_message(), $data ] ) );
	}

	public function test_state_reports_the_local_status(): void {
		Settings::update( [ 'api_key' => self::LIVE_KEY, 'enabled' => false ] );
		Log::error( 'api', [ 'code' => 'UNAUTHORIZED', 'status' => 401, 'route' => '/check' ] );
		set_transient( QuotaGuard::BREAKER_TRANSIENT, time(), 3600 );
		$GLOBALS['trustorder_test']['hpos'] = false;

		$data = $this->ok( $this->call( 'GET', '/connection' ) );

		$this->assertTrue( $data['has_key'] );
		$this->assertSame( 'live', $data['env'] );
		$this->assertSame( substr( self::LIVE_KEY, 0, 12 ), $data['key_prefix'] );
		$this->assertFalse( $data['enabled'] );
		$this->assertSame( 'api', $data['last_error']['class'] );
		$this->assertSame( 'UNAUTHORIZED', $data['last_error']['code'] );
		$this->assertSame( 401, $data['last_error']['status'] );
		$this->assertIsInt( $data['last_error']['at'] );
		$this->assertIsBool( $data['intl'] );
		$this->assertFalse( $data['hpos'] );
		$this->assertTrue( $data['breaker'] );
		$this->assertFalse( $data['submit_cap'] );
		$this->assertArrayHasKey( 'blocks_checkout', $data );
		$this->assertStringNotContainsString( self::LIVE_KEY, (string) json_encode( $data ) );
		$this->assertSame( [], $this->requests() );
	}

	public function test_state_without_error_or_key(): void {
		$data = $this->ok( $this->call( 'GET', '/connection' ) );

		$this->assertFalse( $data['has_key'] );
		$this->assertNull( $data['env'] );
		$this->assertNull( $data['last_error'] );
	}

	public function test_corrupt_last_error_is_ignored(): void {
		update_option( Log::LAST_ERROR_OPTION, [ 'class' => 'buyer@example.com', 'at' => 'x' ] );

		$data = $this->ok( $this->call( 'GET', '/connection' ) );

		$this->assertNull( $data['last_error'] );
	}
}
