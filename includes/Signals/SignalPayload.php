<?php
/**
 * Signal body (hashes + outcome + occurred_at) for one order.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Signals;

use LightweightPlugins\TrustOrder\Checkout\IdentityBuilder;
use LightweightPlugins\TrustOrder\Order\OrderResult;

/**
 * Hashes come from `_trustorder_hashes` (stored at checkout); orders without
 * a usable copy (pre-plugin, admin-created) are hashed locally from the
 * order's WooCommerce getters. Only hashes leave the site; without an e-mail
 * hash there is no body.
 */
final class SignalPayload {

	private const HASH = '/^[0-9a-f]{64}$/D';

	/**
	 * Body for PUT /signals/{ref} (a batch row adds `order_ref`).
	 *
	 * @param \WC_Order   $order      Order.
	 * @param string      $outcome    delivered|refused.
	 * @param string|null $occurred_at Reuse this time (a retry of the same outcome).
	 * @return array<string, string>|null Null when there is no e-mail hash.
	 */
	public static function build( \WC_Order $order, string $outcome, ?string $occurred_at = null ): ?array {
		$h = self::hashes( $order );
		if ( null === $h['email_hash'] ) {
			return null;
		}

		$body = [
			'outcome'    => $outcome,
			'email_hash' => $h['email_hash'],
		];
		if ( null !== $h['phone_hash'] ) {
			$body['phone_hash'] = $h['phone_hash'];
		}
		if ( null !== $h['address_hash'] ) {
			$body['address_hash'] = $h['address_hash'];
		}
		$body['occurred_at'] = null !== $occurred_at && self::isTime( $occurred_at ) ? $occurred_at : self::occurredAt( $order, $outcome );

		return $body;
	}

	/**
	 * Identity hashes (stored copy first).
	 *
	 * @param \WC_Order $order Order.
	 * @return array{email_hash: string|null, phone_hash: string|null, address_hash: string|null}
	 */
	public static function hashes( \WC_Order $order ): array {
		$raw    = $order->get_meta( OrderResult::META_HASHES, true );
		$stored = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		$valid  = static fn( $v ): ?string => is_string( $v ) && 1 === preg_match( self::HASH, $v ) ? $v : null;

		if ( is_array( $stored ) && null !== $valid( $stored['email_hash'] ?? null ) ) {
			$h = $stored;
		} else {
			$h = IdentityBuilder::fromOrder( $order )->hashes();
		}

		return [
			'email_hash'   => $valid( $h['email_hash'] ?? null ),
			'phone_hash'   => $valid( $h['phone_hash'] ?? null ),
			'address_hash' => $valid( $h['address_hash'] ?? null ),
		];
	}

	/**
	 * Delivered: the completion date, else the last modification. Refused:
	 * the last modification (a refused order may carry an old completion
	 * date from an earlier "completed"). Never in the future; RFC 3339 UTC.
	 *
	 * @param \WC_Order $order   Order.
	 * @param string    $outcome delivered|refused.
	 */
	public static function occurredAt( \WC_Order $order, string $outcome = Outcome::DELIVERED ): string {
		$date = Outcome::DELIVERED === $outcome ? $order->get_date_completed() : null;
		if ( ! $date instanceof \DateTimeInterface ) {
			$date = $order->get_date_modified();
		}
		$ts = $date instanceof \DateTimeInterface ? $date->getTimestamp() : time();

		return gmdate( 'Y-m-d\TH:i:s\Z', min( $ts, time() ) );
	}

	/**
	 * RFC 3339 UTC as written by this class.
	 *
	 * @param string $value Value.
	 */
	private static function isTime( string $value ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value );
	}
}
