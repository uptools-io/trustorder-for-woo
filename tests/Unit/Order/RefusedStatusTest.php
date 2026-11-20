<?php
/**
 * The wc-to-refused order status.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Order;

use LightweightPlugins\TrustOrder\Order\RefusedStatus;
use PHPUnit\Framework\TestCase;

final class RefusedStatusTest extends TestCase {

	protected function setUp(): void {
		trustorder_test_reset();
		RefusedStatus::register();
	}

	public function test_slug_fits_the_20_character_status_column(): void {
		$this->assertSame( 'wc-to-refused', RefusedStatus::KEY );
		$this->assertLessThanOrEqual( 20, strlen( RefusedStatus::KEY ) );
	}

	public function test_registered_as_post_status(): void {
		$s = apply_filters( 'woocommerce_register_shop_order_post_statuses', [ 'wc-completed' => [] ] );

		$this->assertArrayHasKey( 'wc-to-refused', $s );
		$this->assertSame( 'Not picked up', $s['wc-to-refused']['label'] );
		$this->assertFalse( $s['wc-to-refused']['public'] );
		$this->assertTrue( $s['wc-to-refused']['show_in_admin_status_list'] );
		$this->assertTrue( $s['wc-to-refused']['show_in_admin_all_list'] );
		$this->assertArrayHasKey( 'label_count', $s['wc-to-refused'] );
	}

	public function test_listed_right_after_completed(): void {
		$s = apply_filters( 'wc_order_statuses', [ 'wc-pending' => 'P', 'wc-completed' => 'C', 'wc-cancelled' => 'X' ] );

		$this->assertSame( [ 'wc-pending', 'wc-completed', 'wc-to-refused', 'wc-cancelled' ], array_keys( $s ) );
		$this->assertSame( [ 'wc-x' => 'Y', 'wc-to-refused' => 'Not picked up' ], apply_filters( 'wc_order_statuses', [ 'wc-x' => 'Y' ] ) );
	}

	public function test_bulk_action_on_both_order_lists(): void {
		foreach ( [ 'bulk_actions-woocommerce_page_wc-orders', 'bulk_actions-edit-shop_order' ] as $hook ) {
			$a = apply_filters( $hook, [ 'mark_completed' => 'C' ] );
			$this->assertArrayHasKey( 'mark_to-refused', $a, $hook );
		}
	}

	public function test_stock_is_restored_when_an_order_becomes_not_picked_up(): void {
		$this->assertSame( 10, has_action( 'woocommerce_order_status_to-refused', 'wc_maybe_increase_stock_levels' ) );
	}

	public function test_left_out_of_analytics_revenue(): void {
		$s = apply_filters( 'woocommerce_analytics_excluded_order_statuses', [ 'pending', 'to-refused' ] );

		$this->assertSame( [ 'pending', 'to-refused' ], $s );
		$this->assertSame( [ 'failed', 'to-refused' ], apply_filters( 'woocommerce_analytics_excluded_order_statuses', [ 'failed' ] ) );
		$this->assertSame( 'x', RefusedStatus::analyticsExcluded( 'x' ) );
	}

	public function test_non_arrays_pass_through(): void {
		$this->assertNull( RefusedStatus::orderStatuses( null ) );
		$this->assertSame( 'x', RefusedStatus::postStatuses( 'x' ) );
		$this->assertSame( 1, RefusedStatus::bulkActions( 1 ) );
	}
	public function test_only_the_own_status_counts_as_refused_by_default(): void {
		$this->assertSame( [ 'to-refused' ], RefusedStatus::slugs() );
	}

	public function test_the_filter_adds_statuses_with_or_without_prefix(): void {
		add_filter( RefusedStatus::FILTER, static fn( array $s ): array => array_merge( $s, [ 'wc-returned', 'undelivered', 'undelivered', '' ] ) );

		$this->assertSame( [ 'to-refused', 'returned', 'undelivered' ], RefusedStatus::slugs() );
	}

	public function test_the_filter_cannot_drop_the_own_status_or_take_reserved_ones(): void {
		add_filter( RefusedStatus::FILTER, static fn(): array => [ 'completed', 'wc-processing', 'on-hold', 'pending', 'refunded', 'checkout-draft', 'returned', 42, [ 'x' ] ] );

		$this->assertSame( [ 'to-refused', 'returned' ], RefusedStatus::slugs() );
	}

	public function test_a_broken_filter_value_keeps_the_default(): void {
		add_filter( RefusedStatus::FILTER, static fn(): string => 'returned' );

		$this->assertSame( [ 'to-refused' ], RefusedStatus::slugs() );
	}
}
