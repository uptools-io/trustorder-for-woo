<?php
/**
 * WP-CLI command group: trustorder exceptions.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\CLI;

use LightweightPlugins\TrustOrder\Rest\ExceptionsController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Always-allow / always-deny exceptions, through the same services as the
 * admin screen. The value you type is hashed locally; only the hash is sent
 * and nothing identifying is printed or stored.
 */
final class ExceptionsCommand {

	/**
	 * Lists the exceptions (id, kind, rule, note, date; never the values).
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Rows per page, 1-100 (default 50).
	 *
	 * [--cursor=<cursor>]
	 * : The next-page cursor printed by the previous call.
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>   $args  Positional args.
	 * @param array<string, mixed> $assoc Associative args.
	 */
	public function list_( array $args, array $assoc ): void {
		$format = Output::format( $assoc );
		$params = [];
		if ( array_key_exists( 'limit', $assoc ) ) {
			if ( ! is_string( $assoc['limit'] ) || 1 !== preg_match( '/^[1-9][0-9]{0,2}$/D', $assoc['limit'] ) ) {
				Output::fail( '--limit must be a whole number from 1 to 100.' );
			}
			$params['limit'] = $assoc['limit'];
		}
		if ( isset( $assoc['cursor'] ) && is_string( $assoc['cursor'] ) ) {
			$params['cursor'] = $assoc['cursor'];
		}
		$data = $this->data( ExceptionsController::index( $this->requestFor( 'GET', $params ) ) );
		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $data ) );
			return;
		}
		Output::rows( $data['items'], [ 'id', 'kind', 'action', 'note', 'created_at' ], 'table' );
		if ( is_string( $data['next_cursor'] ) ) {
			\WP_CLI::log( 'Next page: --cursor=' . $data['next_cursor'] );
		}
	}

	/**
	 * Adds (or replaces) an exception.
	 *
	 * ## OPTIONS
	 *
	 * --kind=<kind>
	 * : email, phone or address.
	 *
	 * --action=<action>
	 * : allow or deny.
	 *
	 * [--value=<value>]
	 * : The e-mail address or phone number (kind email or phone).
	 *
	 * [--country=<country>]
	 * : Two-letter country code (kind address; for a phone number the region of a national number).
	 *
	 * [--postcode=<postcode>]
	 * : Postcode (kind address).
	 *
	 * [--address=<address>]
	 * : Street and house number (kind address).
	 *
	 * [--address-2=<address-2>]
	 * : Second address line (kind address).
	 *
	 * [--note=<note>]
	 * : Internal note, up to 500 characters.
	 *
	 * ## EXAMPLES
	 *
	 *     wp trustorder exceptions add --kind=email --value=regular@example.com --action=allow --note="regular customer"
	 *
	 * @param array<int, string>   $args  Positional args.
	 * @param array<string, mixed> $assoc Associative args.
	 */
	public function add( array $args, array $assoc ): void {
		$this->data( ExceptionsController::create( $this->request( 'POST', $assoc ) ) );
		\WP_CLI::success( sprintf( 'Exception saved (%s, %s).', (string) $assoc['kind'], (string) $assoc['action'] ) );
	}

	/**
	 * Removes the exception of a value.
	 *
	 * ## OPTIONS
	 *
	 * --kind=<kind>
	 * : email, phone or address.
	 *
	 * [--value=<value>]
	 * : The e-mail address or phone number (kind email or phone).
	 *
	 * [--country=<country>]
	 * : Two-letter country code (kind address, or the region of a national phone number).
	 *
	 * [--postcode=<postcode>]
	 * : Postcode (kind address).
	 *
	 * [--address=<address>]
	 * : Street and house number (kind address).
	 *
	 * [--address-2=<address-2>]
	 * : Second address line (kind address).
	 *
	 * @param array<int, string>   $args  Positional args.
	 * @param array<string, mixed> $assoc Associative args.
	 */
	public function remove( array $args, array $assoc ): void {
		$this->data( ExceptionsController::remove( $this->request( 'DELETE', $assoc ) ) );
		\WP_CLI::success( 'Exception removed.' );
	}

	/**
	 * The controller's request for the CLI flags.
	 *
	 * @param string               $method HTTP method of the equivalent REST call.
	 * @param array<string, mixed> $assoc  Associative args.
	 */
	private function request( string $method, array $assoc ): WP_REST_Request {
		$map    = [
			'kind'      => 'kind',
			'action'    => 'action',
			'value'     => 'value',
			'country'   => 'country',
			'postcode'  => 'postcode',
			'address'   => 'address_1',
			'address-2' => 'address_2',
			'note'      => 'note',
		];
		$params = [];
		foreach ( $map as $flag => $field ) {
			if ( array_key_exists( $flag, $assoc ) ) {
				$params[ $field ] = $assoc[ $flag ];
			}
		}

		return $this->requestFor( $method, $params );
	}

	/**
	 * A REST request carrying the params (set_param: the constructor's third
	 * argument holds route attributes, not params).
	 *
	 * @param string               $method HTTP method.
	 * @param array<string, mixed> $params Params.
	 */
	private function requestFor( string $method, array $params ): WP_REST_Request {
		$request = new WP_REST_Request( $method, '/trustorder/v1/exceptions' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	/**
	 * Unwrap a controller result or exit with its error.
	 *
	 * @param WP_REST_Response|WP_Error $result Controller result.
	 * @return array<string, mixed>
	 */
	private function data( $result ): array {
		if ( $result instanceof WP_Error ) {
			Output::wpError( $result );
		}
		$data = $result->get_data();

		return is_array( $data ) ? $data : [];
	}
}
