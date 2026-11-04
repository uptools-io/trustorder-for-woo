<?php
/**
 * TRUSTORDER_API_KEY constant (wp-config.php / Bedrock .env) tests.
 *
 * Each test runs in its own process: a defined constant cannot be undone.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit;

use LightweightPlugins\TrustOrder\Admin\SiteState;
use LightweightPlugins\TrustOrder\Rest\SettingsController;
use LightweightPlugins\TrustOrder\Settings;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class KeyConstantTest extends TestCase {

	private const CONST_KEY  = 'to_live_Constant0123456789abcdefABCDEFGH';
	private const STORED_KEY = 'to_test_0123456789abcdefABCDEF0123456789';

	protected function setUp(): void {
		trustorder_test_reset();
	}

	public function test_without_the_constant_the_stored_key_is_used(): void {
		Settings::update( [ 'api_key' => self::STORED_KEY ] );

		$this->assertFalse( Settings::keyLocked() );
		$this->assertSame( self::STORED_KEY, Settings::get()['api_key'] );
		$this->assertFalse( SiteState::key()['key_locked'] );
	}

	public function test_the_constant_overrides_the_stored_key(): void {
		Settings::update( [ 'api_key' => self::STORED_KEY ] );
		define( 'TRUSTORDER_API_KEY', ' ' . self::CONST_KEY . ' ' );

		$this->assertTrue( Settings::keyLocked() );
		$this->assertSame( self::CONST_KEY, Settings::get()['api_key'] );
		$key = SiteState::key();
		$this->assertTrue( $key['key_locked'] );
		$this->assertSame( 'live', $key['env'] );
		$this->assertSame( substr( self::CONST_KEY, 0, 12 ), $key['key_prefix'] );
	}

	public function test_the_constant_is_never_written_to_the_option(): void {
		define( 'TRUSTORDER_API_KEY', self::CONST_KEY );
		Settings::update( [ 'strictness' => 'strict' ] );

		$stored = get_option( Settings::OPTION, [] );
		$this->assertSame( '', $stored['api_key'] );
		$this->assertSame( 'strict', $stored['strictness'] );
	}

	public function test_an_invalid_constant_means_no_key_and_stays_locked(): void {
		Settings::update( [ 'api_key' => self::STORED_KEY ] );
		define( 'TRUSTORDER_API_KEY', 'not-a-key' );

		$this->assertTrue( Settings::keyLocked() );
		$this->assertSame( '', Settings::get()['api_key'] );
		$this->assertFalse( SiteState::key()['has_key'] );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function unsetValues(): array {
		return [
			'null (Bedrock env() of a missing variable)' => [ null ],
			'empty string'                               => [ '' ],
			'whitespace'                                 => [ '  ' ],
			'false'                                      => [ false ],
		];
	}

	/**
	 * @dataProvider unsetValues
	 *
	 * @param mixed $value Constant value.
	 */
	public function test_an_empty_constant_counts_as_not_set( $value ): void {
		Settings::update( [ 'api_key' => self::STORED_KEY ] );
		define( 'TRUSTORDER_API_KEY', $value );

		$this->assertFalse( Settings::keyLocked() );
		$this->assertSame( self::STORED_KEY, Settings::get()['api_key'] );
		$this->assertTrue( SettingsController::apply( [ 'api_key' => '' ] ) );
		$this->assertSame( '', Settings::get()['api_key'] );
	}

	public function test_changing_the_key_is_refused_while_the_constant_is_set(): void {
		define( 'TRUSTORDER_API_KEY', self::CONST_KEY );

		foreach ( [ self::STORED_KEY, '' ] as $value ) {
			$res = SettingsController::apply( [ 'api_key' => $value ] );
			$this->assertInstanceOf( WP_Error::class, $res );
			$this->assertSame( 'trustorder_key_locked', $res->get_error_code() );
		}
		$this->assertTrue( SettingsController::apply( [ 'strictness' => 'lenient' ] ) );
		$this->assertSame( self::CONST_KEY, Settings::get()['api_key'] );
	}
}
