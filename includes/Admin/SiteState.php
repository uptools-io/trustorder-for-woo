<?php
/**
 * Local plugin state for the overview screen.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Admin;

use LightweightPlugins\TrustOrder\Api\ApiKey;
use LightweightPlugins\TrustOrder\Checkout\QuotaGuard;
use LightweightPlugins\TrustOrder\Identity\Unicode;
use LightweightPlugins\TrustOrder\Log;
use LightweightPlugins\TrustOrder\Settings;

/**
 * What the admin may see about the key and the environment. The key itself is
 * never returned, only its first 12 characters (`to_live_xxxx`).
 */
final class SiteState {

	public const PREFIX_LENGTH = 12;

	/**
	 * Masked key view.
	 *
	 * @return array{has_key: bool, key_prefix: string, env: string|null}
	 */
	public static function key(): array {
		$key = Settings::get()['api_key'];

		return [
			'has_key'    => '' !== $key,
			'key_prefix' => substr( $key, 0, self::PREFIX_LENGTH ),
			'env'        => ApiKey::env( $key ),
			'key_locked' => Settings::keyLocked(),
		];
	}

	/**
	 * Key, last error and environment checks (no API call).
	 *
	 * @return array<string, mixed>
	 */
	public static function overview(): array {
		return self::key() + [
			'enabled'         => Settings::get()['enabled'],
			'last_error'      => self::lastError(),
			'intl'            => Unicode::full(),
			'hpos'            => self::hpos(),
			'blocks_checkout' => self::blocksCheckout(),
			'breaker'         => QuotaGuard::breakerTripped(),
			'submit_cap'      => QuotaGuard::submitCapTripped(),
		];
	}

	/**
	 * The stored last error, re-validated (only token-like values).
	 *
	 * @return array{class: string, code: string|null, status: int|null, at: int}|null
	 */
	public static function lastError(): ?array {
		$last  = get_option( Log::LAST_ERROR_OPTION );
		$token = static fn( $v ): bool => is_string( $v ) && 1 === preg_match( '/^[A-Za-z0-9_.-]{1,32}$/D', $v );
		if ( ! is_array( $last ) || ! $token( $last['class'] ?? null ) || ! is_int( $last['at'] ?? null ) ) {
			return null;
		}

		return [
			'class'  => $last['class'],
			'code'   => $token( $last['code'] ?? null ) ? $last['code'] : null,
			'status' => is_int( $last['status'] ?? null ) ? $last['status'] : null,
			'at'     => $last['at'],
		];
	}

	/**
	 * Whether WooCommerce stores orders in its own tables (null: unknown).
	 */
	private static function hpos(): ?bool {
		$util = \Automattic\WooCommerce\Utilities\OrderUtil::class;

		return class_exists( $util ) ? (bool) $util::custom_orders_table_usage_is_enabled() : null;
	}

	/**
	 * Whether the checkout page uses the Checkout block (null: unknown).
	 */
	private static function blocksCheckout(): ?bool {
		if ( ! class_exists( '\WC_Blocks_Utils' ) || ! function_exists( 'wc_get_page_id' ) ) {
			return null;
		}
		$page = wc_get_page_id( 'checkout' );

		return $page > 0 ? \WC_Blocks_Utils::has_block_in_page( $page, 'woocommerce/checkout' ) : null;
	}
}
