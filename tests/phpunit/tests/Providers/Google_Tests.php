<?php
/**
 * Tests for the Google provider.
 *
 * @package smtp
 */

use WordPress\SMTP\Credentials;
use WordPress\SMTP\Delivery_Tracker;
use WordPress\SMTP\Mailer;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Providers\Google;
use WordPress\SMTP\Settings;
use WordPress\SMTP\Transport_PHPMailer;
use WordPress\SMTP\Test_Utils\Traits\With_Log_Table;
use WordPress\SMTP\Test_Utils\Traits\With_PHPMailer;

class Google_Tests extends WP_UnitTestCase {
	use With_Log_Table;
	use With_PHPMailer;

	/**
	 * Requests sent to the Google endpoints.
	 *
	 * @var array[]
	 */
	private $requests = array();

	/**
	 * Responses returned by the token endpoint and the send endpoint, in order.
	 *
	 * @var array
	 */
	private $responses = array();

	/**
	 * @var Google
	 */
	private $google;

	public function set_up() {
		parent::set_up();

		add_filter( 'wp_mail_from', array( $this, 'get_valid_from_email' ) );
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );

		$this->create_log_table();

		// Loads the PHPMailer classes before Transport_PHPMailer is autoloaded.
		$this->get_phpmailer();

		$this->google    = new Google();
		$this->requests  = array();
		$this->responses = array(
			Google::TOKEN_URL => array(
				self::response(
					200,
					array(
						'access_token' => 'access-1',
						'expires_in'   => 3599,
					)
				),
			),
			Google::SEND_URL  => array( self::response( 200, array( 'id' => 'msg-1' ) ) ),
		);
	}

	public function get_valid_from_email() {
		return 'wordpress@example.org';
	}

	public function mock_http( $preempt, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		if ( empty( $this->responses[ $url ] ) ) {
			return new WP_Error( 'unexpected_request', $url );
		}

		// The last response repeats for later requests.
		return count( $this->responses[ $url ] ) > 1 ? array_shift( $this->responses[ $url ] ) : $this->responses[ $url ][0];
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

	private static function values(): array {
		return array(
			'google_client_id'     => 'client-id.apps.googleusercontent.com',
			'google_client_secret' => 'client-secret',
			'google_refresh_token' => 'refresh-token',
		);
	}

	private function get_requests( string $url ): array {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $request ) use ( $url ) {
					return $request['url'] === $url;
				}
			)
		);
	}

	private function send( array $values = array(), ?callable $prepare = null ) {
		$mailer = new Transport_PHPMailer( false );
		$mailer->setFrom( 'wordpress@example.org' );
		$mailer->addAddress( 'to@example.org' );
		$mailer->Subject = 'Hello';
		$mailer->Body    = 'Body';
		$mailer->Timeout = 15;

		if ( $prepare ) {
			$prepare( $mailer );
		}

		$values = $values ? $values : self::values();
		$mailer->set_transport( array( $this->google, 'send' ), $values, array( $values['google_client_secret'], $values['google_refresh_token'] ) );

		return array( $mailer->send(), $mailer );
	}

	private static function decode_raw( array $request ): string {
		$body = json_decode( $request['args']['body'], true );

		return base64_decode( strtr( $body['raw'], '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	public function test_send_exchanges_the_refresh_token_and_posts_the_raw_message() {
		list( $sent ) = $this->send();

		$this->assertTrue( $sent );

		$token = $this->get_requests( Google::TOKEN_URL );
		$this->assertCount( 1, $token );
		$this->assertSame( 'refresh_token', $token[0]['args']['body']['grant_type'] );
		$this->assertSame( 'client-id.apps.googleusercontent.com', $token[0]['args']['body']['client_id'] );
		$this->assertSame( 'client-secret', $token[0]['args']['body']['client_secret'] );
		$this->assertSame( 'refresh-token', $token[0]['args']['body']['refresh_token'] );
		$this->assertSame( 15, $token[0]['args']['timeout'] );

		$send = $this->get_requests( Google::SEND_URL );
		$this->assertCount( 1, $send );
		$this->assertSame( 'Bearer access-1', $send[0]['args']['headers']['Authorization'] );
		$this->assertSame( 15, $send[0]['args']['timeout'] );

		$body = json_decode( $send[0]['args']['body'], true );
		$this->assertDoesNotMatchRegularExpression( '/[+\/=]/', $body['raw'], 'The raw message must be base64url without padding.' );

		$raw = self::decode_raw( $send[0] );
		$this->assertStringContainsString( 'To: to@example.org', $raw );
		$this->assertStringContainsString( 'Subject: Hello', $raw );
		$this->assertStringContainsString( 'Body', $raw );
	}

	public function test_the_access_token_is_cached() {
		$this->send();
		$this->send();

		$this->assertCount( 1, $this->get_requests( Google::TOKEN_URL ) );
		$this->assertCount( 2, $this->get_requests( Google::SEND_URL ) );
	}

	public function test_each_account_gets_its_own_access_token() {
		$this->send();

		$other                         = self::values();
		$other['google_refresh_token'] = 'other-refresh-token';
		$this->send( $other );

		$this->assertCount( 2, $this->get_requests( Google::TOKEN_URL ) );
	}

	public function test_bcc_recipients_are_kept_in_the_raw_message() {
		$this->send(
			array(),
			static function ( $mailer ) {
				$mailer->addBCC( 'hidden@example.org' );
				// Another phpmailer_init callback may switch the mailer to SMTP, which drops the Bcc header.
				$mailer->isSMTP();
			}
		);

		$raw = self::decode_raw( $this->get_requests( Google::SEND_URL )[0] );
		$this->assertMatchesRegularExpression( '/^Bcc: hidden@example\.org/m', $raw );
	}

	public function test_bcc_header_is_not_duplicated() {
		$this->send(
			array(),
			static function ( $mailer ) {
				$mailer->addBCC( 'hidden@example.org' );
			}
		);

		$raw = self::decode_raw( $this->get_requests( Google::SEND_URL )[0] );
		$this->assertSame( 1, preg_match_all( '/^Bcc:/mi', $raw ) );
	}

	public function test_a_refused_refresh_token_fails_with_the_google_error() {
		$this->responses[ Google::TOKEN_URL ] = array(
			self::response(
				400,
				array(
					'error'             => 'invalid_grant',
					'error_description' => 'Token has been expired or revoked.',
				)
			),
		);

		list( $sent, $mailer ) = $this->send();

		$this->assertFalse( $sent );
		$this->assertStringContainsString( 'Google did not issue an access token (HTTP 400): invalid_grant: Token has been expired or revoked.', $mailer->ErrorInfo );
		$this->assertCount( 0, $this->get_requests( Google::SEND_URL ) );
	}

	public function test_a_refused_email_fails_with_the_gmail_error() {
		$this->responses[ Google::SEND_URL ] = array(
			self::response( 403, array( 'error' => array( 'message' => 'Request had insufficient authentication scopes.' ) ) ),
		);

		list( $sent, $mailer ) = $this->send();

		$this->assertFalse( $sent );
		$this->assertStringContainsString( 'Gmail refused the email (HTTP 403): Request had insufficient authentication scopes.', $mailer->ErrorInfo );
	}

	public function test_an_unauthorized_answer_drops_the_cached_token() {
		$this->responses[ Google::SEND_URL ] = array(
			self::response( 401, array( 'error' => array( 'message' => 'Invalid Credentials' ) ) ),
			self::response( 200, array( 'id' => 'msg-2' ) ),
		);

		list( $first )  = $this->send();
		list( $second ) = $this->send();

		$this->assertFalse( $first );
		$this->assertTrue( $second );
		$this->assertCount( 2, $this->get_requests( Google::TOKEN_URL ), 'A new access token must be requested after a 401.' );
	}

	public function test_secrets_echoed_by_google_are_redacted() {
		$this->responses[ Google::TOKEN_URL ] = array(
			self::response(
				400,
				array(
					'error'             => 'invalid_client',
					'error_description' => 'Unknown secret client-secret',
				)
			),
		);

		list( , $mailer ) = $this->send();

		$this->assertStringNotContainsString( 'client-secret', $mailer->ErrorInfo );
		$this->assertStringContainsString( '[redacted]', $mailer->ErrorInfo );
	}

	public function test_an_http_error_is_returned() {
		$this->responses[ Google::TOKEN_URL ] = array();

		list( $sent, $mailer ) = $this->send();

		$this->assertFalse( $sent );
		$this->assertStringContainsString( Google::TOKEN_URL, $mailer->ErrorInfo );
	}

	public function test_the_mailer_sends_through_gmail_when_google_is_the_provider() {
		update_option(
			Settings::OPTION,
			array(
				'provider'         => 'google',
				'google_client_id' => 'client-id.apps.googleusercontent.com',
			)
		);
		$credentials = new Credentials();
		$credentials->set( 'google_client_secret', 'client-secret' );
		$credentials->set( 'google_refresh_token', 'refresh-token' );

		$registry = new Provider_Registry();
		$settings = new Settings( $registry, $credentials );
		$this->assertTrue( $settings->is_configured() );

		$phpmailer = new Transport_PHPMailer( true );
		$phpmailer->setFrom( 'wordpress@example.org' );
		$phpmailer->addAddress( 'to@example.org' );
		$phpmailer->Body = 'Body';

		( new Mailer( $settings, $registry, new Delivery_Tracker( $settings ) ) )->configure( $phpmailer );

		$this->assertTrue( $phpmailer->send() );
		$this->assertSame( 'mail', $phpmailer->Mailer, 'The Google provider must not switch PHPMailer to SMTP.' );
		$this->assertCount( 1, $this->get_requests( Google::SEND_URL ) );
	}
}
