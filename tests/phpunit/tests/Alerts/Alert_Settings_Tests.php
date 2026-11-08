<?php
/**
 * Tests for the Alert_Settings class.
 *
 * @package smtp
 */

use WordPress\SMTP\Alerts\Alert_Channel_Registry;
use WordPress\SMTP\Alerts\Alert_Settings;
use WordPress\SMTP\Credentials;
use WordPress\SMTP\Test_Utils\Alerts\Fake_Channel;

class Alert_Settings_Tests extends WP_UnitTestCase {

	/**
	 * @var Alert_Settings
	 */
	private $settings;

	/**
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * @var Fake_Channel
	 */
	private $fake;

	public function set_up() {
		parent::set_up();

		$this->fake = new Fake_Channel();
		add_filter(
			'smtp_alert_channels',
			function () {
				return array( $this->fake );
			}
		);

		$this->credentials = new Credentials();
		$this->settings    = new Alert_Settings( new Alert_Channel_Registry(), $this->credentials );
	}

	public function test_alerts_are_disabled_by_default() {
		$this->assertFalse( $this->settings->is_enabled() );
		$this->assertSame( '', $this->settings->get_selected_channel_id() );
		$this->assertSame( array(), $this->settings->get_active_channels() );
	}

	public function test_selecting_a_channel_enables_alerts() {
		update_option( Alert_Settings::OPTION, array( 'channel' => 'fake' ) );

		$this->assertTrue( $this->settings->is_enabled() );
		$this->assertSame( 'fake', $this->settings->get_selected_channel_id() );
	}

	public function test_a_channel_that_no_longer_exists_disables_alerts() {
		update_option( Alert_Settings::OPTION, array( 'channel' => 'removed' ) );

		$this->assertFalse( $this->settings->is_enabled() );
		$this->assertSame( '', $this->settings->get_selected_channel_id() );
	}

	public function test_sanitize_stores_secrets_in_the_credentials() {
		$clean = $this->settings->sanitize(
			array(
				'channel'  => 'fake',
				'channels' => array(
					'fake' => array(
						'room'  => ' #alerts ',
						'token' => 'secret-token',
					),
				),
			)
		);

		$this->assertSame(
			array(
				'channel'  => 'fake',
				'channels' => array(
					'fake' => array(
						'room' => '#alerts',
					),
				),
			),
			$clean
		);
		$this->assertSame( 'secret-token', $this->credentials->get( 'alert_fake_token' ) );
	}

	public function test_an_empty_secret_keeps_the_saved_one() {
		$this->credentials->set( 'alert_fake_token', 'saved-token' );

		$this->settings->sanitize( array( 'channels' => array( 'fake' => array( 'token' => '' ) ) ) );

		$this->assertSame( 'saved-token', $this->credentials->get( 'alert_fake_token' ) );
	}

	public function test_sanitize_ignores_unknown_channels_and_malformed_input() {
		$this->assertSame(
			array(
				'channel'  => '',
				'channels' => array(
					'fake' => array(
						'room' => '',
					),
				),
			),
			$this->settings->sanitize(
				array(
					'channel'  => 'unknown',
					'channels' => array(
						'unknown' => array( 'enabled' => '1' ),
						'fake'    => array( 'room' => array( 'not', 'a', 'string' ) ),
					),
				)
			)
		);

		$this->assertSame( array( 'channel', 'channels' ), array_keys( $this->settings->sanitize( 'not an array' ) ) );
	}

	public function test_get_channel_values_resolves_secrets() {
		update_option( Alert_Settings::OPTION, array( 'channels' => array( 'fake' => array( 'room' => '#alerts' ) ) ) );
		$this->credentials->set( 'alert_fake_token', 'secret-token' );

		$this->assertSame(
			array(
				'room'  => '#alerts',
				'token' => 'secret-token',
			),
			$this->settings->get_channel_values( $this->fake )
		);
	}

	public function test_secrets_are_never_written_to_the_settings_option() {
		update_option(
			Alert_Settings::OPTION,
			$this->settings->sanitize( array( 'channels' => array( 'fake' => array( 'token' => 'secret-token' ) ) ) )
		);

		$this->assertStringNotContainsString( 'secret-token', maybe_serialize( get_option( Alert_Settings::OPTION ) ) );
	}

	public function test_the_active_channel_must_be_selected_and_configured() {
		update_option( Alert_Settings::OPTION, array( 'channel' => 'fake' ) );
		$this->assertSame( array(), $this->settings->get_active_channels() );

		$this->credentials->set( 'alert_fake_token', 'secret-token' );
		$this->assertSame( array( 'fake' => $this->fake ), $this->settings->get_active_channels() );

		update_option( Alert_Settings::OPTION, array( 'channel' => '' ) );
		$this->assertSame( array(), $this->settings->get_active_channels() );
	}

	public function test_switching_to_none_keeps_the_channel_fields() {
		$clean = $this->settings->sanitize(
			array(
				'channel'  => '',
				'channels' => array( 'fake' => array( 'room' => '#alerts' ) ),
			)
		);

		$this->assertSame( '#alerts', $clean['channels']['fake']['room'] );
	}
}
