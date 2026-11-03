<?php
/**
 * Server-side enforcement: an order with a hidden gateway is refused.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Checkout;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use LightweightPlugins\TrustOrder\Log;

/**
 * Only the hidden-gateway case is refused; any TrustOrder error lets the
 * order through (the gateway is then not hidden either).
 */
final class Enforcer {

	public const ERROR_CODE = 'trustorder_payment_not_allowed';

	/**
	 * Hook in.
	 */
	public static function register(): void {
		// includes/class-wc-checkout.php:1070 (classic place order; not fired by the Store API).
		add_action( 'woocommerce_after_checkout_validation', [ self::class, 'validateClassic' ], 10, 2 );
		// includes/class-wc-checkout.php:1392, before the posted data is validated: place order uses the submit budget.
		add_action( 'woocommerce_checkout_process', [ Gatekeeper::class, 'enterSubmit' ] );
		// includes/class-wc-form-handler.php:567 (pay for an existing order, after its key is verified).
		add_action( 'woocommerce_before_pay_action', [ self::class, 'validatePayAction' ] );
		// src/StoreApi/Routes/V1/Checkout.php:954 (WC 8.2+): fires in process_order() right after the
		// customer is updated from the request and before the payment method is resolved (CheckoutTrait.php:187).
		// Also fired for pay-for-order, POST checkout/{id} (src/StoreApi/Routes/V1/CheckoutOrder.php:235).
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', [ self::class, 'validateStoreApi' ], 10, 2 );
	}

	/**
	 * Classic checkout. WooCommerce has already added its generic "Invalid
	 * payment method." for a gateway we hid (class-wc-checkout.php:1063); it is
	 * replaced by our message.
	 *
	 * @param mixed $data   Posted checkout data (WC-sanitised).
	 * @param mixed $errors WP_Error.
	 */
	public static function validateClassic( $data, $errors ): void {
		if ( ! is_array( $data ) || ! $errors instanceof \WP_Error ) {
			return;
		}
		$chosen = isset( $data['payment_method'] ) && is_string( $data['payment_method'] ) ? $data['payment_method'] : '';
		if ( '' === $chosen || ! in_array( $chosen, Gatekeeper::hiddenNow(), true ) ) {
			return;
		}
		$errors->remove( 'payment' );
		$errors->add( self::ERROR_CODE, self::message() );
	}

	/**
	 * Block checkout (Store API POST /checkout).
	 *
	 * @param mixed $customer WC_Customer.
	 * @param mixed $request  WP_REST_Request.
	 * @throws RouteException When the chosen gateway is hidden for this buyer.
	 */
	public static function validateStoreApi( $customer, $request ): void {
		if ( ! $request instanceof \WP_REST_Request ) {
			return;
		}
		Gatekeeper::enterSubmit();
		$raw    = $request['payment_method'] ?? '';
		$chosen = is_string( $raw ) ? (string) wc_clean( wp_unslash( $raw ) ) : '';
		if ( '' === $chosen || ! in_array( $chosen, Gatekeeper::hiddenNow(), true ) ) {
			return;
		}
		if ( ! class_exists( RouteException::class ) ) {
			Log::error( 'checkout', [ 'code' => 'NO_ROUTE_EXCEPTION' ] );

			return;
		}

		// The code is a constant; the message is escaped.
		throw new RouteException( self::ERROR_CODE, esc_html( self::message() ), 400 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Pay for an existing order (classic order-pay page). A notice stops the
	 * payment: WC_Form_Handler::pay_action() only pays when there is no error
	 * notice (class-wc-form-handler.php:605); the hidden gateway is also
	 * missing from the available list (:593).
	 *
	 * @param mixed $order WC_Order.
	 */
	public static function validatePayAction( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- pay_action() verified the `woocommerce-pay` nonce (class-wc-form-handler.php:554) and the order key before this action.
		// Same sanitising as pay_action() (wc_clean of a string = sanitize_text_field).
		$chosen = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' !== $chosen && in_array( $chosen, Gatekeeper::hiddenNow(), true ) ) {
			wc_add_notice( self::message(), 'error' );
		}
	}

	/**
	 * The buyer-facing message.
	 */
	private static function message(): string {
		return __( 'Choose another payment method for this order.', 'trustorder-for-woo' );
	}
}
