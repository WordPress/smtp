<?php
/**
 * Tests for the Delivery_Tracker class.
 *
 * @package smtp
 */

use WordPress\SMTP\Connections;
use WordPress\SMTP\Credentials;
use WordPress\SMTP\Delivery_Tracker;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Settings;
use WordPress\SMTP\Test_Utils\Traits\With_PHPMailer;

class Delivery_Tracker_Tests extends WP_UnitTestCase {
	use With_PHPMailer;

	/**
	 * @var Delivery_Tracker
	 */
	private $tracker;

	/**
	 * @var Provider_Registry
	 */
	private $registry;

	public function set_up() {
		parent::set_up();

		$this->registry = new Provider_Registry();
		$this->tracker  = new Delivery_Tracker( new Settings( $this->registry, new Credentials() ) );
	}

	private function configure_plugin() {
		update_option(
			Settings::OPTION,
			array(
				'host' => 'smtp.example.com',
				'auth' => false,
			)
		);
	}

	private function configured_phpmailer() {
		$phpmailer = $this->get_phpmailer();
		$this->registry->configure_generic(
			$phpmailer,
			array(
				'host'       => 'smtp.example.com',
				'port'       => 587,
				'encryption' => 'tls',
				'auth'       => false,
				'username'   => '',
				'password'   => '',
			)
		);

		return $phpmailer;
	}

	private function tracker_with_wp_mail_owner( string $owner ): Delivery_Tracker {
		return new class( new Settings( $this->registry, new Credentials() ), $owner ) extends Delivery_Tracker {
			private $owner;

			public function __construct( Settings $settings, string $owner ) {
				parent::__construct( $settings );
				$this->owner = $owner;
			}

			public function get_wp_mail_owner(): string {
				return $this->owner;
			}
		};
	}

	public function test_add_hooks_registers_the_hooks() {
		$this->tracker->add_hooks();

		$this->assertSame( PHP_INT_MAX, has_action( 'phpmailer_init', array( $this->tracker, 'inspect' ) ) );
		$this->assertSame( PHP_INT_MIN, has_action( 'wp_mail_succeeded', array( $this->tracker, 'keep' ) ) );
		$this->assertSame( PHP_INT_MIN, has_action( 'wp_mail_failed', array( $this->tracker, 'keep' ) ) );
		$this->assertSame( PHP_INT_MAX, has_action( 'wp_mail_succeeded', array( $this->tracker, 'reset' ) ) );
		$this->assertSame( PHP_INT_MAX, has_action( 'wp_mail_failed', array( $this->tracker, 'reset' ) ) );
	}

	public function test_an_email_sent_with_the_provider_settings_is_credited_to_the_provider() {
		$this->configure_plugin();
		$phpmailer = $this->configured_phpmailer();

		$this->tracker->remember( $phpmailer );
		$this->tracker->inspect( $phpmailer );

		$this->assertSame( 'smtp', $this->tracker->get_provider() );
	}

	public function test_an_email_sent_through_a_replaced_wp_mail_is_credited_to_another_plugin() {
		$this->configure_plugin();
		$tracker   = $this->tracker_with_wp_mail_owner( 'FluentSMTP' );
		$phpmailer = $this->configured_phpmailer();

		// FluentSMTP runs phpmailer_init and then sets its own host in send().
		$tracker->remember( $phpmailer );
		$tracker->inspect( $phpmailer );

		$this->assertSame( Delivery_Tracker::PROVIDER_OTHER, $tracker->get_provider() );
	}

	public function test_an_email_reconfigured_after_the_provider_is_credited_to_another_plugin() {
		$this->configure_plugin();
		$phpmailer = $this->configured_phpmailer();

		$this->tracker->remember( $phpmailer );
		$phpmailer->Host = 'smtp.other-plugin.example';
		$this->tracker->inspect( $phpmailer );

		$this->assertSame( Delivery_Tracker::PROVIDER_OTHER, $this->tracker->get_provider() );
	}

	public function test_an_email_with_a_replaced_phpmailer_is_credited_to_another_plugin() {
		$this->configure_plugin();
		$phpmailer = $this->configured_phpmailer();
		$this->tracker->remember( $phpmailer );

		$replacement = new class() extends PHPMailer\PHPMailer\PHPMailer {};
		foreach ( Delivery_Tracker::TRACKED_PROPERTIES as $property ) {
			$replacement->$property = $phpmailer->$property;
		}
		$this->tracker->inspect( $replacement );

		$this->assertSame( Delivery_Tracker::PROVIDER_OTHER, $this->tracker->get_provider() );
	}

	public function test_an_unconfigured_plugin_credits_the_default_mailer() {
		$phpmailer = $this->get_phpmailer();
		$phpmailer->isMail();

		$this->tracker->inspect( $phpmailer );

		$this->assertSame( Delivery_Tracker::PROVIDER_DEFAULT, $this->tracker->get_provider() );
	}

