<?php
/**
 * Payment gateways and shipping methods offered in the settings.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Admin;

use LightweightPlugins\TrustOrder\Settings;

/**
 * Lists the shop's gateways and shipping methods for the settings screen.
 * Ids that are stored but no longer offered stay listed (marked), so saving
 * the form never drops them silently.
 */
final class Choices {

	/**
	 * Payment gateways: every enabled one, plus selected disabled ones.
	 *
	 * @param string[] $selected Ids used in the settings.
	 * @return list<array{id: string, title: string, enabled: bool}>
	 */
	public static function gateways( array $selected ): array {
		$out = [];
		foreach ( self::allGateways() as $id => $gateway ) {
			$enabled = 'yes' === ( $gateway->enabled ?? 'no' );
			if ( ! $enabled && ! in_array( $id, $selected, true ) ) {
				continue;
			}
			$title = method_exists( $gateway, 'get_title' ) ? (string) $gateway->get_title() : $id;
			$out[] = [
				'id'      => $id,
				'title'   => self::label( $title, $id ),
				'enabled' => $enabled,
			];
		}

		return $out;
	}

	/**
	 * Shipping methods installed in the shop (stored ids of absent methods are
	 * not listed; the settings value keeps them).
	 *
	 * @return list<array{id: string, title: string}>
	 */
	public static function shippingMethods(): array {
		$out = [];
		/**
		 * Untyped on purpose: WC() may be missing or partial.
		 *
		 * @var mixed $wc
		 */
		$wc = function_exists( 'WC' ) ? WC() : null;
		/**
		 * Untyped on purpose: WC() may be missing or partial.
		 *
		 * @var mixed $all
		 */
		$all = is_object( $wc ) && method_exists( $wc, 'shipping' ) && is_object( $wc->shipping() ) ? $wc->shipping()->get_shipping_methods() : [];
		foreach ( (array) $all as $method ) {
			$id = is_object( $method ) ? (string) ( $method->id ?? '' ) : '';
			if ( ! Settings::isId( $id ) || isset( $out[ $id ] ) ) {
				continue;
			}
			$out[ $id ] = [
				'id'    => $id,
				'title' => self::shippingTitle( $id, (string) ( $method->method_title ?? '' ) ),
			];
		}

		return array_values( $out );
	}

	/**
	 * Ids of every registered payment gateway (enabled or not).
	 *
	 * @return list<string>
	 */
	public static function gatewayIds(): array {
		return array_keys( self::allGateways() );
	}

	/**
	 * Method title; the two core local pickups get distinct names.
	 *
	 * @param string $id    Method id.
	 * @param string $title Registered title.
	 */
	private static function shippingTitle( string $id, string $title ): string {
		if ( 'local_pickup' === $id ) {
			return __( 'Local pickup (classic checkout)', 'trustorder-for-woo' );
		}
		if ( 'pickup_location' === $id ) {
			return __( 'Local pickup (block checkout)', 'trustorder-for-woo' );
		}

		return self::label( $title, $id );
	}

	/**
	 * All registered gateways, id => gateway.
	 *
	 * @return array<string, object>
	 */
	private static function allGateways(): array {
		/**
		 * Untyped on purpose: WC() may be missing or partial.
		 *
		 * @var mixed $wc
		 */
		$wc = function_exists( 'WC' ) ? WC() : null;
		if ( ! is_object( $wc ) || ! method_exists( $wc, 'payment_gateways' ) ) {
			return [];
		}
		$registry = $wc->payment_gateways();
		/**
		 * Untyped on purpose: WC() may be missing or partial.
		 *
		 * @var mixed $list
		 */
		$list = is_object( $registry ) && method_exists( $registry, 'payment_gateways' ) ? $registry->payment_gateways() : [];
		$out  = [];
		foreach ( (array) $list as $gateway ) {
			$id = is_object( $gateway ) ? (string) ( $gateway->id ?? '' ) : '';
			if ( Settings::isId( $id ) ) {
				$out[ $id ] = $gateway;
			}
		}

		return $out;
	}

	/**
	 * Plain-text title, or the id when empty.
	 *
	 * @param string $title Title (may contain HTML).
	 * @param string $id    Fallback.
	 */
	private static function label( string $title, string $id ): string {
		$plain = trim( wp_strip_all_tags( $title ) );

		return '' !== $plain ? $plain : $id;
	}
}
