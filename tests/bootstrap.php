<?php
/**
 * PHPUnit bootstrap for pure-logic unit tests.
 *
 * Loads minimal, in-memory WordPress/WooCommerce polyfills so the plugin's
 * classes run without a WordPress install.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/Support/WpStubs.php';
require_once __DIR__ . '/Support/WcFakes.php';
require_once __DIR__ . '/Support/RestStubs.php';
require_once __DIR__ . '/Support/CliStubs.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/vendor-prefixed/autoload.php';

// Plugin constants (the main file defines them in a real install).
foreach ( [
	'TRUSTORDER_VERSION' => '1.0.1',
	'TRUSTORDER_FILE'    => dirname( __DIR__ ) . '/trustorder-for-woo.php',
	'TRUSTORDER_DIR'     => dirname( __DIR__ ) . '/',
	'TRUSTORDER_URL'     => 'https://example.test/wp-content/plugins/trustorder-for-woo/',
] as $trustorder_const => $trustorder_value ) {
	if ( ! defined( $trustorder_const ) ) {
		define( $trustorder_const, $trustorder_value );
	}
}
