<?php
/**
 * The exception note's help text says where the note lives (EN source + HU).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class ExceptionNoteTextTest extends TestCase {

	private const EN = 'Stored on the TrustOrder service and visible only to your shop, up to 500 characters. Do not write personal data here (no name, e-mail, phone or address).';

	public function test_help_text_names_the_service_the_shop_and_no_personal_data(): void {
		$root = dirname( __DIR__, 3 );

		$this->assertStringContainsString( "'" . self::EN . "'", (string) file_get_contents( $root . '/src/pages/ExceptionForm.js' ) );
		$this->assertStringContainsString( self::EN, (string) file_get_contents( $root . '/build/index.js' ) );

		$json = glob( $root . '/languages/trustorder-for-woo-hu_HU-*.json' );
		$this->assertNotEmpty( $json );
		$data = json_decode( (string) file_get_contents( $json[0] ), true );
		$hu   = $data['locale_data']['messages'][ self::EN ][0] ?? '';
		$this->assertStringContainsString( 'TrustOrder szolgáltatásnál', $hu );
		$this->assertStringContainsString( 'csak a boltod látja', $hu );
		$this->assertStringContainsString( 'személyes adatot', $hu );
	}
}
