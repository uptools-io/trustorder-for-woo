<?php
/**
 * Plugin bootstrap.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder;

/**
 * Registers the plugin's hooks once WooCommerce is loaded.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * The plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hook the plugin in (call once from the main file).
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'before_woocommerce_init', [ $this, 'declare_compatibility' ] );
		add_action( 'plugins_loaded', [ $this, 'init' ], 20 );
	}

	/**
	 * Deactivation: cancel the pending background jobs (Action Scheduler group
	 * `trustorder`); running and finished actions are left alone. Status changes
	 * made while inactive are caught up by starting the backfill again.
	 */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], Signals\SignalRouter::GROUP );
		}
	}

	/**
	 * Declare HPOS and Cart/Checkout blocks compatibility.
	 */
	public function declare_compatibility(): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TRUSTORDER_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', TRUSTORDER_FILE, true );
	}

	/**
	 * Register feature hooks; only when WooCommerce is active.
	 */
	public function init(): void {
		if ( ! class_exists( 'WooCommerce', false ) ) {
			return;
		}

		Identity\MxCache::register();
		Admin\IntlNotice::register( Identity\Unicode::full() );

		Checkout\Scope::register();
		Checkout\Gatekeeper::register();
		Checkout\ClassicCheckout::register();
		Checkout\Enforcer::register();
		Order\OrderResult::register();
		Order\RefusedStatus::register();
		Signals\SignalRouter::register();
		Signals\Backfill::register();
		Geo\GeoRouter::register();
		I18n::register();
		Rest\Routes::register();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			CLI\Command::register();
		}
		if ( is_admin() ) {
			Admin\AdminPage::register();
			Admin\NoticeIsolation::register();
			Admin\Privacy::register();
			Admin\CapNotice::register();
			Order\OrderColumn::register();
			Order\OrderMetabox::register();
		}

		/**
		 * Fires after TrustOrder has registered its hooks (WooCommerce is active).
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'trustorder_init', $this );
	}
}
