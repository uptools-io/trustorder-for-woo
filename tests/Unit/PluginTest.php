<?php
/**
 * Plugin bootstrap tests.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit;

use LightweightPlugins\TrustOrder\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase {

	protected function setUp(): void {
		trustorder_test_reset();
	}

	public function test_instance_is_a_singleton(): void {
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_boot_registers_init_at_priority_20_and_compat_declaration(): void {
		$p = Plugin::instance();
		$p->boot();

		$this->assertSame( 20, has_action( 'plugins_loaded', [ $p, 'init' ] ) );
		$this->assertSame( 10, has_action( 'before_woocommerce_init', [ $p, 'declare_compatibility' ] ) );
	}

	public function test_init_does_nothing_without_woocommerce(): void {
		$fired = false;
		add_action(
			'trustorder_init',
			static function () use ( &$fired ): void {
				$fired = true;
			}
		);

		Plugin::instance()->init();

		$this->assertFalse( class_exists( 'WooCommerce', false ) );
		$this->assertFalse( $fired );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_init_wires_status_signals_and_backfill(): void {
		require_once dirname( __DIR__ ) . '/Support/FakeWooCommerce.php';
		Plugin::instance()->init();

		$this->assertArrayHasKey( 'wc-to-refused', apply_filters( 'wc_order_statuses', [] ) );
		$this->assertSame( 10, has_action( 'woocommerce_order_status_changed', [ \LightweightPlugins\TrustOrder\Signals\SignalRouter::class, 'onStatusChanged' ] ) );
		$this->assertSame( 10, has_action( 'trustorder_signal', [ \LightweightPlugins\TrustOrder\Signals\SignalJob::class, 'handle' ] ) );
		$this->assertSame( 10, has_action( 'trustorder_backfill', [ \LightweightPlugins\TrustOrder\Signals\Backfill::class, 'tick' ] ) );
		$this->assertSame( 10, has_action( 'rest_api_init', [ \LightweightPlugins\TrustOrder\Rest\Routes::class, 'registerRoutes' ] ) );
		$this->assertSame( 10, has_action( 'init', [ \LightweightPlugins\TrustOrder\I18n::class, 'load' ] ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_init_in_admin_wires_the_page_and_privacy_text(): void {
		require_once dirname( __DIR__ ) . '/Support/FakeWooCommerce.php';
		$GLOBALS['trustorder_test']['admin'] = true;
		Plugin::instance()->init();

		$this->assertSame( 60, has_action( 'admin_menu', [ \LightweightPlugins\TrustOrder\Admin\AdminPage::class, 'addMenu' ] ) );
		$this->assertSame( 10, has_action( 'admin_init', [ \LightweightPlugins\TrustOrder\Admin\Privacy::class, 'add' ] ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_init_registers_the_cli_commands_only_under_wp_cli(): void {
		require_once dirname( __DIR__ ) . '/Support/FakeWooCommerce.php';
		Plugin::instance()->init();
		$this->assertSame( [], \WP_CLI::$commands );

		define( 'WP_CLI', true );
		Plugin::instance()->init();

		$this->assertSame( [ 'trustorder', 'trustorder settings', 'trustorder backfill', 'trustorder signal', 'trustorder exceptions' ], array_keys( \WP_CLI::$commands ) );
		$this->assertSame( \LightweightPlugins\TrustOrder\CLI\Command::class, \WP_CLI::$commands['trustorder'] );
	}

	public function test_deactivate_cancels_only_pending_trustorder_actions(): void {
		$GLOBALS['trustorder_test']['as'] = [
			[ 'hook' => 'trustorder_signal', 'args' => [ 'order_id' => 5 ], 'group' => 'trustorder', 'status' => 'pending' ],
			[ 'hook' => 'trustorder_backfill', 'args' => [], 'group' => 'trustorder', 'status' => 'pending' ],
			[ 'hook' => 'trustorder_signal', 'args' => [ 'order_id' => 6 ], 'group' => 'trustorder', 'status' => 'in-progress' ],
			[ 'hook' => 'trustorder_mx', 'args' => [ 'h' ], 'group' => 'trustorder', 'status' => 'complete' ],
			[ 'hook' => 'other_hook', 'args' => [], 'group' => 'other', 'status' => 'pending' ],
		];

		Plugin::deactivate();

		$this->assertSame(
			[ 'canceled', 'canceled', 'in-progress', 'complete', 'pending' ],
			array_column( $GLOBALS['trustorder_test']['as'], 'status' )
		);
	}
}
