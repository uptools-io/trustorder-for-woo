<?php
/**
 * The decision that applies when paying for an existing order.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Checkout;

use LightweightPlugins\TrustOrder\Order\OrderResult;

/**
 * On the pay-for-order page a buyer could otherwise switch a blocked order to
 * a hidden gateway. The order's stored `_trustorder_result` is applied; there
 * is no API call, and an order without a stored result hides nothing.
 */
final class OrderPayGate {

	/**
	 * Stored decision of the order being paid, null when none applies.
	 */
	public static function decision(): ?Decision {
		$id = Scope::orderPayId();
		if ( 0 === $id ) {
			return null;
		}
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}
		$result = OrderResult::read( $order );

		return null === $result ? null : Decision::fromArray( $result );
	}
}
