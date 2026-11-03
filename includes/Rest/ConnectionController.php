<?php
/**
 * GET /connection, POST /connection/test.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Rest;

use LightweightPlugins\TrustOrder\Admin\SiteState;
use LightweightPlugins\TrustOrder\Api\Client;
use WP_Error;
use WP_REST_Response;

/**
 * Local state (no API call) and the connection test (`GET /me`).
 */
final class ConnectionController {

	private const ME_STRINGS = [ 'shop_name', 'domain', 'env', 'account_status' ];

	private const ME_INTS = [ 'daily_check_quota', 'checks_today' ];

	/**
	 * Register the routes.
	 */
	public static function registerRoutes(): void {
		Routes::add( '/connection', 'GET', [ self::class, 'state' ] );
		Routes::add( '/connection/test', 'POST', [ self::class, 'test' ] );
	}

	/**
	 * GET /connection.
	 */
	public static function state(): WP_REST_Response {
		return new WP_REST_Response( SiteState::overview() );
	}

	/**
	 * POST /connection/test.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function test() {
		if ( ! SiteState::key()['has_key'] ) {
			return ApiError::noKey();
		}
		$response = Client::fromSettings()->me();
		if ( ! $response->ok() ) {
			return ApiError::fromResponse( $response );
		}

		return new WP_REST_Response(
			[
				'me'        => self::me( $response->data() ),
				'tested_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			]
		);
	}

	/**
	 * Allow-listed `/me` fields (shop_id is internal and dropped).
	 *
	 * @param array<mixed> $data Response data.
	 * @return array<string, string|int|null>
	 */
	private static function me( array $data ): array {
		$out = [];
		foreach ( self::ME_STRINGS as $key ) {
			$out[ $key ] = is_string( $data[ $key ] ?? null ) ? sanitize_text_field( $data[ $key ] ) : null;
		}
		foreach ( self::ME_INTS as $key ) {
			$out[ $key ] = is_int( $data[ $key ] ?? null ) ? $data[ $key ] : null;
		}

		return $out;
	}
}
