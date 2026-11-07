<?php
/**
 * TrustOrder column in the order list (HPOS and legacy).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Order;

use LightweightPlugins\TrustOrder\Admin\KeyNotice;
use LightweightPlugins\TrustOrder\Settings;

/**
 * HPOS: `manage_woocommerce_page_wc-orders_columns` / `..._custom_column`
 * (src/Internal/Admin/Orders/ListTable.php:112, :205). Legacy:
 * `manage_edit-shop_order_columns` / `manage_shop_order_posts_custom_column`
 * (includes/admin/list-tables/abstract-class-wc-admin-list-table.php:49-51). WC 11.2.1.
 */
final class OrderColumn {

	public const COLUMN = 'trustorder';

	/**
	 * Hook in.
	 */
	public static function register(): void {
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ self::class, 'addColumn' ] );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ self::class, 'renderHpos' ], 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', [ self::class, 'addColumn' ] );
		add_action( 'manage_shop_order_posts_custom_column', [ self::class, 'renderLegacy' ], 10, 2 );
	}

	/**
	 * Append the column at the end (after Actions); no column without an API
	 * key (KeyNotice warns instead) or in silent mode.
	 *
	 * @param mixed $columns Columns.
	 * @return mixed
	 */
	public static function addColumn( $columns ) {
		if ( ! is_array( $columns ) || ! KeyNotice::hasKey() || Settings::silent() ) {
			return $columns;
		}
		$columns[ self::COLUMN ] = __( 'COD check', 'trustorder-for-woo' );

		return $columns;
	}

	/**
	 * HPOS cell.
	 *
	 * @param mixed $column Column id.
	 * @param mixed $order  WC_Order.
	 */
	public static function renderHpos( $column, $order ): void {
		if ( self::COLUMN === $column && $order instanceof \WC_Order ) {
			self::cell( $order );
		}
	}

	/**
	 * Legacy cell.
	 *
	 * @param mixed $column  Column id.
	 * @param mixed $post_id Post id.
	 */
	public static function renderLegacy( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$order = wc_get_order( (int) $post_id );
		if ( $order instanceof \WC_Order ) {
			self::cell( $order );
		}
	}

	/**
	 * Badge or a dash.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function cell( \WC_Order $order ): void {
		$result = OrderResult::read( $order );
		if ( null === $result ) {
			echo '<span aria-hidden="true">&ndash;</span>';
			return;
		}
		echo Labels::badge( $result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Labels::badge() escapes every part.
	}
}
