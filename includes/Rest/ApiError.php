<?php
/**
 * REST errors.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Rest;

use LightweightPlugins\TrustOrder\Api\Response;
use WP_Error;

/**
 * WP_Error builders. Messages are fixed strings: no request data, no API
 * problem `detail`, no key.
 */
final class ApiError {

	/**
	 * Client-side validation error (HTTP 400).
	 *
	 * @param string $code    Error code (`trustorder_…`).
	 * @param string $message Human message.
	 */
	public static function invalid( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => 400 ] );
	}

	/**
	 * No API key saved.
	 */
	public static function noKey(): WP_Error {
		return self::invalid( 'trustorder_no_key', __( 'Save an API key first.', 'trustorder-for-woo' ) );
	}

	/**
	 * A failed TrustOrder API call, as a 502 carrying the API error code.
	 *
	 * @param Response $response Failed response.
	 */
	public static function fromResponse( Response $response ): WP_Error {
		$code = (string) $response->errorCode();

		return new WP_Error(
			'trustorder_api_error',
			__( 'The TrustOrder service returned an error.', 'trustorder-for-woo' ),
			[
				'status'     => 502,
				'api_code'   => 1 === preg_match( '/^[A-Z0-9_]{1,32}$/D', $code ) ? $code : 'HTTP_' . $response->status(),
				'api_status' => $response->status(),
			]
		);
	}
}
