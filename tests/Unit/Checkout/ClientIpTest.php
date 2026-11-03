<?php
/**
 * Client network detection.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Checkout;

use LightweightPlugins\TrustOrder\Checkout\ClientIp;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase {

	private const KEYS = [ 'REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR' ];

	protected function setUp(): void {
		trustorder_test_reset();
	}

	protected function tearDown(): void {
		foreach ( self::KEYS as $k ) {
			unset( $_SERVER[ $k ] );
		}
	}

	public function test_remote_addr_by_default_and_xff_ignored(): void {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.20';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';

		$this->assertSame( '198.51.100.20', ClientIp::address() );
		$this->assertSame( '198.51.100.20', ClientIp::bucket() );
	}

	public function test_cf_header_only_from_cloudflare_edges(): void {
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.5';

		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$this->assertSame( '198.51.100.20', ClientIp::address(), 'spoofed header from a non-Cloudflare peer' );

		$_SERVER['REMOTE_ADDR'] = '173.245.48.10';
		$this->assertSame( '203.0.113.5', ClientIp::address() );

		$_SERVER['REMOTE_ADDR'] = '2606:4700:10::6816:1';
		$this->assertSame( '203.0.113.5', ClientIp::address(), 'IPv6 edge' );

		$_SERVER['REMOTE_ADDR'] = '104.24.0.1';
		$this->assertSame( '203.0.113.5', ClientIp::address(), '/14 boundary inside' );
		$_SERVER['REMOTE_ADDR'] = '104.28.0.1';
		$this->assertSame( '104.28.0.1', ClientIp::address(), '/14 boundary outside' );
	}

	public function test_invalid_cf_header_from_an_edge_falls_back_to_remote_addr(): void {
		$_SERVER['REMOTE_ADDR']           = '173.245.48.10';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';

		$this->assertSame( '173.245.48.10', ClientIp::address() );
	}

	public function test_ipv4_mapped_addresses_are_unmapped(): void {
		$_SERVER['REMOTE_ADDR'] = '::ffff:198.51.100.20';
		$this->assertSame( '198.51.100.20', ClientIp::address() );
		$this->assertSame( '198.51.100.20', ClientIp::bucket() );

		$_SERVER['REMOTE_ADDR'] = '::ffff:198.51.100.21';
		$this->assertSame( '198.51.100.21', ClientIp::bucket(), 'not collapsed into ::/64' );

		$_SERVER['REMOTE_ADDR']           = '::ffff:173.245.48.10';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '::ffff:203.0.113.5';
		$this->assertSame( '203.0.113.5', ClientIp::address(), 'mapped Cloudflare edge matches the v4 range' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function localAddresses(): array {
		return [
			'10/8'       => [ '10.1.2.3' ],
			'172.16/12'  => [ '172.20.0.9' ],
			'192.168/16' => [ '192.168.1.10' ],
			'loopback'   => [ '127.0.0.1' ],
			'link-local' => [ '169.254.10.1' ],
			'v6 loop'    => [ '::1' ],
			'v6 ULA'     => [ 'fd12:3456::1' ],
			'v6 link'    => [ 'fe80::1' ],
			'mapped 10'  => [ '::ffff:10.0.0.7' ],
			'CGNAT low'  => [ '100.64.0.1' ],
			'CGNAT high' => [ '100.127.255.254' ],
		];
	}

	public function test_addresses_next_to_cgnat_still_count(): void {
		foreach ( [ '100.63.255.255', '100.128.0.1' ] as $ip ) {
			$_SERVER['REMOTE_ADDR'] = $ip;
			$this->assertSame( $ip, ClientIp::bucket() );
		}
	}

	/**
	 * @dataProvider localAddresses
	 */
	public function test_local_addresses_get_no_network_bucket( string $ip ): void {
		$_SERVER['REMOTE_ADDR'] = $ip;

		$this->assertSame( '', ClientIp::bucket() );
	}

	public function test_local_address_counts_when_the_client_ip_filter_is_set(): void {
		$_SERVER['REMOTE_ADDR'] = '10.1.2.3';
		add_filter( 'trustorder_client_ip', static fn( string $ip ): string => $ip );

		$this->assertSame( '10.1.2.3', ClientIp::bucket() );
	}

	public function test_filters(): void {
		$_SERVER['REMOTE_ADDR']           = '10.0.0.5';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.5';
		add_filter( 'trustorder_cloudflare_ranges', static fn(): array => [ '10.0.0.0/8' ] );
		$this->assertSame( '203.0.113.5', ClientIp::address() );

		add_filter( 'trustorder_client_ip', static fn( string $ip, string $remote ): string => '192.0.2.44', 10, 2 );
		$this->assertSame( '192.0.2.44', ClientIp::address() );
	}

	public function test_ipv6_is_bucketed_per_64(): void {
		$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:aaaa:bbbb:cccc:dddd';
		$a                      = ClientIp::bucket();
		$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::1';

		$this->assertSame( '2001:db8:1:2::/64', $a );
		$this->assertSame( $a, ClientIp::bucket() );

		$_SERVER['REMOTE_ADDR'] = '2001:db8:1:3::1';
		$this->assertNotSame( $a, ClientIp::bucket() );
	}

	public function test_no_remote_addr(): void {
		$this->assertSame( '', ClientIp::address() );
		$this->assertSame( '', ClientIp::bucket() );
	}

	public function test_in_ranges_rejects_garbage(): void {
		$this->assertFalse( ClientIp::inRanges( 'nope', [ '10.0.0.0/8' ] ) );
		$this->assertFalse( ClientIp::inRanges( '10.1.1.1', [ 'garbage', 5, '2001:db8::/32' ] ) );
		$this->assertTrue( ClientIp::inRanges( '10.1.1.1', [ 'garbage', '10.0.0.0/8' ] ) );
	}
}
