<?php
/**
 * PHPStan-only bootstrap: the runtime constants with representative values,
 * so the main file never has to run during analysis.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

define( 'TRUSTORDER_VERSION', '1.0.8' );
define( 'TRUSTORDER_FILE', __DIR__ . '/trustorder-for-woo.php' );
define( 'TRUSTORDER_DIR', __DIR__ . '/' );
define( 'TRUSTORDER_URL', 'https://example.test/wp-content/plugins/trustorder-for-woo/' );
