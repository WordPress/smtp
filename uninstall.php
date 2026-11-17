<?php
/**
 * Uninstall routine: removes all data created by the plugin.
 *
 * @package smtp
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Per-user dismissal of the recent failures notice. User meta is shared by the whole network,
// so it is removed once, before the per-site loop.
delete_metadata( 'user', 0, 'smtp_failures_dismissed', '', true );

// A closure, not a function, so the file can be loaded more than once (tests).
$smtp_uninstall_site = static function () {
	global $wpdb;

	// Secrets stored with the WordPress Secrets API. They stay if the API is not available any more.
	if ( function_exists( 'wp_delete_secret' ) ) {
		foreach ( (array) get_option( 'smtp_secret_keys', array() ) as $smtp_secret_key ) {
			wp_delete_secret( 'smtp/' . $smtp_secret_key );
		}
	}

	delete_option( 'smtp_settings' );
	delete_option( 'smtp_connections' );
	delete_option( 'smtp_credentials' );
	delete_option( 'smtp_secret_keys' );
	delete_transient( 'smtp_secrets_migration_retry' );
	delete_option( 'smtp_db_version' );
	delete_option( 'smtp_alert_settings' );
	delete_option( 'smtp_alert_state' );
	delete_transient( 'smtp_db_upgrade_lock' );

	// Data of the legacy plugin that this one replaces, and the import bookkeeping.
	delete_option( 'smtp_options' );
	delete_option( 'smtp_legacy_migrated' );
	delete_option( 'smtp_migration_notice' );

	wp_clear_scheduled_hook( 'smtp_purge_logs' );

	// The table name is built from the trusted `$wpdb->prefix` only.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}smtp_logs" );
};

if ( is_multisite() ) {
	// Sites of every network (the default `network_id` is 0), in batches to keep memory low on large networks.
	$smtp_offset = 0;

	do {
		$smtp_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $smtp_offset,
			)
		);

		foreach ( $smtp_site_ids as $smtp_site_id ) {
			switch_to_blog( $smtp_site_id );
			$smtp_uninstall_site();
			restore_current_blog();
		}

		$smtp_offset += count( $smtp_site_ids );
	} while ( $smtp_site_ids );
} else {
	$smtp_uninstall_site();
}
