<?php
/**
 * POST /backfill/start, GET /backfill/status.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Rest;

use LightweightPlugins\TrustOrder\Signals\Backfill;
use LightweightPlugins\TrustOrder\Signals\SignalJob;
use WP_Error;
use WP_REST_Response;

/**
 * Starts (or resumes) the backfill for the current key's env and reports the
 * progress of both envs.
 */
final class BackfillController {

	/**
	 * Register the routes.
	 */
	public static function registerRoutes(): void {
		Routes::add( '/backfill/start', 'POST', [ self::class, 'start' ] );
		Routes::add( '/backfill/status', 'GET', [ self::class, 'status' ] );
	}

	/**
	 * POST /backfill/start. The env always comes from the saved key.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function start() {
		$env = SignalJob::env();
		if ( null === $env ) {
			return ApiError::noKey();
		}
		Backfill::start( $env );

		return self::status();
	}

	/**
	 * GET /backfill/status.
	 */
	public static function status(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'env'  => SignalJob::env(),
				'live' => Backfill::status( 'live' ),
				'test' => Backfill::status( 'test' ),
			]
		);
	}
}
