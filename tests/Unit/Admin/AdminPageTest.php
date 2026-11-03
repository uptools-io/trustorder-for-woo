<?php
/**
 * Admin page, privacy text and wiring.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Admin;

use LightweightPlugins\TrustOrder\Admin\AdminPage;
use LightweightPlugins\TrustOrder\Admin\Privacy;
use PHPUnit\Framework\TestCase;
use WP_Screen;

final class AdminPageTest extends TestCase {

	protected function setUp(): void {
		trustorder_test_reset();
		self::forgetHook();
	}

	protected function tearDown(): void {
		self::forgetHook();
	}

	private static function forgetHook(): void {
		( new \ReflectionProperty( AdminPage::class, 'hook_suffix' ) )->setValue( null, null );
	}

	public function test_hook_suffix_comes_from_add_submenu_page(): void {
		$this->assertSame( AdminPage::HOOK_SUFFIX, AdminPage::hookSuffix(), 'constant before admin_menu' );

		$GLOBALS['trustorder_test']['submenu_hook'] = 'kereskedelem_page_trustorder';
		AdminPage::addMenu();
		$this->assertSame( 'kereskedelem_page_trustorder', AdminPage::hookSuffix() );

		$screen     = new WP_Screen();
		$screen->id = 'kereskedelem_page_trustorder';
		$GLOBALS['trustorder_test']['screen'] = $screen;
		$this->assertSame( 'a trustorder-screen', AdminPage::bodyClass( 'a' ) );
		$this->assertFalse( \LightweightPlugins\TrustOrder\Admin\Screen::relevant(), 'own screen shows no notices' );

		$screen->id = AdminPage::HOOK_SUFFIX;
		$this->assertSame( 'a', AdminPage::bodyClass( 'a' ) );
	}

	public function test_a_failed_submenu_keeps_the_constant(): void {
		$GLOBALS['trustorder_test']['submenu_hook'] = false;
		AdminPage::addMenu();

		$this->assertSame( AdminPage::HOOK_SUFFIX, AdminPage::hookSuffix() );
	}

	public function test_menu_is_under_woocommerce_for_shop_managers(): void {
		AdminPage::addMenu();

		$menu = $GLOBALS['trustorder_test']['submenus'][0];
		$this->assertSame( 'woocommerce', $menu['parent_slug'] );
		$this->assertSame( 'manage_woocommerce', $menu['capability'] );
		$this->assertSame( 'trustorder', $menu['menu_slug'] );
	}

	public function test_render_prints_the_mount_point_outside_wrap(): void {
		if ( ! is_readable( TRUSTORDER_DIR . 'build/index.js' ) ) {
			$this->markTestSkipped( 'build/ missing' );
		}
		ob_start();
		AdminPage::render();
		$html = (string) ob_get_clean();

		$this->assertSame( '<div id="trustorder-root" class="trustorder-root"></div>', $html );
	}

	public function test_render_prints_nothing_without_capability(): void {
		$GLOBALS['trustorder_test']['caps'] = [];
		ob_start();
		AdminPage::render();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_body_class_only_on_our_screen(): void {
		$screen     = new WP_Screen();
		$screen->id = AdminPage::HOOK_SUFFIX;
		$GLOBALS['trustorder_test']['screen'] = $screen;
		$this->assertSame( 'a trustorder-screen', AdminPage::bodyClass( 'a' ) );

		$screen->id = 'woocommerce_page_wc-orders';
		$this->assertSame( 'a', AdminPage::bodyClass( 'a' ) );
	}

	public function test_boot_data_has_the_namespace_and_shop_country(): void {
		update_option( 'woocommerce_default_country', 'HU:BU' );
		$data = AdminPage::bootData();

		$this->assertSame( 'trustorder/v1', $data['namespace'] );
		$this->assertSame( 'HU', $data['shopCountry'] );
		$this->assertSame( [ 'namespace', 'version', 'shopCountry', 'countries' ], array_keys( $data ) );
	}

	public function test_privacy_text_names_the_hashed_data_and_the_basis(): void {
		Privacy::register();
		do_action( 'admin_init' );

		[ $title, $text ] = $GLOBALS['trustorder_test']['privacy'][0];
		$this->assertSame( 'TrustOrder for WooCommerce', $title );
		foreach ( [ 'e-mail address', 'phone number', 'shipping address', 'e-mail domain', 'can receive mail', 'picked up', 'date', 'order number', 'past 3 years', 'legitimate interest', '3 years', 'contact point' ] as $needle ) {
			$this->assertStringContainsString( $needle, $text );
		}
		$this->assertStringStartsWith( '<p>', $text );
		// Impersonal: written for the shop's own policy, not addressing the buyer.
		$this->assertDoesNotMatchRegularExpression( '/\\byou(r)?\\b/i', $text );
	}
}
