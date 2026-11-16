<?php
/**
 * Tests for the Connections class.
 *
 * @package smtp
 */

use WordPress\SMTP\Connections;
use WordPress\SMTP\Credentials;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Settings;

class Connections_Tests extends WP_UnitTestCase {

	/**
	 * @var Connections
	 */
	private $connections;

	/**
	 * @var Credentials
	 */
	private $credentials;

	public function set_up() {
		parent::set_up();

		$registry          = new Provider_Registry();
		$this->credentials = new Credentials();
		$this->connections = new Connections( $registry, $this->credentials, new Settings( $registry, $this->credentials ) );
	}

	private function add_connection( string $id = 'sponsors', array $extra = array() ) {
		$this->connections->save(
			$id,
			'Sponsors',
			array_merge(
				array(
					'provider'   => 'smtp',
					'host'       => 'smtp.sponsors.example',
					'auth'       => '1',
					'username'   => 'sponsors@example.org',
					'password'   => 'sponsors-secret',
					'from_email' => 'sponsors@example.org',
					'from_name'  => 'Sponsors',
				),
				$extra
			)
		);
	}

	private function configure_default() {
		update_option(
			Settings::OPTION,
			array(
				'provider' => 'smtp',
				'host'     => 'smtp.default.example',
				'auth'     => false,
			)
		);
	}

	public function test_there_is_only_the_default_connection_at_first() {
		$this->assertSame( array( 'default' ), $this->connections->get_ids() );
		$this->assertFalse( $this->connections->has_extra() );
		$this->assertTrue( $this->connections->exists( 'default' ) );
		$this->assertFalse( $this->connections->exists( 'sponsors' ) );
		$this->assertSame( 'Default', $this->connections->get_label( 'default' ) );
	}

	public function test_the_default_connection_uses_the_current_settings() {
		$this->configure_default();

		$this->assertSame( 'smtp.default.example', $this->connections->get_settings()->get( 'host' ) );
		$this->assertSame( 'smtp.default.example', $this->connections->get_settings( 'default' )->get( 'host' ) );
		$this->assertSame( 'default', $this->connections->get_settings()->get_connection_id() );
	}

	public function test_sanitize_id_keeps_lowercase_letters_numbers_dashes_and_underscores() {
		$this->assertSame( 'wc-europe_2026', Connections::sanitize_id( 'WC-Europe_2026!' ) );
		$this->assertSame( 'sponsors', Connections::sanitize_id( 'Sponsors' ) );
		$this->assertSame( 'a-b_c', Connections::sanitize_id( 'a-b_c' ) );
	}

	public function test_sanitize_id_rejects_the_reserved_and_empty_ids() {
		$this->assertSame( '', Connections::sanitize_id( 'default' ) );
		$this->assertSame( '', Connections::sanitize_id( 'DEFAULT' ) );
		$this->assertSame( '', Connections::sanitize_id( '' ) );
		$this->assertSame( '', Connections::sanitize_id( '!!!' ) );
		$this->assertSame( '', Connections::sanitize_id( array( 'x' ) ) );
	}

	public function test_sanitize_id_is_limited_to_the_size_of_the_log_column() {
		$this->assertSame( Connections::MAX_ID_LENGTH, strlen( Connections::sanitize_id( str_repeat( 'a', 80 ) ) ) );
	}

	public function test_save_creates_a_connection() {
		$this->add_connection();

		$this->assertSame( array( 'default', 'sponsors' ), $this->connections->get_ids() );
		$this->assertTrue( $this->connections->has_extra() );
		$this->assertSame( 'Sponsors', $this->connections->get_label( 'sponsors' ) );

		$settings = $this->connections->get_settings( 'sponsors' );
		$this->assertSame( 'sponsors', $settings->get_connection_id() );
		$this->assertSame( 'smtp.sponsors.example', $settings->get( 'host' ) );
		$this->assertSame( 'sponsors@example.org', $settings->get( 'from_email' ) );
		$this->assertTrue( $settings->is_configured() );
	}

	public function test_save_returns_the_clean_id() {
		$this->assertSame( 'sponsors', $this->connections->save( 'Sponsors', 'Sponsors', array( 'host' => 'smtp.example.com' ) ) );
	}

