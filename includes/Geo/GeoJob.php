<?php
/**
 * Sends one order's country and postcode (on by default, anonymous statistics).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Geo;

use LightweightPlugins\TrustOrder\Api\ApiKey;
use LightweightPlugins\TrustOrder\Api\Client;
use LightweightPlugins\TrustOrder\Api\Response;
use LightweightPlugins\TrustOrder\Identity\Address;
use LightweightPlugins\TrustOrder\Log;
use LightweightPlugins\TrustOrder\Settings;
use LightweightPlugins\TrustOrder\Signals\SignalJob;
use LightweightPlugins\TrustOrder\Signals\SignalState;

/**
 * Re-reads the setting, the key and the order's `_trustorder_geo_sent` meta,
 * then makes at most one POST /geo with only `country` and `postcode`
 * (shipping address, else billing). Retryable failures use the signal job's
 * backoff and attempt budget (`_trustorder_geo_attempts`); here every 5xx
 * is retryable too, on top of Response::retryable(). Other failures give up
 * at once. Neither value is ever logged.
 */
final class GeoJob {

	public const META          = '_trustorder_geo_sent';
	public const ATTEMPTS_META = '_trustorder_geo_attempts';
	public const DECIDED_META  = '_trustorder_geo_decided';

	private const MAX_POSTCODE = 10;

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
	 * Whether the order's geo was already sent.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function sent( \WC_Order $order ): bool {
		return '1' === $order->get_meta( self::META, true );
	}

	/**
	 * Run for one order. Never throws.
	 *
	 * @param int $order_id Order id.
	 */
	public static function run( int $order_id ): void {
		try {
			$settings = Settings::get();
			$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;
			if ( ! $settings['share_geo'] || null === ApiKey::env( $settings['api_key'] ) || ! $order instanceof \WC_Order || self::sent( $order ) ) {
				return; // Opted out, no key, deleted order or already sent.
			}
			$body = self::body( $order );
			if ( null === $body ) {
				return; // Nothing usable to send; a retry would not change that.
			}
			$attempts = absint( $order->get_meta( self::ATTEMPTS_META, true ) ) + 1;
			self::after( $order, Client::fromSettings()->postGeo( $body ), $attempts );
		} catch ( \Throwable $e ) {
			Log::error( 'geo', [ 'code' => 'EXCEPTION' ] );
		}
	}

	/**
	 * `{country, postcode}` from the shipping address, or from the billing
	 * address when shipping lacks either; null when neither has both. The
	 * postcode is normalised and checked like the backend does (1 to 10 of
	 * [a-z0-9-]); one that would be refused with a 400 counts as missing.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{country: string, postcode: string}|null
	 */
	public static function body( \WC_Order $order ): ?array {
		$addresses = [
			[ $order->get_shipping_country(), $order->get_shipping_postcode() ],
			[ $order->get_billing_country(), $order->get_billing_postcode() ],
		];
		foreach ( $addresses as [ $country, $postcode ] ) {
			$country = strtoupper( trim( (string) $country ) );
			if ( 1 !== preg_match( '/^[A-Z]{2}$/D', $country ) ) {
				continue;
			}
			$postcode = Address::postcode( (string) $postcode, strtolower( $country ) );
			if ( null !== $postcode && strlen( $postcode ) <= self::MAX_POSTCODE ) {
				return [
					'country'  => $country,
					'postcode' => $postcode,
				];
			}
		}

		return null;
	}

	/**
	 * Record the result: sent, retry later, or give up.
	 *
	 * @param \WC_Order $order    Order.
	 * @param Response  $resp     Response.
	 * @param int       $attempts This attempt's number.
	 */
	private static function after( \WC_Order $order, Response $resp, int $attempts ): void {
		if ( $resp->ok() ) {
			$order->update_meta_data( self::META, '1' );
			$order->delete_meta_data( self::ATTEMPTS_META );
			$order->save_meta_data();
			return;
		}
		if ( self::retryable( $resp ) && $attempts < SignalJob::MAX_ATTEMPTS ) {
			$order->update_meta_data( self::ATTEMPTS_META, (string) $attempts );
			$order->save_meta_data();
			GeoRouter::schedule( $order->get_id(), SignalJob::BACKOFF[ $attempts - 1 ] );
			return;
		}
		$order->delete_meta_data( self::ATTEMPTS_META );
		$order->save_meta_data();
		Log::error(
			'geo',
			[
				'code'   => SignalState::errorCode( $resp->errorCode(), $resp->status() ),
				'status' => $resp->status(),
			]
		);
	}

	/**
	 * Network errors, 429 and every 5xx are worth another try.
	 *
	 * @param Response $resp Response.
	 */
	private static function retryable( Response $resp ): bool {
		return $resp->retryable() || $resp->status() >= 500;
	}
}
