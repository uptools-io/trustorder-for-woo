<?php
/**
 * Every route is registered and closed to users without manage_woocommerce.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Rest;

final class RoutesTest extends RestTestCase {

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function routes(): array {
		return [
			'settings get'      => [ 'GET', '/settings' ],
			'settings post'     => [ 'POST', '/settings' ],
			'connection get'    => [ 'GET', '/connection' ],
			'connection test'   => [ 'POST', '/connection/test' ],
			'exceptions get'    => [ 'GET', '/exceptions' ],
			'exceptions post'   => [ 'POST', '/exceptions' ],
			'exceptions delete' => [ 'DELETE', '/exceptions' ],
			'backfill start'    => [ 'POST', '/backfill/start' ],
			'backfill status'   => [ 'GET', '/backfill/status' ],
		];
	}

	/**
	 * @dataProvider routes
	 */
	public function test_route_is_registered_with_a_permission_callback( string $method, string $route ): void {
		$set = $GLOBALS['trustorder_test']['routes'][ '/trustorder/v1' . $route ][ $method ] ?? null;

		$this->assertIsArray( $set, "$method $route missing" );
		$this->assertIsCallable( $set['permission_callback'] );
		$this->assertNotSame( '__return_true', $set['permission_callback'] );
	}

	/**
	 * @dataProvider routes
	 */
	public function test_without_capability_every_route_is_forbidden_and_calls_nothing( string $method, string $route ): void {
		update_option( 'trustorder_settings', [ 'api_key' => self::TEST_KEY ] );
		$GLOBALS['trustorder_test']['caps'] = [ 'manage_options' => true ];
		$before = $GLOBALS['trustorder_test']['options'];

		$this->err( $this->call( $method, $route, [ 'kind' => 'email', 'value' => 'a@example.com', 'action' => 'deny', 'api_key' => self::LIVE_KEY ] ), 'rest_forbidden', 403 );
		$this->assertSame( [], $this->requests() );
		$this->assertSame( $before, $GLOBALS['trustorder_test']['options'] );
	}
}
