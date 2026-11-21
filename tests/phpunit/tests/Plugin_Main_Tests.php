<?php
/**
 * Tests for the Plugin_Main class.
 *
 * @package smtp
 */

use WordPress\SMTP\Alerts\Alert_Dispatcher;
use WordPress\SMTP\CLI\Command;
use WordPress\SMTP\CLI\Settings_Command;
use WordPress\SMTP\Credentials;
use WordPress\SMTP\Delivery_Tracker;
use WordPress\SMTP\Log_Repository;
use WordPress\SMTP\Plugin_Main;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Settings;
use WordPress\SMTP\Test_Utils\Traits\With_Log_Table;

class Plugin_Main_Tests extends WP_UnitTestCase {
	use With_Log_Table;

	public function set_up() {
		parent::set_up();

		wp_clear_scheduled_hook( Plugin_Main::PURGE_HOOK );
	}

	public function test_plugin_registers_mail_hooks() {
		$this->assertNotFalse( has_action( 'phpmailer_init' ) );
		$this->assertNotFalse( has_filter( 'wp_mail_from' ) );
		$this->assertNotFalse( has_filter( 'wp_mail_from_name' ) );
	}

	public function test_plugin_registers_log_hooks() {
		$this->assertNotFalse( has_action( 'wp_mail_succeeded' ) );
		$this->assertNotFalse( has_action( 'wp_mail_failed' ) );
		$this->assertNotFalse( has_action( Plugin_Main::PURGE_HOOK ) );
	}

	public function test_plugin_registers_the_alert_dispatcher_after_the_logger() {
		global $wp_filter;

		$callbacks = isset( $wp_filter['wp_mail_failed'][20] ) ? $wp_filter['wp_mail_failed'][20] : array();
		$found     = false;

		foreach ( $callbacks as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Alert_Dispatcher ) {
				$found = true;
			}
		}

		$this->assertTrue( $found );
	}

	public function test_register_cli_commands_adds_the_commands() {
		require_once TESTS_PLUGIN_DIR . '/tests/phpunit/utils/wp-cli-stub.php';
		WP_CLI::reset();

		$registry = new Provider_Registry();
		$settings = new Settings( $registry, new Credentials() );

		( new Plugin_Main( WP_SMTP_MAIN_FILE ) )->register_cli_commands( $settings, $registry, new Delivery_Tracker( $settings ) );

		$this->assertInstanceOf( Command::class, WP_CLI::$commands['smtp'] );
		$this->assertInstanceOf( Settings_Command::class, WP_CLI::$commands['smtp settings'] );
	}

	/**
	 * Returns the Log_Repository callback registered on `init` at priority 0, if any.
	 */
	private function get_init_upgrade_callback(): ?callable {
		global $wp_filter;

		$callbacks = isset( $wp_filter['init'][0] ) ? $wp_filter['init'][0] : array();
		foreach ( $callbacks as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Log_Repository ) {
				return $callback['function'];
			}
		}

		return null;
	}

	public function test_plugin_upgrades_the_settings_on_init() {
		global $wp_filter;

		$found = false;
		foreach ( isset( $wp_filter['init'][0] ) ? $wp_filter['init'][0] : array() as $callback ) {
			$found = $found || ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Settings && 'maybe_upgrade' === $callback['function'][1] );
		}

		$this->assertTrue( $found );
	}

	public function test_plugin_creates_the_log_table_on_init_and_no_longer_on_admin_init() {
		global $wp_filter;

		$this->assertNotNull( $this->get_init_upgrade_callback() );

		foreach ( isset( $wp_filter['admin_init'] ) ? $wp_filter['admin_init']->callbacks : array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$this->assertFalse( is_array( $callback['function'] ) && $callback['function'][0] instanceof Log_Repository, 'Log_Repository is still hooked to admin_init.' );
			}
		}
	}

	public function test_email_sent_after_an_update_in_place_is_logged() {
		global $wpdb;

		// Updating from the 1.x plugin runs no activation hook, so neither the table nor the schema version exists.
		$table = ( new Log_Repository() )->get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( Log_Repository::DB_VERSION_OPTION );

		// A front-end request or a cron run: init fires, admin_init does not.
		$callback = $this->get_init_upgrade_callback();
		$this->assertNotNull( $callback );
		call_user_func( $callback );

		reset_phpmailer_instance();
		add_filter(
			'wp_mail_from',
			static function () {
				return 'wordpress@example.org';
			}
		);

		wp_mail( 'to@example.org', 'Order received', 'Body' );

		$entries = $this->get_logged_entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'Order received', $entries[0]['subject'] );
	}

	public function test_schedule_purge_schedules_a_daily_event() {
		$plugin_main = new Plugin_Main( WP_SMTP_MAIN_FILE );

		$this->assertFalse( wp_next_scheduled( Plugin_Main::PURGE_HOOK ) );

		$plugin_main->schedule_purge();

		$this->assertNotFalse( wp_next_scheduled( Plugin_Main::PURGE_HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( Plugin_Main::PURGE_HOOK ) );
	}

	public function test_schedule_purge_does_not_duplicate_the_event() {
		$plugin_main = new Plugin_Main( WP_SMTP_MAIN_FILE );

		$plugin_main->schedule_purge();
		$first = wp_next_scheduled( Plugin_Main::PURGE_HOOK );
		$plugin_main->schedule_purge();

		$this->assertSame( $first, wp_next_scheduled( Plugin_Main::PURGE_HOOK ) );
	}

	public function test_activate_creates_the_table_and_schedules_the_purge() {
		Plugin_Main::activate();

		$this->assertNotFalse( wp_next_scheduled( Plugin_Main::PURGE_HOOK ) );
		$this->assertSame( Log_Repository::DB_VERSION, get_option( 'smtp_db_version' ) );
	}

	public function test_deactivate_clears_the_scheduled_purge() {
		Plugin_Main::activate();
		Plugin_Main::deactivate();

		$this->assertFalse( wp_next_scheduled( Plugin_Main::PURGE_HOOK ) );
	}
}
