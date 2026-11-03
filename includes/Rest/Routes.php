<?php
/**
 * REST namespace of the admin app.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Rest;

/**
 * Registers the `trustorder/v1` routes. Every route is for shop managers only:
 * cookie auth with the standard `X-WP-Nonce` header (without a valid nonce
 * WordPress treats the request as logged out, so the capability check fails).
 */
final class Routes {

	public const NAMESPACE = 'trustorder/v1';

	/**
	 * Hook in.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'registerRoutes' ] );
	}

	/**
	 * `rest_api_init` callback.
	 */
	public static function registerRoutes(): void {
		SettingsController::registerRoutes();
		ConnectionController::registerRoutes();
		ExceptionsController::registerRoutes();
		BackfillController::registerRoutes();
	}

	/**
	 * Permission callback of every route.
	 */
	public static function canManage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Register one route (single method) with the shared permission check.
	 *
	 * @param string   $route    Route, e.g. `/settings`.
	 * @param string   $method   HTTP method.
	 * @param callable $callback Handler.
	 */
	public static function add( string $route, string $method, callable $callback ): void {
		register_rest_route(
			self::NAMESPACE,
			$route,
			[
				'methods'             => $method,
				'callback'            => $callback,
				'permission_callback' => [ self::class, 'canManage' ],
			]
		);
	}
}
