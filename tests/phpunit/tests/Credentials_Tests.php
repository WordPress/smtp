<?php
/**
 * Tests for the Credentials class.
 *
 * @package smtp
 */

use WordPress\SMTP\Credentials;

class Credentials_Tests extends WP_UnitTestCase {

	/**
	 * @var Credentials
	 */
	private $credentials;

	public function set_up() {
		parent::set_up();

		$this->credentials = new Credentials();
	}

	public function test_get_returns_an_empty_string_when_nothing_is_stored() {
		$this->assertSame( '', $this->credentials->get( 'password' ) );
	}

	public function test_set_and_get_a_secret() {
		$this->credentials->set( 'password', 'secret' );

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

	public function test_delete_removes_only_the_given_secret() {
		$this->credentials->set( 'password', 'one' );
		$this->credentials->set( 'api_key', 'two' );

		$this->credentials->delete( 'password' );

		$this->assertSame( '', $this->credentials->get( 'password' ) );
		$this->assertSame( 'two', $this->credentials->get( 'api_key' ) );
		$this->assertArrayNotHasKey( 'password', get_option( Credentials::OPTION ) );
	}

	public function test_delete_ignores_a_missing_secret_and_a_corrupted_option() {
		$this->credentials->delete( 'password' );
		$this->assertFalse( get_option( Credentials::OPTION ) );

		update_option( Credentials::OPTION, 'not-an-array' );
		$this->credentials->delete( 'password' );
		$this->assertSame( 'not-an-array', get_option( Credentials::OPTION ) );
	}

	public function test_deleting_the_last_secret_removes_the_option() {
		$this->credentials->set( 'password', 'one' );

		$this->credentials->delete( 'password' );

		$this->assertFalse( get_option( Credentials::OPTION ) );
	}

	public function test_secrets_are_not_autoloaded_after_a_delete() {
		$this->credentials->set( 'password', 'one' );
		$this->credentials->set( 'api_key', 'two' );

		$this->credentials->delete( 'password' );

		$this->assertArrayNotHasKey( Credentials::OPTION, wp_load_alloptions( true ) );
	}

	public function test_secrets_are_not_autoloaded() {
		$this->credentials->set( 'password', 'secret' );

		$this->assertArrayNotHasKey( Credentials::OPTION, wp_load_alloptions( true ) );
	}
}
