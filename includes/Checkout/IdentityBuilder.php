<?php
/**
 * IdentitySet from WooCommerce-sanitised checkout and order data.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Checkout;

use LightweightPlugins\TrustOrder\Identity\IdentitySet;

/**
 * Raw values go straight into IdentitySet (which keeps hashes only); they are
 * always the values WooCommerce itself would store, never unsanitised input.
 */
final class IdentityBuilder {

	/**
	 * From the session customer (Store API, classic page render, place order).
	 *
	 * @param \WC_Customer $customer       Customer.
	 * @param bool         $needs_shipping Cart needs shipping.
	 * @param string[]     $method_ids     Chosen shipping method ids.
	 *
	 * @phpstan-param list<string> $method_ids
	 */
	public static function fromCustomer( \WC_Customer $customer, bool $needs_shipping, array $method_ids ): IdentitySet {
		return self::build(
			[
				'email'              => (string) $customer->get_billing_email(),
				'phone'              => (string) $customer->get_billing_phone(),
				'billing_country'    => (string) $customer->get_billing_country(),
				'shipping_country'   => (string) $customer->get_shipping_country(),
				'shipping_postcode'  => (string) $customer->get_shipping_postcode(),
				'shipping_address_1' => (string) $customer->get_shipping_address_1(),
				'shipping_address_2' => (string) $customer->get_shipping_address_2(),
			],
			$needs_shipping,
			$method_ids
		);
	}

	/**
	 * From the classic `update_order_review` post_data (a serialised checkout
	 * form). Sanitised like WC_Checkout::get_posted_data() (wc_clean) and the
	 * order's e-mail setter (sanitize_email). Without "ship to a different
	 * address" the shipping address is the billing address.
	 *
	 * @param string   $post_data      Raw serialised form.
	 * @param bool     $needs_shipping Cart needs shipping.
	 * @param string[] $method_ids     Chosen shipping method ids.
	 *
	 * @phpstan-param list<string> $method_ids
	 */
	public static function fromPostData( string $post_data, bool $needs_shipping, array $method_ids ): IdentitySet {
		$form = [];
		parse_str( $post_data, $form );
		$f = static fn( string $k ): string => isset( $form[ $k ] ) && is_string( $form[ $k ] ) ? (string) wc_clean( $form[ $k ] ) : '';

		$ship = ! empty( $form['ship_to_different_address'] ) && ! wc_ship_to_billing_address_only() ? 'shipping_' : 'billing_';

		return self::build(
			[
				'email'              => sanitize_email( $f( 'billing_email' ) ),
				'phone'              => $f( 'billing_phone' ),
				'billing_country'    => $f( 'billing_country' ),
				'shipping_country'   => $f( $ship . 'country' ),
				'shipping_postcode'  => $f( $ship . 'postcode' ),
				'shipping_address_1' => $f( $ship . 'address_1' ),
				'shipping_address_2' => $f( $ship . 'address_2' ),
			],
			$needs_shipping,
			$method_ids
		);
	}

	/**
	 * From a created order. WC_Order::needs_shipping() exists only since
	 * WC 9.9 (abstract-wc-order.php:3009); before that, shipping lines decide.
	 * An order without a shipping address uses the billing address.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function fromOrder( \WC_Order $order ): IdentitySet {
		$method_ids = [];
		foreach ( $order->get_shipping_methods() as $item ) {
			$method_ids[] = (string) $item->get_method_id();
		}
		$needs = method_exists( $order, 'needs_shipping' ) ? (bool) $order->needs_shipping() : [] !== $method_ids;

		$has_shipping = '' !== (string) $order->get_shipping_address_1() || '' !== (string) $order->get_shipping_postcode();
		$p            = $has_shipping ? 'shipping' : 'billing';
		$get          = static fn( string $field ): string => (string) $order->{"get_{$p}_{$field}"}();

		return self::build(
			[
				'email'              => (string) $order->get_billing_email(),
				'phone'              => (string) $order->get_billing_phone(),
				'billing_country'    => (string) $order->get_billing_country(),
				'shipping_country'   => $get( 'country' ),
				'shipping_postcode'  => $get( 'postcode' ),
				'shipping_address_1' => $get( 'address_1' ),
				'shipping_address_2' => $get( 'address_2' ),
			],
			$needs,
			$method_ids
		);
	}

	/**
	 * Common tail.
	 *
	 * @param array<string, string> $fields         Identity fields.
	 * @param bool                  $needs_shipping Needs shipping.
	 * @param string[]              $method_ids     Shipping method ids.
	 *
	 * @phpstan-param list<string> $method_ids
	 */
	private static function build( array $fields, bool $needs_shipping, array $method_ids ): IdentitySet {
		$fields['needs_shipping'] = $needs_shipping;
		$fields['skip_address']   = $needs_shipping && PickupRule::skipAddress( $method_ids );

		return IdentitySet::fromOrderData( $fields );
	}
}
