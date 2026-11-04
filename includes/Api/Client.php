<?php
/**
 * TrustOrder HTTP API client.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Api;

use LightweightPlugins\TrustOrder\Log;
use LightweightPlugins\TrustOrder\Settings;

/**
 * Thin wrapper over the WordPress HTTP API. Never throws: every failure comes
 * back as a Response, and is logged as an error class only (no key, no body).
 */
final class Client {

	public const DEFAULT_BASE = 'https://api.uptools.io/v1/trustorder';

	/**
	 * Total timeout for the checkout-time `/check` call (seconds).
	 */
	public const CHECK_TIMEOUT = 1.5;

	/**
	 * Timeout for admin and background calls (seconds).
	 */
	public const BACKGROUND_TIMEOUT = 10.0;

	/**
	 * API key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Base URL without trailing slash.
	 *
	 * @var string
	 */
	private string $base;

	/**
	 * Constructor.
	 *
	 * @param string $key  API key (`to_live_…` / `to_test_…`).
	 * @param string $base API base URL, e.g. DEFAULT_BASE.
	 */
	public function __construct( string $key, string $base ) {
		$this->key  = $key;
		$this->base = rtrim( $base, '/' );
	}

	/**
	 * Client for the stored key and the (filterable) default base.
	 *
	 * A filtered base that isn't allowed (see isAllowedBase()) is ignored: the
	 * default is used and `BAD_API_BASE` is logged.
	 *
	 * @return self
	 */
	public static function fromSettings(): self {
		$base = apply_filters( 'trustorder_api_base', self::DEFAULT_BASE );

		if ( ! is_string( $base ) || ! self::isAllowedBase( $base ) ) {
			Log::error( 'BAD_API_BASE' );
			$base = self::DEFAULT_BASE;
		}

		return new self( Settings::get()['api_key'], $base );
	}

	/**
	 * Whether the Bearer key may be sent to this base: HTTPS with a host, or
	 * plain HTTP to localhost / 127.0.0.1 (local testing only).
	 *
	 * @param string $base Base URL.
	 * @return bool
	 */
	public static function isAllowedBase( string $base ): bool {
		$parts = wp_parse_url( $base );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] );
		if ( 'https' === $scheme ) {
			return true;
		}

		return 'http' === $scheme && in_array( strtolower( $parts['host'] ), [ 'localhost', '127.0.0.1' ], true );
	}

	/**
	 * POST /check (1.5 s total timeout).
	 *
	 * @param array<string, mixed> $body CheckRequest.
	 * @return Response
	 */
	public function check( array $body ): Response {
		return $this->request( 'POST', '/check', '/check', $body, self::CHECK_TIMEOUT );
	}

	/**
	 * GET /me (connection test).
	 *
	 * @return Response
	 */
	public function me(): Response {
		return $this->request( 'GET', '/me', '/me', null, self::BACKGROUND_TIMEOUT );
	}

	/**
	 * PUT /signals/{order_ref}.
	 *
	 * @param string               $ref  Order ref.
	 * @param array<string, mixed> $body SignalBody.
	 * @return Response
	 */
	public function putSignal( string $ref, array $body ): Response {
		return $this->request( 'PUT', '/signals/' . rawurlencode( $ref ), '/signals/{ref}', $body, self::BACKGROUND_TIMEOUT );
	}

	/**
	 * DELETE /signals/{order_ref}.
	 *
	 * @param string $ref Order ref.
	 * @return Response
	 */
	public function deleteSignal( string $ref ): Response {
		return $this->request( 'DELETE', '/signals/' . rawurlencode( $ref ), '/signals/{ref}', null, self::BACKGROUND_TIMEOUT );
	}

	/**
	 * POST /signals/batch.
	 *
	 * @param list<array<string, mixed>> $rows Signals (each with order_ref), 1..500.
	 * @return Response
	 */
	public function batchSignals( array $rows ): Response {
		return $this->request( 'POST', '/signals/batch', '/signals/batch', [ 'signals' => $rows ], self::BACKGROUND_TIMEOUT );
	}

	/**
	 * POST /geo (country and postcode of one order, nothing else).
	 *
	 * @param array<string, string> $body GeoBody (country, postcode).
	 * @return Response
	 */
	public function postGeo( array $body ): Response {
		return $this->request( 'POST', '/geo', '/geo', $body, self::BACKGROUND_TIMEOUT );
	}

	/**
	 * PUT /exceptions.
	 *
	 * @param array<string, mixed> $body ExceptionBody (kind, identity_hash, action, note).
	 * @return Response
	 */
	public function putException( array $body ): Response {
		return $this->request( 'PUT', '/exceptions', '/exceptions', $body, self::BACKGROUND_TIMEOUT );
	}

	/**
	 * DELETE /exceptions/{kind}/{identity_hash}.
	 *
	 * @param string $kind email|phone|address.
	 * @param string $hash Identity hash.
	 * @return Response
	 */
	public function deleteException( string $kind, string $hash ): Response {
		$path = '/exceptions/' . rawurlencode( $kind ) . '/' . rawurlencode( $hash );

		return $this->request( 'DELETE', $path, '/exceptions/{kind}/{hash}', null, self::BACKGROUND_TIMEOUT );
	}

	/**
	 * GET /exceptions (one page).
	 *
	 * @param int         $limit  Page size (1..500).
	 * @param string|null $cursor Previous page's `next_cursor`.
	 * @return Response
	 */
	public function listExceptions( int $limit, ?string $cursor ): Response {
		$query = 'limit=' . $limit;
		if ( null !== $cursor && '' !== $cursor ) {
			$query .= '&cursor=' . rawurlencode( $cursor );
		}

		return $this->request( 'GET', '/exceptions?' . $query, '/exceptions', null, self::BACKGROUND_TIMEOUT );
	}

	/**
	 * Perform one request.
	 *
	 * @param string                    $method  HTTP method.
	 * @param string                    $path    Path (with query) under the base.
	 * @param string                    $route   Route template for logs (no ids).
	 * @param array<string, mixed>|null $body    JSON body or null.
	 * @param float                     $timeout Total timeout in seconds.
	 * @return Response
	 */
	private function request( string $method, string $path, string $route, ?array $body, float $timeout ): Response {
		if ( '' === $this->key ) {
			return Response::failure( Response::NO_KEY );
		}

		$args = [
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 0,
			'headers'     => [
				'Authorization' => 'Bearer ' . $this->key,
				'Accept'        => 'application/json',
			],
		];

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}

		$raw      = wp_remote_request( $this->base . $path, $args );
		$response = is_wp_error( $raw )
			? Response::failure( self::classify( $raw->get_error_message() ) )
			: Response::fromHttp( (int) wp_remote_retrieve_response_code( $raw ), wp_remote_retrieve_body( $raw ) );

		// A 404 on DELETE means "already gone": the callers treat it as success.
		if ( ! $response->ok() && ! ( 'DELETE' === $method && 404 === $response->status() ) ) {
			Log::error(
				'api',
				[
					'code'   => $response->errorCode(),
					'status' => $response->status(),
					'route'  => $route,
				]
			);
		}

		return $response;
	}

	/**
	 * TIMEOUT for cURL error 28 / "timed out", NETWORK otherwise.
	 *
	 * @param string $message WP_Error message (never logged).
	 * @return string
	 */
	private static function classify( string $message ): string {
		if ( false !== stripos( $message, 'cURL error 28' ) || false !== stripos( $message, 'timed out' ) ) {
			return Response::TIMEOUT;
		}

		return Response::NETWORK;
	}
}
