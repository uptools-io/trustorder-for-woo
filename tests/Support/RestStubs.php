<?php
/**
 * In-memory WordPress REST API polyfills: route registry and a dispatcher
 * that applies the permission callback like WP_REST_Server does.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

// phpcs:ignoreFile -- Test-only global polyfills that mirror WordPress core signatures.

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal WP_REST_Response.
	 */
	class WP_REST_Response {
		/** @var mixed */
		public $data;
		/** @var int */
		public $status;

		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = (int) $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status() {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	/**
	 * Method constants only.
	 */
	class WP_REST_Server {
		const READABLE  = 'GET';
		const CREATABLE = 'POST';
		const DELETABLE = 'DELETE';
	}
}

function register_rest_route( $route_namespace, $route, $args = [], $override = false ) {
	$sets = isset( $args['callback'] ) ? [ $args ] : $args;
	foreach ( $sets as $set ) {
		foreach ( array_map( 'trim', explode( ',', (string) $set['methods'] ) ) as $method ) {
			$GLOBALS['trustorder_test']['routes'][ '/' . $route_namespace . $route ][ $method ] = $set;
		}
	}
	return true;
}

function rest_ensure_response( $response ) {
	return $response instanceof WP_REST_Response || $response instanceof WP_Error ? $response : new WP_REST_Response( $response );
}

/**
 * Dispatch like WP_REST_Server: unknown route → 404, permission false → 403.
 *
 * @param string               $method HTTP method.
 * @param string               $route  Full route, e.g. /trustorder/v1/settings.
 * @param array<string, mixed> $params Request params.
 * @return WP_REST_Response|WP_Error
 */
function trustorder_test_rest( string $method, string $route, array $params = [] ) {
	$set = $GLOBALS['trustorder_test']['routes'][ $route ][ $method ] ?? null;
	if ( null === $set ) {
		return new WP_Error( 'rest_no_route', 'No route', [ 'status' => 404 ] );
	}
	$request = new WP_REST_Request( $method, $route, $params );
	$allowed = call_user_func( $set['permission_callback'], $request );
	if ( true !== $allowed ) {
		return new WP_Error( 'rest_forbidden', 'Forbidden', [ 'status' => 403 ] );
	}
	return rest_ensure_response( call_user_func( $set['callback'], $request ) );
}

function wp_strip_all_tags( $text, $remove_breaks = false ) {
	return trim( strip_tags( (string) $text ) );
}

function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
	$GLOBALS['trustorder_test']['submenus'][] = compact( 'parent_slug', 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback' );
	return array_key_exists( 'submenu_hook', $GLOBALS['trustorder_test'] ) ? $GLOBALS['trustorder_test']['submenu_hook'] : 'woocommerce_page_' . $menu_slug;
}

function wp_add_privacy_policy_content( $plugin_name, $policy_text ) {
	$GLOBALS['trustorder_test']['privacy'][] = [ $plugin_name, $policy_text ];
}

function wp_kses_post( $data ) {
	return (string) $data;
}
