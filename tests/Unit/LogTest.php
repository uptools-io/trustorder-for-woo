<?php
/**
 * Log tests: only allow-listed scalar context survives.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit;

use LightweightPlugins\TrustOrder\Log;
use PHPUnit\Framework\TestCase;

final class LogTest extends TestCase {

	protected function setUp(): void {
		trustorder_test_reset();
	}

	public function test_only_allow_listed_scalar_context_is_kept(): void {
		Log::error(
			'api',
			[
				'code'   => 'TIMEOUT',
				'status' => 0,
				'route'  => '/check',
				'email'  => 'someone@example.com',
				'key'    => 'to_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			]
		);

		$log = $GLOBALS['trustorder_test']['logs'][0];
		$this->assertSame( 'error', $log['level'] );
		$this->assertSame( [ 'source' => 'trustorder', 'code' => 'TIMEOUT', 'status' => 0, 'route' => '/check' ], $log['context'] );
		$this->assertStringNotContainsString( 'example.com', serialize( $log ) );
		$this->assertStringContainsString( 'api', $log['message'] );
	}

	public function test_non_scalar_or_unsafe_values_are_dropped(): void {
		Log::error(
			'api',
			[
				'code'   => [ 'x' ],
				'status' => 'someone@example.com',
				'route'  => "/check\nINJECTED",
			]
		);

		$log = $GLOBALS['trustorder_test']['logs'][0];
		$this->assertSame( [ 'source' => 'trustorder' ], $log['context'] );
		$this->assertStringNotContainsString( 'example.com', serialize( $log ) );
		$this->assertStringNotContainsString( 'INJECTED', serialize( $log ) );
	}

	public function test_unsafe_class_is_replaced(): void {
		Log::error( 'a@b.hu' );

		$this->assertStringNotContainsString( 'a@b.hu', serialize( $GLOBALS['trustorder_test']['logs'] ) );
	}

	public function test_identical_error_within_60s_skips_the_option_write_but_still_logs(): void {
		Log::error( 'api', [ 'code' => 'TIMEOUT', 'route' => '/check' ] );
		$first = get_option( Log::LAST_ERROR_OPTION );
		$GLOBALS['trustorder_test']['options'][ Log::LAST_ERROR_OPTION ]['marker'] = 'kept';

		Log::error( 'api', [ 'code' => 'TIMEOUT', 'route' => '/me' ] );

		$this->assertSame( 'kept', get_option( Log::LAST_ERROR_OPTION )['marker'] ?? null, 'option was rewritten' );
		$this->assertSame( $first['at'], get_option( Log::LAST_ERROR_OPTION )['at'] );
		$this->assertCount( 2, $GLOBALS['trustorder_test']['logs'] );
	}

	public function test_different_code_or_class_is_written(): void {
		Log::error( 'api', [ 'code' => 'TIMEOUT' ] );
		Log::error( 'api', [ 'code' => 'NETWORK' ] );
		$this->assertSame( 'NETWORK', get_option( Log::LAST_ERROR_OPTION )['code'] );

		Log::error( 'identity', [ 'code' => 'NETWORK' ] );
		$this->assertSame( 'identity', get_option( Log::LAST_ERROR_OPTION )['class'] );
	}

	public function test_identical_error_older_than_60s_is_written_again(): void {
		$GLOBALS['trustorder_test']['options'][ Log::LAST_ERROR_OPTION ] = [ 'class' => 'api', 'code' => 'TIMEOUT', 'at' => time() - 61 ];

		Log::error( 'api', [ 'code' => 'TIMEOUT' ] );

		$this->assertGreaterThanOrEqual( time() - 1, get_option( Log::LAST_ERROR_OPTION )['at'] );
	}

	public function test_missing_code_counts_as_identical_only_when_both_lack_it(): void {
		Log::error( 'api' );
		Log::error( 'api', [ 'code' => 'TIMEOUT' ] );

		$this->assertSame( 'TIMEOUT', get_option( Log::LAST_ERROR_OPTION )['code'] ?? null );
	}
}
