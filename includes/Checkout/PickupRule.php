<?php
/**
 * Pickup point / locker / local pickup: no delivery address to hash.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Checkout;

use LightweightPlugins\TrustOrder\Settings;

/**
 * Matches chosen shipping method ids (the part before ':') against the
 * settings' pickup list and WooCommerce's own local-pickup list.
 */
final class PickupRule {

	/**
	 * WooCommerce's default for the `woocommerce_local_pickup_methods` filter
	 * (includes/class-wc-customer.php:198, abstract-wc-order.php:2269, WC 11.2.1;
	 * the blocks ShippingController adds `pickup_location`, ShippingController.php:76).
	 */
	private const WC_DEFAULT = [ 'legacy_local_pickup', 'local_pickup' ];

	/**
	 * Whether to skip the address hash for these shipping method ids.
	 *
	 * @param string[] $method_ids Chosen shipping method ids.
	 *
	 * @phpstan-param list<string> $method_ids
	 */
	public static function skipAddress( array $method_ids ): bool {
		$wc = apply_filters( 'woocommerce_local_pickup_methods', self::WC_DEFAULT ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reads WooCommerce's own list.
		$wc = is_array( $wc ) ? $wc : self::WC_DEFAULT;

		$pickup = array_merge( Settings::get()['pickup_methods'], array_filter( $wc, 'is_string' ) );
		$skip   = [] !== array_intersect( $method_ids, $pickup );

		/**
		 * Whether to send no address hash for this checkout / order.
		 *
		 * @param bool         $skip       True for pickup-type shipping.
		 * @param string[]    $method_ids Chosen shipping method ids.
		 *
		 * @phpstan-param list<string> $method_ids
		 */
		return (bool) apply_filters( 'trustorder_skip_address_hash', $skip, $method_ids );
	}
}
