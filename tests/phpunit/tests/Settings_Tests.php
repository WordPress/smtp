<?php
/**
 * Tests for the Settings class.
 *
 * @package smtp
 */

use WordPress\SMTP\Credentials;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Settings;
use WordPress\SMTP\Test_Utils\Traits\With_Settings;

class Settings_Tests extends WP_UnitTestCase {

	use With_Settings;

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
			array( 'host', 'port', 'encryption', 'auth', 'username', 'password', 'from_email', 'from_name', 'force_from_email', 'force_from_name', 'return_path', 'reply_to', 'force_reply_to', 'reply_to_sender' ),
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
			array( 'from_email', 'from_name', 'force_from_email', 'force_from_name', 'return_path', 'reply_to', 'force_reply_to', 'reply_to_sender' ),
			array_keys( $this->settings->get_fields( 'unknown' ) )
		);
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
		$this->credentials->set( $this->main_secret( 'password' ), 'secret' );

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

		$this->credentials->set( $this->main_secret( 'password' ), 'secret' );

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
		$this->credentials->set( $this->main_secret( 'password' ), 'secret' );

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
		$clean = $this->flatten_settings(
			$this->settings->sanitize(
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
		$clean = $this->flatten_settings( $this->settings->sanitize( array( 'provider' => 'smtp' ) ) );

		$this->assertFalse( $clean['auth'] );
		$this->assertFalse( $clean['force_from_email'] );
	}

	public function test_sanitize_falls_back_to_defaults_for_invalid_values() {
		$clean = $this->flatten_settings(
			$this->settings->sanitize(
				array(
					'provider'   => 'smtp',
					'port'       => '0',
					'encryption' => 'evil',
				)
			)
		);

		$this->assertSame( 587, $clean['port'] );
		$this->assertSame( 'tls', $clean['encryption'] );
	}

	public function test_sanitize_rejects_a_port_above_65535() {
		$this->assertSame( 587, $this->flatten_settings( $this->settings->sanitize( array( 'port' => '65536' ) ) )['port'] );
		$this->assertSame( 65535, $this->flatten_settings( $this->settings->sanitize( array( 'port' => '65535' ) ) )['port'] );
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

		$this->assertSame( 587, $this->flatten_settings( $this->settings->sanitize( array( 'port' => '' ) ) )['port'] );
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

		$this->assertSame( 587, $this->flatten_settings( $this->settings->sanitize( array( 'port' => '1001' ) ) )['port'] );
		$this->assertSame( 1000, $this->flatten_settings( $this->settings->sanitize( array( 'port' => '1000' ) ) )['port'] );
	}

	public function test_number_fields_without_a_max_accept_any_positive_value() {
		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				unset( $providers['smtp']['fields']['port']['max'] );

				return $providers;
			}
		);

		$this->assertSame( 99999, $this->flatten_settings( $this->settings->sanitize( array( 'port' => '99999' ) ) )['port'] );
	}

	public function test_sanitize_falls_back_to_the_current_provider_when_unknown() {
		$clean = $this->flatten_settings( $this->settings->sanitize( array( 'provider' => 'unknown' ) ) );

		$this->assertSame( 'smtp', $clean['provider'] );
	}

	public function test_sanitize_accepts_non_array_input() {
		$clean = $this->flatten_settings( $this->settings->sanitize( 'garbage' ) );

		$this->assertSame( 'smtp', $clean['provider'] );
		$this->assertSame( '', $clean['host'] );
	}

	public function test_sanitize_ignores_non_string_text_values() {
		$clean = $this->flatten_settings(
			$this->settings->sanitize(
				array(
					'provider' => 'smtp',
					'host'     => array( 'nested' ),
				)
			)
		);

		$this->assertSame( '', $clean['host'] );
	}

	public function test_sanitize_stores_the_password_in_the_credentials_storage_only() {
		$clean = $this->flatten_settings(
			$this->settings->sanitize(
				array(
					'provider' => 'smtp',
					'password' => 'p@ss <word> %20',
				)
			)
		);

		$this->assertArrayNotHasKey( 'password', $clean );
		$this->assertSame( 'p@ss <word> %20', $this->credentials->get( $this->main_secret( 'password' ) ) );
	}

	public function test_sanitize_keeps_the_current_password_when_the_field_is_empty() {
		$this->credentials->set( $this->main_secret( 'password' ), 'current' );

		$this->settings->sanitize(
			array(
				'provider' => 'smtp',
				'password' => '',
			)
		);

		$this->assertSame( 'current', $this->credentials->get( $this->main_secret( 'password' ) ) );
	}

	public function test_sanitize_skips_fields_locked_by_a_constant() {
		$this->add_locked_provider();

		$clean = $this->flatten_settings(
			$this->settings->sanitize(
				array(
					'provider' => 'locked',
					'token'    => 'attempt',
				)
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
		$this->assertSame( 'secret', $this->credentials->get( $this->main_secret( 'password' ) ) );
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

		$this->assertSame( 's3cret-value', $this->credentials->get( $this->main_secret( 'password' ) ) );
		$this->assertArrayNotHasKey( 'password', $this->get_saved_settings() );
	}

	public function test_update_keeps_the_secret_when_it_is_not_given() {
		$this->credentials->set( $this->main_secret( 'password' ), 's3cret-value' );

		$this->settings->update( array( 'host' => 'smtp.example.com' ) );

		$this->assertSame( 's3cret-value', $this->credentials->get( $this->main_secret( 'password' ) ) );
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
		$this->assertArrayNotHasKey( 'token', $this->get_saved_settings() );
	}

	public function test_get_source_reports_where_a_value_comes_from() {
		$this->assertSame( 'default', $this->settings->get_source( 'host' ) );
		$this->assertSame( 'default', $this->settings->get_source( 'password' ) );
		$this->assertSame( '', $this->settings->get_source( 'unknown' ) );

		$this->save( array( 'host' => 'smtp.example.com' ) );
		$this->credentials->set( $this->main_secret( 'password' ), 's3cret-value' );

		$this->assertSame( 'database', $this->settings->get_source( 'host' ) );
		$this->assertSame( 'database', $this->settings->get_source( 'password' ) );
	}

	public function test_get_source_reports_a_constant() {
		$this->add_locked_provider();
		$this->save( array( 'provider' => 'locked' ) );

		$this->assertSame( 'constant', $this->settings->get_source( 'token' ) );
	}

	public function test_sanitize_cleans_the_default_reply_to() {
		$clean = $this->flatten_settings(
			$this->settings->sanitize(
				array(
					'provider' => 'smtp',
					'reply_to' => 'Help@Example.com<script>',
				)
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

		$options = $this->get_saved_settings();
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

		$this->assertSame( array( 'provider' => 'smtp' ) + $saved, $this->get_saved_settings() );
	}

	private function add_relay_provider() {
		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['relay'] = array(
					'label'     => 'Relay',
					'required'  => array( 'api_key' ),
					'fields'    => array(
						'region'  => array(
							'label'   => 'Region',
							'type'    => 'text',
							'default' => 'eu',
						),
						'api_key' => array(
							'label' => 'API key',
							'type'  => 'password',
						),
					),
					'configure' => '__return_null',
				);

				return $providers;
			}
		);
	}

	public function test_maybe_upgrade_converts_version_1_and_keeps_every_value_and_secret() {
		$v1 = array(
			'provider'         => 'smtp',
			'host'             => 'smtp.example.com',
			'port'             => 2525,
			'encryption'       => 'ssl',
			'auth'             => true,
			'username'         => 'me',
			'from_email'       => 'team@example.com',
			'from_name'        => 'Team',
			'force_from_email' => true,
			'force_from_name'  => false,
			'return_path'      => true,
			'reply_to'         => 'help@example.com',
			'force_reply_to'   => false,
			'reply_to_sender'  => true,
		);
		update_option( Settings::OPTION, $v1 );
		$this->credentials->set( 'password', 'old-secret' );

		$this->settings->maybe_upgrade();

		$stored = get_option( Settings::OPTION );
		$this->assertSame( Settings::VERSION, $stored['version'] );
		$this->assertSame( Settings::MAIN, $stored['primary'] );
		$this->assertSame( 'smtp', $stored['connections'][ Settings::MAIN ]['provider'] );
		$this->assertSame( 'team@example.com', $stored['general']['from_email'] );
		$this->assertArrayNotHasKey( 'from_email', $stored['connections'][ Settings::MAIN ]['values']['smtp'] );
		$this->assertSame( $v1, $this->get_saved_settings() );
		$this->assertSame( 'old-secret', $this->settings->get( 'password' ) );
		$this->assertSame( 'old-secret', $this->credentials->get( $this->main_secret( 'password' ) ) );
		$this->assertTrue( $this->settings->is_configured() );
	}

	public function test_maybe_upgrade_keeps_version_1_for_a_rollback() {
		$v1 = array(
			'provider' => 'smtp',
			'host'     => 'smtp.example.com',
		);
		update_option( Settings::OPTION, $v1 );
		$this->credentials->set( 'password', 'old-secret' );

		$this->settings->maybe_upgrade();

		$this->assertSame( $v1, get_option( Settings::V1_OPTION ) );
		$this->assertSame( 'old-secret', $this->credentials->get( 'password' ) );
		$this->assertArrayNotHasKey( Settings::V1_OPTION, wp_load_alloptions( true ) );
	}

	public function test_maybe_upgrade_twice_changes_nothing() {
		update_option(
			Settings::OPTION,
			array(
				'provider'   => 'smtp',
				'host'       => 'smtp.example.com',
				'force_from' => true,
			)
		);
		$this->credentials->set( 'password', 'old-secret' );

		$this->settings->maybe_upgrade();
		$stored      = get_option( Settings::OPTION );
		$v1          = get_option( Settings::V1_OPTION );
		$credentials = get_option( Credentials::OPTION );

		$this->settings->maybe_upgrade();

		$this->assertSame( $stored, get_option( Settings::OPTION ) );
		$this->assertSame( $v1, get_option( Settings::V1_OPTION ) );
		$this->assertSame( $credentials, get_option( Credentials::OPTION ) );
	}

	public function test_maybe_upgrade_ignores_settings_in_the_version_2_format() {
		$this->settings->update( array( 'host' => 'smtp.example.com' ) );
		$stored = get_option( Settings::OPTION );

		$this->settings->maybe_upgrade();

		$this->assertSame( $stored, get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( Settings::V1_OPTION ) );
	}

	public function test_constants_still_win_after_the_upgrade() {
		$this->add_locked_provider();
		update_option(
			Settings::OPTION,
			array(
				'provider' => 'locked',
				'token'    => 'from-option',
			)
		);

		$this->settings->maybe_upgrade();

		$this->assertSame( 'from-constant', $this->settings->get( 'token' ) );
		$this->assertSame( 'constant', $this->settings->get_source( 'token' ) );
	}

	public function test_constants_apply_to_the_main_connection_only() {
		$this->add_locked_provider();
		update_option(
			Settings::OPTION,
			array(
				'version'     => Settings::VERSION,
				'general'     => array( 'from_name' => 'Team' ),
				'connections' => array(
					Settings::MAIN => array(
						'provider' => 'locked',
						'values'   => array(),
					),
					'backup'       => array(
						'provider' => 'locked',
						'values'   => array( 'locked' => array( 'token' => 'backup-token' ) ),
					),
				),
				'primary'     => Settings::MAIN,
			)
		);

		$this->assertSame( 'from-constant', $this->settings->get( 'token' ) );
		$this->assertSame( 'backup-token', $this->settings->get( 'token', 'backup' ) );
		$this->assertSame( 'Team', $this->settings->get( 'from_name', 'backup' ) );
	}

	public function test_secrets_are_kept_per_connection() {
		$this->credentials->set( $this->main_secret( 'password' ), 'main-secret' );
		$this->credentials->set( Settings::get_secret_key( 'backup', 'password' ), 'backup-secret' );
		update_option(
			Settings::OPTION,
			array(
				'version'     => Settings::VERSION,
				'connections' => array(
					'backup' => array(
						'provider' => 'smtp',
						'values'   => array(),
					),
				),
			)
		);

		$this->assertSame( 'main-secret', $this->settings->get( 'password' ) );
		$this->assertSame( 'backup-secret', $this->settings->get( 'password', 'backup' ) );
	}

	public function test_get_connection_returns_the_provider_and_its_values() {
		$this->settings->update(
			array(
				'host'       => 'smtp.example.com',
				'from_email' => 'team@example.com',
			)
		);

		$connection = $this->settings->get_connection( Settings::MAIN );

		$this->assertSame( 'smtp', $connection['provider'] );
		$this->assertSame( 'smtp.example.com', $connection['values']['host'] );
		$this->assertArrayNotHasKey( 'from_email', $connection['values'] );
	}

	public function test_get_connection_returns_null_for_an_unknown_connection() {
		$this->assertNull( $this->settings->get_connection( 'unknown' ) );
	}

	public function test_switching_provider_in_the_form_and_back_keeps_the_values() {
		$this->add_relay_provider();
		$this->save( $this->settings->sanitize( array( 'host' => 'smtp.example.com' ) ) );

		// The form still shows the fields of Generic SMTP when the provider changes.
		$this->save(
			$this->settings->sanitize(
				array(
					'provider' => 'relay',
					'host'     => 'smtp.example.com',
				)
			)
		);
		$this->assertSame( 'relay', $this->settings->get_provider_slug() );
		$this->assertSame( 'eu', $this->settings->get( 'region' ) );

		$this->save(
			$this->settings->sanitize(
				array(
					'provider' => 'relay',
					'region'   => 'us',
				)
			)
		);
		$this->save(
			$this->settings->sanitize(
				array(
					'provider' => 'smtp',
					'region'   => 'us',
				)
			)
		);
		$this->assertSame( 'smtp.example.com', $this->settings->get( 'host' ) );

		$this->save(
			$this->settings->sanitize(
				array(
					'provider' => 'relay',
					'host'     => 'smtp.example.com',
				)
			)
		);
		$this->assertSame( 'us', $this->settings->get( 'region' ) );
	}

	public function test_switching_provider_through_update_and_back_keeps_the_values() {
		$this->add_relay_provider();
		$this->settings->update( array( 'host' => 'smtp.example.com' ) );
		$this->settings->update(
			array(
				'provider' => 'relay',
				'region'   => 'us',
			)
		);

		$this->assertSame( 'us', $this->settings->get( 'region' ) );

		$this->settings->update( array( 'provider' => 'smtp' ) );
		$this->assertSame( 'smtp.example.com', $this->settings->get( 'host' ) );

		$this->settings->update( array( 'provider' => 'relay' ) );
		$this->assertSame( 'us', $this->settings->get( 'region' ) );
	}

	public function test_sanitize_of_the_stored_format_keeps_every_provider() {
		$this->add_relay_provider();
		$this->settings->update( array( 'host' => 'smtp.example.com' ) );
		$this->settings->update(
			array(
				'provider' => 'relay',
				'region'   => 'us',
			)
		);
		$stored = get_option( Settings::OPTION );

		$this->assertSame( $stored, $this->settings->sanitize( $stored ) );
	}

	public function test_sanitize_of_the_stored_format_cleans_the_values_of_every_provider() {
		$this->add_relay_provider();

		$clean = $this->settings->sanitize(
			array(
				'version'     => Settings::VERSION,
				'general'     => array( 'from_name' => '<b>Team</b>' ),
				'connections' => array(
					Settings::MAIN => array(
						'provider' => 'relay',
						'values'   => array(
							'smtp'  => array( 'host' => '<script>x</script>smtp.example.com' ),
							'relay' => array( 'region' => '<i>us</i>' ),
						),
					),
				),
			)
		);

		$this->assertSame( 'Team', $clean['general']['from_name'] );
		$this->assertSame( 'smtp.example.com', $clean['connections'][ Settings::MAIN ]['values']['smtp']['host'] );
		$this->assertSame( 'us', $clean['connections'][ Settings::MAIN ]['values']['relay']['region'] );
	}

	public function test_sanitize_stores_a_secret_for_the_main_connection() {
		$this->settings->sanitize( array( 'password' => 'new-secret' ) );

		$this->assertSame( 'new-secret', $this->credentials->get( $this->main_secret( 'password' ) ) );
		$this->assertSame( '', $this->credentials->get( 'password' ) );
	}

	public function test_maybe_upgrade_keeps_the_secret_of_a_provider_registered_later() {
		update_option(
			Settings::OPTION,
			array(
				'provider' => 'relay',
				'region'   => 'us',
			)
		);
		$this->credentials->set( 'api_key', 'relay-key' );
		$this->credentials->set( 'alert_slack_webhook_url', 'https://hooks.slack.com/services/secret' );

		$this->settings->maybe_upgrade();
		$this->add_relay_provider();

		$this->assertSame( 'relay', $this->settings->get_provider_slug() );
		$this->assertSame( 'us', $this->settings->get( 'region' ) );
		$this->assertSame( 'relay-key', $this->settings->get( 'api_key' ) );
		$this->assertSame( '', $this->credentials->get( $this->main_secret( 'alert_slack_webhook_url' ) ) );
	}

	public function test_a_version_saved_as_a_string_is_read_as_version_2() {
		update_option(
			Settings::OPTION,
			array(
				'version'     => '2',
				'general'     => array( 'from_name' => 'Team' ),
				'connections' => array(
					Settings::MAIN => array(
						'provider' => 'smtp',
						'values'   => array( 'smtp' => array( 'host' => 'smtp.example.com' ) ),
					),
				),
			)
		);

		$this->settings->maybe_upgrade();

		$this->assertSame( 'smtp.example.com', $this->settings->get( 'host' ) );
		$this->assertSame( 'Team', $this->settings->get( 'from_name' ) );
		$this->assertFalse( get_option( Settings::V1_OPTION ) );
	}

	public function test_sanitize_of_the_stored_format_drops_unknown_keys() {
		$clean = $this->settings->sanitize(
			array(
				'version'     => Settings::VERSION,
				'extra'       => 'x',
				'connections' => array(
					Settings::MAIN => array(
						'provider' => 'smtp',
						'values'   => array(),
						'extra'    => 'x',
					),
					7              => array( 'provider' => 'smtp' ),
					'broken'       => 'not-an-array',
				),
			)
		);

		$this->assertSame( array( 'version', 'general', 'connections', 'primary' ), array_keys( $clean ) );
		$this->assertSame( array( Settings::MAIN ), array_keys( $clean['connections'] ) );
		$this->assertSame( array( 'provider', 'values' ), array_keys( $clean['connections'][ Settings::MAIN ] ) );
	}

	public function test_sanitize_of_the_stored_format_cleans_every_connection() {
		$clean = $this->settings->sanitize(
			array(
				'version'     => Settings::VERSION,
				'connections' => array(
					'backup' => array(
						'provider' => 'smtp',
						'values'   => array(
							'smtp' => array(
								'host'     => '<b>backup.example.com</b>',
								'password' => 'backup-secret',
							),
						),
					),
				),
			)
		);

		$this->assertSame( 'backup.example.com', $clean['connections']['backup']['values']['smtp']['host'] );
		$this->assertArrayNotHasKey( 'password', $clean['connections']['backup']['values']['smtp'] );
		$this->assertSame( 'backup-secret', $this->credentials->get( Settings::get_secret_key( 'backup', 'password' ) ) );
		$this->assertSame( '', $this->credentials->get( $this->main_secret( 'password' ) ) );
	}
}