	public function test_save_rejects_an_invalid_id() {
		$result = $this->connections->save( 'default', 'Default', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'smtp_invalid_connection_id', $result->get_error_code() );
		$this->assertFalse( $this->connections->has_extra() );
	}

	public function test_save_uses_the_id_as_name_when_there_is_none() {
		$this->connections->save( 'volunteers', '', array() );

		$this->assertSame( 'volunteers', $this->connections->get_label( 'volunteers' ) );
	}

	public function test_save_updates_an_existing_connection_and_keeps_its_secret() {
		$this->add_connection();
		$this->connections->save(
			'sponsors',
			'Sponsors team',
			array(
				'provider' => 'smtp',
				'host'     => 'smtp.new.example',
				'auth'     => '1',
				'username' => 'sponsors@example.org',
				'password' => '',
			)
		);

		$settings = $this->connections->get_settings( 'sponsors' );
		$this->assertSame( 'Sponsors team', $this->connections->get_label( 'sponsors' ) );
		$this->assertSame( 'smtp.new.example', $settings->get( 'host' ) );
		$this->assertSame( 'sponsors-secret', $settings->get( 'password' ) );
	}

	public function test_secrets_are_stored_apart_from_the_connection_and_the_default_secret() {
		( new Credentials() )->set( 'password', 'default-secret' );
		$this->add_connection();

		$this->assertSame( 'default-secret', $this->credentials->get( 'password' ) );
		$this->assertSame( 'sponsors-secret', $this->credentials->get( 'connection:sponsors:password' ) );
		$this->assertStringNotContainsString( 'sponsors-secret', wp_json_encode( get_option( Connections::OPTION ) ) );
		$this->assertSame( 'default-secret', ( new Settings( new Provider_Registry(), $this->credentials ) )->get( 'password' ) );
	}

