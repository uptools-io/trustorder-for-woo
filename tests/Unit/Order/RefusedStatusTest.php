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

	public function test_non_arrays_pass_through(): void {
		$this->assertNull( RefusedStatus::orderStatuses( null ) );
		$this->assertSame( 'x', RefusedStatus::postStatuses( 'x' ) );
		$this->assertSame( 1, RefusedStatus::bulkActions( 1 ) );
	}
}
