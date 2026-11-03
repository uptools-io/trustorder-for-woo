<?php
/**
 * Local caps on /check calls.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Checkout;

use LightweightPlugins\TrustOrder\Log;

/**
 * Two independent budgets, each a set of buckets that must all have room;
 * a unit is taken from every bucket only when none of them denies.
 *
 * - Browsing: MAX per session, IP_MAX per client network
 *   (`trustorder_ip_check_limit`) and the shop-wide breaker SHOP_MAX per hour
 *   (`trustorder_hourly_check_limit`).
 * - Place order: SUBMIT_MAX per session and SUBMIT_IP_MAX per client network
 *   (`trustorder_submit_ip_check_limit`). Never the browsing buckets and never
 *   the breaker, so browsing cannot starve the submit check.
 *
 * Counts: see Counter (atomic with a persistent object cache, approximate
 * otherwise). The network is ClientIp::bucket(); a private / loopback /
 * link-local / ULA address (a proxy in front) has no network bucket.
 */
final class QuotaGuard {

	public const MAX           = 5;
	public const SUBMIT_MAX    = 3;
	public const IP_MAX        = 20;
	public const SUBMIT_IP_MAX = 10;
	public const WINDOW        = 1800;
	public const SHOP_MAX      = 300;
	public const SHOP_WINDOW   = 3600;

	public const SESSION_QUOTA = 'SESSION_QUOTA';
	public const SUBMIT_QUOTA  = 'SUBMIT_QUOTA';
	public const IP_QUOTA      = 'IP_QUOTA';
	public const LOCAL_QUOTA   = 'LOCAL_QUOTA';

	/**
	 * Logged (once per request) when both the object cache and the transient
	 * fallback fail for a bucket; the check is then allowed for that bucket.
	 */
	public const COUNTER_UNAVAILABLE = 'COUNTER_UNAVAILABLE';

	/**
	 * At most this many place-order units given back per session per WINDOW.
	 */
	public const REFUND_MAX = 3;

	/**
	 * At most this many place-order network units given back per client
	 * network per WINDOW (`trustorder_network_refund_limit`).
	 */
	public const NETWORK_REFUND_MAX = 6;

	/**
	 * Bucket prefix of the place-order network cap.
	 */
	private const SUBMIT_NET = 'submit-ip|';

	/**
	 * Set while the shop-wide breaker denied within the last hour.
	 */
	public const BREAKER_TRANSIENT = 'trustorder_breaker';

	/**
	 * Set while the place-order network cap denied within the last hour.
	 */
	public const SUBMIT_IP_TRANSIENT = 'trustorder_submit_ip_cap';

	/**
	 * Denial codes already logged in this request.
	 *
	 * @var array<string, true>
	 */
	private static array $logged = [];

	/**
	 * Take one browsing unit; null when allowed, else the denial code.
	 *
	 * @param string $session_key Session key ('' = unknown session: denied).
	 */
	public static function browse( string $session_key ): ?string {
		if ( '' === $session_key ) {
			return self::SESSION_QUOTA;
		}
		$buckets = [ [ $session_key, self::MAX, self::WINDOW, self::SESSION_QUOTA ] ];
		$net     = ClientIp::bucket();
		if ( '' !== $net ) {
			$buckets[] = [ 'ip|' . $net, self::limit( 'trustorder_ip_check_limit', self::IP_MAX ), self::WINDOW, self::IP_QUOTA ];
		}
		$buckets[] = [ 'shop', self::limit( 'trustorder_hourly_check_limit', self::SHOP_MAX ), self::SHOP_WINDOW, self::LOCAL_QUOTA ];

		return self::take( $buckets )[0];
	}

	/**
	 * Take one place-order unit; null when allowed, else the denial code.
	 *
	 * @param string $session_key Session key ('' = denied).
	 * @param array  $taken       Out: the units taken (for refundSubmit()).
	 * @phpstan-param list<array{0: string, 1: int, 2: bool, 3: string}> $taken
	 */
	public static function submit( string $session_key, array &$taken = [] ): ?string {
		$taken = [];
		if ( '' === $session_key ) {
			return self::SUBMIT_QUOTA;
		}
		[ $code, $taken ] = self::take( self::submitBuckets( $session_key ) );
		if ( self::IP_QUOTA === $code ) {
			self::mark( self::SUBMIT_IP_TRANSIENT );
		}

		return $code;
	}

	/**
	 * Give back a place-order unit whose check hit a transport or server
	 * outage, so an outage cannot spend the budget into the block fallback.
	 * Decrements exactly the storage submit() took from (no recomputing from
	 * the clock). At most REFUND_MAX times per session per WINDOW, else
	 * nothing is given back; the network unit additionally at most
	 * NETWORK_REFUND_MAX times per client network per WINDOW, else it stays
	 * spent. An unavailable refund counter means no refund.
	 *
	 * @param string $session_key Session key.
	 * @param array  $taken       Units from submit().
	 * @phpstan-param list<array{0: string, 1: int, 2: bool, 3: string}> $taken
	 */
	public static function refundSubmit( string $session_key, array $taken ): void {
		if ( '' === $session_key || [] === $taken || ! self::refundAllowed( 'refund|' . $session_key, self::REFUND_MAX ) ) {
			return;
		}
		foreach ( $taken as [ $key, $window, $transient, $bucket ] ) {
			if ( str_starts_with( $bucket, self::SUBMIT_NET ) && ! self::refundAllowed( 'refund-ip|' . substr( $bucket, strlen( self::SUBMIT_NET ) ), self::limit( 'trustorder_network_refund_limit', self::NETWORK_REFUND_MAX ) ) ) {
				continue;
			}
			Counter::decrKey( $key, $window, $transient );
		}
	}