	public function test_constants_do_not_lock_the_fields_of_other_connections() {
		if ( ! defined( 'SMTP_TESTS_CONNECTIONS_TOKEN' ) ) {
			define( 'SMTP_TESTS_CONNECTIONS_TOKEN', 'from-constant' );
		}

		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['locked'] = array(
					'label'     => 'Locked',
					'configure' => '__return_null',
					'fields'    => array(
						'token' => array(
							'label'    => 'Token',
							'type'     => 'text',
							'constant' => 'SMTP_TESTS_CONNECTIONS_TOKEN',
						),
					),
				);

				return $providers;
			}
		);
		update_option( Settings::OPTION, array( 'provider' => 'locked' ) );
		$this->connections->save(
			'sponsors',
			'Sponsors',
			array(
				'provider' => 'locked',
				'token'    => 'saved',
			)
		);

		$this->assertSame( 'from-constant', $this->connections->get_settings()->get( 'token' ) );
		$this->assertSame( 'saved', $this->connections->get_settings( 'sponsors' )->get( 'token' ) );
	}

	public function test_delete_removes_the_connection_and_its_secrets_only() {
		$this->add_connection( 'sponsors' );
		$this->add_connection( 'sponsors_eu' );

		$this->assertTrue( $this->connections->delete( 'sponsors' ) );

		$this->assertFalse( $this->connections->exists( 'sponsors' ) );
		$this->assertSame( '', $this->credentials->get( 'connection:sponsors:password' ) );
		$this->assertSame( 'sponsors-secret', $this->credentials->get( 'connection:sponsors_eu:password' ), 'A longer ID that starts the same way must keep its secret.' );
		$this->assertTrue( $this->connections->exists( 'sponsors_eu' ) );
	}

	public function test_delete_ignores_the_default_and_unknown_connections() {
		$this->assertFalse( $this->connections->delete( 'default' ) );
		$this->assertFalse( $this->connections->delete( 'missing' ) );
	}

	public function test_get_settings_is_null_for_an_unknown_connection() {
		$this->assertNull( $this->connections->get_settings( 'missing' ) );
	}

	public function test_get_label_falls_back_to_the_id() {
		$this->assertSame( 'removed', $this->connections->get_label( 'removed' ) );
	}

	public function test_a_corrupted_option_is_ignored() {
		update_option( Connections::OPTION, 'nope' );

		$this->assertSame( array( 'default' ), $this->connections->get_ids() );

		update_option(
			Connections::OPTION,
			array(
				'default' => array( 'label' => 'x' ),
				'broken'  => 'nope',
				0         => array(),
				'ok'      => array( 'label' => 'Ok' ),
			)
		);

		$this->assertSame( array( 'default', 'ok' ), $this->connections->get_ids() );
	}

	public function test_resolve_uses_the_default_connection_by_default() {
		$this->add_connection();

		$this->assertSame( 'default', $this->connections->resolve( array() ) );
	}

	public function test_resolve_lets_a_filter_pick_the_connection() {
		$this->add_connection();
		add_filter(
			'smtp_connection_for_email',
			static function ( $connection_id, $mail_data ) {
				return false !== strpos( (string) $mail_data['subject'], 'Sponsor' ) ? 'sponsors' : $connection_id;
			},
			10,
			2
		);

		$this->assertSame( 'sponsors', $this->connections->resolve( array( 'subject' => 'Sponsor invoice' ) ) );
		$this->assertSame( 'default', $this->connections->resolve( array( 'subject' => 'Hello' ) ) );
	}

	public function test_resolve_falls_back_to_the_default_for_an_unknown_connection() {
		add_filter(
			'smtp_connection_for_email',
			static function () {
				return 'missing';
			}
		);

		$this->assertSame( 'default', $this->connections->resolve( array() ) );
	}

	public function test_resolve_falls_back_to_the_default_for_an_incomplete_connection() {
		$this->connections->save( 'empty', 'Empty', array( 'provider' => 'smtp' ) );
		add_filter(
			'smtp_connection_for_email',
			static function () {
				return 'empty';
			}
		);

		$this->assertSame( 'default', $this->connections->resolve( array() ) );
	}

	public function test_resolve_ignores_a_filter_result_that_is_not_a_string() {
		add_filter( 'smtp_connection_for_email', '__return_true' );

		$this->assertSame( 'default', $this->connections->resolve( array() ) );
	}

	public function test_describe_lists_the_connections_without_credentials() {
		$this->configure_default();
		$this->add_connection( 'sponsors', array( 'rate_limit_per_minute' => '30' ) );

		$described = $this->connections->describe();

		$this->assertSame( array( 'default', 'sponsors' ), array_keys( $described ) );
		$this->assertSame( 'Default', $described['default']['label'] );
		$this->assertSame( 'smtp', $described['sponsors']['provider'] );
		$this->assertTrue( $described['sponsors']['configured'] );
		$this->assertSame( array( 'per_minute' => 30 ), $described['sponsors']['rate_limits'] );
		$this->assertSame( array(), $described['default']['rate_limits'] );
		$this->assertStringNotContainsString( 'sponsors-secret', wp_json_encode( $described ) );
	}

	public function test_each_connection_has_its_own_rate_limits() {
		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['limited'] = array(
					'label'       => 'Limited',
					'configure'   => '__return_null',
					'rate_limits' => array( 'per_second' => 2 ),
				);

				return $providers;
			}
		);
		$this->add_connection( 'sponsors', array( 'provider' => 'limited' ) );

		$described = $this->connections->describe();

		$this->assertSame( array( 'per_second' => 2 ), $described['sponsors']['rate_limits'] );
		$this->assertSame( array(), $described['default']['rate_limits'] );
	}

	public function test_the_rate_limits_filter_receives_the_connection() {
		$seen = array();
		add_filter(
			'smtp_rate_limits',
			static function ( $limits, $provider, $connection ) use ( &$seen ) {
				$seen[] = $connection;

				return $limits;
			},
			10,
			3
		);
		$this->add_connection();

		$this->connections->describe();

		$this->assertSame( array( 'default', 'sponsors' ), $seen );
	}

	public function test_wp_smtp_get_connections_describes_the_connections() {
		$this->add_connection();

		$connections = wp_smtp_get_connections();

		$this->assertSame( array( 'default', 'sponsors' ), array_keys( $connections ) );
		$this->assertSame( 'Sponsors', $connections['sponsors']['label'] );
	}
}
