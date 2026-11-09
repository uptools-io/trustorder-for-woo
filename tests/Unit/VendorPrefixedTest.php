<?php
/**
 * The prefixed third-party code.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit;

use LightweightPlugins\TrustOrder\Vendor\Symfony\Polyfill\Mbstring\Mbstring;
use PHPUnit\Framework\TestCase;

final class VendorPrefixedTest extends TestCase {

	/**
	 * The mbstring polyfill's global bootstrap defines `mb_ucfirst()` & co. on PHP < 8.4
	 * for the whole site, and those call into the prefixed class. Its `MB_CASE_*` constants
	 * must stay the native ones: the prefixed copies are only defined when the mbstring
	 * extension is missing, so with mbstring loaded every such call would be fatal.
	 */
	public function test_global_constants_are_not_renamed(): void {
		$dir   = dirname( __DIR__, 2 ) . '/vendor-prefixed';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$this->assertStringNotContainsString( 'TRUSTORDER_VENDOR_', (string) file_get_contents( $file->getPathname() ), $file->getPathname() );
		}
	}

	public function test_polyfill_case_functions_work_with_mbstring_loaded(): void {
		$this->assertTrue( extension_loaded( 'mbstring' ) );
		$this->assertSame( 'Élet', Mbstring::mb_ucfirst( 'élet' ) );
		$this->assertSame( 'éLET', Mbstring::mb_lcfirst( 'ÉLET' ) );
		$this->assertSame( 'Árvíz Tűrő', Mbstring::mb_convert_case( 'árvíz tűrő', \MB_CASE_TITLE ) );
	}
}
