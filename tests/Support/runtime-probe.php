<?php
/**
 * Loads the main plugin file the way WordPress does, without Composer's
 * vendor/autoload.php, and prints what resolved. Run by MainFileTest in a
 * separate PHP process.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

// phpcs:ignoreFile -- Test-only probe script.

define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/WpStubs.php';

function plugin_dir_path( $file ) {
	return rtrim( dirname( $file ), '/' ) . '/';
}

function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/trustorder-for-woo/';
}

require dirname( __DIR__, 2 ) . '/trustorder-for-woo.php';

$plugin = \LightweightPlugins\TrustOrder\Plugin::instance();

echo json_encode(
	[
		'version'     => TRUSTORDER_VERSION,
		'file'        => basename( TRUSTORDER_FILE ),
		'composer'    => class_exists( \Composer\Autoload\ClassLoader::class, false ),
		'settings'    => class_exists( \LightweightPlugins\TrustOrder\Settings::class ),
		'client'      => class_exists( \LightweightPlugins\TrustOrder\Api\Client::class ),
		'phone'       => class_exists( \LightweightPlugins\TrustOrder\Vendor\libphonenumber\PhoneNumberUtil::class ),
		'unprefixed'  => class_exists( 'libphonenumber\PhoneNumberUtil' ),
		'init_prio'   => has_action( 'plugins_loaded', [ $plugin, 'init' ] ),
		'compat_prio' => has_action( 'before_woocommerce_init', [ $plugin, 'declare_compatibility' ] ),
		'deactivate'  => $GLOBALS['trustorder_test']['deactivation']['trustorder-for-woo.php'] ?? null,
	]
);
