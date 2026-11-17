<?php
/**
 * Tests for the Transport_PHPMailer class.
 *
 * @package smtp
 */

use PHPMailer\PHPMailer\Exception;
use WordPress\SMTP\Credentials;
use WordPress\SMTP\Settings;
use WordPress\SMTP\Transport_PHPMailer;
use WordPress\SMTP\Test_Utils\Traits\With_Log_Table;
use WordPress\SMTP\Test_Utils\Traits\With_PHPMailer;

class Transport_PHPMailer_Tests extends WP_UnitTestCase {
	use With_Log_Table;
	use With_PHPMailer;

	/**
	 * @var array
	 */
	private $calls = array();

	/**
	 * @var mixed
	 */
	private $result = true;

	public function set_up() {
		parent::set_up();

		// The wp-env site runs on localhost, which makes the default From address invalid.
		add_filter( 'wp_mail_from', array( $this, 'get_valid_from_email' ) );

		$this->create_log_table();

		// Loads the PHPMailer classes before Transport_PHPMailer is autoloaded.
		$this->get_phpmailer();

		$this->calls  = array();
		$this->result = true;
	}

	public function get_valid_from_email() {
		return 'wordpress@example.org';
	}

	public function send( $phpmailer, $values ) {
		$this->calls[] = array(
			'to'          => wp_list_pluck( $phpmailer->getToAddresses(), 0 ),
			'cc'          => wp_list_pluck( $phpmailer->getCcAddresses(), 0 ),
			// Older PHPMailer versions key Reply-To addresses by address.
			'reply_to'    => array_values( wp_list_pluck( $phpmailer->getReplyToAddresses(), 0 ) ),
			'attachments' => wp_list_pluck( $phpmailer->getAttachments(), 2 ),
			'subject'     => $phpmailer->Subject,
			'body'        => $phpmailer->Body,
			'mime'        => $phpmailer->getSentMIMEMessage(),
			'values'      => $values,
		);

		return $this->result;
	}

	private function get_mailer( bool $exceptions = true ): Transport_PHPMailer {
		$mailer = new Transport_PHPMailer( $exceptions );
		$mailer->setFrom( 'wordpress@example.org' );
		$mailer->addAddress( 'to@example.org' );
		$mailer->Subject = 'Hello';
		$mailer->Body    = 'Body';

		return $mailer;
	}

	private function register_api_provider() {
		add_filter(
			'smtp_providers',
			function ( $providers ) {
				$providers['api'] = array(
					'label'    => 'API',
					'required' => array( 'api_key' ),
					'fields'   => array(
						'api_key' => array(
							'label' => 'API key',
							'type'  => 'password',
						),
					),
					'send'     => array( $this, 'send' ),
				);

				return $providers;
			}
		);
		update_option( Settings::OPTION, array( 'provider' => 'api' ) );
		( new Credentials() )->set( 'api_key', 'key-123' );
	}

	public function test_send_calls_the_transport_with_the_values() {
		$mailer = $this->get_mailer();
		$mailer->set_transport( array( $this, 'send' ), array( 'api_key' => 'abc' ) );

		$this->assertTrue( $mailer->send() );
		$this->assertCount( 1, $this->calls );
		$this->assertSame( array( 'to@example.org' ), $this->calls[0]['to'] );
		$this->assertSame( array( 'api_key' => 'abc' ), $this->calls[0]['values'] );
		$this->assertStringContainsString( 'Subject: Hello', $this->calls[0]['mime'], 'The MIME message must be built for raw APIs.' );
	}

	public function test_the_transport_is_used_for_one_email_only() {
		$mailer = $this->get_mailer();
		$mailer->set_transport( array( $this, 'send' ), array() );
		$mailer->send();

		$mailer->clearAddresses();
		try {
			// Without a transport, PHPMailer validates and fails on the missing recipient.
			$mailer->send();
		} catch ( Exception $e ) {
			unset( $e );
		}

		$this->assertCount( 1, $this->calls );
	}

	public function test_an_invalid_email_never_reaches_the_transport() {
		$mailer = $this->get_mailer();
		$mailer->clearAddresses();
		$mailer->set_transport( array( $this, 'send' ), array() );

		$this->expectException( Exception::class );

		try {
			$mailer->send();
		} finally {
			$this->assertCount( 0, $this->calls );
		}
	}

