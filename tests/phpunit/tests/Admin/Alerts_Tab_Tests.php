<?php
/**
 * Tests for the Alerts_Tab class.
 *
 * @package smtp
 */

use WordPress\SMTP\Admin\Alerts_Tab;
use WordPress\SMTP\Alerts\Alert_Channel_Registry;
use WordPress\SMTP\Alerts\Alert_Dispatcher;
use WordPress\SMTP\Alerts\Alert_Settings;
use WordPress\SMTP\Credentials;
use WordPress\SMTP\Test_Utils\Alerts\Fake_Channel;

class Alerts_Tab_Tests extends WP_UnitTestCase {

	const WEBHOOK = 'https://hooks.slack.com/services/T000/B000/SECRETPART';

	/**
	 * @var Alerts_Tab
	 */
	private $tab;

	/**
	 * @var Alert_Settings
	 */
	private $settings;

	/**
	 * @var Alert_Dispatcher
	 */
	private $dispatcher;

	/**
	 * @var array[]
	 */
	private $requests = array();

	public function set_up() {
		parent::set_up();

		$this->settings   = new Alert_Settings( new Alert_Channel_Registry(), new Credentials() );
		$this->dispatcher = new Alert_Dispatcher( $this->settings );
		$this->tab        = new Alerts_Tab( $this->settings, $this->dispatcher );
		$this->requests   = array();

		add_filter( 'pre_http_request', array( $this, 'intercept_request' ), 10, 3 );
	}

	public function tear_down() {
		unset( $_REQUEST['_wpnonce'], $_POST['channel'] );

		parent::tear_down();
	}

	public function intercept_request( $preempt, $args, $url ) {
		$this->requests[] = $url;

		return array(
			'headers'  => array(),
			'body'     => 'ok',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	private function configure_slack( bool $selected = true ) {
		update_option( Alert_Settings::OPTION, array( 'channel' => $selected ? 'slack' : '' ) );
		( new Credentials() )->set( Alert_Settings::get_secret_key( 'slack', 'webhook_url' ), self::WEBHOOK );
	}

	private function as_admin() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function render(): string {
		ob_start();
		$this->tab->render();

		return ob_get_clean();
	}

	private function run_test_handler( string $channel ): string {
		$_REQUEST['_wpnonce'] = wp_create_nonce( Alerts_Tab::TEST_ACTION );
		$_POST['channel']     = $channel;

		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( $location );
			}
		);

		try {
			$this->tab->handle_test();
		} catch ( RuntimeException $redirect ) {
			return $redirect->getMessage();
		}

		return '';
	}

	public function test_add_hooks_registers_the_settings_and_the_test_action() {
		$this->tab->add_hooks();

		$this->assertSame( 10, has_action( 'admin_init', array( $this->tab, 'register_settings' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_smtp_send_test_alert', array( $this->tab, 'handle_test' ) ) );
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $this->tab, 'enqueue_script' ) ) );
	}

	public function test_the_toggle_script_is_only_enqueued_on_the_smtp_screen() {
		$this->tab->enqueue_script( 'index.php' );
		$this->assertFalse( wp_script_is( 'smtp-alerts', 'enqueued' ) );

		$this->tab->enqueue_script( 'tools_page_smtp' );
		$this->assertTrue( wp_script_is( 'smtp-alerts', 'enqueued' ) );
		$this->assertStringContainsString( 'data-smtp-alert-channel', implode( '', wp_scripts()->get_data( 'smtp-alerts', 'after' ) ) );
	}

	public function test_register_settings_registers_the_option_in_its_own_group() {
		$this->tab->register_settings();

		$registered = get_registered_settings();

		$this->assertArrayHasKey( Alert_Settings::OPTION, $registered );
		$this->assertSame( Alerts_Tab::OPTION_GROUP, $registered[ Alert_Settings::OPTION ]['group'] );
		$this->assertSame( array( $this->settings, 'sanitize' ), $registered[ Alert_Settings::OPTION ]['sanitize_callback'] );
	}

