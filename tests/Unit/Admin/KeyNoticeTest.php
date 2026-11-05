<?php
/**
 * No API key: no order list column, a warning on the order list instead.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Admin;

use LightweightPlugins\TrustOrder\Admin\KeyNotice;
use LightweightPlugins\TrustOrder\Order\OrderColumn;
use LightweightPlugins\TrustOrder\Settings;
use PHPUnit\Framework\TestCase;

final class KeyNoticeTest extends TestCase {

	private const KEY = 'to_live_0123456789abcdefABCDEF0123456789';

	protected function setUp(): void {
		trustorder_test_reset();
	}

	private function onScreen( string $id ): void {
		$screen     = new \WP_Screen();
		$screen->id = $id;
		$GLOBALS['trustorder_test']['screen'] = $screen;
	}

	private function render(): string {
		ob_start();
		KeyNotice::render();
		return (string) ob_get_clean();
	}

	public function test_without_a_key_the_order_list_has_no_column(): void {
		$cols = [ 'cb' => '', 'order_status' => 'Status', 'wc_actions' => 'Actions' ];

		$this->assertFalse( KeyNotice::hasKey() );
		$this->assertSame( $cols, OrderColumn::addColumn( $cols ) );
	}

	public function test_with_a_key_the_column_is_back(): void {
		Settings::update( [ 'api_key' => self::KEY ] );

		$this->assertTrue( KeyNotice::hasKey() );
		$this->assertArrayHasKey( OrderColumn::COLUMN, OrderColumn::addColumn( [ 'cb' => '' ] ) );
	}

	public function test_without_a_key_both_order_lists_warn_with_a_settings_link(): void {
		foreach ( [ 'woocommerce_page_wc-orders', 'edit-shop_order' ] as $id ) {
			$this->onScreen( $id );
			$html = $this->render();
			$this->assertStringContainsString( 'notice-warning', $html, $id );
			$this->assertStringContainsString( 'no API key is set', $html, $id );
			$this->assertStringContainsString( 'admin.php?page=trustorder', $html, $id );
		}
	}

	public function test_no_warning_with_a_key_elsewhere_or_without_capability(): void {
		$this->onScreen( 'dashboard' );
		$this->assertSame( '', $this->render() );

		$this->onScreen( 'woocommerce_page_wc-orders' );
		$GLOBALS['trustorder_test']['caps'] = [];
		$this->assertSame( '', $this->render() );

		$GLOBALS['trustorder_test']['caps'] = [ 'manage_woocommerce' => true ];
		Settings::update( [ 'api_key' => self::KEY ] );
		$this->assertSame( '', $this->render() );
	}
}
