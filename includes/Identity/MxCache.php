<?php
/**
 * Local MX check of e-mail domains, cached; checkout never waits on DNS.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Identity;

use LightweightPlugins\TrustOrder\Log;

/**
 * Transient-cached "does this domain accept mail" (MX, or A/AAAA fallback).
 * Unknown domains read as null; the lookup runs in an Action Scheduler job.
 *
 * The job argument is sha256(domain), never the domain: the domain is parked
 * in a short-lived transient under that hash and removed by the handler.
 */
final class MxCache {

	public const HOOK  = 'trustorder_mx_lookup';
	public const GROUP = 'trustorder';

	private const RESULT_PREFIX = 'trustorder_mx_';
	private const PARK_PREFIX   = 'trustorder_mxq_';
	private const CANARY        = 'gmail.com';

	/**
	 * Cached result: true (mail-capable), false (no MX/A/AAAA), null (unknown).
	 *
	 * @param string $domain Normalised domain.
	 */
	public static function get( string $domain ): ?bool {
		$v = get_transient( self::RESULT_PREFIX . self::id( $domain ) );
		if ( 'yes' === $v ) {
			return true;
		}

		return 'no' === $v ? false : null;
	}

	/**
	 * Queue a background lookup unless the result is cached or already queued.
	 *
	 * Not `$unique`: before Action Scheduler 4.0 it matched hook + group only,
	 * so other domains would be dropped. The explicit pre-check dedups; a race
	 * costs at most one idempotent duplicate lookup.
	 *
	 * @param string $domain Normalised domain.
	 */
	public static function schedule( string $domain ): void {
		if ( ! self::isNormalised( $domain ) || null !== self::get( $domain ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$id = self::id( $domain );
		set_transient( self::PARK_PREFIX . $id, $domain, HOUR_IN_SECONDS );

		$args = [ $id ];
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return;
		}
		as_schedule_single_action( time(), self::HOOK, $args, self::GROUP, false );
	}

	/**
	 * Hook the Action Scheduler handler.
	 */
	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'lookup' ] );
	}

	/**
	 * Action Scheduler handler. Caches 24 h when the domain resolves, 1 h when
	 * it does not, and nothing when DNS itself looks broken (the canary fails
	 * too), so a resolver outage never marks domains dead.
	 *
	 * @param mixed $id sha256 of the normalised domain (job argument).
	 */
	public static function lookup( $id ): void {
		if ( ! is_string( $id ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $id ) ) {
			return;
		}
		$domain = get_transient( self::PARK_PREFIX . $id );
		delete_transient( self::PARK_PREFIX . $id );
		if ( ! is_string( $domain ) || ! self::isNormalised( $domain ) || ! hash_equals( $id, self::id( $domain ) ) ) {
			return;
		}

		if ( self::resolves( $domain ) ) {
			set_transient( self::RESULT_PREFIX . $id, 'yes', DAY_IN_SECONDS );
			return;
		}

		/**
		 * Domain that must resolve when DNS works (checked before caching a "no").
		 *
		 * @param string $canary Canary domain.
		 */
		$canary = apply_filters( 'trustorder_mx_canary', self::CANARY );
		if ( ! is_string( $canary ) || '' === $canary ) {
			$canary = self::CANARY;
		}
		if ( ! self::resolves( $canary ) ) {
			Log::error( 'mx', [ 'code' => 'DNS_UNAVAILABLE' ] );
			return;
		}
		set_transient( self::RESULT_PREFIX . $id, 'no', HOUR_IN_SECONDS );
	}

	/**
	 * MX, else A or AAAA. Queried as an absolute name (trailing dot) so the
	 * resolver's search list is never appended.
	 *
	 * @param string $domain Domain.
	 */
	private static function resolves( string $domain ): bool {
		/**
		 * Short-circuit a DNS lookup (return a bool to use it).
		 *
		 * @param bool|null $result Null to resolve via DNS.
		 * @param string    $domain Domain being checked (also the canary).
		 */
		$pre = apply_filters( 'trustorder_pre_mx_lookup', null, $domain );
		if ( is_bool( $pre ) ) {
			return $pre;
		}
		$fqdn = rtrim( $domain, '.' ) . '.';

		return checkdnsrr( $fqdn, 'MX' ) || checkdnsrr( $fqdn, 'A' ) || checkdnsrr( $fqdn, 'AAAA' );
	}

	/**
	 * Only already-normalised domains are looked up.
	 *
	 * @param string $domain Domain.
	 */
	private static function isNormalised( string $domain ): bool {
		return Domain::normalize( $domain ) === $domain;
	}

	/**
	 * The domain's id in job args and transient names (no raw domain).
	 *
	 * @param string $domain Domain.
	 */
	private static function id( string $domain ): string {
		return hash( 'sha256', $domain );
	}
}
