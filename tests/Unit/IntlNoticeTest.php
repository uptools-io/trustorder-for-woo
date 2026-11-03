<?php
/**
 * Admin warning when intl/mbstring are missing, and init wiring.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit;

use LightweightPlugins\TrustOrder\Admin\IntlNotice;
use LightweightPlugins\TrustOrder\Identity\MxCache;
use LightweightPlugins\TrustOrder\Identity\Unicode;
use LightweightPlugins\TrustOrder\Plugin;
use PHPUnit\Framework\TestCase;

final class IntlNoticeTest extends TestCase {

	protected function setUp(): void {
		trustorder_test_reset();
		$_GET = [];
	}

	/**
	 * Pretend the given admin screen is current.
	 *
	 * @param string $id Screen id.
	 */
	private function onScreen( string $id ): void {
		$screen                               = new \WP_Screen();
		$screen->id                           = $id;
		$GLOBALS['trustorder_test']['screen'] = $screen;
	}

	private function rendered(): string {
		ob_start();
		IntlNotice::render();
		return (string) ob_get_clean();
	}

	public function test_nothing_registered_when_unicode_support_is_full(): void {
		IntlNotice::register( true );

		$this->assertFalse( has_action( 'admin_notices', [ IntlNotice::class, 'render' ] ) );
		$this->assertFalse( has_action( 'admin_post_' . IntlNotice::ACTION, [ IntlNotice::class, 'dismiss' ] ) );
	}

	public function test_notice_and_dismiss_handler_registered_when_support_is_missing(): void {
		IntlNotice::register( false );

		$this->assertSame( 10, has_action( 'admin_notices', [ IntlNotice::class, 'render' ] ) );
		$this->assertSame( 10, has_action( 'admin_post_' . IntlNotice::ACTION, [ IntlNotice::class, 'dismiss' ] ) );
	}

	public function test_shown_on_woocommerce_screens_but_not_on_the_trustorder_app(): void {
		$this->onScreen( 'woocommerce_page_wc-settings' );
		$html = $this->rendered();
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'intl', $html );
		$this->assertStringContainsString( 'mbstring', $html );
		$this->assertStringContainsString( 'action=' . IntlNotice::ACTION, $html );
		$this->assertStringContainsString( '_wpnonce=nonce-' . IntlNotice::ACTION, $html );

		// The TrustOrder app shows the same state on its overview.
		$this->onScreen( 'woocommerce_page_trustorder' );
		$this->assertSame( '', $this->rendered() );

		$this->onScreen( 'dashboard' );
		$this->assertSame( '', $this->rendered() );

		$GLOBALS['trustorder_test']['screen'] = null;
		$this->assertSame( '', $this->rendered() );
	}

	public function test_hidden_for_users_without_manage_woocommerce(): void {
		$this->onScreen( 'woocommerce_page_wc-settings' );
		$GLOBALS['trustorder_test']['caps'] = [];

		$this->assertSame( '', $this->rendered() );
	}

	public function test_dismissal_is_per_user_and_needs_a_valid_nonce(): void {
		$this->onScreen( 'woocommerce_page_wc-settings' );

		$_GET['_wpnonce'] = 'forged';
		$this->assertFalse( IntlNotice::recordDismissal() );
		$this->assertNotSame( '', $this->rendered() );

		$_GET['_wpnonce'] = 'nonce-' . IntlNotice::ACTION;
		$this->assertTrue( IntlNotice::recordDismissal() );
		$this->assertSame( '', $this->rendered() );

		$GLOBALS['trustorder_test']['user'] = 2;
		$this->assertNotSame( '', $this->rendered(), 'other users still see it' );
	}

	public function test_dismissal_requires_the_capability(): void {
		$_GET['_wpnonce']                   = 'nonce-' . IntlNotice::ACTION;
		$GLOBALS['trustorder_test']['caps'] = [];

		$this->assertFalse( IntlNotice::recordDismissal() );
		$this->assertSame( [], $GLOBALS['trustorder_test']['usermeta'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_init_registers_mx_handler_with_woocommerce(): void {
		require_once dirname( __DIR__ ) . '/Support/FakeWooCommerce.php';
		Plugin::instance()->init();

		$this->assertSame( 10, has_action( MxCache::HOOK, [ MxCache::class, 'lookup' ] ) );
		$this->assertSame( Unicode::full() ? false : 10, has_action( 'admin_notices', [ IntlNotice::class, 'render' ] ) );
	}
}
