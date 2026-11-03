<?php
/**
 * Shared setup for the REST controller tests.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Rest;

use LightweightPlugins\TrustOrder\Rest\Routes;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Response;

abstract class RestTestCase extends TestCase {

	protected const TEST_KEY = 'to_test_abcdefghijABCDEFGHIJ0123456789xy';
	protected const LIVE_KEY = 'to_live_abcdefghijABCDEFGHIJ0123456789xy';

	/**
	 * Scripted API answers (the last one repeats).
	 *
	 * @var list<array<string, mixed>|WP_Error>
	 */
	protected array $answers = [];

	protected function setUp(): void {
		trustorder_test_reset();
		$this->answers = [];
		add_filter(
			'pre_http_request',
			function () {
				if ( [] === $this->answers ) {
					return self::http( 204, '' );
				}
				return count( $this->answers ) > 1 ? array_shift( $this->answers ) : $this->answers[0];
			}
		);
		Routes::register();
		do_action( 'rest_api_init' );
	}

	/**
	 * HTTP response array.
	 *
	 * @param int                 $code Status.
	 * @param array<mixed>|string $body Body (array = JSON).
	 * @return array<string, mixed>
	 */
	protected static function http( int $code, $body ): array {
		return [
			'headers'  => [],
			'body'     => is_array( $body ) ? (string) json_encode( $body ) : $body,
			'response' => [ 'code' => $code, 'message' => '' ],
		];
	}

	/**
	 * Dispatch a request to the trustorder/v1 namespace.
	 *
	 * @param array<string, mixed> $params Params.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function call( string $method, string $route, array $params = [] ) {
		return trustorder_test_rest( $method, '/trustorder/v1' . $route, $params );
	}

	/**
	 * @param mixed $res Result.
	 * @return array<string, mixed>
	 */
	protected function ok( $res, int $status = 200 ): array {
		$this->assertInstanceOf( WP_REST_Response::class, $res, $res instanceof WP_Error ? $res->get_error_code() : '' );
		$this->assertSame( $status, $res->get_status() );

		return $res->get_data();
	}

	/**
	 * @param mixed $res Result.
	 */
	protected function err( $res, string $code, int $status ): WP_Error {
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( $code, $res->get_error_code() );
		$this->assertSame( $status, $res->get_error_data()['status'] ?? null );

		return $res;
	}

	/**
	 * @return list<array{url: string, args: array<string, mixed>}>
	 */
	protected function requests(): array {
		return $GLOBALS['trustorder_test']['http'];
	}

	/**
	 * Everything the plugin could have persisted or logged, as one string.
	 */
	protected function persisted(): string {
		$t = $GLOBALS['trustorder_test'];

		return (string) json_encode( [ $t['options'], $t['transients'], $t['logs'], $t['usermeta'] ] );
	}
}
