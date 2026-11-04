<?php
/**
 * Sends (or retracts) one order's outcome signal.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Signals;

use LightweightPlugins\TrustOrder\Api\ApiKey;
use LightweightPlugins\TrustOrder\Api\Client;
use LightweightPlugins\TrustOrder\Api\Response;
use LightweightPlugins\TrustOrder\Checkout\CacheGeneration;
use LightweightPlugins\TrustOrder\Log;
use LightweightPlugins\TrustOrder\Settings;

/**
 * Reads the order's CURRENT status, compares it with what the backend holds
 * in the current key's env (`_trustorder_signal`) and makes at most one call.
 * The env always comes from the current key: a test → live switch sends
 * again to live and never mixes the two.
 */
final class SignalJob {

	/**
	 * Retry delays (seconds) after attempts 1..4; attempt 5 is the last.
	 */
	public const BACKOFF = [ 60, 300, 1800, 7200 ];

	public const MAX_ATTEMPTS = 5;

	/**
	 * Action Scheduler callback; the args are untrusted.
	 *
	 * @param mixed $order_id Order id.
	 */
	public static function handle( $order_id ): void {
		if ( is_int( $order_id ) || ( is_string( $order_id ) && ctype_digit( $order_id ) ) ) {
			self::run( (int) $order_id );
		}
	}

	/**
	 * Env of the current API key, null without a valid key.
	 */
	public static function env(): ?string {
		return ApiKey::env( Settings::get()['api_key'] );
	}

	/**
	 * Run for one order. Never throws.
	 *
	 * @param int $order_id Order id.
	 */
	public static function run( int $order_id ): void {
		try {
			$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
			$env   = self::env();
			if ( ! $order instanceof \WC_Order || null === $env ) {
				return; // Deleted order or no key: nothing to do.
			}
			if ( ! SignalLock::acquire( $order_id ) ) {
				SignalRouter::schedule( $order_id, SignalLock::TTL ); // The backfill (or another job) has it.
				return;
			}
		} catch ( \Throwable $e ) {
			Log::error( 'signal', [ 'code' => 'EXCEPTION' ] );
			return;
		}
		try {
			self::process( $order, $env );
		} catch ( \Throwable $e ) {
			Log::error( 'signal', [ 'code' => 'EXCEPTION' ] );
		} finally {
			SignalLock::release( $order_id );
		}
	}

	/**
	 * Decide and make the call.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $env   Current env.
	 */
	private static function process( \WC_Order $order, string $env ): void {
		$want  = Outcome::forStatus( (string) $order->get_status() );
		$state = SignalState::read( $order );
		$held  = SignalState::held( $state, $env );

		if ( Outcome::NONE === $want || SignalState::voided( $state, $env ) ) {
			return;
		}
		if ( Outcome::RETRACT === $want && null === $held && ! self::mustDelete( $state, $env ) ) {
			if ( $env === $state['env'] && SignalState::PENDING === $state['status'] ) {
				// An unsent PUT retry is cancelled: nothing is held on the backend.
				SignalState::write( $order, self::next( $state, $env, null, SignalState::RETRACTED, Outcome::RETRACT ) );
			}
			return;
		}
		if ( $want === $held && SignalState::SENT === $state['status'] ) {
			return; // Already sent (e.g. completed → refunded → completed).
		}

		$same     = $env === $state['env'] && $want === $state['target'];
		$attempts = ( $same && SignalState::PENDING === $state['status'] ? $state['attempts'] : 0 ) + 1;
		$client   = Client::fromSettings();

		if ( Outcome::RETRACT === $want ) {
			$resp = $client->deleteSignal( (string) $order->get_id() );
			self::afterDelete( $order, $state, $env, $resp, $attempts );
			return;
		}

		$body = SignalPayload::build( $order, $want, $same ? $state['occurred_at'] : null );
		if ( null === $body ) {
			SignalState::write( $order, self::next( $state, $env, $held, SignalState::SKIPPED, $want ) );
			return;
		}
		$resp = $client->putSignal( (string) $order->get_id(), $body );
		self::afterPut( $order, $state, $env, $want, $body['occurred_at'], $resp, $attempts );
	}

	/**
	 * A retract with nothing known in this env still needs a DELETE when a
	 * signal was recorded under the other key's env (this env may hold one
	 * from before the switch; 404 = nothing there), or when a DELETE retry
	 * is pending. The other env itself cannot be reached without its key.
	 *
	 * @param array<string, mixed> $state State.
	 * @param string               $env   Current env.
	 */
	private static function mustDelete( array $state, string $env ): bool {
		if ( null !== $state['env'] && $env !== $state['env'] ) {
			return null !== $state['outcome'] || null !== $state['status'];
		}

		return SignalState::PENDING === $state['status'] && Outcome::RETRACT === $state['target'];
	}

