<?php
/**
 * Which payment gateways a decision hides.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Checkout;

use LightweightPlugins\TrustOrder\Log;

/**
 * `block` hides the configured gateways (default `cod`), but only while the
 * buyer keeps a way to pay: at least one configured fallback gateway (or, with
 * no fallback configured, any gateway) must stay visible. Otherwise nothing is
 * hidden (fail-open) and HIDE_WOULD_EMPTY is recorded for the admin.
 */
final class HideRule {

	public const HIDE_WOULD_EMPTY = 'HIDE_WOULD_EMPTY';

	private const THROTTLE     = 'trustorder_hwe';
	private const THROTTLE_TTL = 600;

	/**
	 * Whether the warning was already handled in this request.
	 *
	 * @var bool
	 */
	private static bool $warned = false;

	/**
	 * Forget per-request state (tests).
	 */
	public static function reset(): void {
		self::$warned = false;
	}

	/**
	 * Gateway ids to remove.
	 *
	 * @param Decision             $decision  Decision.
	 * @param string[]             $available Gateway ids WooCommerce offers now.
	 * @param array<string, mixed> $settings  Settings::get().
	 * @return list<string>
	 *
	 * @phpstan-param list<string> $available
	 */
	public static function hidden( Decision $decision, array $available, array $settings ): array {
		if ( Decision::BLOCK !== $decision->verdict() || $decision->failedOpen() ) {
			return [];
		}

		$configured = is_array( $settings['hidden_gateways'] ?? null ) ? $settings['hidden_gateways'] : [];
		$hide       = array_values( array_intersect( $available, $configured ) );
		if ( [] === $hide ) {
			return [];
		}

		$remaining = array_values( array_diff( $available, $hide ) );
		$fallback  = is_array( $settings['fallback_gateways'] ?? null ) ? $settings['fallback_gateways'] : [];
		$payable   = [] === $fallback ? [] !== $remaining : [] !== array_intersect( $remaining, $fallback );

		if ( ! $payable ) {
			self::warn();

			return [];
		}

		return $hide;
	}

	/**
	 * Log HIDE_WOULD_EMPTY at most once per request and once per 10 minutes.
	 */
	private static function warn(): void {
		if ( self::$warned ) {
			return;
		}
		self::$warned = true;
		if ( false !== get_transient( self::THROTTLE ) ) {
			return;
		}
		set_transient( self::THROTTLE, 1, self::THROTTLE_TTL );
		Log::error( 'checkout', [ 'code' => self::HIDE_WOULD_EMPTY ] );
	}
}
