<?php
/**
 * Translation loading.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder;

/**
 * Loads the bundled `languages/` translations (the plugin is not on
 * WordPress.org, so no language pack arrives for it).
 */
final class I18n {

	public const DOMAIN = 'trustorder-for-woo';

	/**
	 * Hook in.
	 */
	public static function register(): void {
		// Priority 0: WooCommerce registers the order statuses at init 9 (class-wc-post-types.php),
		// and RefusedStatus::label() translates then (WC 11.2.1).
		add_action( 'init', [ self::class, 'load' ], 0 );
	}

	/**
	 * `init` callback.
	 */
	public static function load(): void {
		load_plugin_textdomain( self::DOMAIN, false, dirname( plugin_basename( TRUSTORDER_FILE ) ) . '/languages' );
	}
}