	public function test_an_unconfigured_plugin_credits_another_plugin_that_switched_to_smtp() {
		$phpmailer = $this->get_phpmailer();
		$phpmailer->isSMTP();

		$this->tracker->inspect( $phpmailer );

		$this->assertSame( Delivery_Tracker::PROVIDER_OTHER, $this->tracker->get_provider() );
	}

	public function test_an_email_that_never_reached_phpmailer_is_credited_to_a_pre_wp_mail_callback() {
		$this->configure_plugin();
		add_filter( 'pre_wp_mail', '__return_true' );

		$this->assertSame( Delivery_Tracker::PROVIDER_OTHER, $this->tracker->get_provider() );
	}

	public function test_an_email_that_failed_before_phpmailer_keeps_the_provider() {
		$this->configure_plugin();

		$this->assertSame( 'smtp', $this->tracker->get_provider() );
	}

	public function test_an_email_that_failed_before_phpmailer_is_default_when_not_configured() {
		$this->assertSame( Delivery_Tracker::PROVIDER_DEFAULT, $this->tracker->get_provider() );
	}

	public function test_reset_forgets_the_current_email() {
		$this->configure_plugin();
		$phpmailer = $this->configured_phpmailer();
		$this->tracker->remember( $phpmailer );
		$phpmailer->Host = 'smtp.other-plugin.example';
		$this->tracker->inspect( $phpmailer );

		$this->tracker->reset();

		$this->assertSame( 'smtp', $this->tracker->get_provider() );
	}

	public function test_the_core_wp_mail_has_no_owner() {
		$this->assertSame( '', $this->tracker->get_wp_mail_owner() );
	}

	public function test_a_function_defined_by_a_plugin_is_owned_by_that_plugin() {
		$this->assertSame( 'SMTP', $this->tracker->get_function_owner( 'wp_smtp_load' ) );
	}

	public function test_a_missing_function_has_no_owner() {
		$this->assertSame( '', $this->tracker->get_function_owner( 'smtp_tests_missing_function' ) );
	}

	public function test_a_function_outside_the_plugins_folder_has_a_generic_owner() {
		$this->assertSame( 'Another plugin', $this->tracker->get_function_owner( 'tests_add_filter' ) );
	}

	public function test_get_provider_is_not_fooled_by_previous_emails() {
		$this->configure_plugin();
		$this->tracker->add_hooks();

		add_action(
			'phpmailer_init',
			static function ( $phpmailer ) {
				$phpmailer->Host = 'smtp.other-plugin.example';
			},
			20
		);
		wp_mail( 'to@example.org', 'First', 'Body' );

		$this->assertSame( 'smtp', $this->tracker->get_provider() );
	}

	public function test_the_connection_is_the_default_one_until_another_is_set() {
		$this->assertSame( 'default', $this->tracker->get_connection_id() );
	}

	public function test_it_uses_the_provider_of_the_selected_connection() {
		$registry = new Provider_Registry();
		$settings = new Settings( $registry, new Credentials(), 'sponsors' );
		update_option(
			Connections::OPTION,
			array(
				'sponsors' => array(
					'label'    => 'Sponsors',
					'settings' => array(
						'provider' => 'sponsor_mailer',
						'token'    => 'x',
					),
				),
			)
		);
		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['sponsor_mailer'] = array(
					'label'     => 'Sponsor mailer',
					'configure' => '__return_null',
				);

				return $providers;
			}
		);

		$this->tracker->set_connection( 'sponsors', $settings );

		$this->assertSame( 'sponsors', $this->tracker->get_connection_id() );
		$this->assertSame( 'sponsor_mailer', $this->tracker->get_provider() );
	}

	public function test_reset_goes_back_to_the_default_connection() {
		$this->tracker->set_connection( 'sponsors', new Settings( new Provider_Registry(), new Credentials(), 'sponsors' ) );

		$this->tracker->reset();

		$this->assertSame( 'default', $this->tracker->get_connection_id() );
	}

	public function test_an_email_whose_listener_threw_does_not_change_the_next_emails() {
		$default_settings = new Settings( $this->registry, new Credentials() );
		$phpmailer        = $this->configured_phpmailer();

		// A listener throws and the exception is caught: reset() never runs for this email.
		$this->tracker->set_connection( 'default', $default_settings );
		$this->tracker->remember( $phpmailer );
		$this->tracker->inspect( $phpmailer );
		$this->tracker->keep();

		// The next email finishes normally, so reset() restores the state left in the stack.
		$this->tracker->set_connection( 'default', $default_settings );
		$this->tracker->remember( $phpmailer );
		$this->tracker->inspect( $phpmailer );
		$this->tracker->keep();
		$this->tracker->reset();

		// An email sent by the default WordPress mailer, as the plugin is not configured.
		$this->tracker->set_connection( 'default', $default_settings );
		$this->tracker->inspect( $this->get_phpmailer() );

		$this->assertSame( Delivery_Tracker::PROVIDER_DEFAULT, $this->tracker->get_provider() );
	}
}
