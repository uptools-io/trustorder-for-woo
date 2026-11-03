<?php
/**
 * GET|POST|DELETE /exceptions: raw input is hashed server-side and never kept.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Rest;

use LightweightPlugins\TrustOrder\Checkout\CacheGeneration;
use LightweightPlugins\TrustOrder\Identity\Hasher;
use LightweightPlugins\TrustOrder\Identity\IdentitySet;
use LightweightPlugins\TrustOrder\Settings;

final class ExceptionsControllerTest extends RestTestCase {

	private const RAW_EMAIL = 'Buyer.Name+shop@Example.com';

	protected function setUp(): void {
		parent::setUp();
		Settings::update( [ 'api_key' => self::TEST_KEY ] );
		update_option( 'woocommerce_default_country', 'HU:BU' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function sentBody( int $i = 0 ): array {
		$body = $this->requests()[ $i ]['args']['body'] ?? '';

		return is_string( $body ) && '' !== $body ? (array) json_decode( $body, true ) : [];
	}

	public function test_add_email_sends_only_the_hash(): void {
		$data = $this->ok( $this->call( 'POST', '/exceptions', [ 'kind' => 'email', 'value' => self::RAW_EMAIL, 'action' => 'deny', 'note' => "  refused <b>twice</b>\n" ] ), 201 );

		$req = $this->requests()[0];
		$this->assertSame( 'PUT', $req['args']['method'] );
		$this->assertStringEndsWith( '/exceptions', $req['url'] );
		$this->assertSame(
			[
				'kind'          => 'email',
				'identity_hash' => Hasher::client( 'email', 'buyer.name@example.com' ),
				'action'        => 'deny',
				'note'          => 'refused twice',
			],
			$this->sentBody()
		);
		$this->assertSame( [ 'saved' => true ], $data );
		$this->assertStringNotContainsString( 'example.com', $this->persisted() );
		$this->assertStringNotContainsString( 'xample', (string) json_encode( $data ) );
	}

	public function test_add_phone_uses_the_given_or_the_shop_country(): void {
		$this->ok( $this->call( 'POST', '/exceptions', [ 'kind' => 'phone', 'value' => '06 30 123 4567', 'action' => 'allow' ] ), 201 );
		$this->ok( $this->call( 'POST', '/exceptions', [ 'kind' => 'phone', 'value' => '0664 1234567', 'country' => 'AT', 'action' => 'allow' ] ), 201 );

		$this->assertSame( Hasher::client( 'phone', '+36301234567' ), $this->sentBody( 0 )['identity_hash'] );
		$this->assertSame( Hasher::client( 'phone', '+436641234567' ), $this->sentBody( 1 )['identity_hash'] );
		$this->assertSame( '', $this->sentBody( 0 )['note'] );
	}

	public function test_add_address_hashes_the_normalised_address(): void {
		$fields = [
			'country'   => 'HU',
			'postcode'  => '1011',
			'address_1' => 'Fő utca 1.',
			'address_2' => '2. em.',
		];
		$this->ok( $this->call( 'POST', '/exceptions', [ 'kind' => 'address', 'action' => 'deny' ] + $fields ), 201 );

		$expected = IdentitySet::fromOrderData(
			[
				'needs_shipping'     => true,
				'shipping_country'   => 'HU',
				'shipping_postcode'  => '1011',
				'shipping_address_1' => 'Fő utca 1.',
				'shipping_address_2' => '2. em.',
			]
		)->hashes()['address_hash'];
		$this->assertIsString( $expected );
		$this->assertSame( $expected, $this->sentBody()['identity_hash'] );
		$this->assertStringNotContainsString( 'utca', $this->persisted() );
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function badAdds(): array {
		return [
			'unknown kind'     => [ [ 'kind' => 'name', 'value' => 'x', 'action' => 'deny' ], 'trustorder_invalid_kind' ],
			'bad action'       => [ [ 'kind' => 'email', 'value' => 'a@example.com', 'action' => 'block' ], 'trustorder_invalid_action' ],
			'invalid email'    => [ [ 'kind' => 'email', 'value' => 'not an email', 'action' => 'deny' ], 'trustorder_invalid_identity' ],
			'invalid phone'    => [ [ 'kind' => 'phone', 'value' => '123', 'action' => 'deny' ], 'trustorder_invalid_identity' ],
			'no house number'  => [ [ 'kind' => 'address', 'country' => 'HU', 'postcode' => '1011', 'address_1' => 'Fő utca', 'action' => 'deny' ], 'trustorder_invalid_identity' ],
			'array value'      => [ [ 'kind' => 'email', 'value' => [ 'a@example.com' ], 'action' => 'deny' ], 'trustorder_invalid_identity' ],
			'note too long'    => [ [ 'kind' => 'email', 'value' => 'a@example.com', 'action' => 'deny', 'note' => str_repeat( 'x', 501 ) ], 'trustorder_invalid_note' ],
		];
	}

	/**
	 * @dataProvider badAdds
	 *
	 * @param array<string, mixed> $params Params.
	 */
	public function test_bad_input_is_refused_without_a_request( array $params, string $code ): void {
		$err = $this->err( $this->call( 'POST', '/exceptions', $params ), $code, 400 );

		$this->assertSame( [], $this->requests() );
		$this->assertStringNotContainsString( 'example.com', $err->get_error_message() );
		$this->assertStringNotContainsString( 'example.com', $this->persisted() );
	}

	public function test_without_key_nothing_is_sent(): void {
		Settings::update( [ 'api_key' => '' ] );

		$this->err( $this->call( 'POST', '/exceptions', [ 'kind' => 'email', 'value' => 'a@example.com', 'action' => 'deny' ] ), 'trustorder_no_key', 400 );
		$this->assertSame( [], $this->requests() );
	}

	public function test_add_passes_api_errors_through(): void {
		$this->answers = [ self::http( 403, [ 'type' => 'about:blank', 'title' => 't', 'status' => 403, 'detail' => 'd', 'code' => 'FORBIDDEN' ] ) ];

		$err = $this->err( $this->call( 'POST', '/exceptions', [ 'kind' => 'email', 'value' => self::RAW_EMAIL, 'action' => 'deny' ] ), 'trustorder_api_error', 502 );

		$this->assertSame( 'FORBIDDEN', $err->get_error_data()['api_code'] );
		$this->assertStringNotContainsString( 'example.com', $this->persisted() );
	}

	public function test_delete_hashes_the_value_and_calls_delete(): void {
		$data = $this->ok( $this->call( 'DELETE', '/exceptions', [ 'kind' => 'email', 'value' => self::RAW_EMAIL ] ) );

		$req = $this->requests()[0];
		$this->assertSame( 'DELETE', $req['args']['method'] );
		$this->assertStringEndsWith( '/exceptions/email/' . Hasher::client( 'email', 'buyer.name@example.com' ), $req['url'] );
		$this->assertSame( [ 'deleted' => true ], $data );
	}

	public function test_delete_of_a_missing_exception_is_a_404(): void {
		$this->answers = [ self::http( 404, [ 'type' => 'about:blank', 'title' => 't', 'status' => 404, 'detail' => 'd', 'code' => 'NOT_FOUND' ] ) ];

		$this->err( $this->call( 'DELETE', '/exceptions', [ 'kind' => 'email', 'value' => self::RAW_EMAIL ] ), 'trustorder_exception_not_found', 404 );
	}

	public function test_successful_add_and_remove_start_a_new_cache_generation(): void {
		$this->assertSame( 0, CacheGeneration::current() );

		$this->ok( $this->call( 'POST', '/exceptions', [ 'kind' => 'email', 'value' => self::RAW_EMAIL, 'action' => 'allow' ] ), 201 );
		$this->assertSame( 1, CacheGeneration::current() );

		$this->ok( $this->call( 'DELETE', '/exceptions', [ 'kind' => 'email', 'value' => self::RAW_EMAIL ] ) );
		$this->assertSame( 2, CacheGeneration::current() );
		$this->assertFalse( $GLOBALS['trustorder_test']['autoload'][ CacheGeneration::OPTION ] );
	}

	public function test_failed_add_or_remove_keeps_the_cache_generation(): void {
		$this->answers = [
			self::http( 403, [ 'type' => 'about:blank', 'title' => 't', 'status' => 403, 'detail' => 'd', 'code' => 'FORBIDDEN' ] ),
			self::http( 404, [ 'type' => 'about:blank', 'title' => 't', 'status' => 404, 'detail' => 'd', 'code' => 'NOT_FOUND' ] ),
		];

		$this->call( 'POST', '/exceptions', [ 'kind' => 'email', 'value' => self::RAW_EMAIL, 'action' => 'deny' ] );
		$this->call( 'DELETE', '/exceptions', [ 'kind' => 'email', 'value' => self::RAW_EMAIL ] );

		$this->assertSame( 0, CacheGeneration::current() );
	}

	public function test_list_returns_only_allow_listed_fields(): void {
		$this->answers = [
			self::http(
				200,
				[
					'items'       => [
						[ 'id' => '9f3a10c2', 'kind' => 'email', 'action' => 'deny', 'note' => 'n <i>1</i>', 'created_at' => '2026-11-02T09:12:44Z', 'identity_hash' => str_repeat( 'a', 64 ) ],
						[ 'id' => 'NOT-HEX', 'kind' => 'email', 'action' => 'deny', 'note' => '', 'created_at' => '2026-11-02T09:12:44Z' ],
						'garbage',
					],
					'next_cursor' => 'abc123',
				]
			),
		];

		$data = $this->ok( $this->call( 'GET', '/exceptions', [ 'cursor' => 'prev', 'limit' => 9999 ] ) );

		$this->assertStringContainsString( '/exceptions?limit=100&cursor=prev', $this->requests()[0]['url'] );
		$this->assertSame(
			[
				[ 'id' => '9f3a10c2', 'kind' => 'email', 'action' => 'deny', 'note' => 'n 1', 'created_at' => '2026-11-02T09:12:44Z' ],
			],
			$data['items']
		);
		$this->assertSame( 'abc123', $data['next_cursor'] );
	}

	public function test_list_default_page_and_null_cursor(): void {
		$this->answers = [ self::http( 200, [ 'items' => [], 'next_cursor' => null ] ) ];

		$data = $this->ok( $this->call( 'GET', '/exceptions' ) );

		$this->assertStringEndsWith( '/exceptions?limit=50', $this->requests()[0]['url'] );
		$this->assertNull( $data['next_cursor'] );
	}
}
