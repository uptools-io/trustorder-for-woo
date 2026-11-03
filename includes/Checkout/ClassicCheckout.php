<?php
/**
 * Classic (shortcode) checkout integration.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Checkout;

/**
 * Makes the e-mail and phone fields refresh the order review, and keeps the
 * review's serialised form for the gateway filter: `update_order_review` does
 * not copy e-mail/phone into WC()->customer (includes/class-wc-ajax.php:408-453,
 * WC 11.2.1), so they are only in `post_data`.
 */
final class ClassicCheckout {

	public const REFRESH_CLASS = 'update_totals_on_change';

	/**
	 * Raw post_data of the current `update_order_review`, if any.
	 *
	 * @var string|null
	 */
	private static ?string $review = null;

	/**
	 * Hook in.
	 */
	public static function register(): void {
		// includes/class-wc-checkout.php:321.
		add_filter( 'woocommerce_checkout_fields', [ self::class, 'addRefreshClass' ] );
		// includes/class-wc-ajax.php:408 (fires before the payment methods render).
		add_action( 'woocommerce_checkout_update_order_review', [ self::class, 'captureReview' ] );
	}

	/**
	 * Forget per-request state (tests).
	 */
	public static function reset(): void {
		self::$review = null;
	}

	/**
	 * `woocommerce_checkout_fields`: the form-row class is matched by the
	 * descendant selector `.update_totals_on_change input.input-text`
	 * (assets/js/frontend/checkout.js:206).
	 *
	 * @param mixed $fields Checkout fields.
	 * @return mixed
	 */
	public static function addRefreshClass( $fields ) {
		if ( ! is_array( $fields ) || ! isset( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
			return $fields;
		}
		foreach ( [ 'billing_email', 'billing_phone' ] as $key ) {
			if ( ! isset( $fields['billing'][ $key ] ) || ! is_array( $fields['billing'][ $key ] ) ) {
				continue;
			}
			$class = isset( $fields['billing'][ $key ]['class'] ) && is_array( $fields['billing'][ $key ]['class'] ) ? $fields['billing'][ $key ]['class'] : [];
			if ( ! in_array( self::REFRESH_CLASS, $class, true ) ) {
				$class[] = self::REFRESH_CLASS;
			}
			$fields['billing'][ $key ]['class'] = $class;
		}

		return $fields;
	}

	/**
	 * `woocommerce_checkout_update_order_review`: keep the (unslashed, not yet
	 * sanitised) post_data; IdentityBuilder::fromPostData() sanitises it.
	 *
	 * @param mixed $post_data Serialised checkout form.
	 */
	public static function captureReview( $post_data ): void {
		self::$review = is_string( $post_data ) ? $post_data : '';
	}

	/**
	 * The captured post_data, null outside `update_order_review`.
	 */
	public static function reviewData(): ?string {
		return self::$review;
	}
}
