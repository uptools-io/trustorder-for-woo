<?php
/**
 * wp trustorder exceptions list|add|remove.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\CLI;

use LightweightPlugins\TrustOrder\CLI\ExceptionsCommand;
use LightweightPlugins\TrustOrder\Identity\IdentitySet;
use LightweightPlugins\TrustOrder\Settings;
use WP_CLI;

final class ExceptionsCommandTest extends CliTestCase {

	private const EMAIL_RAW = 'Friend@Example.com';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['trustorder_test']['strict_request'] = true; // As in core: params only via set_param().
	}

	private function emailHash(): string {
		return IdentitySet::fromOrderData( [ 'email' => self::EMAIL_RAW ] )->hashes()['email_hash'];
	}

	public function test_list_shows_the_ids_not_the_identities(): void {
		$this->answers = [ self::http( 200, [ 'items' => [ [ 'id' => '8b835f07', 'kind' => 'email', 'action' => 'deny', 'note' => 'seen', 'created_at' => '2026-11-03T10:00:00Z', 'identity_hash' => 'leak' ] ], 'next_cursor' => 'abc' ] ) ];

		$this->assertSame( 0, $this->exec( fn() => ( new ExceptionsCommand() )->list_( [], [ 'limit' => '10' ] ) ) );

		$this->assertSame( [ 'GET', '/exceptions?limit=10' ], [ $this->calls()[0][0], $this->calls()[0][1] ] );
		$this->assertSame( [ '8b835f07' ], array_column( $this->table(), 'id' ) );
		$this->assertStringNotContainsString( 'leak', $this->output() );
		$this->assertStringContainsString( 'abc', implode( ' ', WP_CLI::$lines ), 'the next cursor is shown' );
	}

	public function test_list_json_and_cursor(): void {
		$this->answers = [ self::http( 200, [ 'items' => [], 'next_cursor' => null ] ) ];

		$this->assertSame( 0, $this->exec( fn() => ( new ExceptionsCommand() )->list_( [], [ 'cursor' => 'c1', 'format' => 'json' ] ) ) );

		$this->assertSame( '/exceptions?limit=50&cursor=c1', $this->calls()[0][1] );
		$this->assertSame( [ 'items' => [], 'next_cursor' => null ], $this->json() );
	}

	public function test_list_validates_arguments_and_reports_api_errors(): void {
		foreach ( [ [ 'limit' => '0' ], [ 'limit' => 'x' ], [ 'format' => 'csv' ] ] as $assoc ) {
			$this->assertSame( 1, $this->exec( fn() => ( new ExceptionsCommand() )->list_( [], $assoc ) ) );
		}
		$this->assertSame( [], $this->calls() );

		$this->answers = [ self::problem( 401, 'UNAUTHORIZED' ) ];
		$this->assertSame( 1, $this->exec( fn() => ( new ExceptionsCommand() )->list_( [], [] ) ) );
		$this->assertSame( 'UNAUTHORIZED', WP_CLI::$errors[ count( WP_CLI::$errors ) - 1 ] );
	}

	public function test_add_sends_the_local_hash_only(): void {
		$this->assertSame( 0, $this->exec( fn() => ( new ExceptionsCommand() )->add( [], [ 'kind' => 'email', 'value' => self::EMAIL_RAW, 'action' => 'allow', 'note' => 'regular' ] ) ) );

		$call = $this->calls()[0];
		$this->assertSame( [ 'PUT', '/exceptions' ], [ $call[0], $call[1] ] );
		$this->assertSame( [ 'kind' => 'email', 'identity_hash' => $this->emailHash(), 'action' => 'allow', 'note' => 'regular' ], $call[2] );
		$raw = json_encode( [ $this->calls(), $this->logged() ] ) . $this->output();
		$this->assertStringNotContainsStringIgnoringCase( 'friend@example.com', $raw );
	}

	public function test_add_phone_and_address(): void {
		$this->assertSame( 0, $this->exec( fn() => ( new ExceptionsCommand() )->add( [], [ 'kind' => 'phone', 'value' => '+36301234567', 'action' => 'deny' ] ) ) );
		$this->assertSame( 0, $this->exec( fn() => ( new ExceptionsCommand() )->add( [], [ 'kind' => 'address', 'country' => 'HU', 'postcode' => '1051', 'address' => 'Fő utca 3', 'action' => 'deny' ] ) ) );

		$this->assertSame( [ 'phone', 'address' ], array_column( array_column( $this->calls(), 2 ), 'kind' ) );
		$this->assertStringNotContainsString( 'Fő utca', json_encode( $this->calls() ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function badAdds(): array {
		return [
			'no kind'      => [ [ 'value' => 'a@example.com', 'action' => 'allow' ] ],
			'bad kind'     => [ [ 'kind' => 'name', 'value' => 'x', 'action' => 'allow' ] ],
			'no action'    => [ [ 'kind' => 'email', 'value' => 'a@example.com' ] ],
			'bad action'   => [ [ 'kind' => 'email', 'value' => 'a@example.com', 'action' => 'maybe' ] ],
			'bad email'    => [ [ 'kind' => 'email', 'value' => 'secret-text', 'action' => 'allow' ] ],
			'no value'     => [ [ 'kind' => 'email', 'action' => 'allow' ] ],
			'long note'    => [ [ 'kind' => 'email', 'value' => 'a@example.com', 'action' => 'allow', 'note' => str_repeat( 'x', 501 ) ] ],
			'short address' => [ [ 'kind' => 'address', 'country' => 'HU', 'action' => 'allow' ] ],
		];
	}

	/**
	 * @dataProvider badAdds
	 * @param array<string, mixed> $assoc Args.
	 */
	public function test_add_validates_before_any_request_and_never_echoes_the_value( array $assoc ): void {
		$this->assertSame( 1, $this->exec( fn() => ( new ExceptionsCommand() )->add( [], $assoc ) ) );

		$this->assertSame( [], $this->calls() );
		$this->assertStringNotContainsString( 'secret-text', $this->output() );
	}

	public function test_add_without_a_key_and_with_api_errors(): void {
		$this->answers = [ self::problem( 429, 'QUOTA_EXCEEDED' ) ];
		$assoc         = [ 'kind' => 'email', 'value' => self::EMAIL_RAW, 'action' => 'allow' ];
		$this->assertSame( 1, $this->exec( fn() => ( new ExceptionsCommand() )->add( [], $assoc ) ) );
		$this->assertSame( [ 'QUOTA_EXCEEDED' ], WP_CLI::$errors );

		WP_CLI::reset();
		Settings::update( [ 'api_key' => '' ] );
		$before = count( $this->calls() );
		$this->assertSame( 1, $this->exec( fn() => ( new ExceptionsCommand() )->add( [], $assoc ) ) );
		$this->assertSame( $before, count( $this->calls() ) );
	}

	public function test_remove_deletes_by_the_local_hash(): void {
		$this->assertSame( 0, $this->exec( fn() => ( new ExceptionsCommand() )->remove( [], [ 'kind' => 'email', 'value' => self::EMAIL_RAW ] ) ) );

		$this->assertSame( [ 'DELETE', '/exceptions/email/' . $this->emailHash() ], [ $this->calls()[0][0], $this->calls()[0][1] ] );
		$this->assertStringNotContainsStringIgnoringCase( 'friend@example.com', $this->output() );
	}

	public function test_remove_of_an_unknown_value_exits_1(): void {
		$this->answers = [ self::problem( 404, 'NOT_FOUND' ) ];

		$this->assertSame( 1, $this->exec( fn() => ( new ExceptionsCommand() )->remove( [], [ 'kind' => 'email', 'value' => self::EMAIL_RAW ] ) ) );

		$this->assertStringContainsString( 'trustorder_exception_not_found', WP_CLI::$errors[0] );
	}

	public function test_remove_validates_the_identity(): void {
		$this->assertSame( 1, $this->exec( fn() => ( new ExceptionsCommand() )->remove( [], [ 'kind' => 'email', 'value' => 'nope' ] ) ) );
		$this->assertSame( [], $this->calls() );
	}
}
