<?php
/**
 * Tests for the Microsoft provider.
 *
 * @package smtp
 */

use WordPress\SMTP\Credentials;
use WordPress\SMTP\Delivery_Tracker;
use WordPress\SMTP\Mailer;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Providers\Microsoft;
use WordPress\SMTP\Settings;
use WordPress\SMTP\Transport_PHPMailer;
use WordPress\SMTP\Test_Utils\Traits\With_Log_Table;
use WordPress\SMTP\Test_Utils\Traits\With_PHPMailer;

class Microsoft_Tests extends WP_UnitTestCase {
	use With_Log_Table;
	use With_PHPMailer;

	/**
	 * Requests sent to the Microsoft endpoints.
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
	 * @var Microsoft
	 */
	private $microsoft;

	/**
	 * Token endpoint of the tenant used by the default test values.
	 *
	 * @var string
	 */
	private $token_url = '';

	public function set_up() {
		parent::set_up();

		add_filter( 'wp_mail_from', array( $this, 'get_valid_from_email' ) );
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );

		$this->create_log_table();

		// Loads the PHPMailer classes before Transport_PHPMailer is autoloaded.
		$this->get_phpmailer();

		$this->microsoft = new Microsoft();
		$this->token_url = Microsoft::get_token_url( self::values() );
		$this->requests  = array();
		$this->responses = array(
			$this->token_url    => array(
				self::response(
					200,
					array(
						'access_token' => 'access-1',
						'expires_in'   => 3599,
					)
				),
			),
			Microsoft::SEND_URL => array( self::response( 200, array( 'id' => 'msg-1' ) ) ),
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
			'microsoft_tenant'        => 'tenant-id',
			'microsoft_client_id'     => 'client-id',
			'microsoft_client_secret' => 'client-secret',
			'microsoft_refresh_token' => 'refresh-token',
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
		$mailer->set_transport( array( $this->microsoft, 'send' ), $values, array( $values['microsoft_client_secret'], $values['microsoft_refresh_token'] ) );

		return array( $mailer->send(), $mailer );
	}

