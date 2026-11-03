<?php
/**
 * The client network a request comes from (for the per-network caps).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Checkout;

/**
 * REMOTE_ADDR by default. `CF-Connecting-IP` is used only when REMOTE_ADDR
 * is a Cloudflare edge address; `X-Forwarded-For` is never trusted. Behind
 * another proxy, set the client address with the `trustorder_client_ip`
 * filter. IPv6 is bucketed per /64, IPv4 per address.
 */
final class ClientIp {

	/**
	 * Cloudflare's published edge ranges, fetched 2026-11-03 from
	 * https://www.cloudflare.com/ips-v4 and https://www.cloudflare.com/ips-v6
	 * (filter `trustorder_cloudflare_ranges`).
	 */
	public const CLOUDFLARE = [
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	];

	/**
	 * Proxy indicators: private, CGNAT (RFC 6598), loopback, link-local, ULA,
	 * unspecified. A client in these ranges is most likely the shop's own
	 * proxy, so it gets no network bucket (unless `trustorder_client_ip` is filtered). Explicit
	 * list: FILTER_FLAG_NO_RES_RANGE differs between PHP versions.
	 */
	public const LOCAL = [
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.168.0.0/16',
		'::/128',
		'::1/128',
		'fc00::/7',
		'fe80::/10',
	];

	/**
	 * The client address ('' when unknown). IPv4-mapped IPv6 addresses
	 * (`::ffff:a.b.c.d`) are unmapped first.
	 */
	public static function address(): string {
		$remote = self::unmap( self::server( 'REMOTE_ADDR' ) );
		$ip     = $remote;

		$cf = self::unmap( self::server( 'HTTP_CF_CONNECTING_IP' ) );
		if ( '' !== $remote && '' !== $cf && false !== filter_var( $cf, FILTER_VALIDATE_IP ) ) {
			/**
			 * Cloudflare edge ranges whose CF-Connecting-IP header is trusted.
			 *
			 * @param string[] $ranges CIDR list.
			 */
			$ranges = apply_filters( 'trustorder_cloudflare_ranges', self::CLOUDFLARE );
			if ( is_array( $ranges ) && self::inRanges( $remote, $ranges ) ) {
				$ip = $cf;
			}
		}

		/**
		 * The client IP address used for the per-network check caps.
		 *
		 * @param string $ip     Detected address.
		 * @param string $remote REMOTE_ADDR.
		 */
		$filtered = apply_filters( 'trustorder_client_ip', $ip, $remote );

		// A third-party callback may return anything.
		$ip = is_string( $filtered ) ? self::unmap( trim( $filtered ) ) : ''; // @phpstan-ignore ternary.elseUnreachable (False positive: apply_filters() is typed from the docblock, a callback can return a non-string.)

		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Bucket id: the IPv4 address, or the IPv6 /64. '' when unknown, or when
	 * the address is local (proxy indicator) and `trustorder_client_ip` is
	 * not filtered.
	 */
	public static function bucket(): string {
		$ip = self::address();
		if ( '' === $ip || ( self::inRanges( $ip, self::LOCAL ) && ! has_filter( 'trustorder_client_ip' ) ) ) {
			return '';
		}
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $ip;
		}
		$bin = (string) inet_pton( $ip );

		return (string) inet_ntop( substr( $bin, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
	}

	/**
	 * Whether an address is inside any CIDR of the list.
	 *
	 * @param string $ip     Address.
	 * @param array  $ranges CIDR strings.
	 * @phpstan-param array<mixed> $ranges
	 */
	public static function inRanges( string $ip, array $ranges ): bool {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input just means "not in range".
		if ( false === $bin ) {
			return false;
		}
		foreach ( $ranges as $cidr ) {
			if ( is_string( $cidr ) && self::inCidr( $bin, $cidr ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Binary address inside one CIDR.
	 *
	 * @param string $bin  inet_pton() output.
	 * @param string $cidr CIDR.
	 */
	private static function inCidr( string $bin, string $cidr ): bool {
		$parts = explode( '/', $cidr, 2 );
		$net   = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A malformed filtered range is skipped.
		if ( false === $net || strlen( $net ) !== strlen( $bin ) ) {
			return false;
		}
		$bits = isset( $parts[1] ) && ctype_digit( $parts[1] ) ? (int) $parts[1] : strlen( $net ) * 8;
		$bits = min( $bits, strlen( $net ) * 8 );

		$bytes = intdiv( $bits, 8 );
		if ( substr( $bin, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;

		return ( ord( $bin[ $bytes ] ) & $mask ) === ( ord( $net[ $bytes ] ) & $mask );
	}

	/**
	 * `::ffff:a.b.c.d` (::ffff:0:0/96) → `a.b.c.d`; anything else unchanged.
	 *
	 * @param string $ip Address.
	 */
	private static function unmap( string $ip ): string {
		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $ip;
		}
		$bin = (string) inet_pton( $ip );
		if ( str_repeat( "\0", 10 ) . "\xff\xff" !== substr( $bin, 0, 12 ) ) {
			return $ip;
		}

		return (string) inet_ntop( substr( $bin, 12 ) );
	}

	/**
	 * A sanitised $_SERVER value.
	 *
	 * @param string $key Key.
	 */
	private static function server( string $key ): string {
		return isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) ) : '';
	}
}
