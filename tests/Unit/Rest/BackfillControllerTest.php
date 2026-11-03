<?php
/**
 * POST /backfill/start, GET /backfill/status.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Rest;

use LightweightPlugins\TrustOrder\Settings;
use LightweightPlugins\TrustOrder\Signals\Backfill;

final class BackfillControllerTest extends RestTestCase {

	public function test_start_without_key_is_refused(): void {
		$this->err( $this->call( 'POST', '/backfill/start' ), 'trustorder_no_key', 400 );
		$this->assertFalse( get_option( 'trustorder_backfill_test' ) );
		$this->assertFalse( get_option( 'trustorder_backfill_live' ) );
	}

	public function test_start_runs_for_the_current_env_only(): void {
		Settings::update( [ 'api_key' => self::TEST_KEY ] );

		$data = $this->ok( $this->call( 'POST', '/backfill/start', [ 'env' => 'live' ] ) );

		$this->assertSame( 'test', $data['env'] );
		$this->assertTrue( $data['test']['running'] );
		$this->assertFalse( $data['live']['running'] );
		$this->assertNull( $data['live']['started_at'] );
		$this->assertTrue( as_has_scheduled_action( Backfill::HOOK, [], 'trustorder' ) );
	}

	public function test_start_again_resumes_without_a_second_tick(): void {
		Settings::update( [ 'api_key' => self::TEST_KEY ] );
		$this->ok( $this->call( 'POST', '/backfill/start' ) );
		$this->ok( $this->call( 'POST', '/backfill/start' ) );

		$this->assertCount( 1, array_filter( $GLOBALS['trustorder_test']['as'], static fn( $a ): bool => Backfill::HOOK === $a['hook'] ) );
	}

	public function test_status_reports_both_envs(): void {
		Settings::update( [ 'api_key' => self::LIVE_KEY ] );

		$data = $this->ok( $this->call( 'GET', '/backfill/status' ) );

		$this->assertSame( 'live', $data['env'] );
		$this->assertSame( Backfill::status( 'live' ), $data['live'] );
		$this->assertSame( Backfill::status( 'test' ), $data['test'] );
		$this->assertSame( [], $this->requests() );
	}
}
