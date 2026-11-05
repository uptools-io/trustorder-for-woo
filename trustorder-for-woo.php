<?php
/**
 * Plugin Name:       TrustOrder for WooCommerce
 * Plugin URI:        https://uptools.io
 * Description:       Cash-on-delivery buyer reputation for WooCommerce: hides cash on delivery for risky buyers, based on hashed identities shared across shops.
 * Version:           1.0.4
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            uptools.io
 * Author URI:        https://uptools.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       trustorder-for-woo
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.2
 * WC tested up to:   11.2
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TRUSTORDER_VERSION', '1.0.4' );
define( 'TRUSTORDER_FILE', __FILE__ );
define( 'TRUSTORDER_DIR', plugin_dir_path( __FILE__ ) );
define( 'TRUSTORDER_URL', plugin_dir_url( __FILE__ ) );

// The bundled libphonenumber needs PHP 8.1 (enums); its autoloader would throw on older PHP.
if ( PHP_VERSION_ID < 80100 ) {
	return;
}

// Scoped (Strauss-prefixed) dependencies under LightweightPlugins\TrustOrder\Vendor\ (committed).
if ( file_exists( TRUSTORDER_DIR . 'vendor-prefixed/autoload.php' ) ) {
	require_once TRUSTORDER_DIR . 'vendor-prefixed/autoload.php';
}

// PSR-4 autoloader for the plugin's own classes (no vendor/ in release builds).
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) || 0 === strncmp( $class, $prefix . 'Vendor\\', strlen( $prefix ) + 7 ) ) {
			return;
		}

		$file = TRUSTORDER_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

Plugin::instance()->boot();
