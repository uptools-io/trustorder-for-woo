<?php
/**
 * Keeps admin notices off the TrustOrder screen.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Admin;

/**
 * The TrustOrder app draws its own state, so no admin notice is shown on its
 * screen, from any plugin or theme (TrustOrder's own notices skip this screen
 * already, see Screen::relevant()). Two layers, as on every LW screen:
 * 1. Right before WordPress prints the notice hooks, every callback on them
 *    is removed.
 * 2. A stylesheet hides notice markup printed any other way (echoed from
 *    another hook, injected by script).
 */
final class NoticeIsolation {

	/** The hooks WordPress prints admin notices from. */
	public const HOOKS = [ 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' ];

	/**
	 * Hook in (admin only).
	 */
	public static function register(): void {
		// in_admin_header runs in admin-header.php right before the notice hooks.
		add_action( 'in_admin_header', [ self::class, 'isolate' ], PHP_INT_MAX );
		add_action( 'admin_head', [ self::class, 'printStyles' ] );
	}

	/**
	 * Whether the current screen is the TrustOrder app.
	 */
	public static function onOwnScreen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen instanceof \WP_Screen && AdminPage::hookSuffix() === $screen->id;
	}

	/**
	 * Remove every callback from the notice hooks on the TrustOrder screen.
	 */
	public static function isolate(): void {
		if ( ! self::onOwnScreen() ) {
			return;
		}
		foreach ( self::HOOKS as $hook ) {
			remove_all_actions( $hook );
		}
	}

	/**
	 * Hide notice markup that did not come through the notice hooks.
	 */
	public static function printStyles(): void {
		if ( ! self::onOwnScreen() ) {
			return;
		}
		$selectors = [];
		foreach ( [ '.notice', '.error', '.updated', '.update-nag', '#message' ] as $notice ) {
			$selectors[] = 'body.trustorder-screen #wpbody-content > ' . $notice;
			$selectors[] = 'body.trustorder-screen #wpbody-content > .wrap > ' . $notice;
		}

		echo '<style>' . implode( ',', $selectors ) . '{display:none!important}</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed selectors built above.
	}
}
