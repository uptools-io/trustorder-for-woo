<?php
/**
 * Shared output and error helpers of the WP-CLI commands.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\CLI;

use LightweightPlugins\TrustOrder\Api\Response;
use LightweightPlugins\TrustOrder\Signals\SignalState;
use WP_Error;

/**
 * Formats results and turns failures into exit status 1 with an error code
 * or a fixed message only (never request data, identities or the key).
 */
final class Output {

	public const FORMATS = [ 'table', 'json' ];

	/**
	 * The requested --format, validated.
	 *
	 * @param array<string, mixed> $assoc Associative args.
	 */
	public static function format( array $assoc ): string {
		$format = $assoc['format'] ?? 'table';
		if ( ! is_string( $format ) || ! in_array( $format, self::FORMATS, true ) ) {
			self::fail( 'Unknown format. Use table or json.' );
		}

		return $format;
	}

	/**
	 * Exit with status 1.
	 *
	 * @param string $message Fixed message or error code.
	 * @phpstan-return never
	 */
	public static function fail( string $message ): void {
		\WP_CLI::error( $message );
	}

	/**
	 * Exit with a REST-layer error: the API code when there is one, else the
	 * validation code and message.
	 *
	 * @param WP_Error $error Error from a shared controller.
	 * @phpstan-return never
	 */
	public static function wpError( WP_Error $error ): void {
		$data = $error->get_error_data();
		$api  = is_array( $data ) && isset( $data['api_code'] ) && is_string( $data['api_code'] ) ? $data['api_code'] : null;

		self::fail( null !== $api ? $api : $error->get_error_code() . ': ' . $error->get_error_message() );
	}

	/**
	 * Exit with the (sanitised) code of a failed API response.
	 *
	 * @param Response $response Failed response.
	 * @phpstan-return never
	 */
	public static function apiError( Response $response ): void {
		self::fail( SignalState::errorCode( $response->errorCode(), $response->status() ) );
	}

	/**
	 * Key/value result: a two-column table (nested data flattened to dotted
	 * keys) or a JSON object.
	 *
	 * @param array<string, mixed> $data   Data.
	 * @param string               $format table|json.
	 */
	public static function pairs( array $data, string $format ): void {
		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $data ) );
			return;
		}
		$rows = [];
		foreach ( self::flatten( $data ) as $key => $value ) {
			$rows[] = [
				'field' => $key,
				'value' => self::text( $value ),
			];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'field', 'value' ] );
	}

	/**
	 * A list of rows as a table or JSON.
	 *
	 * @param list<array<string, mixed>> $rows   Rows.
	 * @param array<int, string>         $fields Columns.
	 * @param string                     $format table|json.
	 */
	public static function rows( array $rows, array $fields, string $format ): void {
		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $rows ) );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', array_map( static fn( array $r ): array => array_map( [ self::class, 'text' ], $r ), $rows ), $fields );
	}

	/**
	 * Dotted keys for nested arrays (lists stay values).
	 *
	 * @param array<string, mixed> $data   Data.
	 * @param string               $prefix Key prefix.
	 * @return array<string, mixed>
	 */
	public static function flatten( array $data, string $prefix = '' ): array {
		$out = [];
		foreach ( $data as $key => $value ) {
			$name = $prefix . $key;
			if ( is_array( $value ) && ! array_is_list( $value ) ) {
				$out += self::flatten( $value, $name . '.' );
			} else {
				$out[ $name ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Cell text.
	 *
	 * @param mixed $value Value.
	 */
	public static function text( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( null === $value ) {
			return '-';
		}
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( [ self::class, 'text' ], $value ) );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}
}
