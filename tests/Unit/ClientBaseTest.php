<?php
/**
 * API base validation (the Bearer key must only go to an HTTPS base).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit;

use LightweightPlugins\TrustOrder\Api\Client;
use LightweightPlugins\TrustOrder\Settings;
use PHPUnit\Framework\TestCase;

final class ClientBaseTest extends TestCase {

	private const KEY = 'to_test_SECRETsecret0123456789abcdefABCD';

	protected function setUp(): void {
		trustorder_test_reset();
		Settings::update( [ 'api_key' => self::KEY ] );
		add_filter(
			'pre_http_request',
			static function () {
				return [ 'headers' => [], 'body' => '{}', 'response' => [ 'code' => 200 ] ];
			}
		);
	}

	private function url_for_base( $base ): string {
		add_filter(
			'trustorder_api_base',
			static function () use ( $base ) {
				return $base;
			}
		);
		Client::fromSettings()->me();
		$all = $GLOBALS['trustorder_test']['http'];

		return end( $all )['url'];
	}

	/**
	 * @dataProvider allowed
	 */
	public function test_allowed_bases_are_used( string $base, string $expected ): void {
		$this->assertSame( $expected, $this->url_for_base( $base ) );
		$this->assertSame( [], $GLOBALS['trustorder_test']['logs'] );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function allowed(): array {
		return [
			'https'           => [ 'https://staging.example.test/v1/trustorder', 'https://staging.example.test/v1/trustorder/me' ],
			'HTTPS uppercase' => [ 'HTTPS://api.example.test/x/', 'HTTPS://api.example.test/x/me' ],
			'http localhost'  => [ 'http://localhost:8080/v1/trustorder', 'http://localhost:8080/v1/trustorder/me' ],
			'http loopback'   => [ 'http://127.0.0.1:8080/v1/trustorder', 'http://127.0.0.1:8080/v1/trustorder/me' ],
		];
	}

	/**
	 * @dataProvider rejected
	 *
	 * @param mixed $base Filtered value.
	 */
	public function test_rejected_bases_fall_back_to_default_and_log( $base ): void {
		$this->assertSame( Client::DEFAULT_BASE . '/me', $this->url_for_base( $base ) );

		$logs = $GLOBALS['trustorder_test']['logs'];
		$this->assertCount( 1, $logs );
		$this->assertStringContainsString( 'BAD_API_BASE', $logs[0]['message'] );
		$this->assertSame( 'BAD_API_BASE', get_option( 'trustorder_last_error' )['class'] );
		$this->assertStringNotContainsString( self::KEY, serialize( $logs ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function rejected(): array {
		return [
			'plain http'          => [ 'http://api.example.test/v1/trustorder' ],
			'http lookalike host' => [ 'http://localhost.evil.test/v1' ],
			'userinfo trick'      => [ 'http://localhost@evil.test/v1' ],
			'no scheme'           => [ 'api.example.test/v1' ],
			'ftp'                 => [ 'ftp://api.example.test/v1' ],
			'https without host'  => [ 'https:///v1' ],
			'not a string'        => [ [ 'https://api.example.test' ] ],
			'empty'               => [ '' ],
		];
	}

	public function test_default_base_without_filter_does_not_log(): void {
		Client::fromSettings()->me();

		$this->assertSame( Client::DEFAULT_BASE . '/me', $GLOBALS['trustorder_test']['http'][0]['url'] );
		$this->assertSame( [], $GLOBALS['trustorder_test']['logs'] );
	}
}
