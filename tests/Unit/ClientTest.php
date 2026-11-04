<?php
/**
 * API client tests (HTTP stubbed through the pre_http_request filter).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit;

use LightweightPlugins\TrustOrder\Api\Client;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class ClientTest extends TestCase {

	private const KEY  = 'to_test_SECRETsecret0123456789abcdefABCD';
	private const BASE = 'https://api.example.test/v1/trustorder';

	protected function setUp(): void {
		trustorder_test_reset();
	}

	/**
	 * Answer every request with the given response.
	 *
	 * @param array<string, mixed>|WP_Error $response Response.
	 */
	private function respond( $response ): void {
		add_filter(
			'pre_http_request',
			static function () use ( $response ) {
				return $response;
			}
		);
	}

	/**
	 * @param array<string, string> $headers Headers.
	 * @return array<string, mixed>
	 */
	private function http( int $code, string $body, array $headers = [] ): array {
		return [
			'headers'  => $headers,
			'body'     => $body,
			'response' => [ 'code' => $code, 'message' => '' ],
		];
	}

	/**
	 * @return array{url: string, args: array<string, mixed>}
	 */
	private function last_request(): array {
		$all = $GLOBALS['trustorder_test']['http'];
		$this->assertNotEmpty( $all, 'no HTTP request was made' );

		return end( $all );
	}

	private function client(): Client {
		return new Client( self::KEY, self::BASE );
	}

	public function test_check_success_returns_decoded_json(): void {
		$this->respond( $this->http( 200, '{"verdict":"block","level":"blocked","score":33,"reasons":["LOW_SCORE"],"check_id":"01a1"}' ) );

		$r = $this->client()->check( [ 'email_hash' => str_repeat( 'a', 64 ), 'strictness' => 'normal' ] );

		$this->assertTrue( $r->ok() );
		$this->assertSame( 200, $r->status() );
		$this->assertNull( $r->errorCode() );
		$this->assertFalse( $r->retryable() );
		$this->assertSame( 'block', $r->data()['verdict'] );
		$this->assertSame( [ 'LOW_SCORE' ], $r->data()['reasons'] );
	}

	public function test_check_request_shape(): void {
		$this->respond( $this->http( 200, '{}' ) );
		$body = [ 'email_hash' => str_repeat( 'b', 64 ), 'strictness' => 'strict' ];

		$this->client()->check( $body );
		$req = $this->last_request();

		$this->assertSame( self::BASE . '/check', $req['url'] );
		$this->assertSame( 'POST', $req['args']['method'] );
		$this->assertSame( 1.5, $req['args']['timeout'] );
		$this->assertSame( 'Bearer ' . self::KEY, $req['args']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $req['args']['headers']['Content-Type'] );
		$this->assertSame( $body, json_decode( $req['args']['body'], true ) );
		$this->assertSame( 0, $req['args']['redirection'] );
	}

	public function test_background_routes_use_ten_second_timeout_and_right_urls(): void {
		$this->respond( $this->http( 200, '{}' ) );
		$c    = $this->client();
		$hash = str_repeat( 'c', 64 );

		$c->me();
		$this->assertSame( [ 'GET', self::BASE . '/me' ], $this->method_url() );
		$c->putSignal( '12/3', [ 'outcome' => 'delivered' ] );
		$this->assertSame( [ 'PUT', self::BASE . '/signals/12%2F3' ], $this->method_url() );
		$c->deleteSignal( '42' );
		$this->assertSame( [ 'DELETE', self::BASE . '/signals/42' ], $this->method_url() );
		$c->batchSignals( [ [ 'order_ref' => '1', 'outcome' => 'refused' ] ] );
		$this->assertSame( [ 'POST', self::BASE . '/signals/batch' ], $this->method_url() );
		$this->assertSame( [ 'signals' => [ [ 'order_ref' => '1', 'outcome' => 'refused' ] ] ], json_decode( $this->last_request()['args']['body'], true ) );
		$c->postGeo( [ 'country' => 'HU', 'postcode' => '1051' ] );
		$this->assertSame( [ 'POST', self::BASE . '/geo' ], $this->method_url() );
		$this->assertSame( '{"country":"HU","postcode":"1051"}', $this->last_request()['args']['body'] );
		$c->putException( [ 'kind' => 'email', 'identity_hash' => $hash, 'action' => 'deny' ] );
		$this->assertSame( [ 'PUT', self::BASE . '/exceptions' ], $this->method_url() );
		$c->deleteException( 'email', $hash );
		$this->assertSame( [ 'DELETE', self::BASE . '/exceptions/email/' . $hash ], $this->method_url() );
		$c->listExceptions( 50, 'abc+/=' );
		$this->assertSame( [ 'GET', self::BASE . '/exceptions?limit=50&cursor=abc%2B%2F%3D' ], $this->method_url() );
		$c->listExceptions( 100, null );
		$this->assertSame( [ 'GET', self::BASE . '/exceptions?limit=100' ], $this->method_url() );

		foreach ( $GLOBALS['trustorder_test']['http'] as $req ) {
			$this->assertSame( 10.0, $req['args']['timeout'] );
			$this->assertSame( 'Bearer ' . self::KEY, $req['args']['headers']['Authorization'] );
		}
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private function method_url(): array {
		$req = $this->last_request();

		return [ $req['args']['method'], $req['url'] ];
	}

	public function test_get_and_delete_send_no_body(): void {
		$this->respond( $this->http( 204, '' ) );

		$r = $this->client()->deleteSignal( '7' );

		$this->assertArrayNotHasKey( 'body', $this->last_request()['args'] );
		$this->assertTrue( $r->ok() );
		$this->assertSame( 204, $r->status() );
		$this->assertSame( [], $r->data() );
	}

	public function test_base_trailing_slash_is_trimmed(): void {
		$this->respond( $this->http( 200, '{}' ) );

		( new Client( self::KEY, self::BASE . '/' ) )->me();

		$this->assertSame( self::BASE . '/me', $this->last_request()['url'] );
	}

	public function test_problem_json_401_gives_its_code(): void {
		$this->respond( $this->http( 401, '{"type":"about:blank","title":"Unauthorized","status":401,"detail":"unknown key","code":"UNAUTHORIZED"}', [ 'content-type' => 'application/problem+json' ] ) );

		$r = $this->client()->me();

		$this->assertFalse( $r->ok() );
		$this->assertSame( 401, $r->status() );
		$this->assertSame( 'UNAUTHORIZED', $r->errorCode() );
		$this->assertFalse( $r->retryable() );
	}

	public function test_429_and_503_are_retryable(): void {
		$this->respond( $this->http( 429, '{"status":429,"code":"QUOTA_EXCEEDED"}' ) );
		$r = $this->client()->check( [] );
		$this->assertSame( 'QUOTA_EXCEEDED', $r->errorCode() );
		$this->assertTrue( $r->retryable() );

		trustorder_test_reset();
		$this->respond( $this->http( 503, '{"status":503,"code":"INTERNAL"}' ) );
		$r = $this->client()->check( [] );
		$this->assertSame( 'INTERNAL', $r->errorCode() );
		$this->assertTrue( $r->retryable() );
	}

	public function test_timeout_wp_error(): void {
		$this->respond( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 1501 milliseconds with 0 bytes received' ) );

		$r = $this->client()->check( [] );

		$this->assertFalse( $r->ok() );
		$this->assertSame( 0, $r->status() );
		$this->assertSame( 'TIMEOUT', $r->errorCode() );
		$this->assertTrue( $r->retryable() );
		$this->assertSame( [], $r->data() );
	}

	public function test_other_wp_error_is_network(): void {
		$this->respond( new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: api.example.test' ) );

		$r = $this->client()->check( [] );

		$this->assertSame( 'NETWORK', $r->errorCode() );
		$this->assertTrue( $r->retryable() );
	}

	/**
	 * @dataProvider bad_bodies
	 */
	public function test_non_json_body_is_bad_json( int $code, string $body ): void {
		$this->respond( $this->http( $code, $body ) );

		$r = $this->client()->check( [] );

		$this->assertFalse( $r->ok() );
		$this->assertSame( 'BAD_JSON', $r->errorCode() );
		$this->assertSame( [], $r->data() );
	}

	/**
	 * @return array<string, array{0: int, 1: string}>
	 */
	public function bad_bodies(): array {
		return [
			'html 200'     => [ 200, '<html>hi</html>' ],
			'scalar json'  => [ 200, '"ok"' ],
			'empty 200'    => [ 200, '' ],
			'html 502'     => [ 502, '<html>Bad gateway</html>' ],
			'problem w/o code' => [ 400, '{"status":400}' ],
		];
	}

	public function test_non_json_503_stays_retryable(): void {
		$this->respond( $this->http( 503, 'upstream down' ) );

		$this->assertTrue( $this->client()->check( [] )->retryable() );
	}

	public function test_empty_key_sends_nothing(): void {
		$this->respond( $this->http( 200, '{}' ) );

		$r = ( new Client( '', self::BASE ) )->check( [] );

		$this->assertSame( [], $GLOBALS['trustorder_test']['http'] );
		$this->assertFalse( $r->ok() );
		$this->assertSame( 'NO_KEY', $r->errorCode() );
		$this->assertFalse( $r->retryable() );
	}

	public function test_failures_are_logged_without_key_or_payload(): void {
		$email_hash = str_repeat( 'e', 64 );
		$this->respond( new WP_Error( 'http_request_failed', 'cURL error 28: timed out ' . self::KEY ) );
		$this->client()->check( [ 'email_hash' => $email_hash ] );

		trustorder_test_reset();
		$this->respond( $this->http( 401, '{"code":"UNAUTHORIZED","detail":"' . self::KEY . '"}' ) );
		$this->client()->putSignal( '99', [ 'email_hash' => $email_hash ] );
		$logs = $GLOBALS['trustorder_test']['logs'];
		$last = get_option( 'trustorder_last_error' );

		$this->assertCount( 1, $logs );
		$this->assertSame( 'error', $logs[0]['level'] );
		$this->assertSame( 'trustorder', $logs[0]['context']['source'] );
		$this->assertSame( 'UNAUTHORIZED', $logs[0]['context']['code'] );
		$this->assertSame( 401, $logs[0]['context']['status'] );
		$this->assertSame( '/signals/{ref}', $logs[0]['context']['route'] );
		$dump = serialize( $logs ) . serialize( $last );
		$this->assertStringNotContainsString( self::KEY, $dump );
		$this->assertStringNotContainsString( 'SECRET', $dump );
		$this->assertStringNotContainsString( $email_hash, $dump );
		$this->assertStringNotContainsString( '99', serialize( $logs[0]['context'] ) );
	}

	public function test_timeout_is_logged_and_recorded_as_last_error(): void {
		$this->respond( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		$this->client()->check( [] );

		$logs = $GLOBALS['trustorder_test']['logs'];
		$this->assertCount( 1, $logs );
		$this->assertSame( 'TIMEOUT', $logs[0]['context']['code'] );
		$this->assertSame( '/check', $logs[0]['context']['route'] );
		$last = get_option( 'trustorder_last_error' );
		$this->assertIsArray( $last );
		$this->assertSame( 'api', $last['class'] );
		$this->assertSame( 'TIMEOUT', $last['code'] );
		$this->assertIsInt( $last['at'] );
		$this->assertFalse( $GLOBALS['trustorder_test']['autoload']['trustorder_last_error'] );
	}

	public function test_success_is_not_logged(): void {
		$this->respond( $this->http( 200, '{}' ) );

		$this->client()->me();

		$this->assertSame( [], $GLOBALS['trustorder_test']['logs'] );
	}

	public function test_404_on_delete_routes_is_not_logged(): void {
		$this->respond( $this->http( 404, '{"code":"NOT_FOUND"}' ) );

		$this->client()->deleteSignal( '12' );
		$this->client()->deleteException( 'email', str_repeat( 'a', 64 ) );

		$this->assertSame( [], $GLOBALS['trustorder_test']['logs'] );
		$this->assertFalse( get_option( 'trustorder_last_error' ) );
	}

	public function test_404_on_other_routes_and_other_delete_errors_are_logged(): void {
		$this->respond( $this->http( 404, '{"code":"NOT_FOUND"}' ) );
		$this->client()->putSignal( '12', [] );
		$this->assertCount( 1, $GLOBALS['trustorder_test']['logs'] );

		trustorder_test_reset();
		$this->respond( $this->http( 500, '{"code":"INTERNAL"}' ) );
		$this->client()->deleteSignal( '12' );
		$this->assertCount( 1, $GLOBALS['trustorder_test']['logs'] );
		$this->assertSame( 500, $GLOBALS['trustorder_test']['logs'][0]['context']['status'] );
	}
}
