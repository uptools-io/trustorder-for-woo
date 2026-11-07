<?php
/**
 * Runtime load of the main file without Composer (release-ZIP layout).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MainFileTest extends TestCase {

	public function test_main_file_autoloads_plugin_and_prefixed_vendor_without_composer(): void {
		$probe = dirname( __DIR__ ) . '/Support/runtime-probe.php';
		$out   = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $probe ) . ' 2>&1' );

		$this->assertIsString( $out );
		$got = json_decode( $out, true );
		$this->assertIsArray( $got, 'probe output: ' . $out );
		$this->assertSame(
			[
				'version'     => '1.0.10',
				'file'        => 'trustorder-for-woo.php',
				'composer'    => false,
				'settings'    => true,
				'client'      => true,
				'phone'       => true,
				'unprefixed'  => false,
				'init_prio'   => 20,
				'compat_prio' => 10,
				'deactivate'  => [ 'LightweightPlugins\\TrustOrder\\Plugin', 'deactivate' ],
			],
			$got
		);
	}
}
