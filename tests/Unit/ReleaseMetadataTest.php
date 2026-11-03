<?php
/**
 * Release metadata: requirements and the changelog in both formats.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ReleaseMetadataTest extends TestCase {

	/**
	 * The Store API customer hook used by the Enforcer exists since WooCommerce 8.2.0.
	 */
	public function test_woocommerce_minimum_is_8_2_everywhere(): void {
		$root = dirname( __DIR__, 2 );

		$this->assertMatchesRegularExpression( '/^ \* WC requires at least: 8\.2$/m', (string) file_get_contents( $root . '/trustorder-for-woo.php' ) );
		$readme = (string) file_get_contents( $root . '/readme.txt' );
		$this->assertMatchesRegularExpression( '/^WC requires at least: 8\.2$/m', $readme );
		$this->assertStringContainsString( 'WooCommerce 8.2 or newer', $readme );
		$this->assertStringContainsString( 'WooCommerce 8.2+', (string) file_get_contents( $root . '/README.md' ) );
		$this->assertStringContainsString( 'WooCommerce 8.2,', (string) file_get_contents( $root . '/CHANGELOG.md' ) );
		$this->assertStringContainsString(
			'woocommerce_store_api_checkout_update_customer_from_request',
			(string) file_get_contents( $root . '/includes/Checkout/Enforcer.php' )
		);
	}

	public function test_readme_and_changelog_list_the_same_changes(): void {
		$root = dirname( __DIR__, 2 );
		$readme = (string) file_get_contents( $root . '/readme.txt' );
		$this->assertMatchesRegularExpression( '/^Stable tag: (\S+)$/m', $readme );
		preg_match( '/^Stable tag: (\S+)$/m', $readme, $tag );
		$version = $tag[1];

		$this->assertSame( 1, preg_match( '/^= ' . preg_quote( $version, '/' ) . ' =\n((?:\* .*\n?)+)/m', $readme, $m ) );
		$from_readme = array_map( static fn( string $l ): string => (string) preg_replace( '/^\* (New|Fix|Update|Change): /', '', $l ), array_filter( explode( "\n", trim( $m[1] ) ) ) );

		$changelog = (string) file_get_contents( $root . '/CHANGELOG.md' );
		$this->assertSame( 1, preg_match( '/^## \[' . preg_quote( $version, '/' ) . '\] - \d{4}-\d{2}-\d{2}\n(.*?)(?=^## \[|\z)/ms', $changelog, $c ) );
		preg_match_all( '/^- (.*)$/m', $c[1], $lines );

		$this->assertSame( array_values( $from_readme ), $lines[1] );
		$this->assertStringNotContainsString( 'scaffold', strtolower( $c[1] ) );
	}
}
