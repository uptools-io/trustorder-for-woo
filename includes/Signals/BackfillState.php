<?php
/**
 * Stored progress of the backfill.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Signals;

/**
 * One non-autoloaded option per env (`trustorder_backfill_{env}`): reading
 * (normalised), writing (allow-listed keys) and the public status shape.
 */
final class BackfillState {

	private const KEYS = [ 'running', 'processed', 'sent', 'failed', 'cursor', 'started_at', 'finished_at' ];

	/**
	 * Progress of one env, as shown to the admin and the CLI.
	 *
	 * @param string $env live|test.
	 * @return array{running: bool, processed: int, sent: int, failed: int, cursor: int, started_at: string|null, finished_at: string|null}
	 */
	public static function status( string $env ): array {
		$s = self::load( $env );

		return [
			'running'     => $s['running'],
			'processed'   => $s['processed'],
			'sent'        => $s['sent'],
			'failed'      => $s['failed'],
			'cursor'      => $s['cursor'],
			'started_at'  => $s['started_at'],
			'finished_at' => $s['finished_at'],
		];
	}

	/**
	 * Stored state, normalised.
	 *
	 * @param string $env Env.
	 * @return array{running: bool, processed: int, sent: int, failed: int, cursor: int, started_at: string|null, finished_at: string|null, attempts: int}
	 */
	public static function load( string $env ): array {
		$raw = get_option( self::option( $env ), [] );
		$raw = is_array( $raw ) ? $raw : [];
		$int = static fn( string $k ): int => isset( $raw[ $k ] ) && is_int( $raw[ $k ] ) ? max( 0, $raw[ $k ] ) : 0;
		$str = static fn( string $k ): ?string => isset( $raw[ $k ] ) && is_string( $raw[ $k ] ) ? $raw[ $k ] : null;

		return [
			'running'     => true === ( $raw['running'] ?? false ),
			'processed'   => $int( 'processed' ),
			'sent'        => $int( 'sent' ),
			'failed'      => $int( 'failed' ),
			'cursor'      => $int( 'cursor' ),
			'started_at'  => $str( 'started_at' ),
			'finished_at' => $str( 'finished_at' ),
			'attempts'    => $int( 'attempts' ),
		];
	}

	/**
	 * Store the state (not autoloaded).
	 *
	 * @param string               $env Env.
	 * @param array<string, mixed> $s   State.
	 */
	public static function save( string $env, array $s ): void {
		$keep = array_intersect_key( $s, array_flip( array_merge( self::KEYS, [ 'attempts' ] ) ) );
		update_option( self::option( $env ), $keep, false );
	}

	/**
	 * Option name.
	 *
	 * @param string $env Env.
	 */
	public static function option( string $env ): string {
		return 'trustorder_backfill_' . ( 'live' === $env ? 'live' : 'test' );
	}
}
