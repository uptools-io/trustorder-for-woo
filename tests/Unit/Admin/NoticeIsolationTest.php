<?php
/**
 * Notice isolation on the TrustOrder screen.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Admin;

use LightweightPlugins\TrustOrder\Admin\AdminPage;
use LightweightPlugins\TrustOrder\Admin\NoticeIsolation;
use PHPUnit\Framework\TestCase;
use WP_Screen;

final class NoticeIsolationTest extends TestCase {

	protected function setUp(): void {
		trustorder_test_reset();
	}

	private function onScreen( string $id ): void {
		$screen     = new WP_Screen();
		$screen->id = $id;
		$GLOBALS['trustorder_test']['screen'] = $screen;
	}

	private function addForeignNotices(): void {
		foreach ( NoticeIsolation::HOOKS as $hook ) {
			add_action( $hook, 'foreign_plugin_notice' );
		}
	}

	public function test_registers_before_the_notice_hooks(): void {
		NoticeIsolation::register();

		$this->assertSame( PHP_INT_MAX, has_action( 'in_admin_header', [ NoticeIsolation::class, 'isolate' ] ) );
		$this->assertSame( 10, has_action( 'admin_head', [ NoticeIsolation::class, 'printStyles' ] ) );
	}

	public function test_removes_every_notice_on_the_own_screen(): void {
		$this->onScreen( AdminPage::hookSuffix() );
		$this->addForeignNotices();

		NoticeIsolation::isolate();

		foreach ( NoticeIsolation::HOOKS as $hook ) {
			$this->assertFalse( has_action( $hook, 'foreign_plugin_notice' ), $hook );
		}
	}

	public function test_leaves_other_screens_alone(): void {
		$this->onScreen( 'woocommerce_page_wc-orders' );
		$this->addForeignNotices();

		NoticeIsolation::isolate();
		ob_start();
		NoticeIsolation::printStyles();
		$css = (string) ob_get_clean();

		foreach ( NoticeIsolation::HOOKS as $hook ) {
			$this->assertSame( 10, has_action( $hook, 'foreign_plugin_notice' ), $hook );
		}
		$this->assertSame( '', $css );
	}

	public function test_no_screen_is_not_the_own_screen(): void {
		$this->addForeignNotices();

		NoticeIsolation::isolate();

		$this->assertSame( 10, has_action( 'admin_notices', 'foreign_plugin_notice' ) );
	}

	public function test_stylesheet_hides_stray_notice_markup_on_the_own_screen(): void {
		$this->onScreen( AdminPage::hookSuffix() );

		ob_start();
		NoticeIsolation::printStyles();
		$css = (string) ob_get_clean();

		$this->assertStringContainsString( 'body.trustorder-screen #wpbody-content > .notice', $css );
		$this->assertStringContainsString( 'body.trustorder-screen #wpbody-content > .wrap > .update-nag', $css );
		$this->assertStringContainsString( '{display:none!important}', $css );
	}
}
