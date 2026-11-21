<?php
/**
 * Tests for the Settings class.
 *
 * @package smtp
 */

use WordPress\SMTP\Connections;
use WordPress\SMTP\Credentials;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Mailer;
use WordPress\SMTP\Settings;

class Settings_Tests extends WP_UnitTestCase {

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var Credentials
	 */
	private $credentials;

	public function set_up() {
		parent::set_up();

		$this->credentials = new Credentials();
		$this->settings    = new Settings( new Provider_Registry(), $this->credentials );
	}

	private function save( array $options ) {
		update_option( Settings::OPTION, $options );
	}

	private function add_locked_provider() {
		if ( ! defined( 'SMTP_TESTS_LOCKED_TOKEN' ) ) {
			define( 'SMTP_TESTS_LOCKED_TOKEN', 'from-constant' );
		}

		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['locked'] = array(
					'label'     => 'Locked',
					'required'  => array( 'token' ),
					'fields'    => array(
						'token' => array(
							'label'    => 'Token',
							'type'     => 'text',
							'constant' => 'SMTP_TESTS_LOCKED_TOKEN',
						),
					),
					'configure' => '__return_null',
				);

				return $providers;
			}
		);
	}

	public function test_provider_slug_defaults_to_smtp() {
		$this->assertSame( 'smtp', $this->settings->get_provider_slug() );
	}

	public function test_provider_slug_uses_the_saved_provider() {
		$this->add_locked_provider();
		$this->save( array( 'provider' => 'locked' ) );

		$this->assertSame( 'locked', $this->settings->get_provider_slug() );
	}

	public function test_provider_slug_falls_back_when_the_saved_provider_is_gone() {
		$this->save( array( 'provider' => 'removed' ) );

		$this->assertSame( 'smtp', $this->settings->get_provider_slug() );
	}

	public function test_fields_include_provider_and_general_fields() {
		$keys = array_keys( $this->settings->get_fields() );

		$this->assertSame(
			array(
				'host',
				'port',
				'encryption',
				'auth',
				'username',
				'password',
				'from_email',
				'force_from_email',
				'from_name',
				'force_from_name',
				'reply_to',
				'force_reply_to',
				'reply_to_sender',
				'return_path',
				'rate_limit_per_second',
				'rate_limit_per_minute',
				'rate_limit_per_hour',
				'rate_limit_per_day',
				'rate_limit_concurrency',
			),
			$keys
		);
	}

	public function test_fields_are_normalized() {
		$field = $this->settings->get_fields()['from_name'];

		$this->assertSame( 'text', $field['type'] );
		$this->assertSame( '', $field['default'] );
		$this->assertSame( array(), $field['options'] );
		$this->assertSame( 'SMTP_FROM_NAME', $field['constant'] );
	}

	public function test_fields_of_an_unknown_provider_are_only_the_general_ones() {
		$this->assertSame(
			array(
				'from_email',
				'force_from_email',
				'from_name',
				'force_from_name',
				'reply_to',
				'force_reply_to',
				'reply_to_sender',
				'return_path',
				'rate_limit_per_second',
				'rate_limit_per_minute',
				'rate_limit_per_hour',
				'rate_limit_per_day',
				'rate_limit_concurrency',
			),
			array_keys( $this->settings->get_fields( 'unknown' ) )
		);
	}

	public function test_rate_limit_fields_are_empty_by_default() {
		$this->assertSame( 0, $this->settings->get( 'rate_limit_per_minute' ) );
	}

	public function test_sanitize_keeps_a_rate_limit_override_and_drops_invalid_ones() {
		$clean = $this->settings->sanitize(
			array(
				'rate_limit_per_second' => '5',
				'rate_limit_per_minute' => '0',
				'rate_limit_per_hour'   => 'abc',
				'rate_limit_per_day'    => '',
			)
		);

		$this->assertSame( 5, $clean['rate_limit_per_second'] );
		$this->assertSame( '', $clean['rate_limit_per_minute'] );
		$this->assertSame( '', $clean['rate_limit_per_hour'] );
		$this->assertSame( '', $clean['rate_limit_per_day'] );
	}

	public function test_get_returns_defaults_when_nothing_is_saved() {
		$this->assertSame( '', $this->settings->get( 'host' ) );
		$this->assertSame( 587, $this->settings->get( 'port' ) );
		$this->assertSame( 'tls', $this->settings->get( 'encryption' ) );
		$this->assertTrue( $this->settings->get( 'auth' ) );
		$this->assertFalse( $this->settings->get( 'force_from_email' ) );
		$this->assertFalse( $this->settings->get( 'force_from_name' ) );
		$this->assertTrue( $this->settings->get( 'return_path' ) );
		$this->assertSame( '', $this->settings->get( 'reply_to' ) );
		$this->assertFalse( $this->settings->get( 'force_reply_to' ) );
		$this->assertFalse( $this->settings->get( 'reply_to_sender' ) );
	}

	public function test_get_returns_saved_values_cast_to_the_field_type() {
		$this->save(
			array(
				'host'             => 'smtp.example.com',
				'port'             => '2525',
				'auth'             => 0,
				'force_from_email' => 1,
			)
		);

		$this->assertSame( 'smtp.example.com', $this->settings->get( 'host' ) );
		$this->assertSame( 2525, $this->settings->get( 'port' ) );
		$this->assertFalse( $this->settings->get( 'auth' ) );
		$this->assertTrue( $this->settings->get( 'force_from_email' ) );
	}

	public function test_get_reads_secrets_from_the_credentials_storage() {
		$this->credentials->set( 'password', 'secret' );

		$this->assertSame( 'secret', $this->settings->get( 'password' ) );
	}

	public function test_get_returns_an_empty_string_for_an_unknown_field() {
		$this->assertSame( '', $this->settings->get( 'unknown' ) );
	}

	public function test_get_survives_a_corrupted_option() {
		update_option( Settings::OPTION, 'not-an-array' );

		$this->assertSame( 587, $this->settings->get( 'port' ) );
	}

	public function test_constants_override_saved_values() {
		$this->add_locked_provider();
		$this->save(
			array(
				'provider' => 'locked',
				'token'    => 'from-option',
			)
		);

		$this->assertSame( 'from-constant', $this->settings->get( 'token' ) );
	}

	public function test_is_locked_detects_defined_constants() {
		$this->add_locked_provider();

		$fields = $this->settings->get_fields( 'locked' );

		$this->assertTrue( $this->settings->is_locked( $fields['token'] ) );
		$this->assertFalse( $this->settings->is_locked( $fields['from_name'] ) );
	}

	public function test_is_locked_ignores_undefined_constants() {
		$field = $this->settings->get_fields()['host'];

		$this->assertSame( 'SMTP_HOST', $field['constant'] );
		$this->assertFalse( $this->settings->is_locked( $field ) );
	}

	public function test_is_configured_requires_the_required_fields() {
		$this->assertFalse( $this->settings->is_configured() );

		$this->save(
			array(
				'host' => 'smtp.example.com',
				'auth' => false,
			)
		);

		$this->assertTrue( $this->settings->is_configured() );
	}

	public function test_is_configured_is_false_when_authentication_has_no_password() {
		$this->save(
			array(
				'host'     => 'smtp.example.com',
				'auth'     => true,
				'username' => 'user',
			)
		);

		$this->assertFalse( $this->settings->is_configured() );

		$this->credentials->set( 'password', 'secret' );

		$this->assertTrue( $this->settings->is_configured() );
	}

	public function test_is_configured_is_true_when_a_constant_defines_the_password() {
		if ( ! defined( 'SMTP_TESTS_LOCKED_PASSWORD' ) ) {
			define( 'SMTP_TESTS_LOCKED_PASSWORD', 'from-constant' );
		}

		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['smtp']['fields']['password']['constant'] = 'SMTP_TESTS_LOCKED_PASSWORD';

				return $providers;
			}
		);

		$this->save(
			array(
				'host'     => 'smtp.example.com',
				'auth'     => true,
				'username' => 'user',
			)
		);

		$this->assertTrue( $this->settings->is_configured() );
	}

	public function test_is_configured_is_false_when_authentication_has_no_username() {
		$this->save(
			array(
				'host' => 'smtp.example.com',
				'auth' => true,
			)
		);
		$this->credentials->set( 'password', 'secret' );

		$this->assertFalse( $this->settings->is_configured() );

		$this->save(
			array(
				'host'     => 'smtp.example.com',
				'auth'     => true,
				'username' => '0',
			)
		);

		$this->assertTrue( $this->settings->is_configured() );
	}

	public function test_is_configured_ignores_username_and_password_without_authentication() {
		$this->save(
			array(
				'host' => 'smtp.example.com',
				'auth' => false,
			)
		);

		$this->assertTrue( $this->settings->is_configured() );
	}

	public function test_is_configured_applies_the_required_if_rules_of_a_custom_provider() {
		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['custom'] = array(
					'label'       => 'Custom',
					'required_if' => array( 'api_key' => 'use_key' ),
					'fields'      => array(
						'use_key' => array(
							'label'   => 'Use key',
							'type'    => 'checkbox',
							'default' => true,
						),
						'api_key' => array(
							'label' => 'API key',
							'type'  => 'text',
						),
					),
					'configure'   => '__return_null',
				);

				return $providers;
			}
		);

		$this->save( array( 'provider' => 'custom' ) );

		$this->assertFalse( $this->settings->is_configured() );

		$this->save(
			array(
				'provider' => 'custom',
				'api_key'  => 'key',
			)
		);

		$this->assertTrue( $this->settings->is_configured() );
	}

	public function test_is_configured_ignores_auth_and_password_fields_without_required_if() {
		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['custom'] = array(
					'label'     => 'Custom',
					'fields'    => array(
						'auth'     => array(
							'label'   => 'Authentication',
							'type'    => 'checkbox',
							'default' => true,
						),
						'password' => array(
							'label' => 'Password',
							'type'  => 'password',
						),
					),
					'configure' => '__return_null',
				);

				return $providers;
			}
		);

		$this->save( array( 'provider' => 'custom' ) );

		$this->assertTrue( $this->settings->is_configured() );
	}

	public function test_is_configured_is_false_without_providers() {
		add_filter( 'smtp_providers', '__return_empty_array' );

		$this->save( array( 'host' => 'smtp.example.com' ) );

		$this->assertFalse( $this->settings->is_configured() );
	}

	public function test_is_configured_is_true_when_a_constant_satisfies_the_requirement() {
		$this->add_locked_provider();
		$this->save( array( 'provider' => 'locked' ) );

		$this->assertTrue( $this->settings->is_configured() );
	}

	public function test_provider_values_only_include_the_provider_fields() {
		$this->save( array( 'host' => 'smtp.example.com' ) );

		$this->assertSame(
			array( 'host', 'port', 'encryption', 'auth', 'username', 'password' ),
			array_keys( $this->settings->get_provider_values() )
		);
	}

	public function test_provider_values_are_empty_without_providers() {
		add_filter( 'smtp_providers', '__return_empty_array' );

		$this->assertSame( array(), $this->settings->get_provider_values() );
	}

	public function test_sanitize_cleans_every_field_type() {
		$clean = $this->settings->sanitize(
			array(
				'provider'         => 'smtp',
				'host'             => ' <b>smtp.example.com</b> ',
				'port'             => '2525abc',
				'encryption'       => 'ssl',
				'auth'             => '1',
				'username'         => 'user',
				'from_email'       => 'Team@Example.com<script>',
				'from_name'        => '<i>Team</i>',
				'force_from_email' => '1',
			)
		);

		$this->assertSame( 'smtp', $clean['provider'] );
		$this->assertSame( 'smtp.example.com', $clean['host'] );
		$this->assertSame( 2525, $clean['port'] );
		$this->assertSame( 'ssl', $clean['encryption'] );
		$this->assertTrue( $clean['auth'] );
		$this->assertSame( 'user', $clean['username'] );
		$this->assertStringNotContainsString( '<', $clean['from_email'] );
		$this->assertSame( 'Team', $clean['from_name'] );
		$this->assertTrue( $clean['force_from_email'] );
	}

	public function test_sanitize_unchecked_checkboxes_are_false() {
		$clean = $this->settings->sanitize( array( 'provider' => 'smtp' ) );

		$this->assertFalse( $clean['auth'] );
		$this->assertFalse( $clean['force_from_email'] );
	}

	public function test_sanitize_falls_back_to_defaults_for_invalid_values() {
		$clean = $this->settings->sanitize(
			array(
				'provider'   => 'smtp',
				'port'       => '0',
				'encryption' => 'evil',
			)
		);

		$this->assertSame( 587, $clean['port'] );
		$this->assertSame( 'tls', $clean['encryption'] );
	}

	public function test_sanitize_rejects_a_port_above_65535() {
		$this->assertSame( 587, $this->settings->sanitize( array( 'port' => '65536' ) )['port'] );
		$this->assertSame( 65535, $this->settings->sanitize( array( 'port' => '65535' ) )['port'] );
	}

	public function test_sanitize_reports_a_reset_number_once() {
		global $wp_settings_errors;
		$wp_settings_errors = array();

		require_once ABSPATH . 'wp-admin/includes/template.php';

		$this->settings->sanitize( array( 'port' => '99999' ) );
		$this->settings->sanitize( array( 'port' => '99999' ) );

		$errors = get_settings_errors( Settings::OPTION );
		$this->assertCount( 1, $errors );
		$this->assertSame( 'smtp_invalid_port', $errors[0]['code'] );
		$this->assertStringContainsString( 'between 1 and 65535', $errors[0]['message'] );
	}

	public function test_sanitize_does_not_report_an_empty_number() {
		global $wp_settings_errors;
		$wp_settings_errors = array();

		require_once ABSPATH . 'wp-admin/includes/template.php';

		$this->assertSame( 587, $this->settings->sanitize( array( 'port' => '' ) )['port'] );
		$this->assertSame( array(), get_settings_errors( Settings::OPTION ) );
	}

	public function test_max_comes_from_the_field_definition() {
		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['smtp']['fields']['port']['max'] = 1000;

				return $providers;
			}
		);

		$this->assertSame( 587, $this->settings->sanitize( array( 'port' => '1001' ) )['port'] );
		$this->assertSame( 1000, $this->settings->sanitize( array( 'port' => '1000' ) )['port'] );
	}

	public function test_number_fields_without_a_max_accept_any_positive_value() {
		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				unset( $providers['smtp']['fields']['port']['max'] );

				return $providers;
			}
		);

		$this->assertSame( 99999, $this->settings->sanitize( array( 'port' => '99999' ) )['port'] );
	}

	public function test_sanitize_falls_back_to_the_current_provider_when_unknown() {
		$clean = $this->settings->sanitize( array( 'provider' => 'unknown' ) );

		$this->assertSame( 'smtp', $clean['provider'] );
	}

	public function test_sanitize_accepts_non_array_input() {
		$clean = $this->settings->sanitize( 'garbage' );

		$this->assertSame( 'smtp', $clean['provider'] );
		$this->assertSame( '', $clean['host'] );
	}

	public function test_sanitize_ignores_non_string_text_values() {
		$clean = $this->settings->sanitize(
			array(
				'provider' => 'smtp',
				'host'     => array( 'nested' ),
			)
		);

		$this->assertSame( '', $clean['host'] );
	}

	public function test_sanitize_stores_the_password_in_the_credentials_storage_only() {
		$clean = $this->settings->sanitize(
			array(
				'provider' => 'smtp',
				'password' => 'p@ss <word> %20',
			)
		);

		$this->assertArrayNotHasKey( 'password', $clean );
		$this->assertSame( 'p@ss <word> %20', $this->credentials->get( 'password' ) );
	}

	public function test_sanitize_keeps_the_current_password_when_the_field_is_empty() {
		$this->credentials->set( 'password', 'current' );

		$this->settings->sanitize(
			array(
				'provider' => 'smtp',
				'password' => '',
			)
		);

		$this->assertSame( 'current', $this->credentials->get( 'password' ) );
	}

	public function test_sanitize_skips_fields_locked_by_a_constant() {
		$this->add_locked_provider();

		$clean = $this->settings->sanitize(
			array(
				'provider' => 'locked',
				'token'    => 'attempt',
			)
		);

		$this->assertArrayNotHasKey( 'token', $clean );
	}

	public function test_sanitize_is_idempotent() {
		$input = array(
			'provider'   => 'smtp',
			'host'       => 'smtp.example.com',
			'port'       => '465',
			'encryption' => 'ssl',
			'auth'       => '1',
			'from_email' => 'team@example.com',
			'password'   => 'secret',
		);

		$first  = $this->settings->sanitize( $input );
		$second = $this->settings->sanitize( $first );

		$this->assertSame( $first, $second );
		$this->assertSame( 'secret', $this->credentials->get( 'password' ) );
	}

	public function test_update_changes_only_the_given_values() {
		$this->save(
			array(
				'host'             => 'smtp.example.com',
				'port'             => 2525,
				'encryption'       => 'ssl',
				'auth'             => false,
				'username'         => 'me',
				'force_from_email' => true,
			)
		);

		$this->settings->update( array( 'host' => 'other.example.com' ) );

		$this->assertSame( 'other.example.com', $this->settings->get( 'host' ) );
		$this->assertSame( 2525, $this->settings->get( 'port' ) );
		$this->assertSame( 'ssl', $this->settings->get( 'encryption' ) );
		$this->assertFalse( $this->settings->get( 'auth' ) );
		$this->assertSame( 'me', $this->settings->get( 'username' ) );
		$this->assertTrue( $this->settings->get( 'force_from_email' ) );
	}

	public function test_update_keeps_the_defaults_of_fields_that_were_never_saved() {
		$this->settings->update( array( 'host' => 'smtp.example.com' ) );

		$this->assertSame( 587, $this->settings->get( 'port' ) );
		$this->assertSame( 'tls', $this->settings->get( 'encryption' ) );
		$this->assertTrue( $this->settings->get( 'auth' ) );
	}

	public function test_update_stores_secrets_apart() {
		$this->settings->update( array( 'password' => 's3cret-value' ) );

		$this->assertSame( 's3cret-value', $this->credentials->get( 'password' ) );
		$this->assertArrayNotHasKey( 'password', get_option( Settings::OPTION ) );
	}

	public function test_update_keeps_the_secret_when_it_is_not_given() {
		$this->credentials->set( 'password', 's3cret-value' );

		$this->settings->update( array( 'host' => 'smtp.example.com' ) );

		$this->assertSame( 's3cret-value', $this->credentials->get( 'password' ) );
	}

	public function test_update_does_not_save_locked_fields() {
		$this->add_locked_provider();

		$this->settings->update(
			array(
				'provider' => 'locked',
				'token'    => 'other',
			)
		);

		$this->assertSame( 'locked', $this->settings->get_provider_slug() );
		$this->assertArrayNotHasKey( 'token', get_option( Settings::OPTION ) );
	}

	public function test_get_source_reports_where_a_value_comes_from() {
		$this->assertSame( 'default', $this->settings->get_source( 'host' ) );
		$this->assertSame( 'default', $this->settings->get_source( 'password' ) );
		$this->assertSame( '', $this->settings->get_source( 'unknown' ) );

		$this->save( array( 'host' => 'smtp.example.com' ) );
		$this->credentials->set( 'password', 's3cret-value' );

		$this->assertSame( 'database', $this->settings->get_source( 'host' ) );
		$this->assertSame( 'database', $this->settings->get_source( 'password' ) );
	}

	public function test_get_source_reports_a_constant() {
		$this->add_locked_provider();
		$this->save( array( 'provider' => 'locked' ) );

		$this->assertSame( 'constant', $this->settings->get_source( 'token' ) );
	}

	public function test_sanitize_cleans_the_default_reply_to() {
		$clean = $this->settings->sanitize(
			array(
				'provider' => 'smtp',
				'reply_to' => 'Help@Example.com<script>',
			)
		);

		$this->assertStringNotContainsString( '<', $clean['reply_to'] );
	}

	public function test_maybe_upgrade_splits_force_from() {
		update_option(
			Settings::OPTION,
			array(
				'provider'   => 'smtp',
				'host'       => 'smtp.example.com',
				'force_from' => true,
			)
		);

		$this->settings->maybe_upgrade();

		$options = get_option( Settings::OPTION );
		$this->assertArrayNotHasKey( 'force_from', $options );
		$this->assertTrue( $options['force_from_email'] );
		$this->assertTrue( $options['force_from_name'] );
		$this->assertSame( 'smtp.example.com', $options['host'] );
	}

	public function test_maybe_upgrade_keeps_force_from_off() {
		update_option( Settings::OPTION, array( 'force_from' => false ) );

		$this->settings->maybe_upgrade();

		$this->assertFalse( $this->settings->get( 'force_from_email' ) );
		$this->assertFalse( $this->settings->get( 'force_from_name' ) );
	}

	public function test_maybe_upgrade_keeps_the_server_return_path_on_existing_sites() {
		update_option(
			Settings::OPTION,
			array(
				'host'       => 'smtp.example.com',
				'force_from' => false,
			)
		);

		$this->settings->maybe_upgrade();

		$this->assertFalse( $this->settings->get( 'return_path' ) );
	}

	public function test_maybe_upgrade_does_nothing_on_new_sites() {
		delete_option( Settings::OPTION );

		$this->settings->maybe_upgrade();

		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertTrue( $this->settings->get( 'return_path' ) );
	}

	public function test_maybe_upgrade_runs_once() {
		update_option( Settings::OPTION, array( 'force_from' => true ) );
		$this->settings->maybe_upgrade();

		$this->settings->update( array( 'return_path' => true ) );
		$this->settings->maybe_upgrade();

		$this->assertTrue( $this->settings->get( 'return_path' ) );
		$this->assertTrue( $this->settings->get( 'force_from_email' ) );
	}

	public function test_maybe_upgrade_ignores_settings_saved_without_a_locked_field() {
		// Locked fields are not saved, so force_from_email can be missing from current settings.
		$saved = array(
			'host'            => 'smtp.example.com',
			'force_from_name' => true,
			'return_path'     => true,
		);
		update_option( Settings::OPTION, $saved );

		$this->settings->maybe_upgrade();

		$this->assertSame( $saved, get_option( Settings::OPTION ) );
	}

	public function test_sender_fields_are_grouped_in_sections() {
		$fields = $this->settings->get_fields();

		$this->assertSame( 'connection', $fields['host']['section'] );
		$this->assertSame( 'sender', $fields['from_email']['section'] );
		$this->assertSame( 'sender', $fields['from_name']['section'] );
		$this->assertSame( 'replies', $fields['reply_to']['section'] );
		$this->assertSame( 'replies', $fields['return_path']['section'] );
		$this->assertSame( 'limits', $fields['rate_limit_per_day']['section'] );
	}

	public function test_force_checkboxes_belong_to_the_field_they_change() {
		$fields = $this->settings->get_fields();

		$this->assertSame( 'from_email', $fields['force_from_email']['parent'] );
		$this->assertSame( 'from_name', $fields['force_from_name']['parent'] );
		$this->assertSame( 'reply_to', $fields['force_reply_to']['parent'] );
		$this->assertSame( 'reply_to', $fields['reply_to_sender']['parent'] );
		$this->assertSame( '', $fields['return_path']['parent'] );
	}

	public function test_options_that_can_not_take_effect_declare_what_they_depend_on() {
		$fields = $this->settings->get_fields();

		$this->assertSame( 'from_email', $fields['force_from_email']['enabled_by'] );
		$this->assertSame( 'from_name', $fields['force_from_name']['enabled_by'] );
		$this->assertSame( 'reply_to', $fields['force_reply_to']['enabled_by'] );
		$this->assertSame( 'force_from_email', $fields['reply_to_sender']['enabled_by'] );
		$this->assertSame( 'force_reply_to', $fields['reply_to_sender']['disabled_by'] );
		$this->assertSame( 'auth', $fields['username']['enabled_by'] );
		$this->assertSame( 'auth', $fields['password']['enabled_by'] );
		$this->assertSame( '', $fields['host']['enabled_by'] );
	}

	public function test_keep_the_sender_as_reply_to_can_be_set_with_a_constant() {
		$this->assertSame( 'SMTP_REPLY_TO_SENDER', $this->settings->get_fields()['reply_to_sender']['constant'] );
	}

	public function test_from_email_description_shows_the_wordpress_default_address() {
		$description = $this->settings->get_fields()['from_email']['description'];

		$this->assertStringContainsString( Mailer::get_default_from_email(), $description );
		$this->assertStringNotContainsString( 'above', $description );
	}

	public function test_return_path_describes_the_from_address_of_each_email() {
		$field = $this->settings->get_fields()['return_path'];

		$this->assertSame( "Use each email's From address as Return-Path", $field['checkbox_label'] );
		$this->assertSame( 'Bounces go to it and SPF checks match its domain.', $field['description'] );
	}

	public function test_the_default_settings_belong_to_the_default_connection() {
		$this->assertSame( 'default', $this->settings->get_connection_id() );
		$this->assertSame( 'default', ( new Settings( new Provider_Registry(), $this->credentials, 'default' ) )->get_connection_id() );
	}

	public function test_settings_of_another_connection_read_their_own_values() {
		update_option(
			Connections::OPTION,
			array(
				'sponsors' => array(
					'label'    => 'Sponsors',
					'settings' => array(
						'host' => 'smtp.sponsors.example',
						'port' => 2525,
					),
				),
			)
		);
		$this->save( array( 'host' => 'smtp.default.example' ) );
		$sponsors = new Settings( new Provider_Registry(), $this->credentials, 'sponsors' );

		$this->assertSame( 'sponsors', $sponsors->get_connection_id() );
		$this->assertSame( 'smtp.sponsors.example', $sponsors->get( 'host' ) );
		$this->assertSame( 2525, $sponsors->get( 'port' ) );
		$this->assertSame( 'smtp.default.example', $this->settings->get( 'host' ) );
	}

	public function test_settings_of_another_connection_read_their_own_secret() {
		$this->credentials->set( 'password', 'default-secret' );
		$this->credentials->set( 'connection:sponsors:password', 'sponsors-secret' );
		$sponsors = new Settings( new Provider_Registry(), $this->credentials, 'sponsors' );

		$this->assertSame( 'sponsors-secret', $sponsors->get( 'password' ) );
		$this->assertSame( 'default-secret', $this->settings->get( 'password' ) );
		$this->assertSame( 'database', $sponsors->get_source( 'password' ) );
	}

	public function test_update_saves_the_settings_of_another_connection_only() {
		update_option(
			Connections::OPTION,
			array(
				'sponsors' => array(
					'label'    => 'Sponsors',
					'settings' => array( 'host' => 'smtp.sponsors.example' ),
				),
			)
		);
		$this->save( array( 'host' => 'smtp.default.example' ) );
		$sponsors = new Settings( new Provider_Registry(), $this->credentials, 'sponsors' );

		$sponsors->update( array( 'port' => 465 ) );

		$this->assertSame( 465, $sponsors->get( 'port' ) );
		$this->assertSame( 'smtp.sponsors.example', $sponsors->get( 'host' ) );
		$this->assertSame( 'Sponsors', Connections::get_stored()['sponsors']['label'] );
		$this->assertSame( 'smtp.default.example', $this->settings->get( 'host' ) );
		$this->assertSame( 587, $this->settings->get( 'port' ) );
	}
}
