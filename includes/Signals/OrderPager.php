<?php
/**
 * Id-cursor pages of completed / not-picked-up orders.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Signals;

use LightweightPlugins\TrustOrder\Order\RefusedStatus;

/**
 * `wc_get_orders` has no "id greater than" argument on both stores: HPOS
 * gets a `field_query` on `id` (OrdersTableFieldQuery, WC 11.2.1), the
 * legacy post store a `posts_where` clause keyed on our own query var
 * (unknown query vars pass through to WP_Query, class-wc-data-store-wp.php:311).
 */
final class OrderPager {

	public const QUERY_VAR = 'trustorder_after_id';

	/**
	 * Whether the legacy filter is hooked.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Orders with id > $after, created at or after $since, ordered by id.
	 *
	 * @param int $after Cursor (last processed id).
	 * @param int $limit Page size.
	 * @param int $since Unix time; older orders are left out.
	 * @return list<\WC_Order>
	 */
	public static function page( int $after, int $limit, int $since ): array {
		$out = [];
		foreach ( (array) wc_get_orders(
			self::args( $after, $since ) + [
				'limit'  => $limit,
				'return' => 'objects',
			]
		) as $order ) {
			if ( $order instanceof \WC_Order && $order->get_id() > $after ) {
				$out[] = $order;
			}
		}

		return $out;
	}

	/**
	 * How many orders are left after the cursor (for progress displays).
	 *
	 * @param int $after Cursor (last processed id).
	 * @param int $since Unix time; older orders are left out.
	 */
	public static function remaining( int $after, int $since ): int {
		$res = wc_get_orders(
			self::args( $after, $since ) + [
				'limit'    => 1,
				'return'   => 'ids',
				'paginate' => true,
			]
		);

		return is_object( $res ) && isset( $res->total ) && is_int( $res->total ) ? max( 0, $res->total ) : 0;
	}

	/**
	 * Query args without limit / return type.
	 *
	 * @param int $after Cursor.
	 * @param int $since Unix time.
	 * @return array<string, mixed>
	 */
	private static function args( int $after, int $since ): array {
		$args = [
			'type'         => 'shop_order',
			'status'       => [ 'wc-completed', RefusedStatus::KEY ],
			'date_created' => '>=' . $since,
			'orderby'      => 'ID',
			'order'        => 'ASC',
		];
		if ( self::hpos() ) {
			$args['field_query'] = [
				[
					'field'   => 'id',
					'value'   => $after,
					'compare' => '>',
					'type'    => 'NUMERIC',
				],
			];
		} else {
			self::hookLegacy();
			$args[ self::QUERY_VAR ] = $after;
		}

		return $args;
	}

	/**
	 * Legacy store: add `ID > cursor` to our own query only.
	 *
	 * @param mixed $where WHERE clause.
	 * @param mixed $query WP_Query.
	 * @return mixed
	 */
	public static function postsWhere( $where, $query = null ) {
		if ( ! is_string( $where ) || ! is_object( $query ) || ! method_exists( $query, 'get' ) ) {
			return $where;
		}
		$after = $query->get( self::QUERY_VAR );
		if ( ! is_int( $after ) ) {
			return $where;
		}
		global $wpdb;

		return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table name property.
	}

	/**
	 * Whether HPOS is the authoritative store.
	 */
	private static function hpos(): bool {
		return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Hook the legacy filter once.
	 */
	private static function hookLegacy(): void {
		if ( ! self::$hooked ) {
			add_filter( 'posts_where', [ self::class, 'postsWhere' ], 10, 2 );
			self::$hooked = true;
		}
	}
}
