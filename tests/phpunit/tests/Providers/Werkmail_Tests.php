<?php
/**
 * Tests for the Werkmail provider.
 *
 * @package smtp
 */

use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Providers\Werkmail;
use WordPress\SMTP\Test_Utils\Traits\With_PHPMailer;

class Werkmail_Tests extends WP_UnitTestCase {
	use With_PHPMailer;

	/**
	 * @var Werkmail
	 */
	private $werkmail;

	public function set_up() {
		parent::set_up();

		$this->werkmail = new Werkmail();
	}

	public function test_provider_is_registered() {
		$registry = new Provider_Registry();

		$this->assertArrayHasKey( 'werkmail', $registry->get_all() );
		$this->assertContains( 'werkmail', Provider_Registry::BUILT_IN );
		// Adding a provider must not change what a site without settings uses.
		$this->assertSame( 'smtp', $registry->get_default_slug() );
	}

	public function test_provider_needs_a_login_and_no_host_field() {
		$provider = $this->werkmail->get_provider();

		$this->assertSame( 'Werkmail', $provider['label'] );
		$this->assertSame( array( 'username', 'password' ), $provider['required'] );
		// The submission endpoint is fixed, so the screen must not ask for it.
		$this->assertArrayNotHasKey( 'host', $provider['fields'] );
		$this->assertSame( 'smtp.werkmail.eu', Werkmail::HOST );
	}

	public function test_provider_declares_the_default_rate_limit() {
		$provider = $this->werkmail->get_provider();

		$this->assertSame( array( 'per_hour' => 500 ), $provider['rate_limits'] );
	}

	public function test_configure_with_starttls() {
		$phpmailer = $this->get_phpmailer();

		$this->werkmail->configure(
			$phpmailer,
			array(
				'port'     => '587',
				'username' => 'praxis.abc',
				'password' => 'secret',
			)
		);

		$this->assertSame( 'smtp', $phpmailer->Mailer );
		$this->assertSame( 'smtp.werkmail.eu', $phpmailer->Host );
		$this->assertSame( 587, $phpmailer->Port );
		$this->assertSame( 'tls', $phpmailer->SMTPSecure );
		$this->assertTrue( $phpmailer->SMTPAutoTLS );
		$this->assertTrue( $phpmailer->SMTPAuth );
		$this->assertSame( 'praxis.abc', $phpmailer->Username );
		$this->assertSame( 'secret', $phpmailer->Password );
	}

	public function test_configure_with_implicit_tls() {
		$phpmailer = $this->get_phpmailer();

		$this->werkmail->configure(
			$phpmailer,
			array(
				'port'     => '465',
				'username' => 'praxis.abc',
				'password' => 'secret',
			)
		);

		$this->assertSame( 465, $phpmailer->Port );
		$this->assertSame( 'ssl', $phpmailer->SMTPSecure );
		$this->assertFalse( $phpmailer->SMTPAutoTLS );
	}

	public function test_configure_falls_back_to_starttls_for_an_unknown_port() {
		$phpmailer = $this->get_phpmailer();

		$this->werkmail->configure(
			$phpmailer,
			array(
				'port'     => '25',
				'username' => 'praxis.abc',
				'password' => 'secret',
			)
		);

		$this->assertSame( 587, $phpmailer->Port );
		$this->assertSame( 'tls', $phpmailer->SMTPSecure );
	}

	public function test_configuration_is_never_sent_unencrypted() {
		$phpmailer = $this->get_phpmailer();

		$this->werkmail->configure(
			$phpmailer,
			array(
				'port'     => '587',
				'username' => 'praxis.abc',
				'password' => 'secret',
			)
		);

		$this->assertNotSame( '', $phpmailer->SMTPSecure );
	}
}
