<?php
/**
 * Tests for the Slack_Channel class.
 *
 * @package smtp
 */

use WordPress\SMTP\Alerts\Alert;
use WordPress\SMTP\Alerts\Alert_Settings;
use WordPress\SMTP\Alerts\Slack_Channel;

class Slack_Channel_Tests extends WP_UnitTestCase {

	const WEBHOOK = 'https://hooks.slack.com/services/T000/B000/XXXX';

	/**
	 * @var Slack_Channel
	 */
	private $channel;

	/**
	 * @var array[]
	 */
	private $requests = array();

	/**
	 * @var array|WP_Error
	 */
	private $response;

	public function set_up() {
		parent::set_up();

		$GLOBALS['wp_settings_errors'] = array();

		$this->channel  = new Slack_Channel();
		$this->requests = array();
		$this->response = array(
			'headers'  => array(),
			'body'     => 'ok',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);

		add_filter( 'pre_http_request', array( $this, 'intercept_request' ), 10, 3 );
	}

	public function intercept_request( $preempt, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		return $this->response;
	}

	public function test_the_webhook_url_is_a_secret_field() {
		$fields = $this->channel->get_fields();

		$this->assertSame( 'slack', $this->channel->get_id() );
		$this->assertSame( 'password', $fields['webhook_url']['type'] );
	}

	public function test_sanitize_settings_keeps_a_valid_webhook_url() {
		$this->assertSame(
			array( 'webhook_url' => self::WEBHOOK ),
			$this->channel->sanitize_settings( array( 'webhook_url' => '  ' . self::WEBHOOK . ' ' ) )
		);
		$this->assertSame( array(), get_settings_errors( Alert_Settings::OPTION ) );
	}

	/**
	 * @dataProvider data_invalid_webhook_urls
	 */
	public function test_sanitize_settings_rejects_other_urls( $url ) {
		$this->assertSame( array( 'webhook_url' => '' ), $this->channel->sanitize_settings( array( 'webhook_url' => $url ) ) );
		$this->assertCount( 1, get_settings_errors( Alert_Settings::OPTION ) );
	}

	public function data_invalid_webhook_urls() {
		return array(
			'http'           => array( 'http://hooks.slack.com/services/T000/B000/XXXX' ),
			'other host'     => array( 'https://example.org/services/T000' ),
			'lookalike host' => array( 'https://hooks.slack.com.example.org/services/T000' ),
			'no path'        => array( 'https://hooks.slack.com/' ),
		);
	}

	public function test_an_empty_webhook_url_is_not_an_error() {
		$this->assertSame( array( 'webhook_url' => '' ), $this->channel->sanitize_settings( array() ) );
		$this->assertSame( array(), get_settings_errors( Alert_Settings::OPTION ) );
	}

	public function test_is_configured_requires_a_valid_webhook_url() {
		$this->assertTrue( $this->channel->is_configured( array( 'webhook_url' => self::WEBHOOK ) ) );
		$this->assertFalse( $this->channel->is_configured( array( 'webhook_url' => '' ) ) );
		$this->assertFalse( $this->channel->is_configured( array( 'webhook_url' => 'https://example.org' ) ) );
		$this->assertFalse( $this->channel->is_configured( array() ) );
	}

	public function test_send_posts_json_to_the_webhook() {
		$result = $this->channel->send( new Alert( Alert::TYPE_TEST ), array( 'webhook_url' => self::WEBHOOK ) );

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( self::WEBHOOK, $this->requests[0]['url'] );
		$this->assertSame( 'POST', $this->requests[0]['args']['method'] );
		$this->assertSame( Slack_Channel::TIMEOUT, $this->requests[0]['args']['timeout'] );

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertStringContainsString( 'Test alert', $body['text'] );
	}

	public function test_send_does_nothing_without_a_webhook_url() {
		$result = $this->channel->send( new Alert( Alert::TYPE_TEST ), array( 'webhook_url' => '' ) );

		$this->assertWPError( $result );
		$this->assertSame( array(), $this->requests );
	}

	public function test_send_returns_the_transport_error() {
		$this->response = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		$result = $this->channel->send( new Alert( Alert::TYPE_TEST ), array( 'webhook_url' => self::WEBHOOK ) );

		$this->assertWPError( $result );
		$this->assertSame( 'cURL error 28: Operation timed out', $result->get_error_message() );
	}

	public function test_send_returns_an_error_for_a_non_200_response() {
		$this->response['response'] = array(
			'code'    => 404,
			'message' => 'Not Found',
		);
		$this->response['body']     = 'no_service';

		$result = $this->channel->send( new Alert( Alert::TYPE_TEST ), array( 'webhook_url' => self::WEBHOOK ) );

		$this->assertWPError( $result );
		$this->assertSame( 'smtp_slack_http_error', $result->get_error_code() );
		$this->assertStringContainsString( '404', $result->get_error_message() );
		$this->assertStringContainsString( 'no_service', $result->get_error_message() );
	}

	public function test_failure_message_describes_the_failed_email() {
		$message = $this->channel->build_message(
			new Alert( Alert::TYPE_FAILURE, 'SMTP connect() failed.', array( 'a@example.org' ), 'Password reset' )
		);

		$this->assertStringContainsString( 'SMTP connect() failed.', $message );
		$this->assertStringContainsString( 'a@example.org', $message );
		$this->assertStringContainsString( 'Password reset', $message );
		$this->assertStringContainsString( home_url( '/' ), $message );
		$this->assertStringContainsString( 'tab=log&status=failed|View failed emails>', $message );
		$this->assertStringNotContainsString( 'since the previous alert', $message );
	}

	public function test_failure_message_counts_the_throttled_failures() {
		$message = $this->channel->build_message( new Alert( Alert::TYPE_FAILURE, 'Failed.', array(), '', 5 ) );

		$this->assertStringContainsString( '5 emails failed since the previous alert.', $message );
	}

	public function test_failure_message_escapes_slack_control_characters() {
		$message = $this->channel->build_message(
			new Alert( Alert::TYPE_FAILURE, 'Bad <!channel> & co', array( 'Name <b@example.org>' ), '<https://evil.example|Click>' )
		);

		$this->assertStringNotContainsString( '<!channel>', $message );
		$this->assertStringNotContainsString( '<b@example.org>', $message );
		$this->assertStringNotContainsString( '<https://evil.example|Click>', $message );
		$this->assertStringContainsString( 'Bad &lt;!channel&gt; &amp; co', $message );
		$this->assertStringContainsString( 'Name &lt;b@example.org&gt;', $message );
	}
}
