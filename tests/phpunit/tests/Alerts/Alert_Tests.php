<?php
/**
 * Tests for the Alert class.
 *
 * @package smtp
 */

use WordPress\SMTP\Alerts\Alert;

class Alert_Tests extends WP_UnitTestCase {

	public function test_from_mail_error_reads_the_error_and_the_mail_data() {
		$alert = Alert::from_mail_error(
			new WP_Error(
				'wp_mail_failed',
				'SMTP connect() failed.',
				array(
					'to'      => array( 'a@example.org', 'Name <b@example.org>' ),
					'subject' => 'Password reset',
				)
			)
		);

		$this->assertSame( Alert::TYPE_FAILURE, $alert->get_type() );
		$this->assertSame( 'SMTP connect() failed.', $alert->get_error() );
		$this->assertSame( array( 'a@example.org', 'Name <b@example.org>' ), $alert->get_recipients() );
		$this->assertSame( 'Password reset', $alert->get_subject() );
		$this->assertSame( 1, $alert->get_failures() );
	}

	public function test_from_mail_error_accepts_missing_mail_data() {
		$alert = Alert::from_mail_error( new WP_Error( 'wp_mail_failed', 'Failed.' ) );

		$this->assertSame( array(), $alert->get_recipients() );
		$this->assertSame( '', $alert->get_subject() );
	}

	public function test_with_failures_returns_a_copy() {
		$alert = new Alert( Alert::TYPE_FAILURE, 'Failed.', array(), '', 1, 1000 );
		$copy  = $alert->with_failures( 4 );

		$this->assertSame( 1, $alert->get_failures() );
		$this->assertSame( 4, $copy->get_failures() );
		$this->assertSame( 1000, $copy->get_time() );
	}

	public function test_failures_are_at_least_one() {
		$this->assertSame( 1, ( new Alert( Alert::TYPE_FAILURE, '', array(), '', 0 ) )->get_failures() );
		$this->assertSame( 1, ( new Alert( Alert::TYPE_FAILURE ) )->with_failures( -3 )->get_failures() );
	}

	public function test_time_defaults_to_now() {
		$this->assertEqualsWithDelta( time(), ( new Alert( Alert::TYPE_TEST ) )->get_time(), 5 );
	}
}
