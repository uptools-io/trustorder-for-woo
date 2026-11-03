<?php
/**
 * Stores the check result and identity hashes on the order.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Order;

use LightweightPlugins\TrustOrder\Checkout\Checker;
use LightweightPlugins\TrustOrder\Checkout\Decision;
use LightweightPlugins\TrustOrder\Checkout\IdentityBuilder;
use LightweightPlugins\TrustOrder\Identity\IdentitySet;
use LightweightPlugins\TrustOrder\Log;
use LightweightPlugins\TrustOrder\Settings;

/**
 * `_trustorder_hashes` (for the outcome signal later) and `_trustorder_result`.
 * A `flag` adds a private order note; the order status is never changed.
 * A local fallback (a spent budget or cap) is stored as `{verdict, fallback}`
 * only: never another identity's check id, score or reasons.
 */
final class OrderResult {

	public const META_HASHES = '_trustorder_hashes';
	public const META_RESULT = '_trustorder_result';

	/**
	 * Hook in.
	 */
	public static function register(): void {
		// Classic: includes/class-wc-checkout.php:521 (not fired by the Store API).
		add_action( 'woocommerce_checkout_order_created', [ self::class, 'onOrder' ] );
		// Blocks: src/StoreApi/Routes/V1/Checkout.php:695, before payment (WC 11.2.1).
		add_action( 'woocommerce_store_api_checkout_order_processed', [ self::class, 'onOrder' ] );
	}

	/**
	 * Order created at checkout. Never throws.
	 *
	 * @param mixed $order WC_Order.
	 */
	public static function onOrder( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		try {
			$ids      = IdentityBuilder::fromOrder( $order );
			$settings = Settings::get();
			$decision = null;
			if ( $settings['enabled'] && (float) $order->get_total() > 0 && $ids->hasEmail() ) {
				// Same identity key as the place-order check: a memo hit, no extra unit.
				$decision = Checker::current()->decideForSubmit( $ids, $settings['strictness'] );
			}
			self::store( $order, $decision, $ids );
		} catch ( \Throwable $e ) {
			Log::error( 'order', [ 'code' => Checker::EXCEPTION ] );
		}
	}

	/**
	 * Save hashes, the result (when checked) and the flag note. Idempotent on
	 * payment retries (resumed classic order, Store API re-POST): the same
	 * check (check_id + verdict) changes nothing, a real result is never
	 * replaced by a fail-open one, and a different verdict updates the result
	 * (keeping the first `checked_at`) with one note.
	 *
	 * @param \WC_Order     $order    Order.
	 * @param Decision|null $decision Decision, null when the order was not checked.
	 * @param IdentitySet   $ids      Identity hashes.
	 */
	public static function store( \WC_Order $order, ?Decision $decision, IdentitySet $ids ): void {
		$order->update_meta_data( self::META_HASHES, (string) wp_json_encode( $ids->hashes() ) );

		if ( null !== $decision ) {
			self::storeResult( $order, $decision );
		}

		$order->save();
	}

	/**
	 * Write `_trustorder_result` and the note unless this check is already stored.
	 *
	 * @param \WC_Order $order    Order.
	 * @param Decision  $decision Decision.
	 */
	private static function storeResult( \WC_Order $order, Decision $decision ): void {
		$old = self::read( $order );
		$now = gmdate( 'c' );
		$new = $decision->toArray() + [
			'env'        => Checker::current()->env(),
			'checked_at' => $now,
		];

		if ( null !== $old ) {
			$old_real = empty( $old['failed_open'] ) && empty( $old['fallback'] );
			if ( ( $decision->failedOpen() || $decision->isFallback() ) && $old_real ) {
				return;
			}
			$same = ( $old['check_id'] ?? null ) === ( $new['check_id'] ?? null ) && $old['verdict'] === $new['verdict']
				&& ( $old['error'] ?? null ) === ( $new['error'] ?? null ) && ( $old['fallback'] ?? null ) === ( $new['fallback'] ?? null );
			if ( $same ) {
				return;
			}
			$new['checked_at'] = isset( $old['checked_at'] ) && is_string( $old['checked_at'] ) ? $old['checked_at'] : $now;
			$new['updated_at'] = $now;
		}

		$order->update_meta_data( self::META_RESULT, (string) wp_json_encode( $new ) );

		if ( $decision->failedOpen() || $decision->isFallback() ) {
			return;
		}
		if ( null !== $old && empty( $old['failed_open'] ) && empty( $old['fallback'] ) && $old['verdict'] !== $new['verdict'] ) {
			$order->add_order_note( self::note( $decision, true ), 0, false );
		} elseif ( Decision::FLAG === $decision->verdict() && ( null === $old || ! empty( $old['failed_open'] ) || ! empty( $old['fallback'] ) ) ) {
			$order->add_order_note( self::note( $decision, false ), 0, false );
		}
	}

	/**
	 * Stored result, null when missing or unreadable.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, mixed>|null
	 */
	public static function read( \WC_Order $order ): ?array {
		$raw = $order->get_meta( self::META_RESULT, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );

		return is_array( $data ) && isset( $data['verdict'] ) ? $data : null;
	}

	/**
	 * Private note text: level, score, reason codes (no identity, no hashes).
	 *
	 * @param Decision $decision Decision.
	 * @param bool     $changed  The verdict changed on a payment retry.
	 */
	private static function note( Decision $decision, bool $changed ): string {
		return sprintf(
			$changed
				/* translators: 1: risk level, 2: score 0-100, 3: comma-separated reason codes */
				? __( 'TrustOrder result changed on a payment retry. Level: %1$s, score: %2$d, reasons: %3$s.', 'trustorder-for-woo' )
				/* translators: 1: risk level, 2: score 0-100, 3: comma-separated reason codes */
				: __( 'TrustOrder flagged this order. Level: %1$s, score: %2$d, reasons: %3$s.', 'trustorder-for-woo' ),
			Labels::level( $decision->level() ),
			$decision->score(),
			[] === $decision->reasons() ? '-' : implode( ', ', $decision->reasons() )
		);
	}
}