	/**
	 * Record a PUT result.
	 *
	 * @param \WC_Order            $order      Order.
	 * @param array<string, mixed> $state      Previous state.
	 * @param string               $env        Env.
	 * @param string               $want       Outcome sent.
	 * @param string               $occurred_at occurred_at sent.
	 * @param Response             $resp       Response.
	 * @param int                  $attempts   This attempt's number.
	 */
	private static function afterPut( \WC_Order $order, array $state, string $env, string $want, string $occurred_at, Response $resp, int $attempts ): void {
		if ( $resp->ok() ) {
			$status = 'voided' === ( $resp->data()['result'] ?? null ) ? SignalState::VOIDED : SignalState::SENT;
			$next   = self::next( $state, $env, $want, $status, $want );

			$next['sent_at']     = SignalState::now();
			$next['occurred_at'] = $occurred_at;
			SignalState::write( $order, $next );
			// The buyer's history changed: a cached check result is now stale.
			CacheGeneration::bump();
			return;
		}
		$next                = self::failure( $order, $state, $env, $want, $resp, $attempts );
		$next['occurred_at'] = $occurred_at;
		SignalState::write( $order, $next );
	}

	/**
	 * Record a DELETE result (404 = nothing to delete = success; 409 = voided).
	 *
	 * @param \WC_Order            $order    Order.
	 * @param array<string, mixed> $state    Previous state.
	 * @param string               $env      Env.
	 * @param Response             $resp     Response.
	 * @param int                  $attempts This attempt's number.
	 */
	private static function afterDelete( \WC_Order $order, array $state, string $env, Response $resp, int $attempts ): void {
		if ( $resp->ok() || 404 === $resp->status() ) {
			$next            = self::next( $state, $env, null, SignalState::RETRACTED, Outcome::RETRACT );
			$next['sent_at'] = SignalState::now();
			SignalState::write( $order, $next );
			CacheGeneration::bump();
			return;
		}
		if ( 409 === $resp->status() ) {
			SignalState::write( $order, self::next( $state, $env, $state['outcome'], SignalState::VOIDED, Outcome::RETRACT ) );
			return;
		}
		SignalState::write( $order, self::failure( $order, $state, $env, Outcome::RETRACT, $resp, $attempts ) );
	}

	/**
	 * Retry later (retryable, attempts left) or give up as `failed`.
	 *
	 * @param \WC_Order            $order    Order.
	 * @param array<string, mixed> $state    Previous state.
	 * @param string               $env      Env.
	 * @param string               $target   Attempted outcome.
	 * @param Response             $resp     Response.
	 * @param int                  $attempts This attempt's number.
	 * @return array<string, mixed>
	 */
	private static function failure( \WC_Order $order, array $state, string $env, string $target, Response $resp, int $attempts ): array {
		$code   = SignalState::errorCode( $resp->errorCode(), $resp->status() );
		$retry  = $resp->retryable() && $attempts < self::MAX_ATTEMPTS;
		$status = $retry ? SignalState::PENDING : SignalState::FAILED;
		$next   = self::next( $state, $env, SignalState::held( $state, $env ), $status, $target );

		$next['attempts'] = $attempts;
		$next['error']    = $code;
		if ( $retry ) {
			SignalRouter::schedule( $order->get_id(), self::BACKOFF[ $attempts - 1 ] );
		} else {
			Log::error(
				'signal',
				[
					'code'   => $code,
					'status' => $resp->status(),
				]
			);
		}

		return $next;
	}

	/**
	 * New state (attempt bookkeeping reset; sent_at / occurred_at kept).
	 *
	 * @param array<string, mixed> $state   Previous state.
	 * @param string               $env     Env.
	 * @param string|null          $outcome Held outcome.
	 * @param string               $status  Status.
	 * @param string               $target  Attempted outcome.
	 * @return array<string, mixed>
	 */
	private static function next( array $state, string $env, ?string $outcome, string $status, string $target ): array {
		$same = $env === $state['env'];

		return [
			'outcome'     => $outcome,
			'env'         => $env,
			'sent_at'     => $same ? $state['sent_at'] : null,
			'status'      => $status,
			'target'      => $target,
			'attempts'    => 0,
			'error'       => null,
			'occurred_at' => $same ? $state['occurred_at'] : null,
		];
	}
}
