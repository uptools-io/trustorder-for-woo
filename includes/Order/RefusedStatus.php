<?php
/**
 * The „Nem vette át” (not picked up) order status.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Order;

/**
 * `wc-to-refused` (the stored value incl. the `wc-` prefix must fit the
 * 20-character `post_status` / `wc_orders.status` columns). No e-mail class:
 * changing an order to this status sends no customer e-mail.
 */
final class RefusedStatus {

	public const SLUG = 'to-refused';
	public const KEY  = 'wc-to-refused';

	/**
	 * Hook in (call before `init`).
	 */
	public static function register(): void {
		// includes/class-wc-post-types.php:652, init priority 9 (WC 11.2.1).
		add_filter( 'woocommerce_register_shop_order_post_statuses', [ self::class, 'postStatuses' ] );
		// includes/wc-order-functions.php:114 (wc_get_order_statuses()).
		add_filter( 'wc_order_statuses', [ self::class, 'orderStatuses' ] );
		// Bulk "mark_" actions are handled by WooCommerce itself for any status in
		// wc_get_order_statuses(): HPOS ListTable.php:1495, legacy
		// class-wc-admin-list-table-orders.php:495 (nonce + capability included).
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ self::class, 'bulkActions' ] );
		add_filter( 'bulk_actions-edit-shop_order', [ self::class, 'bulkActions' ] );
	}

	/**
	 * Add the post status (HPOS and legacy both rely on it).
	 *
	 * @param mixed $statuses Status => register_post_status() args.
	 * @return mixed
	 */
	public static function postStatuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			return $statuses;
		}
		$statuses[ self::KEY ] = [
			'label'                     => self::label(),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of orders */
			'label_count'               => _n_noop( 'Not picked up <span class="count">(%s)</span>', 'Not picked up <span class="count">(%s)</span>', 'trustorder-for-woo' ),
		];

		return $statuses;
	}

	/**
	 * Add the status right after "Completed".
	 *
	 * @param mixed $statuses `wc-` key => label.
	 * @return mixed
	 */
	public static function orderStatuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			return $statuses;
		}
		$out = [];
		foreach ( $statuses as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'wc-completed' === $key ) {
				$out[ self::KEY ] = self::label();
			}
		}
		if ( ! isset( $out[ self::KEY ] ) ) {
			$out[ self::KEY ] = self::label();
		}

		return $out;
	}

	/**
	 * Bulk action on both order lists.
	 *
	 * @param mixed $actions Action => label.
	 * @return mixed
	 */
	public static function bulkActions( $actions ) {
		if ( ! is_array( $actions ) ) {
			return $actions;
		}
		$actions[ 'mark_' . self::SLUG ] = __( 'Change status to not picked up', 'trustorder-for-woo' );

		return $actions;
	}

	/**
	 * Status label.
	 */
	public static function label(): string {
		return _x( 'Not picked up', 'Order status', 'trustorder-for-woo' );
	}
}
