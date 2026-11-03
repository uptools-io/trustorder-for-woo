<?php
/**
 * GET|POST /settings.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Rest;

use LightweightPlugins\TrustOrder\Admin\Choices;
use LightweightPlugins\TrustOrder\Admin\SiteState;
use LightweightPlugins\TrustOrder\Api\ApiKey;
use LightweightPlugins\TrustOrder\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Reads and writes `trustorder_settings`. The API key is write-only: it is
 * accepted here but only its prefix is ever returned. Invalid input is
 * refused as a whole (nothing is saved).
 */
final class SettingsController {

	private const LISTS = [ 'hidden_gateways', 'fallback_gateways', 'pickup_methods' ];

	/**
	 * Register the routes.
	 */
	public static function registerRoutes(): void {
		Routes::add( '/settings', 'GET', [ self::class, 'show' ] );
		Routes::add( '/settings', 'POST', [ self::class, 'save' ] );
	}

	/**
	 * GET /settings.
	 */
	public static function show(): WP_REST_Response {
		return new WP_REST_Response( self::view() );
	}

	/**
	 * POST /settings (partial update).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save( WP_REST_Request $request ) {
		$saved = self::apply( $request->get_params() );

		return $saved instanceof WP_Error ? $saved : new WP_REST_Response( self::view() );
	}

	/**
	 * Validate and store a partial update (shared with the WP-CLI commands):
	 * the whole patch is refused when any field is invalid.
	 *
	 * @param array<string, mixed> $params Fields: api_key, strictness, hidden_gateways, fallback_gateways, pickup_methods, enabled.
	 * @return true|WP_Error
	 */
	public static function apply( array $params ) {
		$patch = self::validate( $params );
		if ( $patch instanceof WP_Error ) {
			return $patch;
		}
		$gateways = self::checkGateways( $patch );
		if ( $gateways instanceof WP_Error ) {
			return $gateways;
		}
		Settings::update( $patch );

		return true;
	}

	/**
	 * Settings (masked key) and the choices for the lists.
	 *
	 * @return array<string, mixed>
	 */
	public static function view(): array {
		$s = Settings::get();

		return [
			'settings'         => SiteState::key() + [
				'strictness'        => $s['strictness'],
				'hidden_gateways'   => $s['hidden_gateways'],
				'fallback_gateways' => $s['fallback_gateways'],
				'pickup_methods'    => $s['pickup_methods'],
				'enabled'           => $s['enabled'],
			],
			'gateways'         => Choices::gateways( array_values( array_unique( array_merge( $s['hidden_gateways'], $s['fallback_gateways'] ) ) ) ),
			'shipping_methods' => Choices::shippingMethods(),
		];
	}

	/**
	 * Gateway lists, checked only when the patch touches one of them (a
	 * legacy conflict must not block unrelated saves): every id must be a
	 * registered gateway or already stored, and no id may be both hidden and
	 * a fallback (after the merge).
	 *
	 * @param array<string, mixed> $patch Validated patch.
	 * @return true|WP_Error
	 */
	private static function checkGateways( array $patch ) {
		if ( ! array_key_exists( 'hidden_gateways', $patch ) && ! array_key_exists( 'fallback_gateways', $patch ) ) {
			return true;
		}
		$stored = Settings::get();
		$known  = array_merge( Choices::gatewayIds(), $stored['hidden_gateways'], $stored['fallback_gateways'] );
		$lists  = [];
		foreach ( [ 'hidden_gateways', 'fallback_gateways' ] as $field ) {
			$ids = array_key_exists( $field, $patch ) ? Settings::sanitizeIds( $patch[ $field ] ) : $stored[ $field ];
			if ( [] !== array_diff( $ids, $known ) ) {
				return ApiError::invalid( 'trustorder_invalid_list', __( 'Unknown payment method.', 'trustorder-for-woo' ) );
			}
			$lists[ $field ] = $ids;
		}
		$both = array_values( array_intersect( $lists['hidden_gateways'], $lists['fallback_gateways'] ) );
		if ( [] !== $both ) {
			return ApiError::invalid(
				'trustorder_invalid_list',
				/* translators: %s: payment method id */
				sprintf( __( 'The payment method "%s" cannot be hidden and a fallback at the same time.', 'trustorder-for-woo' ), $both[0] )
			);
		}

		return true;
	}

	/**
	 * Known fields, type-checked; Settings::update() sanitises the values.
	 *
	 * @param array<string, mixed> $in Request params.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function validate( array $in ) {
		$patch = [];
		if ( array_key_exists( 'api_key', $in ) ) {
			$key = is_string( $in['api_key'] ) ? trim( $in['api_key'] ) : null;
			if ( null === $key || ( '' !== $key && ! ApiKey::isValid( $key ) ) ) {
				return ApiError::invalid( 'trustorder_invalid_key', __( 'This is not a valid TrustOrder API key. It starts with to_live_ or to_test_.', 'trustorder-for-woo' ) );
			}
			$patch['api_key'] = $key;
		}
		if ( array_key_exists( 'strictness', $in ) ) {
			if ( ! is_string( $in['strictness'] ) || ! in_array( $in['strictness'], Settings::STRICTNESS, true ) ) {
				return ApiError::invalid( 'trustorder_invalid_strictness', __( 'Unknown strictness.', 'trustorder-for-woo' ) );
			}
			$patch['strictness'] = $in['strictness'];
		}
		foreach ( self::LISTS as $field ) {
			if ( array_key_exists( $field, $in ) ) {
				if ( ! is_array( $in[ $field ] ) ) {
					return ApiError::invalid( 'trustorder_invalid_list', __( 'A list of ids was expected.', 'trustorder-for-woo' ) );
				}
				$patch[ $field ] = $in[ $field ];
			}
		}
		if ( array_key_exists( 'enabled', $in ) ) {
			if ( ! is_bool( $in['enabled'] ) ) {
				return ApiError::invalid( 'trustorder_invalid_enabled', __( 'The enabled flag must be true or false.', 'trustorder-for-woo' ) );
			}
			$patch['enabled'] = $in['enabled'];
		}

		return $patch;
	}
}
