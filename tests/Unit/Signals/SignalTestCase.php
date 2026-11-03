<?php
/**
 * Shared setup for the signal tests: key, scripted API answers, orders, AS queue.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Signals;

use LightweightPlugins\TrustOrder\Settings;
use LightweightPlugins\TrustOrder\Signals\Backfill;
use LightweightPlugins\TrustOrder\Signals\SignalRouter;
use LightweightPlugins\TrustOrder\Signals\SignalState;
use PHPUnit\Framework\TestCase;
use WP_Error;

abstract class SignalTestCase extends TestCase {

	protected const TEST_KEY = 'to_test_abcdefghijABCDEFGHIJ0123456789xy';
	protected const LIVE_KEY = 'to_live_abcdefghijABCDEFGHIJ0123456789xy';
	protected const EMAIL    = 'buyer@example.com';
	protected const PHONE    = '+36301234567';

	/**
	 * Scripted answers (the last one repeats); null = 200 {"result":"stored"} / 204.
	 *
	 * @var list<array<string, mixed>|WP_Error>
	 */
	protected array $answers = [];

	protected function setUp(): void {
		trustorder_test_reset();
		$GLOBALS['trustorder_test']['orders'] = [];
		Settings::update( [ 'api_key' => self::TEST_KEY ] );
		SignalRouter::register();
		Backfill::register();
		$this->answers = [];
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) {
				if ( false !== $pre ) {
					return $pre; // An earlier (test-specific) filter answered.
				}
				if ( [] === $this->answers ) {
					return 'DELETE' === ( $args['method'] ?? '' ) ? self::http( 204, '' ) : self::http( 200, [ 'result' => 'stored' ] );
				}
				return count( $this->answers ) > 1 ? array_shift( $this->answers ) : $this->answers[0];
			},
			10,
			2
		);
	}

	/**
	 * HTTP response array.
	 *
	 * @param int                 $code Status.
	 * @param array<mixed>|string $body Body (array = JSON).
	 * @return array<string, mixed>
	 */
	protected static function http( int $code, $body ): array {
		return [
			'response' => [ 'code' => $code ],
			'body'     => is_array( $body ) ? (string) json_encode( $body ) : $body,
		];
	}

	/**
	 * Problem response.
	 *
	 * @return array<string, mixed>
	 */
	protected static function problem( int $code, string $error ): array {
		return self::http( $code, [ 'status' => $code, 'code' => $error, 'title' => 'x' ] );
	}

	/**
	 * A registered order.
	 *
	 * @param array<string, mixed> $over Props.
	 */
	protected function order( array $over = [] ): \WC_Order {
		$o           = new \WC_Order(
			$over + [
				'id'                 => 42,
				'status'             => 'processing',
				'billing_email'      => self::EMAIL,
				'billing_phone'      => self::PHONE,
				'billing_country'    => 'HU',
				'shipping_country'   => 'HU',
				'shipping_postcode'  => '1051',
				'shipping_address_1' => 'Fő utca 3',
				'date_completed'     => new \DateTimeImmutable( '2026-10-01T10:00:00Z' ),
				'date_modified'      => new \DateTimeImmutable( '2026-10-02T11:00:00Z' ),
			]
		);
		$o->shipping = [ new \WC_Order_Item_Shipping( 'flat_rate' ) ];
		$GLOBALS['trustorder_test']['orders'][ $o->get_id() ] = $o;

		return $o;
	}

	/**
	 * Change the status like WC_Order::status_transition() and run the queue.
	 */
	protected function transition( \WC_Order $order, string $to, bool $drain = true ): void {
		$from = (string) $order->get_status();
		$order->set_status( $to );
		do_action( 'woocommerce_order_status_changed', $order->get_id(), $from, $to, $order );
		if ( $drain ) {
			$this->drain();
		}
	}

	/**
	 * Run every pending action (Action Scheduler runner stand-in, time ignored).
	 */
	protected function drain(): void {
		for ( $guard = 0; $guard < 50; $guard++ ) {
			$found = false;
			foreach ( $GLOBALS['trustorder_test']['as'] as $i => $a ) {
				if ( 'pending' === $a['status'] ) {
					$GLOBALS['trustorder_test']['as'][ $i ]['status'] = 'in-progress';
					do_action( $a['hook'], ...$a['args'] );
					$GLOBALS['trustorder_test']['as'][ $i ]['status'] = 'complete';
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				return;
			}
		}
	}

	/**
	 * Signal API calls so far: [method, path-after-base, decoded body].
	 *
	 * @return list<array{0: string, 1: string, 2: mixed}>
	 */
	protected function calls(): array {
		$out = [];
		foreach ( $GLOBALS['trustorder_test']['http'] as $c ) {
			$path  = (string) preg_replace( '#^https://api\.uptools\.io/v1/trustorder#', '', $c['url'] );
			$out[] = [ (string) ( $c['args']['method'] ?? 'GET' ), $path, isset( $c['args']['body'] ) ? json_decode( (string) $c['args']['body'], true ) : null ];
		}
		return $out;
	}

	/**
	 * Pending AS actions of a hook.
	 *
	 * @return list<array<string, mixed>>
	 */
	protected function pending( string $hook = SignalRouter::HOOK ): array {
		return array_values(
			array_filter(
				$GLOBALS['trustorder_test']['as'],
				static fn( array $a ): bool => $hook === $a['hook'] && 'pending' === $a['status']
			)
		);
	}

	/**
	 * Decoded signal state.
	 *
	 * @return array<string, mixed>
	 */
	protected function state( \WC_Order $order ): array {
		return SignalState::read( $order );
	}

	/**
	 * Everything the plugin logged or stored as last error.
	 */
	protected function logged(): string {
		return (string) json_encode( [ $GLOBALS['trustorder_test']['logs'], $GLOBALS['trustorder_test']['options']['trustorder_last_error'] ?? null ] );
	}
}