	public function test_a_wp_error_is_thrown_with_the_secrets_redacted() {
		$this->result = new WP_Error( 'api', 'Invalid key key-123 for this account' );

		$mailer = $this->get_mailer();
		$mailer->set_transport( array( $this, 'send' ), array(), array( 'key-123', '' ) );

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Invalid key [redacted] for this account' );

		$mailer->send();
	}

	public function test_a_result_other_than_true_is_a_failure() {
		$this->result = 'ok';

		$mailer = $this->get_mailer();
		$mailer->set_transport( array( $this, 'send' ), array() );

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'The provider did not confirm that the email was sent.' );

		$mailer->send();
	}

	public function test_an_error_thrown_by_the_transport_becomes_a_phpmailer_exception() {
		$mailer = $this->get_mailer();
		$mailer->set_transport(
			static function () {
				throw new RuntimeException( 'Timeout with key-123' );
			},
			array(),
			array( 'key-123' )
		);

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Timeout with [redacted]' );

		$mailer->send();
	}

	public function test_without_exceptions_a_failure_returns_false() {
		$this->result = new WP_Error( 'api', 'Rejected' );

		$mailer = $this->get_mailer( false );
		$mailer->set_transport( array( $this, 'send' ), array() );

		$this->assertFalse( $mailer->send() );
		$this->assertSame( 'Rejected', $mailer->ErrorInfo );
	}

	public function test_wp_mail_sends_through_the_provider_and_logs_it() {
		$this->register_api_provider();
		$GLOBALS['phpmailer'] = null;

		$file = tempnam( get_temp_dir(), 'smtp' );
		file_put_contents( $file, 'Report' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$sent = wp_mail(
			'to@example.org',
			'Integration',
			'<p>Body</p>',
			array(
				'Content-Type: text/html',
				'Cc: cc@example.org',
				'Reply-To: reply@example.org',
			),
			array( $file )
		);

		$this->assertTrue( $sent );
		$this->assertInstanceOf( Transport_PHPMailer::class, $GLOBALS['phpmailer'] );
		$this->assertCount( 1, $this->calls );
		$this->assertSame( array( 'to@example.org' ), $this->calls[0]['to'] );
		$this->assertSame( array( 'cc@example.org' ), $this->calls[0]['cc'] );
		$this->assertSame( array( 'reply@example.org' ), $this->calls[0]['reply_to'] );
		$this->assertSame( array( basename( $file ) ), $this->calls[0]['attachments'] );
		$this->assertSame( '<p>Body</p>', $this->calls[0]['body'] );
		$this->assertSame( array( 'api_key' => 'key-123' ), $this->calls[0]['values'] );
		wp_delete_file( $file );

		$entry = $this->get_logged_entries()[0];
		$this->assertSame( 'sent', $entry['status'] );
		$this->assertSame( 'api', $entry['provider'] );
	}

	public function test_wp_mail_failure_from_the_provider_is_logged_without_the_key() {
		$this->register_api_provider();
		$GLOBALS['phpmailer'] = null;
		$this->result         = new WP_Error( 'api', 'Key key-123 is not allowed' );

		$failed = null;
		add_action(
			'wp_mail_failed',
			static function ( $error ) use ( &$failed ) {
				$failed = $error;
			}
		);

		$this->assertFalse( wp_mail( 'to@example.org', 'Integration', 'Body' ) );
		$this->assertInstanceOf( WP_Error::class, $failed );
		$this->assertSame( 'Key [redacted] is not allowed', $failed->get_error_message() );

		$entry = $this->get_logged_entries()[0];
		$this->assertSame( 'failed', $entry['status'] );
		$this->assertSame( 'Key [redacted] is not allowed', $entry['error'] );
		$this->assertSame( 'api', $entry['provider'] );
	}

	private function get_error_message( Transport_PHPMailer $mailer ): string {
		try {
			$mailer->send();
		} catch ( Exception $e ) {
			return $e->getMessage();
		}

		$this->fail( 'The email must fail.' );
	}

	public function test_encoded_secrets_are_redacted() {
		$mailer = $this->get_mailer();
		$mailer->set_transport( array( $this, 'send' ), array( 'user' => 'me' ), array( 'key/1+2=' ) );
		$this->result = new WP_Error(
			'api',
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'Plain key/1+2= Base64 ' . base64_encode( 'key/1+2=' ) . ' Basic ' . base64_encode( 'me:key/1+2=' ) . ' URL key%2F1%2B2%3D JSON {"key":"key\/1+2="} {"key":"key/1+2="}'
		);

		$this->assertSame(
			'Plain [redacted] Base64 [redacted] Basic [redacted] URL [redacted] JSON {"key":"[redacted]"} {"key":"[redacted]"}',
			$this->get_error_message( $mailer )
		);
	}

	public function test_json_and_basic_forms_of_a_secret_are_redacted() {
		$mailer = $this->get_mailer();
		$mailer->set_transport( array( $this, 'send' ), array( 'api_key' => 'clé/a' ), array( 'clé/a' ) );
		$this->result = new WP_Error(
			'api',
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'A clé/a B clé\/a C clé\/a D ' . base64_encode( ':clé/a' ) . ' E ' . base64_encode( 'clé/a:clé/a' )
		);

		$this->assertSame( 'A [redacted] B [redacted] C [redacted] D [redacted] E [redacted]', $this->get_error_message( $mailer ) );
	}

	public function test_a_secret_that_contains_another_is_redacted_as_a_whole() {
		$mailer = $this->get_mailer();
		$mailer->set_transport( array( $this, 'send' ), array(), array( 'abc', 'abcdef' ) );
		$this->result = new WP_Error( 'api', 'Bad key abcdef' );

		$this->assertSame( 'Bad key [redacted]', $this->get_error_message( $mailer ) );
	}

	public function test_the_provider_is_read_when_an_unconfigured_default_changes() {
		$this->register_api_provider();
		update_option( Settings::OPTION, array( 'provider' => 'smtp' ) );
		$GLOBALS['phpmailer'] = null;

		add_filter(
			'wp_mail',
			static function ( $atts ) {
				update_option( Settings::OPTION, array( 'provider' => 'api' ) );

				return $atts;
			},
			PHP_INT_MAX
		);

		$this->assertTrue( wp_mail( 'to@example.org', 'Selected', 'Body' ) );
		$this->assertCount( 1, $this->calls );
	}

	public function test_phpmailer_can_not_send_while_a_callback_sends() {
		$error  = '';
		$mailer = $this->get_mailer();
		$mailer->set_transport(
			function () use ( &$error ) {
				try {
					// No callback: this would be a plain SMTP or mail() send.
					$this->get_mailer()->send();
				} catch ( Exception $e ) {
					$error = $e->getMessage();
				}

				return true;
			},
			array()
		);

		$this->assertTrue( $mailer->send() );
		$this->assertSame( 'An email can not be sent while the provider is sending another one.', $error );
	}

	public function test_the_provider_is_read_when_phpmailer_is_set_up() {
		$this->register_api_provider();
		update_option(
			Settings::OPTION,
			array(
				'provider' => 'smtp',
				'host'     => 'smtp.example.com',
				'auth'     => false,
			)
		);
		$GLOBALS['phpmailer'] = null;

		// Runs after the plugin, like a callback that picks the connection of the email.
		add_filter(
			'wp_mail',
			static function ( $atts ) {
				update_option( Settings::OPTION, array( 'provider' => 'api' ) );

				return $atts;
			},
			PHP_INT_MAX
		);

		$this->assertTrue( wp_mail( 'to@example.org', 'Selected', 'Body' ) );
		$this->assertCount( 1, $this->calls );
		$this->assertSame( 'Selected', $this->calls[0]['subject'] );
		$this->assertSame( 'api', $this->get_logged_entries()[0]['provider'] );
	}

	public function test_a_send_callback_can_not_send_another_email() {
		$this->register_api_provider();
		$GLOBALS['phpmailer'] = null;

		$nested = null;
		add_filter(
			'smtp_providers',
			function ( $providers ) use ( &$nested ) {
				$providers['api']['send'] = function ( $phpmailer ) use ( &$nested ) {
					$subject = $phpmailer->Subject;
					$nested  = wp_mail( 'other@example.org', 'Nested', 'Body' );

					$this->calls[] = array(
						'subject' => $subject,
						'after'   => $phpmailer->Subject,
					);

					return true;
				};

				return $providers;
			},
			11
		);

		$this->assertTrue( wp_mail( 'to@example.org', 'Outer', 'Body' ) );
		$this->assertFalse( $nested );
		$this->assertSame(
			array(
				array(
					'subject' => 'Outer',
					'after'   => 'Outer',
				),
			),
			$this->calls,
			'The nested email must not change the email in progress.'
		);
	}
}