	private static function decode_raw( array $request ): string {
		return base64_decode( $request['args']['body'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	public function test_send_exchanges_the_refresh_token_and_posts_the_mime_message() {
		list( $sent ) = $this->send();

		$this->assertTrue( $sent );

		$token = $this->get_requests( $this->token_url );
		$this->assertCount( 1, $token );
		$this->assertSame( 'refresh_token', $token[0]['args']['body']['grant_type'] );
		$this->assertSame( 'client-id', $token[0]['args']['body']['client_id'] );
		$this->assertSame( 'client-secret', $token[0]['args']['body']['client_secret'] );
		$this->assertSame( 'refresh-token', $token[0]['args']['body']['refresh_token'] );
		$this->assertSame( 'https://graph.microsoft.com/Mail.Send offline_access', $token[0]['args']['body']['scope'] );
		$this->assertSame( 15, $token[0]['args']['timeout'] );

		$send = $this->get_requests( Microsoft::SEND_URL );
		$this->assertCount( 1, $send );
		$this->assertSame( 'Bearer access-1', $send[0]['args']['headers']['Authorization'] );
		$this->assertSame( 15, $send[0]['args']['timeout'] );

		$this->assertSame( 'text/plain', $send[0]['args']['headers']['Content-Type'] );
		$this->assertSame( $send[0]['args']['body'], base64_encode( base64_decode( $send[0]['args']['body'], true ) ), 'The body must be a base64 encoded MIME message.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		$raw = self::decode_raw( $send[0] );
		$this->assertStringContainsString( 'To: to@example.org', $raw );
		$this->assertStringContainsString( 'Subject: Hello', $raw );
		$this->assertStringContainsString( 'Body', $raw );
	}

	public function test_the_access_token_is_cached() {
		$this->send();
		$this->send();

		$this->assertCount( 1, $this->get_requests( $this->token_url ) );
		$this->assertCount( 2, $this->get_requests( Microsoft::SEND_URL ) );
	}

	public function test_each_account_gets_its_own_access_token() {
		$this->send();

		$other                            = self::values();
		$other['microsoft_refresh_token'] = 'other-refresh-token';
		$this->send( $other );

		$this->assertCount( 2, $this->get_requests( $this->token_url ) );
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

		$raw = self::decode_raw( $this->get_requests( Microsoft::SEND_URL )[0] );
		$this->assertMatchesRegularExpression( '/^Bcc: hidden@example\.org/m', $raw );
	}

	public function test_bcc_header_is_not_duplicated() {
		$this->send(
			array(),
			static function ( $mailer ) {
				$mailer->addBCC( 'hidden@example.org' );
			}
		);

		$raw = self::decode_raw( $this->get_requests( Microsoft::SEND_URL )[0] );
		$this->assertSame( 1, preg_match_all( '/^Bcc:/mi', $raw ) );
	}

	public function test_the_token_endpoint_depends_on_the_tenant() {
		$this->assertSame( 'https://login.microsoftonline.com/tenant-id/oauth2/v2.0/token', Microsoft::get_token_url( self::values() ) );
		$this->assertSame( 'https://login.microsoftonline.com/common/oauth2/v2.0/token', Microsoft::get_token_url( array() ) );
		$this->assertSame( 'https://login.microsoftonline.com/common/oauth2/v2.0/token', Microsoft::get_token_url( array( 'microsoft_tenant' => '  ' ) ) );
		$this->assertSame( 'https://login.microsoftonline.com/a%2Fb/oauth2/v2.0/token', Microsoft::get_token_url( array( 'microsoft_tenant' => 'a/b' ) ), 'The tenant must not change the path.' );
	}

	public function test_each_tenant_gets_its_own_access_token() {
		$this->send();

		$other                     = self::values();
		$other['microsoft_tenant'] = 'other-tenant';
		$this->responses[ Microsoft::get_token_url( $other ) ] = $this->responses[ $this->token_url ];
		$this->send( $other );

		$this->assertCount( 1, $this->get_requests( Microsoft::get_token_url( $other ) ) );
	}

	public function test_a_refused_refresh_token_fails_with_the_microsoft_error() {
		$this->responses[ $this->token_url ] = array(
			self::response(
				400,
				array(
					'error'             => 'invalid_grant',
					'error_description' => 'The refresh token has expired.',
				)
			),
		);

		list( $sent, $mailer ) = $this->send();

		$this->assertFalse( $sent );
		$this->assertStringContainsString( 'Microsoft did not issue an access token (HTTP 400): invalid_grant: The refresh token has expired.', $mailer->ErrorInfo );
		$this->assertCount( 0, $this->get_requests( Microsoft::SEND_URL ) );
	}

	public function test_a_refused_email_fails_with_the_graph_error() {
		$this->responses[ Microsoft::SEND_URL ] = array(
			self::response( 403, array( 'error' => array( 'message' => 'Access is denied.' ) ) ),
		);

		list( $sent, $mailer ) = $this->send();

		$this->assertFalse( $sent );
		$this->assertStringContainsString( 'Microsoft refused the email (HTTP 403): Access is denied.', $mailer->ErrorInfo );
	}

	public function test_an_unauthorized_answer_drops_the_cached_token() {
		$this->responses[ Microsoft::SEND_URL ] = array(
			self::response( 401, array( 'error' => array( 'message' => 'Invalid Credentials' ) ) ),
			self::response( 200, array( 'id' => 'msg-2' ) ),
		);

		list( $first )  = $this->send();
		list( $second ) = $this->send();

		$this->assertFalse( $first );
		$this->assertTrue( $second );
		$this->assertCount( 2, $this->get_requests( $this->token_url ), 'A new access token must be requested after a 401.' );
	}

	public function test_secrets_echoed_by_microsoft_are_redacted() {
		$this->responses[ $this->token_url ] = array(
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
		$this->responses[ $this->token_url ] = array();

		list( $sent, $mailer ) = $this->send();

		$this->assertFalse( $sent );
		$this->assertStringContainsString( $this->token_url, $mailer->ErrorInfo );
	}

	public function test_the_mailer_sends_through_graph_when_microsoft_is_the_provider() {
		update_option(
			Settings::OPTION,
			array(
				'provider'            => 'microsoft',
				'microsoft_tenant'    => 'tenant-id',
				'microsoft_client_id' => 'client-id',
			)
		);
		$credentials = new Credentials();
		$credentials->set( 'microsoft_client_secret', 'client-secret' );
		$credentials->set( 'microsoft_refresh_token', 'refresh-token' );

		$registry = new Provider_Registry();
		$settings = new Settings( $registry, $credentials );
		$this->assertTrue( $settings->is_configured() );

		$phpmailer = new Transport_PHPMailer( true );
		$phpmailer->setFrom( 'wordpress@example.org' );
		$phpmailer->addAddress( 'to@example.org' );
		$phpmailer->Body = 'Body';

		( new Mailer( $settings, $registry, new Delivery_Tracker( $settings ) ) )->configure( $phpmailer );

		$this->assertTrue( $phpmailer->send() );
		$this->assertSame( 'mail', $phpmailer->Mailer, 'The Microsoft provider must not switch PHPMailer to SMTP.' );
		$this->assertCount( 1, $this->get_requests( Microsoft::SEND_URL ) );
	}
}
