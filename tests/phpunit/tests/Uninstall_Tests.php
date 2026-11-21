<?php
/**
 * Tests for the uninstall routine.
 *
 * @package smtp
 */

use WordPress\SMTP\Connections;
use WordPress\SMTP\Alerts\Alert_Dispatcher;
use WordPress\SMTP\Alerts\Alert_Settings;
use WordPress\SMTP\Credentials;
use WordPress\SMTP\Log_Repository;
use WordPress\SMTP\Migration;
use WordPress\SMTP\Plugin_Main;
use WordPress\SMTP\Settings;
use WordPress\SMTP\Test_Utils\Traits\With_Log_Table;

class Uninstall_Tests extends WP_UnitTestCase {
	use With_Log_Table;

	private function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'smtp/smtp.php' );
		}

		require TESTS_PLUGIN_DIR . '/uninstall.php';
	}

	public function test_uninstall_removes_options_cron_and_table() {
		global $wpdb;

		$this->create_log_table();
		update_option( Settings::OPTION, array( 'host' => 'smtp.example.com' ) );
		( new Credentials() )->set( 'password', 'secret' );
		( new Credentials() )->set( Alert_Settings::get_secret_key( 'slack', 'webhook_url' ), 'https://hooks.slack.com/services/secret' );
		update_option( Alert_Settings::OPTION, array( 'channel' => 'slack' ) );
		update_option( Alert_Dispatcher::STATE_OPTION, array( 'slack' => array( 'last_sent' => time() ) ) );
		update_option( Migration::LEGACY_OPTION, array( 'host' => 'smtp.example.com' ) );
		update_option( Migration::DONE_OPTION, '1' );
		update_option( Migration::NOTICE_OPTION, Migration::NOTICE_MIGRATED );
		set_transient( Log_Repository::UPGRADE_LOCK, '1', HOUR_IN_SECONDS );
		( new Log_Repository() )->insert(
			array(
				'recipient' => 'to@example.org',
				'subject'   => 'Subject',
				'status'    => 'sent',
				'error'     => '',
				'provider'  => 'smtp',
			)
		);
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', Plugin_Main::PURGE_HOOK );
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'smtp_failures_dismissed', '2026-01-01 00:00:00' );

		$queries = array();
		add_filter(
			'query',
			static function ( $query ) use ( &$queries ) {
				$queries[] = trim( $query );

				return $query;
			}
		);

		$this->run_uninstall();

		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( Credentials::OPTION ) );
		$this->assertFalse( get_option( Log_Repository::DB_VERSION_OPTION ) );
		$this->assertFalse( get_option( Alert_Settings::OPTION ) );
		$this->assertFalse( get_option( Alert_Dispatcher::STATE_OPTION ) );
		$this->assertFalse( get_option( Migration::LEGACY_OPTION ) );
		$this->assertFalse( get_option( Migration::DONE_OPTION ) );
		$this->assertFalse( get_option( Migration::NOTICE_OPTION ) );
		$this->assertFalse( get_transient( Log_Repository::UPGRADE_LOCK ) );
		$this->assertFalse( wp_next_scheduled( Plugin_Main::PURGE_HOOK ) );
		$this->assertSame( '', get_user_meta( $user_id, 'smtp_failures_dismissed', true ) );

		$this->assertNotEmpty(
			preg_grep( '/^DROP (TEMPORARY )?TABLE IF EXISTS ' . preg_quote( $wpdb->prefix, '/' ) . 'smtp_logs$/', $queries )
		);
	}

	public function test_uninstall_cleans_every_site_of_a_network() {
		global $wpdb;

		$this->skipWithoutMultisite();

		$site_ids = array( get_current_blog_id(), self::factory()->blog->create() );
		$prefixes = array();

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			$this->create_log_table();
			update_option( Settings::OPTION, array( 'host' => 'smtp.example.com' ) );
			( new Credentials() )->set( 'password', 'secret' );
			update_option( Alert_Settings::OPTION, array( 'channel' => 'slack' ) );
			update_option( Migration::LEGACY_OPTION, array( 'host' => 'smtp.example.com' ) );
			set_transient( Log_Repository::UPGRADE_LOCK, '1', HOUR_IN_SECONDS );
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', Plugin_Main::PURGE_HOOK );
			$prefixes[] = $wpdb->prefix;
			restore_current_blog();
		}

		$queries = array();
		add_filter(
			'query',
			static function ( $query ) use ( &$queries ) {
				$queries[] = trim( $query );

				return $query;
			}
		);

		$this->run_uninstall();

		$this->assertSame( $site_ids[0], get_current_blog_id() );

		foreach ( $site_ids as $index => $site_id ) {
			switch_to_blog( $site_id );
			$this->assertFalse( get_option( Settings::OPTION ) );
			$this->assertFalse( get_option( Credentials::OPTION ) );
			$this->assertFalse( get_option( Log_Repository::DB_VERSION_OPTION ) );
			$this->assertFalse( get_option( Alert_Settings::OPTION ) );
			$this->assertFalse( get_option( Migration::LEGACY_OPTION ) );
			$this->assertFalse( get_transient( Log_Repository::UPGRADE_LOCK ) );
			$this->assertFalse( wp_next_scheduled( Plugin_Main::PURGE_HOOK ) );
			restore_current_blog();

			$this->assertNotEmpty(
				preg_grep( '/^DROP (TEMPORARY )?TABLE IF EXISTS ' . preg_quote( $prefixes[ $index ], '/' ) . 'smtp_logs$/', $queries ),
				"Log table of site {$site_id} was not dropped."
			);
		}
	}

	public function test_uninstall_cleans_sites_fetched_in_several_batches() {
		$this->skipWithoutMultisite();

		$site_ids = array( get_current_blog_id(), self::factory()->blog->create(), self::factory()->blog->create() );

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			update_option( Settings::OPTION, array( 'host' => 'smtp.example.com' ) );
			restore_current_blog();
		}

		// One site per batch, so the uninstall has to page through the network.
		add_action(
			'pre_get_sites',
			static function ( $query ) {
				$query->query_vars['number'] = 1;
			}
		);

		$this->run_uninstall();

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			$this->assertFalse( get_option( Settings::OPTION ), "Site {$site_id} was not cleaned." );
			restore_current_blog();
		}
	}

	public function test_uninstall_removes_the_connections() {
		update_option(
			Connections::OPTION,
			array(
				'sponsors' => array(
					'label'    => 'Sponsors',
					'settings' => array(),
				),
			)
		);

		$this->run_uninstall();

		$this->assertFalse( get_option( Connections::OPTION ) );
	}
}
