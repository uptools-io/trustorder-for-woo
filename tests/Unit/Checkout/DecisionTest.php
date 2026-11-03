<?php
/**
 * Decision value object tests.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Checkout;

use LightweightPlugins\TrustOrder\Api\Response;
use LightweightPlugins\TrustOrder\Checkout\Decision;
use PHPUnit\Framework\TestCase;

final class DecisionTest extends TestCase {

	protected function setUp(): void {
		trustorder_test_reset();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function body(): array {
		return [
			'verdict'  => 'block',
			'level'    => 'blocked',
			'score'    => 33,
			'reasons'  => [ 'NEGATIVE_RECENT', 'LOW_SCORE' ],
			'stats'    => [ 'delivered' => 0, 'refused' => 2, 'accounts_refused' => 2 ],
			'check_id' => '01a1a17c-7e54-7032-95b4-ba478164249b',
		];
	}

	public function test_from_valid_response(): void {
		$d = Decision::fromResponse( new Response( 200, self::body(), null ) );

		$this->assertSame( 'block', $d->verdict() );
		$this->assertSame( 'blocked', $d->level() );
		$this->assertSame( 33, $d->score() );
		$this->assertSame( [ 'NEGATIVE_RECENT', 'LOW_SCORE' ], $d->reasons() );
		$this->assertSame( '01a1a17c-7e54-7032-95b4-ba478164249b', $d->checkId() );
		$this->assertFalse( $d->failedOpen() );
		$this->assertNull( $d->errorCode() );
	}

	public function test_fail_open_is_an_allow(): void {
		$d = Decision::failOpen( 'TIMEOUT' );

		$this->assertSame( 'allow', $d->verdict() );
		$this->assertTrue( $d->failedOpen() );
		$this->assertSame( 'TIMEOUT', $d->errorCode() );
		$this->assertSame( [], $d->reasons() );
	}

	public function test_error_response_fails_open_with_its_code(): void {
		$d = Decision::fromResponse( new Response( 429, [ 'code' => 'QUOTA_EXCEEDED' ], 'QUOTA_EXCEEDED' ) );

		$this->assertTrue( $d->failedOpen() );
		$this->assertSame( 'allow', $d->verdict() );
		$this->assertSame( 'QUOTA_EXCEEDED', $d->errorCode() );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function malformed(): array {
		$b = self::body();

		return [
			'unknown verdict'   => [ [ 'verdict' => 'deny' ] + $b ],
			'missing verdict'   => [ array_diff_key( $b, [ 'verdict' => 1 ] ) ],
			'unknown level'     => [ [ 'level' => 'evil' ] + $b ],
			'score string'      => [ [ 'score' => '33' ] + $b ],
			'score too big'     => [ [ 'score' => 101 ] + $b ],
			'reasons not array' => [ [ 'reasons' => 'LOW_SCORE' ] + $b ],
			'reason not string' => [ [ 'reasons' => [ 1 ] ] + $b ],
			'reason not token'  => [ [ 'reasons' => [ "<b>x</b>" ] ] + $b ],
			'check id missing'  => [ array_diff_key( $b, [ 'check_id' => 1 ] ) ],
			'check id junk'     => [ [ 'check_id' => "a\nb" ] + $b ],
			'empty'             => [ [] ],
		];
	}

	/**
	 * @dataProvider malformed
	 * @param array<string, mixed> $body Body.
	 */
	public function test_malformed_body_fails_open( array $body ): void {
		$d = Decision::fromResponse( new Response( 200, $body, null ) );

		$this->assertTrue( $d->failedOpen() );
		$this->assertSame( 'allow', $d->verdict() );
		$this->assertSame( 'BAD_RESPONSE', $d->errorCode() );
	}

	public function test_array_round_trip(): void {
		$d = Decision::fromResponse( new Response( 200, self::body(), null ) );
		$e = Decision::fromArray( $d->toArray() );

		$this->assertNotNull( $e );
		$this->assertSame( $d->toArray(), $e->toArray() );
		$this->assertSame( 'block', $e->verdict() );

		$f = Decision::fromArray( Decision::failOpen( 'NETWORK' )->toArray() );
		$this->assertNotNull( $f );
		$this->assertTrue( $f->failedOpen() );
	}

	public function test_from_array_rejects_garbage(): void {
		$this->assertNull( Decision::fromArray( [ 'verdict' => 'nope' ] ) );
		$this->assertNull( Decision::fromArray( [] ) );
	}

	public function test_fail_open_code_is_a_token(): void {
		$this->assertSame( 'UNKNOWN', Decision::failOpen( "bad\ncode" )->errorCode() );
		$this->assertSame( 'UNKNOWN', Decision::failOpen( 'user@example.com' )->errorCode() );
		$this->assertSame( 'UNKNOWN', Decision::fromResponse( new Response( 418, [ 'code' => '<script>' ], '<script>' ) )->errorCode() );
		$this->assertSame( 'TIMEOUT', Decision::failOpen( 'TIMEOUT' )->errorCode() );
	}
}
