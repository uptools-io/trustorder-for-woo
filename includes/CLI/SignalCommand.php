<?php
/**
 * WP-CLI command group: trustorder signal.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\CLI;

use LightweightPlugins\TrustOrder\Signals\Outcome;
use LightweightPlugins\TrustOrder\Signals\SignalJob;
use LightweightPlugins\TrustOrder\Signals\SignalRouter;
use LightweightPlugins\TrustOrder\Signals\SignalState;

/**
 * Order outcome signals: send the current outcome now, or show the stored
 * state. Sending uses the same job as the background runner (per-order lock,
 * retries and idempotence included).
 */
final class SignalCommand {

	private const PROBLEMS = [ SignalState::FAILED, SignalState::PENDING, 'not_found' ];

	/**
	 * Sends the current outcome of the orders now (completed = delivered,
	 * "Not picked up" = refused, back to processing = retract). An outcome the
	 * service already holds is not sent again.
	 *
	 * Exits with status 1 when an order is missing or its signal failed or is
	 * waiting for a retry.
	 *
	 * ## OPTIONS
	 *
	 * <order-id>...
	 * : One or more order ids.
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp trustorder signal send 1234 1240
	 *
	 * @param array<int, string>   $args  Order ids.
	 * @param array<string, mixed> $assoc Associative args.
	 */
	public function send( array $args, array $assoc = [] ): void {
		$format = Output::format( $assoc );
		$ids    = $this->ids( $args );
		if ( null === SignalJob::env() ) {
			Output::fail( 'NO_KEY' );
		}
		$rows = [];
		foreach ( $ids as $id ) {
			$rows[] = $this->sendOne( $id );
		}
		Output::rows( $rows, [ 'order_id', 'status', 'outcome', 'error' ], $format );

		$bad = count( array_filter( $rows, static fn( array $r ): bool => in_array( $r['status'], self::PROBLEMS, true ) ) );
		if ( $bad > 0 ) {
			Output::fail( sprintf( 'SIGNAL_FAILED: %d of %d orders not reported.', $bad, count( $rows ) ) );
		}
	}

	/**
	 * Shows the stored signal state of one order (no request is made).
	 *
	 * ## OPTIONS
	 *
	 * <order-id>
	 * : Order id.
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * @param array<int, string>   $args  Order id.
	 * @param array<string, mixed> $assoc Associative args.
	 */
	public function status( array $args, array $assoc ): void {
		$format = Output::format( $assoc );
		$ids    = $this->ids( $args );
		if ( count( $ids ) !== 1 ) {
			Output::fail( 'Give exactly one order id.' );
		}
		$order = wc_get_order( $ids[0] );
		if ( ! $order instanceof \WC_Order ) {
			Output::fail( 'Order not found.' );
		}
		$state = SignalState::read( $order );
		Output::pairs(
			[
				'order_id'     => $order->get_id(),
				'order_status' => $order->get_status(),
				'wanted'       => Outcome::forStatus( (string) $order->get_status() ),
				'outcome'      => $state['outcome'],
				'status'       => $state['status'],
				'env'          => $state['env'],
				'sent_at'      => $state['sent_at'],
				'target'       => $state['target'],
				'attempts'     => $state['attempts'],
				'error'        => $state['error'],
				'occurred_at'  => $state['occurred_at'],
				'queued'       => $this->queued( $order->get_id() ),
			],
			$format
		);
	}

	/**
	 * Run the job for one order and describe the result.
	 *
	 * @param int $id Order id.
	 * @return array{order_id: string, status: string, outcome: string, error: string}
	 */
	private function sendOne( int $id ): array {
		$row = [
			'order_id' => (string) $id,
			'status'   => 'not_found',
			'outcome'  => '',
			'error'    => '',
		];
		if ( ! wc_get_order( $id ) instanceof \WC_Order ) {
			return $row;
		}
		SignalJob::run( $id );
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order ) {
			return $row;
		}
		$state = SignalState::read( $order );
		$mine  = SignalJob::env() === $state['env'];

		$row['status']  = $mine && null !== $state['status'] ? $state['status'] : ( $this->queued( $id ) ? 'queued' : 'none' );
		$row['outcome'] = $mine ? (string) $state['outcome'] : '';
		$row['error']   = $mine ? (string) $state['error'] : '';

		return $row;
	}

	/**
	 * Whether a background job for the order is waiting.
	 *
	 * @param int $id Order id.
	 */
	private function queued( int $id ): bool {
		return function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( SignalRouter::HOOK, [ $id ], SignalRouter::GROUP );
	}

	/**
	 * Validated order ids.
	 *
	 * @param array<int, mixed> $args Positional args.
	 * @return list<int>
	 */
	private function ids( array $args ): array {
		if ( [] === $args ) {
			Output::fail( 'Give at least one order id.' );
		}
		$ids = [];
		foreach ( $args as $arg ) {
			if ( ! is_string( $arg ) || 1 !== preg_match( '/^[1-9][0-9]{0,17}$/D', $arg ) ) {
				Output::fail( 'Order ids are positive whole numbers.' );
			}
			$ids[] = (int) $arg;
		}

		return array_values( array_unique( $ids ) );
	}
}