	public function test_render_shows_the_channel_select_with_none_selected_by_default() {
		$this->as_admin();
		$html = $this->render();

		$this->assertStringContainsString( 'name="smtp_alert_settings[channel]"', $html );
		$this->assertMatchesRegularExpression( '/<option value=""\s+selected=\'selected\'>None<\/option>/', $html );
		$this->assertStringContainsString( '<option value="slack"', $html );
		$this->assertMatchesRegularExpression( '/data-smtp-alert-channel="slack"\s+hidden>/', $html );
		$this->assertStringContainsString( 'name="smtp_alert_settings[channels][slack][webhook_url]"', $html );
		$this->assertStringContainsString( "name='option_page' value='" . Alerts_Tab::OPTION_GROUP . "'", $html );
		$this->assertStringContainsString( 'tab=alerts', $html );
		$this->assertStringNotContainsString( 'Send test alert', $html );
	}

	public function test_the_saved_webhook_url_is_never_printed() {
		$this->as_admin();
		$this->configure_slack();

		$html = $this->render();

		$this->assertStringNotContainsString( 'SECRETPART', $html );
		$this->assertStringContainsString( 'A value is already saved.', $html );
		$this->assertMatchesRegularExpression( '/data-smtp-alert-channel="slack"\s+>/', $html );
		$this->assertStringContainsString( 'Send test alert to Slack', $html );
	}

	public function test_a_selected_unconfigured_channel_shows_a_warning() {
		$this->as_admin();
		update_option( Alert_Settings::OPTION, array( 'channel' => 'slack' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'selected but not fully configured', $html );
		$this->assertStringNotContainsString( 'Send test alert', $html );
	}

	public function test_the_test_button_is_hidden_when_no_channel_is_selected() {
		$this->as_admin();
		$this->configure_slack( false );

		$this->assertStringNotContainsString( 'Send test alert', $this->render() );
	}

	public function test_the_last_delivery_error_is_shown_escaped() {
		$this->as_admin();
		$this->configure_slack();
		update_option(
			Alert_Dispatcher::STATE_OPTION,
			array(
				'slack' => array(
					'last_error'      => 'Slack returned <b>HTTP 404</b>',
					'last_error_time' => time(),
				),
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( 'The last alert to Slack could not be delivered', $html );
		$this->assertStringContainsString( '&lt;b&gt;HTTP 404&lt;/b&gt;', $html );
	}

	public function test_handle_test_sends_a_test_alert_and_reports_success() {
		$this->as_admin();
		$this->configure_slack( false );

		$location = $this->run_test_handler( 'slack' );

		$this->assertStringContainsString( 'tab=alerts', $location );
		$this->assertSame( array( self::WEBHOOK ), $this->requests );
		$this->assertStringContainsString( 'Test alert sent to Slack.', $this->render() );
	}

	public function test_handle_test_reports_an_unconfigured_channel() {
		$this->as_admin();

		$this->run_test_handler( 'slack' );

		$this->assertSame( array(), $this->requests );
		$this->assertStringContainsString( 'The test alert could not be sent', $this->render() );
	}

	public function test_handle_test_reports_an_unknown_channel() {
		$this->as_admin();

		$this->run_test_handler( 'unknown' );

		$this->assertStringContainsString( 'Unknown alert channel.', $this->render() );
	}

	public function test_the_result_is_shown_only_once() {
		$this->as_admin();
		$this->configure_slack();
		$this->run_test_handler( 'slack' );

		$this->render();

		$this->assertStringNotContainsString( 'Test alert sent', $this->render() );
	}

	public function test_handle_test_rejects_users_without_the_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->configure_slack();

		$this->expectException( WPDieException::class );

		$this->run_test_handler( 'slack' );
	}

	public function test_handle_test_rejects_an_invalid_nonce() {
		$this->as_admin();
		$this->configure_slack();
		$_REQUEST['_wpnonce'] = 'invalid';

		$this->expectException( WPDieException::class );

		$this->tab->handle_test();
	}

	public function test_render_lists_channels_added_through_the_filter() {
		$this->as_admin();
		add_filter(
			'smtp_alert_channels',
			static function ( $channels ) {
				$channels[] = new Fake_Channel();

				return $channels;
			}
		);

		$html = $this->render();

		$this->assertStringContainsString( '<option value="fake"', $html );
		$this->assertStringContainsString( 'name="smtp_alert_settings[channels][fake][room]"', $html );
		$this->assertStringContainsString( 'name="smtp_alert_settings[channels][fake][token]"', $html );
	}
}
