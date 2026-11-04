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
 * @phpstan-type SettingsArray array{api_key: string, strictness: string, hidden_gateways: list<string>, fallback_gateways: list<string>, pickup_methods: list<string>, enabled: bool, share_geo: bool}
 */
final class Settings {

	public const OPTION = 'trustorder_settings';

	/**
	 * Optional constant (wp-config.php, Bedrock .env) that holds the API key.
	 */
	public const KEY_CONSTANT = 'TRUSTORDER_API_KEY';

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
			'share_geo'         => true,
		];
	}

	/**
	 * Current settings, sanitised over the defaults.
	 *
	 * @return SettingsArray
	 */
	public static function get(): array {
		$out = self::stored();
		if ( self::keyLocked() ) {
			$out['api_key'] = self::constantKey();
		}

		return $out;
	}

	/**
	 * Whether the API key comes from the TRUSTORDER_API_KEY constant (then it
	 * cannot be changed from the admin, REST or WP-CLI). A constant that is
	 * not a non-empty string (e.g. null from Bedrock's env() of a missing
	 * variable) counts as not set, so the saved key stays in use.
	 */
	public static function keyLocked(): bool {
		if ( ! defined( self::KEY_CONSTANT ) ) {
			return false;
		}
		$raw = constant( self::KEY_CONSTANT );

		return is_string( $raw ) && '' !== trim( $raw );
	}

	/**
	 * The constant's key, trimmed; '' when it is not a valid key.
	 */
	private static function constantKey(): string {
		$raw = constant( self::KEY_CONSTANT );
		$key = is_string( $raw ) ? trim( $raw ) : '';

		return ApiKey::isValid( $key ) ? $key : '';
	}

	/**
	 * Stored settings, sanitised over the defaults (without the constant).
	 *
	 * @return SettingsArray
	 */
	private static function stored(): array {
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
		if ( self::keyLocked() ) {
			unset( $in['api_key'] );
		}
		$clean = self::sanitize( $in, self::stored() );
		update_option( self::OPTION, $clean, true );

		return self::get();
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

		foreach ( [ 'enabled', 'share_geo' ] as $flag ) {
			if ( array_key_exists( $flag, $in ) ) {
				$out[ $flag ] = self::to_bool( $in[ $flag ], $base[ $flag ] );
			}
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
