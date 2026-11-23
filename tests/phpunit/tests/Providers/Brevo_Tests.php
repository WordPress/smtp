<?php
/**
 * Tests for the Brevo provider.
 *
 * @package smtp
 */

use WordPress\SMTP\Credentials;
use WordPress\SMTP\Delivery_Tracker;
use WordPress\SMTP\Mailer;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Providers\Brevo;
use WordPress\SMTP\Settings;
use WordPress\SMTP\Transport_PHPMailer;
use WordPress\SMTP\Test_Utils\Traits\With_Log_Table;
use WordPress\SMTP\Test_Utils\Traits\With_PHPMailer;

class Brevo_Tests extends WP_UnitTestCase {
	use With_Log_Table;
	use With_PHPMailer;

	/**
	 * Requests sent to Brevo.
	 *
	 * @var array[]
	 */
	private $requests = array();

	/**
	 * Response returned by the send endpoint, or null for an HTTP error.
	 *
	 * @var array|null
	 */
	private $response;

	/**
	 * @var Brevo
	 */
	private $brevo;

	public function set_up() {
		parent::set_up();

		add_filter( 'wp_mail_from', array( $this, 'get_valid_from_email' ) );
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );

		$this->create_log_table();

		// Loads the PHPMailer classes before Transport_PHPMailer is autoloaded.
		$this->get_phpmailer();

