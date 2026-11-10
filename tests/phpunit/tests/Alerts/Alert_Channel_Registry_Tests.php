<?php
/**
 * Tests for the Alert_Channel_Registry class.
 *
 * @package smtp
 */

use WordPress\SMTP\Alerts\Alert_Channel_Registry;
use WordPress\SMTP\Alerts\Slack_Channel;
use WordPress\SMTP\Test_Utils\Alerts\Fake_Channel;

class Alert_Channel_Registry_Tests extends WP_UnitTestCase {

	public function test_slack_is_registered_by_default() {
		$channels = ( new Alert_Channel_Registry() )->get_all();

		$this->assertSame( array( 'slack' ), array_keys( $channels ) );
		$this->assertInstanceOf( Slack_Channel::class, $channels['slack'] );
	}

	public function test_channels_can_be_added_through_the_filter() {
		$fake = new Fake_Channel( 'teams' );
		add_filter(
			'smtp_alert_channels',
			static function ( $channels ) use ( $fake ) {
				$channels[] = $fake;

				return $channels;
			}
		);

		$registry = new Alert_Channel_Registry();

		$this->assertSame( array( 'slack', 'teams' ), array_keys( $registry->get_all() ) );
		$this->assertSame( $fake, $registry->get( 'teams' ) );
	}

	public function test_invalid_and_duplicate_channels_are_discarded() {
		add_filter(
			'smtp_alert_channels',
			static function ( $channels ) {
				$channels[] = 'not a channel';
				$channels[] = new stdClass();
				$channels[] = new Fake_Channel( 'Invalid ID' );
				$channels[] = new Fake_Channel( '' );
				$channels[] = new Fake_Channel( 'slack' );

				return $channels;
			}
		);

		$channels = ( new Alert_Channel_Registry() )->get_all();

		$this->assertSame( array( 'slack' ), array_keys( $channels ) );
		$this->assertInstanceOf( Slack_Channel::class, $channels['slack'] );
	}

	public function test_a_filter_returning_a_non_array_yields_no_channels() {
		add_filter( 'smtp_alert_channels', '__return_null' );

		$this->assertSame( array(), ( new Alert_Channel_Registry() )->get_all() );
	}

	public function test_get_returns_null_for_an_unknown_channel() {
		$this->assertNull( ( new Alert_Channel_Registry() )->get( 'unknown' ) );
	}
}
