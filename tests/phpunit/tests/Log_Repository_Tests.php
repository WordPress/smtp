<?php
/**
 * Tests for the Log_Repository class.
 *
 * @package smtp
 */

use WordPress\SMTP\Log_Repository;
use WordPress\SMTP\Test_Utils\Traits\With_Log_Table;

class Log_Repository_Tests extends WP_UnitTestCase {
	use With_Log_Table;

	/**
	 * @var Log_Repository
	 */
	private $repository;

	public static function set_up_before_class() {
		global $wpdb;

		parent::set_up_before_class();

		// The plugin creates the real table on init while the test suite boots. Without it, these tests
		// only see the temporary table they create, so dropping that one really leaves no table.
		$table = ( new Log_Repository() )->get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	public function set_up() {
		parent::set_up();

		$this->repository = new Log_Repository();
		$this->create_log_table();
	}

	private function entry( string $status = 'sent', string $subject = 'Subject' ): array {
		return array(
			'recipient' => 'to@example.org',
			'subject'   => $subject,
			'status'    => $status,
			'error'     => 'failed' === $status ? 'Boom' : '',
			'provider'  => 'smtp',
		);
	}

	public function test_table_name_uses_the_site_prefix() {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'smtp_logs', $this->repository->get_table_name() );
	}

	public function test_maybe_upgrade_stores_the_schema_version() {
		delete_option( Log_Repository::DB_VERSION_OPTION );

		$this->repository->maybe_upgrade();

		$this->assertSame( Log_Repository::DB_VERSION, get_option( Log_Repository::DB_VERSION_OPTION ) );
	}

	public function test_maybe_upgrade_is_skipped_when_the_version_matches() {
		update_option( Log_Repository::DB_VERSION_OPTION, Log_Repository::DB_VERSION );

		$this->repository->maybe_upgrade();

		$this->assertSame( Log_Repository::DB_VERSION, get_option( Log_Repository::DB_VERSION_OPTION ) );
	}

	public function test_insert_stores_the_entry() {
		$this->repository->insert( $this->entry( 'failed', 'Hello' ) );

		$entries = $this->get_logged_entries();

		$this->assertCount( 1, $entries );
		$this->assertSame( 'to@example.org', $entries[0]['recipient'] );
		$this->assertSame( 'Hello', $entries[0]['subject'] );
		$this->assertSame( 'failed', $entries[0]['status'] );
		$this->assertSame( 'Boom', $entries[0]['error'] );
		$this->assertSame( 'smtp', $entries[0]['provider'] );
	}

	public function test_insert_stores_the_date_in_gmt() {
		$this->repository->insert( $this->entry() );

		$created = strtotime( $this->get_logged_entries()[0]['created_at'] . ' UTC' );

		$this->assertEqualsWithDelta( time(), $created, 5 );
	}

	public function test_count_filters_by_status() {
		$this->repository->insert( $this->entry( 'sent' ) );
		$this->repository->insert( $this->entry( 'sent' ) );
		$this->repository->insert( $this->entry( 'failed' ) );

		$this->assertSame( 3, $this->repository->count() );
		$this->assertSame( 2, $this->repository->count( 'sent' ) );
		$this->assertSame( 1, $this->repository->count( 'failed' ) );
	}

	public function test_count_is_zero_for_an_empty_log() {
		$this->assertSame( 0, $this->repository->count() );
	}

	public function test_get_items_returns_the_newest_first() {
		$this->repository->insert( $this->entry( 'sent', 'First' ) );
		$this->repository->insert( $this->entry( 'sent', 'Second' ) );

		$items = $this->repository->get_items( '', 10, 0 );

		$this->assertSame( array( 'Second', 'First' ), wp_list_pluck( $items, 'subject' ) );
	}

	public function test_get_items_filters_by_status() {
		$this->repository->insert( $this->entry( 'sent', 'Ok' ) );
		$this->repository->insert( $this->entry( 'failed', 'Bad' ) );

		$items = $this->repository->get_items( 'failed', 10, 0 );

		$this->assertSame( array( 'Bad' ), wp_list_pluck( $items, 'subject' ) );
	}

	public function test_get_items_paginates() {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->repository->insert( $this->entry( 'sent', "Mail {$i}" ) );
		}

		$first_page  = $this->repository->get_items( '', 2, 0 );
		$second_page = $this->repository->get_items( '', 2, 2 );
		$last_page   = $this->repository->get_items( '', 2, 4 );

