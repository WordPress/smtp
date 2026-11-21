<?php
/**
 * Trait WordPress\SMTP\Test_Utils\Traits\With_Log_Table
 *
 * @package smtp
 */

namespace WordPress\SMTP\Test_Utils\Traits;

use WordPress\SMTP\Log_Repository;

trait With_Log_Table {

	/**
	 * Creates the log table. The WordPress test suite turns it into a temporary table.
	 *
	 * Another test may have dropped the table, which is not rolled back like the options are. The saved
	 * schema version would then skip the upgrade, so it is removed when the table is missing.
	 */
	protected function create_log_table() {
		global $wpdb;

		$repository = new Log_Repository();
		$suppress   = $wpdb->suppress_errors();
		// SHOW TABLES does not list temporary tables.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$exists = (bool) $wpdb->get_results( 'SHOW COLUMNS FROM ' . $repository->get_table_name() );
		$wpdb->suppress_errors( $suppress );

		if ( ! $exists ) {
			delete_option( Log_Repository::DB_VERSION_OPTION );
			delete_transient( Log_Repository::UPGRADE_LOCK );
		}

		$repository->maybe_upgrade();
	}

	/**
	 * Returns the logged entries, oldest first.
	 */
	protected function get_logged_entries(): array {
		global $wpdb;

		$table = ( new Log_Repository() )->get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );
	}
}
