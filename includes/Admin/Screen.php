<?php
/**
 * Where TrustOrder admin notices may appear.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Admin;

/**
 * A WooCommerce screen or one of TrustOrder's own.
 */
final class Screen {

	/**
	 * Whether the current admin screen is relevant. The TrustOrder app screen
	 * itself is not: its overview shows the same state.
	 */
	public static function relevant(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen ) {
			return false;
		}
		$id = (string) $screen->id;
		if ( AdminPage::hookSuffix() === $id ) {
			return false;
		}
		if ( str_contains( $id, 'trustorder' ) ) {
			return true;
		}

		return function_exists( 'wc_get_screen_ids' ) && in_array( $id, wc_get_screen_ids(), true );
	}
}
