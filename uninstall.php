<?php
/**
 * Uninstall: remove options, transients and scheduled actions; keep order meta.
 *
 * Order meta (`_trustorder_*`) stays: it documents past checkout decisions and
 * belongs to the shop's order records.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

// Prevent direct access.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( [ 'trustorder_settings', 'trustorder_last_error', 'trustorder_backfill_live', 'trustorder_backfill_test', 'trustorder_cache_gen' ] as $trustorder_option ) {
	delete_option( $trustorder_option );
}

// Per-user dismissed admin notices.
foreach ( [ 'trustorder_cap_notice_dismissed', 'trustorder_intl_notice_dismissed' ] as $trustorder_meta ) {
	delete_metadata( 'user', 0, $trustorder_meta, '', true );
}

global $wpdb;

// Plugin-owned transients (`trustorder_*`) and per-order signal locks
// (`trustorder_lock_*`); a one-off cleanup with no core bulk API.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_trustorder_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_trustorder_' ) . '%',
		$wpdb->esc_like( 'trustorder_lock_' ) . '%'
	)
);

// Background jobs (Action Scheduler group `trustorder`), when WooCommerce is loaded.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', [], 'trustorder' );
}