		$this->assertSame( array( 'Mail 5', 'Mail 4' ), wp_list_pluck( $first_page, 'subject' ) );
		$this->assertSame( array( 'Mail 3', 'Mail 2' ), wp_list_pluck( $second_page, 'subject' ) );
		$this->assertSame( array( 'Mail 1' ), wp_list_pluck( $last_page, 'subject' ) );
	}

	public function test_purge_deletes_only_old_entries() {
		global $wpdb;

		$this->repository->insert( $this->entry( 'sent', 'Old' ) );
		$this->repository->insert( $this->entry( 'sent', 'Recent' ) );

		$wpdb->update(
			$this->repository->get_table_name(),
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) ) ),
			array( 'subject' => 'Old' )
		);

		$this->repository->purge( 30 );

		$this->assertSame( array( 'Recent' ), wp_list_pluck( $this->get_logged_entries(), 'subject' ) );
	}

	public function test_count_failed_since_counts_only_newer_failures() {
		global $wpdb;

		$this->repository->insert( $this->entry( 'failed' ) );
		$this->repository->insert( $this->entry( 'sent' ) );
		$this->repository->insert( $this->entry( 'failed', 'Old' ) );
		$wpdb->update(
			$this->repository->get_table_name(),
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ) ) ),
			array( 'subject' => 'Old' )
		);

		$this->assertSame( 1, $this->repository->count_failed_since( gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		$this->assertSame( 2, $this->repository->count_failed_since( gmdate( 'Y-m-d H:i:s', time() - ( 3 * DAY_IN_SECONDS ) ) ) );
	}

	private function drop_table() {
		global $wpdb;

		$table = $this->repository->get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	public function test_maybe_upgrade_keeps_the_version_unsaved_when_the_table_cannot_be_created() {
		$this->drop_table();
		delete_option( Log_Repository::DB_VERSION_OPTION );
		delete_transient( Log_Repository::UPGRADE_LOCK );

		// Like a database user without the CREATE privilege.
		$block = static function ( $query ) {
			return false !== stripos( $query, 'CREATE' ) && false !== strpos( $query, 'smtp_logs' ) ? 'SELECT * FROM smtp_no_such_table' : $query;
		};
		add_filter( 'query', $block );
		$this->repository->maybe_upgrade();
		remove_filter( 'query', $block );

		$this->assertFalse( get_option( Log_Repository::DB_VERSION_OPTION ) );

		// The next attempt waits instead of running on every request.
		$this->repository->maybe_upgrade();
		$this->assertFalse( get_option( Log_Repository::DB_VERSION_OPTION ) );

		delete_transient( Log_Repository::UPGRADE_LOCK );
		$this->repository->maybe_upgrade();
		$this->assertSame( Log_Repository::DB_VERSION, get_option( Log_Repository::DB_VERSION_OPTION ) );
		$this->assertFalse( get_transient( Log_Repository::UPGRADE_LOCK ) );
	}

	public function test_maybe_upgrade_waits_while_another_request_creates_the_table() {
		delete_option( Log_Repository::DB_VERSION_OPTION );
		set_transient( Log_Repository::UPGRADE_LOCK, '1', MINUTE_IN_SECONDS );

		$this->repository->maybe_upgrade();

		$this->assertFalse( get_option( Log_Repository::DB_VERSION_OPTION ) );
	}

	public function test_insert_creates_the_table_for_email_sent_before_init() {
		$this->drop_table();
		delete_option( Log_Repository::DB_VERSION_OPTION );
		delete_transient( Log_Repository::UPGRADE_LOCK );

		$this->repository->insert( $this->entry( 'sent', 'Early email' ) );

		$this->assertSame( 'Early email', $this->get_logged_entries()[0]['subject'] );
	}

	public function test_insert_recreates_a_table_dropped_after_the_upgrade() {
		global $wpdb;

		$this->drop_table();
		$this->assertSame( Log_Repository::DB_VERSION, get_option( Log_Repository::DB_VERSION_OPTION ) );

		$suppress = $wpdb->suppress_errors( true );
		$this->repository->insert( $this->entry( 'failed', 'After the drop' ) );
		$wpdb->suppress_errors( $suppress );

		$this->assertSame( 'After the drop', $this->get_logged_entries()[0]['subject'] );
	}
}
