<?php
/**
 * The `_trustorder_signal` order meta.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Signals;

/**
 * JSON: `outcome` (what the backend holds for this order in `env`, null =
 * nothing), `env`, `sent_at`, `status`, plus the bookkeeping of the last
 * attempt (`target`, `attempts`, `error`, `occurred_at`). No identity data.
 *
 * @phpstan-type State array{outcome: string|null, env: string|null, sent_at: string|null, status: string|null, target: string|null, attempts: int, error: string|null, occurred_at: string|null}
 */
final class SignalState {

	public const META = '_trustorder_signal';

	public const SENT      = 'sent';
	public const RETRACTED = 'retracted';
	public const PENDING   = 'pending';
	public const FAILED    = 'failed';
	public const SKIPPED   = 'skipped';
	public const VOIDED    = 'voided';

	private const OUTCOMES = [ Outcome::DELIVERED, Outcome::REFUSED ];
	private const TARGETS  = [ Outcome::DELIVERED, Outcome::REFUSED, Outcome::RETRACT ];
	private const STATUSES = [ self::SENT, self::RETRACTED, self::PENDING, self::FAILED, self::SKIPPED, self::VOIDED ];

	/**
	 * Stored state, normalised (unknown values become null).
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, mixed>
	 * @phpstan-return State
	 */
	public static function read( \WC_Order $order ): array {
		$raw  = $order->get_meta( self::META, true );
		$data = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		$data = is_array( $data ) ? $data : [];
		$pick = static fn( string $k, array $allowed ): ?string => isset( $data[ $k ] ) && in_array( $data[ $k ], $allowed, true ) ? $data[ $k ] : null;
		$str  = static fn( string $k ): ?string => isset( $data[ $k ] ) && is_string( $data[ $k ] ) && 1 === preg_match( '/^[0-9A-Za-z_:+.-]{1,40}$/D', $data[ $k ] ) ? $data[ $k ] : null;

		return [
			'outcome'     => $pick( 'outcome', self::OUTCOMES ),
			'env'         => $pick( 'env', [ 'live', 'test' ] ),
			'sent_at'     => $str( 'sent_at' ),
			'status'      => $pick( 'status', self::STATUSES ),
			'target'      => $pick( 'target', self::TARGETS ),
			'attempts'    => isset( $data['attempts'] ) && is_int( $data['attempts'] ) ? max( 0, $data['attempts'] ) : 0,
			'error'       => $str( 'error' ),
			'occurred_at' => $str( 'occurred_at' ),
		];
	}

	/**
	 * What the backend holds for this order in `env` (null = nothing known).
	 *
	 * @param array<string, mixed> $state State from read().
	 * @param string               $env   Current env.
	 */
	public static function held( array $state, string $env ): ?string {
		return $env === $state['env'] ? $state['outcome'] : null;
	}

	/**
	 * Whether the signal is voided in `env` (final: never resent or deleted).
	 *
	 * @param array<string, mixed> $state State from read().
	 * @param string               $env   Current env.
	 */
	public static function voided( array $state, string $env ): bool {
		return $env === $state['env'] && self::VOIDED === $state['status'];
	}

	/**
	 * Store the state (meta only: no status transition, no order save hooks).
	 *
	 * @param \WC_Order            $order Order.
	 * @param array<string, mixed> $state State.
	 */
	public static function write( \WC_Order $order, array $state ): void {
		$order->update_meta_data( self::META, (string) wp_json_encode( $state ) );
		$order->save_meta_data();
	}

	/**
	 * Error code safe for meta and logs.
	 *
	 * @param string|null $code   API error code.
	 * @param int         $status HTTP status.
	 */
	public static function errorCode( ?string $code, int $status ): string {
		if ( null !== $code && 1 === preg_match( '/^[A-Z0-9_]{1,32}$/D', $code ) ) {
			return $code;
		}

		return $status > 0 ? 'HTTP_' . $status : 'ERROR';
	}

	/**
	 * Now, RFC 3339 UTC.
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}
}