	/**
	 * Count one refund in a refund bucket; whether it is within the limit.
	 *
	 * @param string $bucket Refund bucket.
	 * @param int    $max    Limit.
	 */
	private static function refundAllowed( string $bucket, int $max ): bool {
		$n = Counter::incr( $bucket, self::WINDOW );
		if ( null === $n ) {
			self::unavailable();
			return false;
		}

		return $n <= $max;
	}

	/**
	 * Whether the place-order network cap denied within the last hour.
	 */
	public static function submitCapTripped(): bool {
		return false !== get_transient( self::SUBMIT_IP_TRANSIENT );
	}

	/**
	 * Place-order buckets.
	 *
	 * @param string $session_key Session key.
	 * @return list<array{0: string, 1: int, 2: int, 3: string}>
	 */
	private static function submitBuckets( string $session_key ): array {
		$buckets = [ [ 'submit|' . $session_key, self::SUBMIT_MAX, self::WINDOW, self::SUBMIT_QUOTA ] ];
		$net     = ClientIp::bucket();
		if ( '' !== $net ) {
			$buckets[] = [ self::SUBMIT_NET . $net, self::limit( 'trustorder_submit_ip_check_limit', self::SUBMIT_IP_MAX ), self::WINDOW, self::IP_QUOTA ];
		}

		return $buckets;
	}

	/**
	 * Whether the shop-wide breaker tripped within the last hour.
	 */
	public static function breakerTripped(): bool {
		return false !== get_transient( self::BREAKER_TRANSIENT );
	}

	/**
	 * Forget per-request state (tests).
	 */
	public static function reset(): void {
		self::$logged = [];
		Counter::reset();
	}

	/**
	 * Count one unit in every bucket; when any goes over its max, take the
	 * units back and deny, so a denial consumes nothing. Atomic per bucket
	 * with a persistent object cache (the incremented value decides);
	 * approximate on the transient path (also the fallback when the object
	 * cache fails for a bucket; such a unit is marked so it is taken back from
	 * the transient copy). Only a bucket with no working counter at all
	 * allows and is not taken back.
	 *
	 * @param list<array{0: string, 1: int, 2: int, 3: string}> $buckets [bucket, max, window, denial code].
	 * @return array{0: ?string, 1: list<array{0: string, 1: int, 2: bool, 3: string}>} [denial code, units taken: key, window, transient copy, bucket].
	 */
	private static function take( array $buckets ): array {
		$taken = [];
		foreach ( $buckets as [ $bucket, $max, $window, $code ] ) {
			$key       = Counter::key( $bucket, $window );
			$transient = false;
			$n         = Counter::incrKey( $key, $window, $transient );
			if ( null === $n ) {
				self::unavailable();
				continue;
			}
			$taken[] = [ $key, $window, $transient, $bucket ];
			if ( $n > $max ) {
				foreach ( $taken as [ $k, $w, $t ] ) {
					Counter::decrKey( $k, $w, $t );
				}
				self::denied( $code );

				return [ $code, [] ];
			}
		}

		return [ null, $taken ];
	}

	/**
	 * Log COUNTER_UNAVAILABLE once per request.
	 */
	private static function unavailable(): void {
		if ( isset( self::$logged[ self::COUNTER_UNAVAILABLE ] ) ) {
			return;
		}
		self::$logged[ self::COUNTER_UNAVAILABLE ] = true;
		Log::error( self::COUNTER_UNAVAILABLE );
	}

	/**
	 * Log network / shop denials once per request; remember a tripped breaker.
	 *
	 * @param string $code Denial code.
	 */
	private static function denied( string $code ): void {
		if ( self::SESSION_QUOTA === $code || self::SUBMIT_QUOTA === $code || isset( self::$logged[ $code ] ) ) {
			return;
		}
		self::$logged[ $code ] = true;
		if ( self::LOCAL_QUOTA === $code ) {
			self::mark( self::BREAKER_TRANSIENT );
		}
		Log::error( 'checkout', [ 'code' => $code ] );
	}

	/**
	 * Remember a trip for the admin notice; written only when not already set.
	 *
	 * @param string $transient Transient name.
	 */
	private static function mark( string $transient ): void {
		if ( false === get_transient( $transient ) ) {
			set_transient( $transient, time(), HOUR_IN_SECONDS );
		}
	}

	/**
	 * A filtered positive limit.
	 *
	 * @param string $filter  Filter name.
	 * @param int    $default Default.
	 */
	private static function limit( string $filter, int $default ): int {
		$v = apply_filters( $filter, $default ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Always one of the documented trustorder_*_limit filters.

		return is_numeric( $v ) ? max( 0, (int) $v ) : $default;
	}
}
