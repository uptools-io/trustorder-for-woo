<?php
/**
 * WooCommerce → TrustOrder admin screen (React app mount).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Admin;

use LightweightPlugins\TrustOrder\Rest\Routes;

/**
 * Registers the submenu page and enqueues the compiled bundle. PHP renders
 * only an empty mount point, outside the core `.wrap` (its margins would break
 * the full-width shell); every screen is drawn by the app in `build/`.
 */
final class AdminPage {

	public const SLUG = 'trustorder';

	/**
	 * Expected hook suffix; used until add_submenu_page() returned the real one.
	 */
	public const HOOK_SUFFIX = 'woocommerce_page_trustorder';

	public const HANDLE = 'trustorder-admin';

	/**
	 * Hook suffix returned by add_submenu_page(), null before admin_menu.
	 *
	 * @var string|null
	 */
	private static ?string $hook_suffix = null;

	/**
	 * Hook in (admin only).
	 */
	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'addMenu' ], 60 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_filter( 'admin_body_class', [ self::class, 'bodyClass' ] );
	}

	/**
	 * Submenu under WooCommerce.
	 */
	public static function addMenu(): void {
		$hook = add_submenu_page( 'woocommerce', __( 'TrustOrder', 'trustorder-for-woo' ), __( 'TrustOrder', 'trustorder-for-woo' ), 'manage_woocommerce', self::SLUG, [ self::class, 'render' ] );
		if ( is_string( $hook ) && '' !== $hook ) {
			self::$hook_suffix = $hook;
		}
	}

	/**
	 * The screen's hook suffix (and screen id): what WordPress returned for the
	 * submenu, else HOOK_SUFFIX.
	 */
	public static function hookSuffix(): string {
		return self::$hook_suffix ?? self::HOOK_SUFFIX;
	}

	/**
	 * Enqueue the app on our screen only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue( string $hook ): void {
		$asset_file = TRUSTORDER_DIR . 'build/index.asset.php';
		if ( self::hookSuffix() !== $hook || ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		$deps  = is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : [];
		$ver   = is_string( $asset['version'] ?? null ) ? $asset['version'] : TRUSTORDER_VERSION;

		wp_enqueue_script( self::HANDLE, TRUSTORDER_URL . 'build/index.js', $deps, $ver, true );
		wp_set_script_translations( self::HANDLE, 'trustorder-for-woo', TRUSTORDER_DIR . 'languages' );
		wp_add_inline_script( self::HANDLE, 'window.trustorderAdmin = ' . wp_json_encode( self::bootData() ) . ';', 'before' );

		// index.asset.php hashes only the JS; version the CSS by its mtime.
		$css_mtime = @filemtime( TRUSTORDER_DIR . 'build/index.css' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing file falls back to the asset hash.
		wp_enqueue_style( self::HANDLE, TRUSTORDER_URL . 'build/index.css', [ 'wp-components' ], false !== $css_mtime ? (string) $css_mtime : $ver );
	}

	/**
	 * Screen class, so the stylesheet can make the screen full-bleed.
	 *
	 * @param string $classes Body classes.
	 */
	public static function bodyClass( string $classes ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen instanceof \WP_Screen && self::hookSuffix() === $screen->id ? $classes . ' trustorder-screen' : $classes;
	}

	/**
	 * Mount point.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! is_readable( TRUSTORDER_DIR . 'build/index.js' ) ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'The admin app is missing. Run "npm ci && npm run build" in the plugin folder.', 'trustorder-for-woo' ) . '</p></div></div>';
			return;
		}
		echo '<div id="trustorder-root" class="trustorder-root"></div>';
	}

	/**
	 * Data the app needs before its first REST call.
	 *
	 * @return array<string, mixed>
	 */
	public static function bootData(): array {
		$base = get_option( 'woocommerce_default_country', '' );
		/**
		 * Untyped on purpose: WC() may be missing or partial.
		 *
		 * @var mixed $wc
		 */
		$wc = function_exists( 'WC' ) ? WC() : null;
		/**
		 * Untyped on purpose: WC() may be missing or partial.
		 *
		 * @var mixed $countries
		 */
		$countries = is_object( $wc ) && isset( $wc->countries ) ? $wc->countries : null;
		$list      = is_object( $countries ) && method_exists( $countries, 'get_countries' ) ? $countries->get_countries() : [];

		return [
			'namespace'   => Routes::NAMESPACE,
			'version'     => TRUSTORDER_VERSION,
			'shopCountry' => is_string( $base ) ? explode( ':', $base )[0] : '',
			'countries'   => is_array( $list ) ? array_map( 'wp_strip_all_tags', $list ) : [],
		];
	}
}
