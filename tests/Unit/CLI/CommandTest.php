<?php
/**
 * wp trustorder status|check.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\CLI;

use LightweightPlugins\TrustOrder\CLI\Command;
use LightweightPlugins\TrustOrder\Settings;
use WP_CLI;

final class CommandTest extends CliTestCase {

	private const ME = [
		'shop_id'           => 'secret-internal-id',
		'shop_name'         => 'Demo shop',
		'domain'            => 'demo.test',
		'env'               => 'test',
		'account_status'    => 'approved',
		'daily_check_quota' => 5000,
		'checks_today'      => 23,
	];

	private function verdict( string $verdict = 'flag' ): array {
		return self::http( 200, [ 'verdict' => $verdict, 'level' => 'risky', 'score' => 31, 'reasons' => [ 'LOW_SCORE', 'NEW_ACCOUNT' ], 'check_id' => '01a1a17c-7e54' ] );
	}

	public function test_status_shows_the_connection_and_never_the_key(): void {
		$this->answers = [ self::http( 200, self::ME ) ];

		$this->assertSame( 0, $this->exec( fn() => ( new Command() )->status( [], [ 'format' => 'json' ] ) ) );

		$data = $this->json();
		$this->assertSame( 'test', $data['env'] );
		$this->assertSame( substr( self::TEST_KEY, 0, 12 ), $data['api_key'] );
		$this->assertSame( 'Demo shop', $data['connection']['shop_name'] );
		$this->assertSame( 5000, $data['connection']['daily_check_quota'] );
		$this->assertSame( 23, $data['connection']['checks_today'] );
		$this->assertArrayHasKey( 'live', $data['backfill'] );
		$this->assertArrayHasKey( 'test', $data['backfill'] );
		$this->assertArrayHasKey( 'intl', $data );
		$this->assertArrayHasKey( 'hpos', $data );
		$this->assertArrayHasKey( 'blocks_checkout', $data );
		$out = $this->output();
		$this->assertStringNotContainsString( self::TEST_KEY, $out );
		$this->assertStringNotContainsString( 'secret-internal-id', $out );
	}

	public function test_status_table_is_flattened(): void {
		$this->answers = [ self::http( 200, self::ME ) ];

		$this->assertSame( 0, $this->exec( fn() => ( new Command() )->status( [], [] ) ) );

		$rows = array_column( $this->table(), 'value', 'field' );
		$this->assertSame( 'Demo shop', $rows['connection.shop_name'] );
		$this->assertSame( 'no', $rows['backfill.live.running'] );
	}

	public function test_status_reports_the_last_error_and_exits_1_when_the_service_fails(): void {
		update_option( 'trustorder_last_error', [ 'class' => 'api', 'code' => 'INTERNAL', 'status' => 500, 'at' => 1760000000 ] );
		$this->answers = [ self::problem( 503, 'UNAVAILABLE' ) ];

		$this->assertSame( 1, $this->exec( fn() => ( new Command() )->status( [], [ 'format' => 'json' ] ) ) );

		$this->assertSame( 'INTERNAL', $this->json()['last_error']['code'] );
		$this->assertSame( [ 'UNAVAILABLE' ], WP_CLI::$errors );
	}

	public function test_status_without_a_key_makes_no_request_and_exits_0(): void {
		Settings::update( [ 'api_key' => '' ] );

		$this->assertSame( 0, $this->exec( fn() => ( new Command() )->status( [], [ 'format' => 'json' ] ) ) );

		$this->assertSame( 'no_key', $this->json()['connection']['status'] );
		$this->assertSame( [], $this->calls() );
	}

	public function test_check_prints_the_verdict_and_sends_hashes_only(): void {
		$this->answers = [ $this->verdict() ];

		$this->assertSame( 0, $this->exec( fn() => ( new Command() )->check( [], [ 'email' => 'Buyer@Example.com', 'phone' => '+36301234567', 'country' => 'hu', 'postcode' => '1051', 'address' => 'Fő utca 3', 'strictness' => 'strict', 'format' => 'json' ] ) ) );

		$data = $this->json();
		$this->assertSame( [ 'verdict' => 'flag', 'level' => 'risky', 'score' => 31, 'reasons' => [ 'LOW_SCORE', 'NEW_ACCOUNT' ], 'check_id' => '01a1a17c-7e54' ], $data );
		$calls = $this->calls();
		$this->assertCount( 1, $calls );
		$this->assertSame( 'POST', $calls[0][0] );
		$this->assertSame( '/check', $calls[0][1] );
		$this->assertSame( 'strict', $calls[0][2]['strictness'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $calls[0][2]['email_hash'] );
		$keys = array_keys( $calls[0][2] );
		sort( $keys );
		$this->assertSame( [ 'address_hash', 'email_domain_hashes', 'email_domain_mx', 'email_hash', 'phone_hash', 'strictness' ], $keys );
		$this->assertArrayHasKey( 'address_hash', $calls[0][2] );
		$raw = (string) json_encode( [ $this->calls(), $this->logged() ] ) . $this->output();
		foreach ( [ 'buyer@example.com', 'Buyer@Example.com', '+36301234567', 'Fő utca' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $raw );
		}
		$this->assertStringNotContainsString( 'example.com', (string) json_encode( $calls[0][2] ) );
	}

	public function test_check_bypasses_the_result_cache_and_the_quota(): void {
		$this->answers = [ $this->verdict() ];
		$args          = [ 'email' => 'buyer@example.com' ];

		$this->exec( fn() => ( new Command() )->check( [], $args ) );
		$this->exec( fn() => ( new Command() )->check( [], $args ) );

		$this->assertCount( 2, $this->calls() );
		$this->assertSame( [], $GLOBALS['trustorder_test']['transients'], 'no result cache, no quota counters' );
	}

	public function test_check_table_output(): void {
		$this->answers = [ $this->verdict( 'block' ) ];

		$this->assertSame( 0, $this->exec( fn() => ( new Command() )->check( [], [ 'email' => 'buyer@example.com' ] ) ) );

		$rows = array_column( $this->table(), 'value', 'field' );
		$this->assertSame( 'block', $rows['verdict'] );
		$this->assertSame( 'LOW_SCORE, NEW_ACCOUNT', $rows['reasons'] );
		$this->assertSame( '31', $rows['score'] );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function badArgs(): array {
		return [
			'no email'          => [ [] ],
			'invalid email'     => [ [ 'email' => 'not-an-email' ] ],
			'email not a string' => [ [ 'email' => true ] ],
			'bad strictness'    => [ [ 'email' => 'a@example.com', 'strictness' => 'extreme' ] ],
			'bad country'       => [ [ 'email' => 'a@example.com', 'country' => 'Hungary' ] ],
			'bad format'        => [ [ 'email' => 'a@example.com', 'format' => 'xml' ] ],
		];
	}

	/**
	 * @dataProvider badArgs
	 * @param array<string, mixed> $assoc Args.
	 */
	public function test_check_validates_arguments_before_any_request( array $assoc ): void {
		$this->assertSame( 1, $this->exec( fn() => ( new Command() )->check( [], $assoc ) ) );
		$this->assertSame( [], $this->calls() );
		$this->assertStringNotContainsString( 'not-an-email', $this->output() );
	}

	public function test_check_api_errors_exit_1_with_the_code_only(): void {
		$this->answers = [ self::problem( 429, 'QUOTA_EXCEEDED' ) ];

		$this->assertSame( 1, $this->exec( fn() => ( new Command() )->check( [], [ 'email' => 'buyer@example.com' ] ) ) );

		$this->assertSame( [ 'QUOTA_EXCEEDED' ], WP_CLI::$errors );
	}

	public function test_check_without_a_key_exits_1(): void {
		Settings::update( [ 'api_key' => '' ] );

		$this->assertSame( 1, $this->exec( fn() => ( new Command() )->check( [], [ 'email' => 'buyer@example.com' ] ) ) );

		$this->assertSame( [ 'NO_KEY' ], WP_CLI::$errors );
		$this->assertSame( [], $this->calls() );
	}
}
