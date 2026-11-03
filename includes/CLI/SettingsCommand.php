<?php
/**
 * WP-CLI command group: trustorder settings.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\CLI;

use LightweightPlugins\TrustOrder\Admin\SiteState;
use LightweightPlugins\TrustOrder\Rest\SettingsController;
use LightweightPlugins\TrustOrder\Settings;
use WP_Error;

/**
 * Reads and changes the plugin settings through the same validation as the
 * admin screen (SettingsController::apply). The API key is write-only and is
 * never taken from an argument: it comes only from piped STDIN.
 */
class SettingsCommand {

	private const KEYS = [
		'api-key'           => 'api_key',
		'strictness'        => 'strictness',
		'hidden-gateways'   => 'hidden_gateways',
		'fallback-gateways' => 'fallback_gateways',
		'pickup-methods'    => 'pickup_methods',
		'enabled'           => 'enabled',
	];

	private const KEY_MESSAGE = 'The API key is read only from piped STDIN (a terminal or no input within 5 seconds is refused): printf \'%s\' "$KEY" | wp trustorder settings set api-key -';

	private const LISTS = [ 'hidden-gateways', 'fallback-gateways', 'pickup-methods' ];

	/**
	 * Shows the settings (the API key only as its 12-character prefix).
	 *
	 * ## OPTIONS
	 *
	 * [<key>]
	 * : One of api-key, strictness, hidden-gateways, fallback-gateways, pickup-methods, enabled.
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp trustorder settings get
	 *     wp trustorder settings get strictness
	 *
	 * @param array<int, string>   $args  Positional args.
	 * @param array<string, mixed> $assoc Associative args.
	 */
	public function get( array $args, array $assoc ): void {
		$format = Output::format( $assoc );
		$all    = $this->current();
		if ( [] === $args ) {
			$this->printAll( $all, $format );
			return;
		}
		if ( ! array_key_exists( $args[0], $all ) ) {
			Output::fail( 'Unknown setting.' );
		}
		\WP_CLI::line( 'json' === $format ? (string) wp_json_encode( $all[ $args[0] ] ) : Output::text( $all[ $args[0] ] ) );
	}

	/**
	 * Changes one setting.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : One of api-key, strictness, hidden-gateways, fallback-gateways, pickup-methods, enabled.
	 *
	 * [<value>]
	 * : lenient|normal|strict; a comma-separated list of ids; true|false. For api-key use `-` and pipe the key on STDIN (never as an argument); an empty STDIN removes the key.
	 *
	 * ## EXAMPLES
	 *
	 *     wp trustorder settings set strictness strict
	 *     wp trustorder settings set hidden-gateways cod,bacs
	 *     op read "op://vault/trustorder/key" | wp trustorder settings set api-key -
	 *
	 * @param array<int, string>   $args  Positional args.
	 * @param array<string, mixed> $assoc Associative args.
	 */
	public function set( array $args, array $assoc ): void {
		if ( isset( $assoc['api-key'] ) || isset( $assoc['api_key'] ) ) {
			Output::fail( self::KEY_MESSAGE );
		}
		$name = $args[0] ?? '';
		if ( ! isset( self::KEYS[ $name ] ) ) {
			Output::fail( 'Unknown setting.' );
		}
		$raw = 'api-key' === $name ? $this->keyValue( $args[1] ?? null ) : ( $args[1] ?? null );
		if ( null === $raw ) {
			Output::fail( 'A value is required.' );
		}
		$value = $this->parse( $name, $raw );
		if ( $value instanceof WP_Error ) {
			Output::wpError( $value );
		}
		$saved = SettingsController::apply( [ self::KEYS[ $name ] => $value ] );
		if ( $saved instanceof WP_Error ) {
			Output::wpError( $saved );
		}
		\WP_CLI::success( sprintf( '%s = %s', $name, Output::text( $this->current()[ $name ] ) ) );
	}

	/**
	 * The text piped on STDIN; null when STDIN is a terminal or nothing
	 * arrives within 5 seconds (never blocks).
	 */
	protected function stdin(): ?string {
		if ( ! defined( 'STDIN' ) || ( function_exists( 'posix_isatty' ) && posix_isatty( STDIN ) ) ) {
			return null;
		}
		$read   = [ STDIN ];
		$write  = null;
		$except = null;
		if ( 1 !== stream_select( $read, $write, $except, 5 ) ) {
			return null;
		}
		$text = stream_get_contents( STDIN );

		return false === $text ? null : $text;
	}

	/**
	 * The key text, only from piped STDIN (positional `-`); empty = remove.
	 *
	 * @param string|null $given Positional value (must be absent or `-`).
	 */
	private function keyValue( ?string $given ): string {
		if ( null !== $given && '-' !== $given ) {
			Output::fail( self::KEY_MESSAGE );
		}
		$text = $this->stdin();
		if ( null === $text ) {
			Output::fail( self::KEY_MESSAGE );
		}

		return trim( $text );
	}

	/**
	 * CLI text to the typed value the shared validation expects.
	 *
	 * @param string $name Setting name.
	 * @param string $raw  Text.
	 * @return mixed|WP_Error
	 */
	private function parse( string $name, string $raw ) {
		if ( in_array( $name, self::LISTS, true ) ) {
			return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), static fn( string $id ): bool => '' !== $id ) );
		}
		if ( 'enabled' === $name ) {
			$bool = filter_var( $raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

			return null === $bool ? new WP_Error( 'trustorder_invalid_enabled', 'Use true or false.' ) : $bool;
		}

		return $raw;
	}

	/**
	 * Current settings under their CLI names (key: prefix only).
	 *
	 * @return array<string, mixed>
	 */
	private function current(): array {
		$s = Settings::get();

		return [
			'api-key'           => SiteState::key()['key_prefix'],
			'strictness'        => $s['strictness'],
			'hidden-gateways'   => $s['hidden_gateways'],
			'fallback-gateways' => $s['fallback_gateways'],
			'pickup-methods'    => $s['pickup_methods'],
			'enabled'           => $s['enabled'],
		];
	}

	/**
	 * All settings.
	 *
	 * @param array<string, mixed> $all    Settings.
	 * @param string               $format table|json.
	 */
	private function printAll( array $all, string $format ): void {
		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $all ) );
			return;
		}
		$rows = [];
		foreach ( $all as $name => $value ) {
			$rows[] = [
				'setting' => $name,
				'value'   => Output::text( $value ),
			];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'setting', 'value' ] );
	}
}
