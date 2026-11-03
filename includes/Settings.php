<?php
/**
 * Plugin settings model (one autoloaded array option).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder;

use LightweightPlugins\TrustOrder\Api\ApiKey;

/**
 * Reads, sanitises and stores `trustorder_settings`.
 *
 * @phpstan-type SettingsArray array{api_key: string, strictness: string, hidden_gateways: list<string>, fallback_gateways: list<string>, pickup_methods: list<string>, enabled: bool}
 */
final class Settings {

	public const OPTION = 'trustorder_settings';

	public const STRICTNESS = [ 'lenient', 'normal', 'strict' ];

	/**
	 * Shipping methods with no delivery address (no address hash is sent).
	 *
	 * Exact method ids, verified from each plugin's source:
	 * - `local_pickup`: WooCommerce classic local pickup (WC_Shipping_Local_Pickup).
	 * - `pickup_location`: WooCommerce block-checkout local pickup (Blocks\Shipping\PickupLocation).
	 * - `vp_pont`: Hungarian Pickup Points for WooCommerce (WC_Shipping_Pont).
	 * - `packetery_shipping_method`: Packeta pickup-point method (Packetery\Module\ShippingMethod).
	 */
	public const DEFAULT_PICKUP_METHODS = [ 'local_pickup', 'pickup_location', 'vp_pont', 'packetery_shipping_method' ];

	private const LIST_FIELDS = [ 'hidden_gateways', 'fallback_gateways', 'pickup_methods' ];

	/**
	 * Default settings.
	 *
	 * @return SettingsArray
	 */
	public static function defaults(): array {
		return [
			'api_key'           => '',
			'strictness'        => 'normal',
			'hidden_gateways'   => [ 'cod' ],
			'fallback_gateways' => [],
			'pickup_methods'    => self::DEFAULT_PICKUP_METHODS,
			'enabled'           => true,
		];
	}

	/**
	 * Current settings, sanitised over the defaults.
	 *
	 * @return SettingsArray
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, [] );

		return self::sanitize( is_array( $stored ) ? $stored : [], self::defaults() );
	}

	/**
	 * Merge the given fields over the current settings, sanitise and store.
	 *
	 * Unknown keys are ignored; a malformed API key keeps the previous key;
	 * an invalid strictness becomes `normal`.
	 *
	 * @param array<string, mixed> $in Partial settings.
	 * @return SettingsArray
	 */
	public static function update( array $in ): array {
		$clean = self::sanitize( $in, self::get() );
		update_option( self::OPTION, $clean, true );

		return $clean;
	}

	/**
	 * Sanitise input fields over a known-good base.
	 *
	 * @param array<string, mixed> $in   Raw fields.
	 * @param array                $base Clean base.
	 * @phpstan-param SettingsArray $base
	 * @return SettingsArray
	 */
	private static function sanitize( array $in, array $base ): array {
		$out = $base;

		if ( array_key_exists( 'api_key', $in ) ) {
			$out['api_key'] = self::sanitize_key_value( $in['api_key'], $base['api_key'] );
		}

		if ( array_key_exists( 'strictness', $in ) ) {
			$out['strictness'] = is_string( $in['strictness'] ) && in_array( $in['strictness'], self::STRICTNESS, true ) ? $in['strictness'] : 'normal';
		}

		foreach ( self::LIST_FIELDS as $field ) {
			if ( array_key_exists( $field, $in ) && is_array( $in[ $field ] ) ) {
				$out[ $field ] = self::sanitizeIds( $in[ $field ] );
			}
		}

		if ( array_key_exists( 'enabled', $in ) ) {
			$out['enabled'] = self::to_bool( $in['enabled'], $base['enabled'] );
		}

		return $out;
	}

	/**
	 * Trimmed key if well-formed, '' if blank, otherwise the previous key.
	 *
	 * @param mixed  $raw      Raw value.
	 * @param string $previous Previous (clean) key.
	 * @return string
	 */
	private static function sanitize_key_value( $raw, string $previous ): string {
		if ( ! is_string( $raw ) ) {
			return $previous;
		}

		$key = trim( $raw );
		if ( '' === $key ) {
			return '';
		}

		return ApiKey::isValid( $key ) ? $key : $previous;
	}

	/**
	 * Gateway / shipping-method ids: valid ids only (case kept, see isId()),
	 * trimmed, unique (case-sensitive, like WooCommerce's own lookups).
	 *
	 * @param array<mixed> $ids Raw ids.
	 * @return list<string>
	 */
	public static function sanitizeIds( array $ids ): array {
		$out = [];
		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) ) {
				continue;
			}
			$id = trim( (string) $id );
			if ( self::isId( $id ) && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * Whether a string is a usable gateway / shipping-method id. WooCommerce ids
	 * may be mixed case, so sanitize_key() (which lowercases) is not used.
	 *
	 * @param string $id Id.
	 */
	public static function isId( string $id ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_\-]+$/', $id );
	}

	/**
	 * Loose boolean (true/false, 1/0, "yes"/"no", "true"/"false", "on"/"off").
	 *
	 * @param mixed $raw      Raw value.
	 * @param bool  $previous Fallback for unrecognised values.
	 * @return bool
	 */
	private static function to_bool( $raw, bool $previous ): bool {
		if ( is_bool( $raw ) ) {
			return $raw;
		}

		$bool = filter_var( $raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

		return null === $bool ? $previous : $bool;
	}
}
