<?php
/**
 * Rows of one backfill page and their per-row results.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Signals;

/**
 * Orders already sent (same outcome) or voided in this env are skipped;
 * orders without an e-mail hash are recorded as `skipped`. A row result
 * `stored` / `unchanged` is sent, `voided` is final (never resent),
 * `INVALID_REQUEST` is failed, `INTERNAL` (or a missing row) stops the page
 * there so that row and the later ones are resent.
 *
 * @phpstan-type Item array{order: \WC_Order, kind: string, outcome: string, occurred_at: string|null}
 */
final class BackfillPlan {

	public const SEND  = 'send';
	public const SKIP  = 'skip';
	public const EMPTY = 'empty';

	/**
	 * Plan a page.
	 *
	 * @param array<int, \WC_Order> $orders Orders by id.
	 * @param string                $env    Env.
	 * @return array{items: list<array<string, mixed>>, rows: list<array<string, string>>, locked: list<int>}
	 */
	public static function build( array $orders, string $env ): array {
		$items  = [];
		$rows   = [];
		$locked = [];
		foreach ( $orders as $order ) {
			if ( ! SignalLock::acquire( $order->get_id() ) ) {
				// A signal job owns the order right now; it sends the current status.
				$items[] = [
					'order'       => $order,
					'kind'        => self::SKIP,
					'outcome'     => Outcome::NONE,
					'occurred_at' => null,
				];
				continue;
			}
			$locked[] = $order->get_id();
			$order    = self::fresh( $order );
			$want     = Outcome::forStatus( (string) $order->get_status() );
			$state    = SignalState::read( $order );
			$item     = [
				'order'       => $order,
				'kind'        => self::SKIP,
				'outcome'     => $want,
				'occurred_at' => null,
			];
			$done     = SignalState::voided( $state, $env )
				|| ( SignalState::held( $state, $env ) === $want && SignalState::SENT === $state['status'] );
			if ( Outcome::isSignal( $want ) && ! $done ) {
				$body = SignalPayload::build( $order, $want );
				if ( null === $body ) {
					$item['kind'] = self::EMPTY;
				} else {
					$item['kind']        = self::SEND;
					$item['occurred_at'] = $body['occurred_at'];
					$rows[]              = [ 'order_ref' => (string) $order->get_id() ] + $body;
				}
			}
			$items[] = $item;
		}

		return [
			'items'  => $items,
			'rows'   => $rows,
			'locked' => $locked,
		];
	}

	/**
	 * Index the batch response by order_ref.
	 *
	 * @param array<mixed> $data Response data.
	 * @return array<string, array<string, mixed>>
	 */
	public static function results( array $data ): array {
		$out = [];
		foreach ( is_array( $data['results'] ?? null ) ? $data['results'] : [] as $row ) {
			if ( is_array( $row ) && isset( $row['order_ref'] ) && is_string( $row['order_ref'] ) ) {
				$out[ $row['order_ref'] ] = $row;
			}
		}

		return $out;
	}

	/**
	 * Record one item; returns the counter to bump ('sent', 'failed', '' for
	 * none) or null when the page must stop here (INTERNAL / missing row).
	 *
	 * @param array<string, mixed>                $item    Item from build().
	 * @param array<string, array<string, mixed>> $results From results().
	 * @param string                              $env     Env.
	 */
	public static function apply( array $item, array $results, string $env ): ?string {
		$order = $item['order'];
		if ( self::SKIP === $item['kind'] ) {
			return '';
		}
		$state = SignalState::read( $order );
		$held  = SignalState::held( $state, $env );
		if ( self::EMPTY === $item['kind'] ) {
			SignalState::write( $order, self::state( $state, $env, $held, SignalState::SKIPPED, $item ) );
			return '';
		}

		$row    = $results[ (string) $order->get_id() ] ?? null;
		$result = is_array( $row ) ? ( $row['result'] ?? null ) : null;
		$code   = is_array( $row ) && is_string( $row['error_code'] ?? null ) ? $row['error_code'] : null;
		if ( null === $row || 'INTERNAL' === $code || ( null === $result && null === $code ) ) {
			return null;
		}
		if ( 'stored' === $result || 'unchanged' === $result || 'voided' === $result ) {
			$status          = 'voided' === $result ? SignalState::VOIDED : SignalState::SENT;
			$next            = self::state( $state, $env, $item['outcome'], $status, $item );
			$next['sent_at'] = SignalState::now();
			SignalState::write( $order, $next );
			if ( Outcome::forStatus( (string) self::fresh( $order )->get_status() ) !== $item['outcome'] ) {
				// The status changed during the call: the meta records what the
				// backend now holds, and the signal job corrects it.
				SignalRouter::schedule( $order->get_id() );
				return '';
			}
			return SignalState::SENT === $status ? 'sent' : '';
		}

		$next          = self::state( $state, $env, $held, SignalState::FAILED, $item );
		$next['error'] = SignalState::errorCode( $code, 0 );
		SignalState::write( $order, $next );

		return 'failed';
	}

	/**
	 * The order as stored now (another request may have changed its status).
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function fresh( \WC_Order $order ): \WC_Order {
		$fresh = wc_get_order( $order->get_id() );

		return $fresh instanceof \WC_Order ? $fresh : $order;
	}

	/**
	 * New state for an item.
	 *
	 * @param array<string, mixed> $state   Previous state.
	 * @param string               $env     Env.
	 * @param string|null          $outcome Held outcome.
	 * @param string               $status  Status.
	 * @param array<string, mixed> $item    Item.
	 * @return array<string, mixed>
	 */
	private static function state( array $state, string $env, ?string $outcome, string $status, array $item ): array {
		return [
			'outcome'     => $outcome,
			'env'         => $env,
			'sent_at'     => $env === $state['env'] ? $state['sent_at'] : null,
			'status'      => $status,
			'target'      => $item['outcome'],
			'attempts'    => 0,
			'error'       => null,
			'occurred_at' => $item['occurred_at'],
		];
	}
}