		$this->brevo    = new Brevo();
		$this->requests = array();
		$this->response = self::response( 201, array( 'messageId' => '<msg-1@smtp-relay.mailin.fr>' ) );
	}

	public function get_valid_from_email() {
		return 'wordpress@example.org';
	}

	public function mock_http( $preempt, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		if ( Brevo::SEND_URL !== $url || null === $this->response ) {
			return new WP_Error( 'unexpected_request', $url );
		}

		return $this->response;
	}

	private static function response( int $code, $body ): array {
		return array(
			'headers'  => array(),
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	private function send( ?callable $prepare = null ) {
		$mailer = new Transport_PHPMailer( false );
		$mailer->setFrom( 'wordpress@example.org', 'My Site' );
		$mailer->addAddress( 'to@example.org', 'Jane' );
		$mailer->Subject = 'Hello';
		$mailer->Body    = 'Body';
		$mailer->Timeout = 15;

		if ( $prepare ) {
			$prepare( $mailer );
		}

		$mailer->set_transport( array( $this->brevo, 'send' ), array( 'brevo_api_key' => 'xkeysib-secret' ), array( 'xkeysib-secret' ) );

		return array( $mailer->send(), $mailer );
	}

	private function get_body(): array {
		return json_decode( $this->requests[0]['args']['body'], true );
	}

	public function test_send_posts_the_email_as_json() {
		list( $sent ) = $this->send();

		$this->assertTrue( $sent );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( Brevo::SEND_URL, $this->requests[0]['url'] );
		$this->assertSame( 'xkeysib-secret', $this->requests[0]['args']['headers']['api-key'] );
		$this->assertSame( 15, $this->requests[0]['args']['timeout'] );

		$body = $this->get_body();
		$this->assertSame(
			array(
				'email' => 'wordpress@example.org',
				'name'  => 'My Site',
			),
			$body['sender']
		);
		$this->assertSame(
			array(
				array(
					'email' => 'to@example.org',
					'name'  => 'Jane',
				),
			),
			$body['to']
		);
		$this->assertSame( 'Hello', $body['subject'] );
		$this->assertSame( 'Body', $body['textContent'] );
		$this->assertArrayNotHasKey( 'htmlContent', $body );
		$this->assertArrayNotHasKey( 'cc', $body );
		$this->assertArrayNotHasKey( 'attachment', $body );
	}

	public function test_html_email_sends_the_html_and_the_text_version() {
		$this->send(
			static function ( $mailer ) {
				$mailer->isHTML( true );
				$mailer->Body    = '<p>Body</p>';
				$mailer->AltBody = 'Body';
			}
		);

		$body = $this->get_body();
		$this->assertSame( '<p>Body</p>', $body['htmlContent'] );
		$this->assertSame( 'Body', $body['textContent'] );
	}

	public function test_cc_bcc_reply_to_and_headers_are_sent() {
		$this->send(
			static function ( $mailer ) {
				$mailer->addCC( 'cc@example.org' );
				$mailer->addBCC( 'hidden@example.org' );
				$mailer->addReplyTo( 'reply@example.org', 'Support' );
				$mailer->addReplyTo( 'second@example.org' );
				$mailer->addCustomHeader( 'X-Campaign', 'welcome' );
			}
		);

		$body = $this->get_body();
		$this->assertSame( array( array( 'email' => 'cc@example.org' ) ), $body['cc'] );
		$this->assertSame( array( array( 'email' => 'hidden@example.org' ) ), $body['bcc'] );
		$this->assertSame(
			array(
				'email' => 'reply@example.org',
				'name'  => 'Support',
			),
			$body['replyTo'],
			'Brevo accepts a single Reply-To address, the first one is used.'
		);
		$this->assertSame( array( 'X-Campaign' => 'welcome' ), $body['headers'] );
	}

	public function test_attachments_are_sent_base64_encoded() {
		$file = wp_tempnam( 'brevo' );
		file_put_contents( $file, 'file content' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->send(
			static function ( $mailer ) use ( $file ) {
				$mailer->addAttachment( $file, 'report.txt' );
				$mailer->addStringAttachment( 'string content', 'notes.txt' );
			}
		);

		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$body = $this->get_body();
		$this->assertSame(
			array(
				array(
					'content' => base64_encode( 'file content' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'name'    => 'report.txt',
				),
				array(
					'content' => base64_encode( 'string content' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'name'    => 'notes.txt',
				),
			),
			$body['attachment']
		);
	}

	public function test_a_refused_email_fails_with_the_brevo_error() {
		$this->response = self::response(
			400,
			array(
				'code'    => 'invalid_parameter',
				'message' => 'sender is not valid',
			)
		);

		list( $sent, $mailer ) = $this->send();

		$this->assertFalse( $sent );
		$this->assertStringContainsString( 'Brevo refused the email (HTTP 400): invalid_parameter: sender is not valid', $mailer->ErrorInfo );
	}

	public function test_the_api_key_echoed_by_brevo_is_redacted() {
		$this->response = self::response(
			401,
			array(
				'code'    => 'unauthorized',
				'message' => 'Key not found: xkeysib-secret',
			)
		);

		list( , $mailer ) = $this->send();

		$this->assertStringNotContainsString( 'xkeysib-secret', $mailer->ErrorInfo );
		$this->assertStringContainsString( '[redacted]', $mailer->ErrorInfo );
	}

	public function test_an_http_error_is_returned() {
		$this->response = null;

		list( $sent, $mailer ) = $this->send();

		$this->assertFalse( $sent );
		$this->assertStringContainsString( Brevo::SEND_URL, $mailer->ErrorInfo );
	}

	public function test_the_mailer_sends_through_brevo_when_brevo_is_the_provider() {
		update_option( Settings::OPTION, array( 'provider' => 'brevo' ) );
		$credentials = new Credentials();
		$credentials->set( 'brevo_api_key', 'xkeysib-secret' );

		$registry = new Provider_Registry();
		$settings = new Settings( $registry, $credentials );
		$this->assertTrue( $settings->is_configured() );

		$phpmailer = new Transport_PHPMailer( true );
		$phpmailer->setFrom( 'wordpress@example.org' );
		$phpmailer->addAddress( 'to@example.org' );
		$phpmailer->Body = 'Body';

		( new Mailer( $settings, $registry, new Delivery_Tracker( $settings ) ) )->configure( $phpmailer );

		$this->assertTrue( $phpmailer->send() );
		$this->assertSame( 'mail', $phpmailer->Mailer, 'The Brevo provider must not switch PHPMailer to SMTP.' );
		$this->assertCount( 1, $this->requests );
	}
}
