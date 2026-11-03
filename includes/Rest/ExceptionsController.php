<?php
/**
 * GET|POST|DELETE /exceptions.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Rest;

use LightweightPlugins\TrustOrder\Api\Client;
use LightweightPlugins\TrustOrder\Admin\SiteState;
use LightweightPlugins\TrustOrder\Checkout\CacheGeneration;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Shop allow/deny exceptions, kept by the backend only. The plugin hashes the
 * typed value and forwards the hash; the list never contains identities.
 */
final class ExceptionsController {

	public const PAGE = 50;

	public const MAX_PAGE = 100;

	public const NOTE_MAX = 500;

	private const ACTIONS = [ 'allow', 'deny' ];

	/**
	 * Register the routes.
	 */
	public static function registerRoutes(): void {
		Routes::add( '/exceptions', 'GET', [ self::class, 'index' ] );
		Routes::add( '/exceptions', 'POST', [ self::class, 'create' ] );
		Routes::add( '/exceptions', 'DELETE', [ self::class, 'remove' ] );
	}

	/**
	 * GET /exceptions (one page).
	 *
	 * @param WP_REST_Request $request Request (limit, cursor).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function index( WP_REST_Request $request ) {
		if ( ! SiteState::key()['has_key'] ) {
			return ApiError::noKey();
		}
		$limit    = absint( $request->get_param( 'limit' ) );
		$limit    = 0 === $limit ? self::PAGE : min( $limit, self::MAX_PAGE );
		$cursor   = $request->get_param( 'cursor' );
		$cursor   = is_string( $cursor ) ? substr( sanitize_text_field( $cursor ), 0, 512 ) : null;
		$response = Client::fromSettings()->listExceptions( $limit, $cursor );
		if ( ! $response->ok() ) {
			return ApiError::fromResponse( $response );
		}
		$data = $response->data();
		$next = $data['next_cursor'] ?? null;

		return new WP_REST_Response(
			[
				'items'       => ExceptionItems::clean( is_array( $data['items'] ?? null ) ? $data['items'] : [] ),
				'next_cursor' => is_string( $next ) && '' !== $next ? $next : null,
			]
		);
	}

	/**
	 * POST /exceptions.
	 *
	 * @param WP_REST_Request $request Request (kind, value / address fields, action, note).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$params = $request->get_params();
		$action = $params['action'] ?? null;
		if ( ! is_string( $action ) || ! in_array( $action, self::ACTIONS, true ) ) {
			return ApiError::invalid( 'trustorder_invalid_action', __( 'Choose allow or deny.', 'trustorder-for-woo' ) );
		}
		$note = self::note( $params['note'] ?? '' );
		if ( null === $note ) {
			return ApiError::invalid( 'trustorder_invalid_note', __( 'The note can be at most 500 characters.', 'trustorder-for-woo' ) );
		}
		$id = self::identity( $params );
		if ( $id instanceof WP_Error ) {
			return $id;
		}
		$response = Client::fromSettings()->putException(
			[
				'kind'          => $id['kind'],
				'identity_hash' => $id['hash'],
				'action'        => $action,
				'note'          => $note,
			]
		);

		if ( ! $response->ok() ) {
			return ApiError::fromResponse( $response );
		}
		CacheGeneration::bump();

		return new WP_REST_Response( [ 'saved' => true ], 201 );
	}

	/**
	 * DELETE /exceptions (the same identity fields as the create call).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function remove( WP_REST_Request $request ) {
		$id = self::identity( $request->get_params() );
		if ( $id instanceof WP_Error ) {
			return $id;
		}
		$response = Client::fromSettings()->deleteException( $id['kind'], $id['hash'] );
		if ( 404 === $response->status() ) {
			return new WP_Error( 'trustorder_exception_not_found', __( 'There is no exception for this value.', 'trustorder-for-woo' ), [ 'status' => 404 ] );
		}

		if ( ! $response->ok() ) {
			return ApiError::fromResponse( $response );
		}
		CacheGeneration::bump();

		return new WP_REST_Response( [ 'deleted' => true ] );
	}

	/**
	 * Hashed identity, after the key check.
	 *
	 * @param array<string, mixed> $params Params.
	 * @return array{kind: string, hash: string}|WP_Error
	 */
	private static function identity( array $params ) {
		if ( ! SiteState::key()['has_key'] ) {
			return ApiError::noKey();
		}

		return IdentityInput::hash( $params );
	}

	/**
	 * Single-line plain note, or null when too long / not a string.
	 *
	 * @param mixed $raw Raw note.
	 */
	private static function note( $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$note = sanitize_text_field( $raw );

		return mb_strlen( $note ) <= self::NOTE_MAX ? $note : null;
	}
}
