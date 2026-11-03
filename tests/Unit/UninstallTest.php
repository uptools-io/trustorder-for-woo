<?php
/**
 * uninstall.php cleanup.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class UninstallTest extends TestCase {

	protected function setUp(): void {
		trustorder_test_reset();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_removes_dismissed_notice_user_meta_for_every_user(): void {
		$GLOBALS['trustorder_test']['usermeta'] = [
			1 => [ 'trustorder_cap_notice_dismissed' => [ 'trustorder_breaker' => 5 ], 'trustorder_intl_notice_dismissed' => '1', 'other' => 'x' ],
			7 => [ 'trustorder_cap_notice_dismissed' => [ 'trustorder_submit_ip_cap' => 9 ] ],
		];
		update_option( 'trustorder_cache_gen', 3, false );
		$GLOBALS['wpdb'] = new class() {
			/** @var string */
			public $options = 'wp_options';
			/** @var list<string> */
			public array $queries = [];
			public function esc_like( string $s ): string {
				return addcslashes( $s, '_%\\' );
			}
			public function prepare( string $q, ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $q ), $args );
			}
			public function query( string $q ): int {
				$this->queries[] = $q;
				return 0;
			}
		};
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'trustorder-for-woo/trustorder-for-woo.php' );
		}

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertSame( [ 1 => [ 'other' => 'x' ], 7 => [] ], $GLOBALS['trustorder_test']['usermeta'] );
		$this->assertContains( [ 'user', 0, 'trustorder_cap_notice_dismissed', true ], $GLOBALS['trustorder_test']['deleted_meta'] );
		$this->assertContains( [ 'user', 0, 'trustorder_intl_notice_dismissed', true ], $GLOBALS['trustorder_test']['deleted_meta'] );
		$this->assertCount( 1, $GLOBALS['wpdb']->queries );
		$this->assertFalse( get_option( 'trustorder_cache_gen' ), 'the cache generation option is removed' );
	}
}
