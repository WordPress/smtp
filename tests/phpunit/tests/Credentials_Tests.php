<?php
/**
 * Tests for the Credentials class.
 *
 * @package smtp
 */

use WordPress\SMTP\Credentials;
use WordPress\SMTP\Test_Utils\Fake_Secrets_API;

class Credentials_Tests extends WP_UnitTestCase {

	/**
	 * @var Fake_Secrets_API
	 */
	private $api;

	/**
	 * @var Credentials
	 */
	private $credentials;

	public function set_up() {
		parent::set_up();

		$this->api            = new Fake_Secrets_API();
		$this->api->available = false;
		$this->credentials    = new Credentials( $this->api );
	}

	public function test_get_returns_an_empty_string_when_nothing_is_stored() {
		$this->assertSame( '', $this->credentials->get( 'password' ) );
	}

	public function test_set_and_get_a_secret() {
		$this->assertTrue( $this->credentials->set( 'password', 'secret' ) );

		$this->assertSame( 'secret', $this->credentials->get( 'password' ) );
	}

	public function test_secrets_are_stored_independently() {
		$this->credentials->set( 'password', 'one' );
		$this->credentials->set( 'api_key', 'two' );

		$this->assertSame( 'one', $this->credentials->get( 'password' ) );
		$this->assertSame( 'two', $this->credentials->get( 'api_key' ) );
	}

	public function test_set_overwrites_an_existing_secret() {
		$this->credentials->set( 'password', 'old' );
		$this->credentials->set( 'password', 'new' );

		$this->assertSame( 'new', $this->credentials->get( 'password' ) );
	}

	public function test_get_ignores_a_corrupted_option() {
		update_option( Credentials::OPTION, 'not-an-array' );

		$this->assertSame( '', $this->credentials->get( 'password' ) );
	}

	public function test_set_recovers_from_a_corrupted_option() {
		update_option( Credentials::OPTION, 'not-an-array' );

		$this->credentials->set( 'password', 'secret' );

		$this->assertSame( 'secret', $this->credentials->get( 'password' ) );
	}

	public function test_secrets_are_not_autoloaded() {
		$this->credentials->set( 'password', 'secret' );

		$this->assertArrayNotHasKey( Credentials::OPTION, wp_load_alloptions( true ) );
	}

	public function test_delete_prefixed_removes_only_the_matching_secrets() {
		$this->credentials->set( 'connection:a:password', 'one' );
		$this->credentials->set( 'connection:a:token', 'two' );
		$this->credentials->set( 'connection:ab:password', 'three' );
		$this->credentials->set( 'password', 'four' );

		$this->credentials->delete_prefixed( 'connection:a:' );

		$this->assertSame( '', $this->credentials->get( 'connection:a:password' ) );
		$this->assertSame( '', $this->credentials->get( 'connection:a:token' ) );
		$this->assertSame( 'three', $this->credentials->get( 'connection:ab:password' ) );
		$this->assertSame( 'four', $this->credentials->get( 'password' ) );
	}

	public function test_delete_prefixed_with_an_empty_prefix_deletes_nothing() {
		$this->credentials->set( 'password', 'secret' );

		$this->credentials->delete_prefixed( '' );

		$this->assertSame( 'secret', $this->credentials->get( 'password' ) );
	}

	public function test_delete_prefixed_survives_a_corrupted_option() {
		update_option( Credentials::OPTION, 'nope' );

		$this->credentials->delete_prefixed( 'connection:a:' );

		$this->assertSame( 'nope', get_option( Credentials::OPTION ) );
	}

	public function test_without_the_api_secrets_are_stored_in_the_option() {
		$this->credentials->set( 'password', 'secret' );

		$this->assertSame( array( 'password' => 'secret' ), get_option( Credentials::OPTION ) );
		$this->assertSame( Credentials::STORAGE_OPTION, $this->credentials->get_storage() );
	}

	public function test_with_the_api_secrets_are_stored_with_it() {
		$this->api->available = true;

		$this->assertTrue( $this->credentials->set( 'password', 'secret' ) );

		$this->assertSame( array( 'smtp/password' => 'secret' ), $this->api->secrets );
		$this->assertFalse( get_option( Credentials::OPTION ) );
		$this->assertSame( 'secret', $this->credentials->get( 'password' ) );
		$this->assertSame( Credentials::STORAGE_SECRETS, $this->credentials->get_storage() );
	}

	public function test_a_read_only_provider_keeps_the_option_storage() {
		$this->api->available = true;
		$this->api->writable  = false;

		$this->credentials->set( 'password', 'secret' );

		$this->assertSame( array(), $this->api->secrets );
		$this->assertSame( 'secret', $this->credentials->get( 'password' ) );
		$this->assertSame( Credentials::STORAGE_OPTION, $this->credentials->get_storage() );
	}

	public function test_get_reads_a_secret_the_plugin_did_not_list() {
		$this->api->available = true;
		$this->api->writable  = false;
		$this->api->secrets   = array( 'smtp/password' => 'from-host' );

		$this->assertSame( 'from-host', $this->credentials->get( 'password' ) );
	}

	public function test_the_option_wins_over_an_unlisted_secret() {
		$this->credentials->set( 'password', 'from-option' );
		$this->api->available = true;
		$this->api->secrets   = array( 'smtp/password' => 'other' );

		$this->assertSame( 'from-option', $this->credentials->get( 'password' ) );
	}

