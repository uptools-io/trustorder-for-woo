<?php
/**
 * wp trustorder signal send|status.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\CLI;

use LightweightPlugins\TrustOrder\CLI\SignalCommand;
use LightweightPlugins\TrustOrder\Settings;
use LightweightPlugins\TrustOrder\Signals\SignalLock;
use WP_CLI;

final class SignalCommandTest extends CliTestCase {

	public function test_send_reports_the_current_outcome_now(): void {
		$order = $this->order( [ 'id' => 7, 'status' => 'completed' ] );

		$this->assertSame( 0, $this->exec( fn() => ( new SignalCommand() )->send( [ '7' ] ) ) );

		$calls = $this->calls();
		$this->assertCount( 1, $calls );
		$this->assertSame( [ 'PUT', '/signals/7' ], [ $calls[0][0], $calls[0][1] ] );
		$this->assertSame( 'delivered', $calls[0][2]['outcome'] );
		$this->assertSame( 'sent', $this->state( $order )['status'] );
		$this->assertSame( [ '7' ], array_column( $this->table(), 'order_id' ) );
		$this->assertSame( [ 'sent' ], array_column( $this->table(), 'status' ) );
		$this->assertSame( [], $this->pending(), 'nothing was left for the background' );
	}

	public function test_send_is_idempotent_and_sends_each_order(): void {
		$this->order( [ 'id' => 7, 'status' => 'completed' ] );
		$this->order( [ 'id' => 8, 'status' => 'to-refused' ] );

		$this->assertSame( 0, $this->exec( fn() => ( new SignalCommand() )->send( [ '7', '8' ] ) ) );
		$this->assertSame( 0, $this->exec( fn() => ( new SignalCommand() )->send( [ '7', '8' ] ) ) );

		$this->assertCount( 2, $this->calls(), 'the second run finds both already sent' );
		$this->assertSame( 'refused', $this->calls()[1][2]['outcome'] );
	}

	public function test_send_retracts_when_the_order_went_back(): void {
		$order = $this->order( [ 'id' => 7, 'status' => 'completed' ] );
		( new SignalCommand() )->send( [ '7' ] );
		$order->set_status( 'processing' );

		$this->assertSame( 0, $this->exec( fn() => ( new SignalCommand() )->send( [ '7' ] ) ) );

		$this->assertSame( 'DELETE', $this->calls()[1][0] );
		$this->assertSame( [ 'retracted' ], array_column( $this->table(), 'status' ) );
	}

	public function test_send_validates_every_id_before_sending_anything(): void {
		$this->order( [ 'id' => 7, 'status' => 'completed' ] );

		foreach ( [ [], [ '7', 'abc' ], [ '0' ], [ '-3' ], [ '7', '1.5' ] ] as $args ) {
			$this->assertSame( 1, $this->exec( fn() => ( new SignalCommand() )->send( $args ) ) );
		}
		$this->assertSame( [], $this->calls() );
	}

	public function test_send_continues_after_a_missing_order_and_exits_1(): void {
		$this->order( [ 'id' => 7, 'status' => 'completed' ] );

		$this->assertSame( 1, $this->exec( fn() => ( new SignalCommand() )->send( [ '999', '7' ] ) ) );

		$this->assertCount( 1, $this->calls() );
		$this->assertSame( [ 'not_found', 'sent' ], array_column( WP_CLI::$tables[0][1], 'status' ) );
	}

	public function test_send_exits_1_with_the_error_code_when_the_service_refuses(): void {
		$this->order( [ 'id' => 7, 'status' => 'completed' ] );
		$this->answers = [ self::problem( 403, 'FORBIDDEN' ) ];

		$this->assertSame( 1, $this->exec( fn() => ( new SignalCommand() )->send( [ '7' ] ) ) );

		$this->assertSame( [ 'failed' ], array_column( $this->table(), 'status' ) );
		$this->assertSame( [ 'FORBIDDEN' ], array_column( $this->table(), 'error' ) );
		$this->assertStringNotContainsString( self::EMAIL, $this->output() );
	}

	public function test_send_reports_a_pending_retry_as_an_error_and_leaves_it_scheduled(): void {
		$this->order( [ 'id' => 7, 'status' => 'completed' ] );
		$this->answers = [ self::problem( 503, 'UNAVAILABLE' ) ];

		$this->assertSame( 1, $this->exec( fn() => ( new SignalCommand() )->send( [ '7' ] ) ) );

		$this->assertSame( [ 'pending' ], array_column( $this->table(), 'status' ) );
		$this->assertCount( 1, $this->pending() );
	}

	public function test_send_respects_the_order_lock(): void {
		$this->order( [ 'id' => 7, 'status' => 'completed' ] );
		$this->assertTrue( SignalLock::acquire( 7 ) );

		$this->exec( fn() => ( new SignalCommand() )->send( [ '7' ] ) );

		$this->assertSame( [], $this->calls(), 'whoever holds the lock sends it' );
		$this->assertCount( 1, $this->pending() );
	}

	public function test_send_without_a_key_fails(): void {
		$this->order( [ 'id' => 7, 'status' => 'completed' ] );
		Settings::update( [ 'api_key' => '' ] );

		$this->assertSame( 1, $this->exec( fn() => ( new SignalCommand() )->send( [ '7' ] ) ) );
		$this->assertSame( [ 'NO_KEY' ], WP_CLI::$errors );
		$this->assertSame( [], $this->calls() );
	}

	public function test_send_with_nothing_to_report_is_not_an_error(): void {
		$this->order( [ 'id' => 7, 'status' => 'processing' ] );

		$this->assertSame( 0, $this->exec( fn() => ( new SignalCommand() )->send( [ '7' ] ) ) );

		$this->assertSame( [], $this->calls() );
		$this->assertSame( [ 'none' ], array_column( $this->table(), 'status' ) );
	}

	public function test_status_shows_the_stored_state_without_calling_the_service(): void {
		$order = $this->order( [ 'id' => 7, 'status' => 'completed' ] );
		( new SignalCommand() )->send( [ '7' ] );
		WP_CLI::reset();
		$calls = count( $this->calls() );

		$this->assertSame( 0, $this->exec( fn() => ( new SignalCommand() )->status( [ '7' ], [ 'format' => 'json' ] ) ) );

		$d = $this->json();
		$this->assertSame( 7, $d['order_id'] );
		$this->assertSame( 'completed', $d['order_status'] );
		$this->assertSame( 'delivered', $d['wanted'] );
		$this->assertSame( 'sent', $d['status'] );
		$this->assertSame( 'delivered', $d['outcome'] );
		$this->assertSame( 'test', $d['env'] );
		$this->assertSame( $calls, count( $this->calls() ) );
		$this->assertSame( $this->state( $order )['sent_at'], $d['sent_at'] );
	}

	public function test_status_validates_the_id_and_finds_the_order(): void {
		$this->assertSame( 1, $this->exec( fn() => ( new SignalCommand() )->status( [], [] ) ) );
		$this->assertSame( 1, $this->exec( fn() => ( new SignalCommand() )->status( [ 'x' ], [] ) ) );
		$this->assertSame( 1, $this->exec( fn() => ( new SignalCommand() )->status( [ '5', '6' ], [] ) ) );
		$this->assertSame( 1, $this->exec( fn() => ( new SignalCommand() )->status( [ '999' ], [] ) ) );
	}

	public function test_send_and_status_support_json(): void {
		$this->order( [ 'id' => 7, 'status' => 'completed' ] );

		$this->assertSame( 0, $this->exec( fn() => ( new SignalCommand() )->send( [ '7' ], [ 'format' => 'json' ] ) ) );
		$this->assertSame( [ [ 'order_id' => '7', 'status' => 'sent', 'outcome' => 'delivered', 'error' => '' ] ], $this->json() );
		$this->assertSame( 1, $this->exec( fn() => ( new SignalCommand() )->send( [ '7' ], [ 'format' => 'xml' ] ) ) );
		$this->assertCount( 1, $this->calls(), 'a bad format is refused before any request' );
	}
}
