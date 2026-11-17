<?php
/**
 * Uninstall routine: removes all data created by the plugin.
 *
 * @package smtp
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Secrets stored with the WordPress Secrets API. They stay if the API is not available any more.
if ( function_exists( 'wp_delete_secret' ) ) {
	foreach ( (array) get_option( 'smtp_secret_keys', array() ) as $smtp_secret_key ) {
		wp_delete_secret( 'smtp/' . $smtp_secret_key );
	}
}

delete_option( 'smtp_settings' );
delete_option( 'smtp_credentials' );
delete_option( 'smtp_secret_keys' );
delete_transient( 'smtp_secrets_migration_retry' );
delete_option( 'smtp_db_version' );
delete_option( 'smtp_alert_settings' );
delete_option( 'smtp_alert_state' );

// Data of the legacy plugin that this one replaces, and the import bookkeeping.
delete_option( 'smtp_options' );
delete_option( 'smtp_legacy_migrated' );
delete_option( 'smtp_migration_notice' );

// Per-user dismissal of the recent failures notice.
delete_metadata( 'user', 0, 'smtp_failures_dismissed', '', true );

wp_clear_scheduled_hook( 'smtp_purge_logs' );

// The table name is built from the trusted `$wpdb->prefix` only.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}smtp_logs" );
