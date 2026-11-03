<?php
/**
 * Error logging without personal data.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder;

/**
 * Writes error classes to the WooCommerce log (source `trustorder`) and keeps
 * the last one in a non-autoloaded option for the admin screen.
 *
 * Only allow-listed context keys with short, token-like scalar values pass;
 * anything else (e-mails, hashes, keys, free text) is dropped.
 */
final class Log {

	public const SOURCE = 'trustorder';

	public const LAST_ERROR_OPTION = 'trustorder_last_error';

	/**
	 * Seconds during which an identical class + code isn't rewritten.
	 */
	public const LAST_ERROR_THROTTLE = 60;

	private const ALLOWED = [ 'code', 'status', 'route' ];

	private const TOKEN = '/^[A-Za-z0-9_\/{}.-]{1,32}$/D';

	/**
	 * Log an error class.
	 *
	 * @param string               $class   Error class, e.g. `api`.
	 * @param array<string, mixed> $context Context; only `code`, `status`, `route` scalars are kept.
	 */
	public static function error( string $class, array $context = [] ): void {
		$class = self::is_token( $class ) ? $class : 'unknown';
		$clean = self::filter( $context );

		self::remember( $class, $clean );

		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$parts = [ 'TrustOrder error: ' . $class ];
		foreach ( $clean as $k => $v ) {
			$parts[] = $k . '=' . $v;
		}

		wc_get_logger()->error( implode( ' ', $parts ), array_merge( [ 'source' => self::SOURCE ], $clean ) );
	}

	/**
	 * Store the last error, at most one write per minute for a repeated
	 * class + code (an outage would otherwise write on every failed checkout).
	 *
	 * @param string                    $class Error class.
	 * @param array<string, int|string> $clean Filtered context.
	 */
	private static function remember( string $class, array $clean ): void {
		$now  = time();
		$last = get_option( self::LAST_ERROR_OPTION );

		if ( is_array( $last )
			&& ( $last['class'] ?? null ) === $class
			&& ( $last['code'] ?? null ) === ( $clean['code'] ?? null )
			&& is_int( $last['at'] ?? null )
			&& $now - $last['at'] < self::LAST_ERROR_THROTTLE
		) {
			return;
		}

		update_option( self::LAST_ERROR_OPTION, array_merge( [ 'class' => $class ], $clean, [ 'at' => $now ] ), false );
	}

	/**
	 * Keep allow-listed, token-like scalars.
	 *
	 * @param array<string, mixed> $context Raw context.
	 * @return array<string, int|string>
	 */
	private static function filter( array $context ): array {
		$out = [];
		foreach ( self::ALLOWED as $key ) {
			if ( ! array_key_exists( $key, $context ) ) {
				continue;
			}
			$value = $context[ $key ];
			if ( is_int( $value ) ) {
				$out[ $key ] = $value;
			} elseif ( is_string( $value ) && self::is_token( $value ) ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Short identifier-like string (no `@`, spaces or newlines).
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_token( string $value ): bool {
		return 1 === preg_match( self::TOKEN, $value );
	}
}