	public function test_a_failed_write_is_returned_and_never_stored_in_plain_text() {
		$this->api->available = true;
		$this->api->set_error = new WP_Error( 'secret_key_unavailable', 'No key.' );

		$result = $this->credentials->set( 'password', 'secret' );

		$this->assertWPError( $result );
		$this->assertSame( 'secret_key_unavailable', $result->get_error_code() );
		$this->assertFalse( get_option( Credentials::OPTION ) );
		$this->assertSame( '', $this->credentials->get( 'password' ) );
	}

	public function test_a_failed_write_keeps_the_current_secret() {
		$this->api->available = true;
		$this->credentials->set( 'password', 'old' );
		$this->api->set_error = new WP_Error( 'secret_key_unavailable', 'No key.' );

		$this->credentials->set( 'password', 'new' );

		$this->assertSame( 'old', $this->credentials->get( 'password' ) );
	}

	public function test_migrate_does_nothing_without_the_api() {
		$this->credentials->set( 'password', 'secret' );

		$this->assertSame( 0, $this->credentials->migrate() );
		$this->assertSame( array( 'password' => 'secret' ), get_option( Credentials::OPTION ) );
	}

	public function test_migrate_moves_the_option_to_the_api_and_can_run_twice() {
		$this->credentials->set( 'password', 'one' );
		$this->credentials->set( 'alert_slack_webhook_url', 'two' );
		$this->api->available = true;

		$this->assertSame( 2, $this->credentials->migrate() );
		$this->assertSame( 0, $this->credentials->migrate() );

		$this->assertFalse( get_option( Credentials::OPTION ) );
		$this->assertSame(
			array(
				'smtp/password'                => 'one',
				'smtp/alert_slack_webhook_url' => 'two',
			),
			$this->api->secrets
		);
		$this->assertSame( 'one', $this->credentials->get( 'password' ) );
		$this->assertSame( 'two', $this->credentials->get( 'alert_slack_webhook_url' ) );
	}

	public function test_migrate_keeps_values_it_can_not_move() {
		$this->credentials->set( 'password', 'secret' );
		$this->api->available = true;
		$this->api->set_error = new WP_Error( 'secret_key_unavailable', 'No key.' );

		$this->assertSame( 0, $this->credentials->migrate() );

		$this->assertSame( array( 'password' => 'secret' ), get_option( Credentials::OPTION ) );
		$this->assertSame( 'secret', $this->credentials->get( 'password' ) );
	}

	public function test_migrate_keeps_values_that_do_not_read_back() {
		$this->credentials->set( 'password', 'secret' );
		$this->api->available  = true;
		$this->api->unreadable = array( 'smtp/password' );

		$this->assertSame( 0, $this->credentials->migrate() );

		$this->assertSame( array( 'password' => 'secret' ), get_option( Credentials::OPTION ) );
	}

	public function test_an_unreadable_secret_never_falls_back_to_the_option() {
		$this->api->available = true;
		$this->credentials->set( 'password', 'secret' );
		update_option( Credentials::OPTION, array( 'password' => 'stale' ), false );
		$this->api->unreadable = array( 'smtp/password' );

		$this->assertSame( '', $this->credentials->get( 'password' ) );
		$this->assertTrue( $this->credentials->has_unreadable() );
	}

	public function test_secrets_are_unreadable_when_the_api_is_removed_after_migration() {
		$this->api->available = true;
		$this->credentials->set( 'password', 'secret' );
		$this->api->available = false;

		$this->assertSame( '', $this->credentials->get( 'password' ) );
		$this->assertTrue( $this->credentials->has_unreadable() );
	}

	public function test_a_secret_deleted_outside_the_plugin_is_unreadable() {
		$this->api->available = true;
		$this->credentials->set( 'password', 'secret' );
		$this->api->secrets = array();

		$this->assertSame( '', $this->credentials->get( 'password' ) );
		$this->assertTrue( $this->credentials->has_unreadable() );
	}

	public function test_entering_a_secret_again_without_the_api_recovers_it() {
		$this->api->available = true;
		$this->credentials->set( 'password', 'secret' );
		$this->api->available = false;

		$this->credentials->set( 'password', 'new' );

		$this->assertSame( 'new', $this->credentials->get( 'password' ) );
		$this->assertFalse( $this->credentials->has_unreadable() );
	}

	public function test_has_unreadable_is_false_when_everything_can_be_read() {
		$this->credentials->set( 'api_key', 'plain' );
		$this->api->available = true;
		$this->credentials->set( 'password', 'secret' );

		$this->assertFalse( $this->credentials->has_unreadable() );
	}

	public function test_render_notice_when_a_secret_can_not_be_read() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->api->available = true;
		$this->credentials->set( 'password', 'secret' );
		$this->api->unreadable = array( 'smtp/password' );

		ob_start();
		$this->credentials->render_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'WP_SECRETS_KEY', $output );
		$this->assertStringContainsString( 'tab=settings', $output );
	}

	public function test_render_notice_shows_nothing_when_secrets_can_be_read() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->api->available = true;
		$this->credentials->set( 'password', 'secret' );

		ob_start();
		$this->credentials->render_notice();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_render_notice_requires_the_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->api->available = true;
		$this->credentials->set( 'password', 'secret' );
		$this->api->unreadable = array( 'smtp/password' );

		ob_start();
		$this->credentials->render_notice();

		$this->assertSame( '', ob_get_clean() );
	}
}
