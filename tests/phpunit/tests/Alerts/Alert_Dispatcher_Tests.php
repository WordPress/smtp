<?php
/**
 * Tests for the Alert_Dispatcher class.
 *
 * @package smtp
 */

use WordPress\SMTP\Alerts\Alert;
use WordPress\SMTP\Alerts\Alert_Channel_Registry;
use WordPress\SMTP\Alerts\Alert_Dispatcher;
use WordPress\SMTP\Alerts\Alert_Settings;
use WordPress\SMTP\Credentials;
use WordPress\SMTP\Test_Utils\Alerts\Fake_Channel;

class Alert_Dispatcher_Tests extends WP_UnitTestCase {

	/**
	 * @var Alert_Dispatcher
	 */
	private $dispatcher;

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

		$this->dispatcher = new Alert_Dispatcher( new Alert_Settings( new Alert_Channel_Registry(), new Credentials() ) );

		// The dispatcher of the active plugin would also react to the failures triggered here.
		remove_all_actions( 'wp_mail_failed', 20 );
	}

	private function enable( bool $selected = true, bool $configured = true ) {
		update_option(
			Alert_Settings::OPTION,
			array(
				'channel'  => $selected ? 'fake' : '',
				'channels' => array( 'fake' => array( 'room' => '#alerts' ) ),
			)
		);

		if ( $configured ) {
			( new Credentials() )->set( 'alert_fake_token', 'secret-token' );
		}
	}

	private function mail_error( string $message = 'SMTP connect() failed.' ): WP_Error {
		return new WP_Error(
			'wp_mail_failed',
			$message,
			array(
				'to'      => array( 'a@example.org' ),
				'subject' => 'Hello',
			)
		);
	}

	public function test_add_hooks_listens_to_failures_after_the_logger() {
		$this->dispatcher->add_hooks();

		$this->assertSame( 20, has_action( 'wp_mail_failed', array( $this->dispatcher, 'handle_failure' ) ) );
	}

	public function test_a_failure_is_sent_to_the_active_channel() {
		$this->enable();

		$this->dispatcher->handle_failure( $this->mail_error() );

		$this->assertCount( 1, $this->fake->sent );

		$alert = $this->fake->sent[0]['alert'];
		$this->assertSame( Alert::TYPE_FAILURE, $alert->get_type() );
		$this->assertSame( 'SMTP connect() failed.', $alert->get_error() );
		$this->assertSame( array( 'a@example.org' ), $alert->get_recipients() );
		$this->assertSame( 1, $alert->get_failures() );
		$this->assertSame(
			array(
				'room'  => '#alerts',
				'token' => 'secret-token',
			),
			$this->fake->sent[0]['values']
		);
	}

	public function test_a_real_wp_mail_failure_triggers_an_alert() {
		$this->enable();
		$this->dispatcher->add_hooks();

		wp_mail( 'not-an-email', 'Hello', 'Body' );

		$this->assertCount( 1, $this->fake->sent );
	}

	public function test_nothing_is_sent_when_alerts_are_disabled() {
		$this->enable( false );

		$this->dispatcher->handle_failure( $this->mail_error() );

		$this->assertSame( array(), $this->fake->sent );
	}

	public function test_nothing_is_sent_to_an_unconfigured_channel() {
		$this->enable( true, false );

		$this->dispatcher->handle_failure( $this->mail_error() );

		$this->assertSame( array(), $this->fake->sent );
	}

	public function test_the_alert_can_be_skipped_with_a_filter() {
		$this->enable();
		add_filter( 'smtp_alert_enabled', '__return_false' );

		$this->dispatcher->handle_failure( $this->mail_error() );

		$this->assertSame( array(), $this->fake->sent );
	}

	public function test_non_errors_are_ignored() {
		$this->enable();

		$this->dispatcher->handle_failure( 'not an error' );

		$this->assertSame( array(), $this->fake->sent );
	}

	public function test_failures_within_the_throttle_window_are_counted_in_the_next_alert() {
		$this->enable();

		$this->dispatcher->handle_failure( $this->mail_error( 'First' ) );
		$this->dispatcher->handle_failure( $this->mail_error( 'Second' ) );
		$this->dispatcher->handle_failure( $this->mail_error( 'Third' ) );

		$this->assertCount( 1, $this->fake->sent );
		$this->assertSame( 2, $this->dispatcher->get_state( 'fake' )['suppressed'] );

		// Move the last alert out of the throttle window.
		update_option(
			Alert_Dispatcher::STATE_OPTION,
			array( 'fake' => array_merge( $this->dispatcher->get_state( 'fake' ), array( 'last_sent' => time() - Alert_Dispatcher::DEFAULT_THROTTLE - 1 ) ) )
		);

		$this->dispatcher->handle_failure( $this->mail_error( 'Fourth' ) );

		$this->assertCount( 2, $this->fake->sent );
		$this->assertSame( 'Fourth', $this->fake->sent[1]['alert']->get_error() );
		$this->assertSame( 3, $this->fake->sent[1]['alert']->get_failures() );
		$this->assertSame( 0, $this->dispatcher->get_state( 'fake' )['suppressed'] );
	}

	public function test_the_throttle_can_be_disabled_with_a_filter() {
		$this->enable();
		add_filter( 'smtp_alert_throttle', '__return_zero' );

		$this->dispatcher->handle_failure( $this->mail_error() );
		$this->dispatcher->handle_failure( $this->mail_error() );

		$this->assertCount( 2, $this->fake->sent );
	}

	public function test_a_delivery_error_is_recorded_and_cleared_by_the_next_success() {
		$this->enable();
		add_filter( 'smtp_alert_throttle', '__return_zero' );

		$this->fake->result = new WP_Error( 'down', 'Slack is down' );
		$this->dispatcher->handle_failure( $this->mail_error() );

		$state = $this->dispatcher->get_state( 'fake' );
		$this->assertSame( 'Slack is down', $state['last_error'] );
		$this->assertGreaterThan( 0, $state['last_error_time'] );

		$this->fake->result = true;
		$this->dispatcher->handle_failure( $this->mail_error() );

		$this->assertSame( '', $this->dispatcher->get_state( 'fake' )['last_error'] );
	}

	public function test_a_failure_while_dispatching_does_not_trigger_another_alert() {
		$this->enable();
		add_filter( 'smtp_alert_throttle', '__return_zero' );
		$this->dispatcher->add_hooks();

		// A channel that ends up calling wp_mail() would otherwise loop.
		$this->fake->on_send = static function () {
			wp_mail( 'not-an-email', 'Nested', 'Body' );
		};

		$this->dispatcher->handle_failure( $this->mail_error() );

		$this->assertCount( 1, $this->fake->sent );
	}

	public function test_get_state_defaults() {
		$this->assertSame(
			array(
				'last_sent'       => 0,
				'suppressed'      => 0,
				'last_error'      => '',
				'last_error_time' => 0,
			),
			$this->dispatcher->get_state( 'fake' )
		);
	}

	public function test_send_test_sends_a_test_alert_even_when_disabled() {
		$this->enable( false );

		$this->assertTrue( $this->dispatcher->send_test( $this->fake ) );
		$this->assertCount( 1, $this->fake->sent );
		$this->assertSame( Alert::TYPE_TEST, $this->fake->sent[0]['alert']->get_type() );
	}

	public function test_send_test_requires_a_configured_channel() {
		$this->enable( true, false );

		$this->assertWPError( $this->dispatcher->send_test( $this->fake ) );
		$this->assertSame( array(), $this->fake->sent );
	}
}
