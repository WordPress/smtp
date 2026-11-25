<?php
/**
 * Tests for the Provider_Registry class.
 *
 * @package smtp
 */

use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Test_Utils\Traits\With_PHPMailer;

class Provider_Registry_Tests extends WP_UnitTestCase {
	use With_PHPMailer;

	/**
	 * @var Provider_Registry
	 */
	private $registry;

	public function set_up() {
		parent::set_up();

		$this->registry = new Provider_Registry();
	}

	public function test_generic_smtp_is_registered_by_default() {
		$providers = $this->registry->get_all();

		$this->assertArrayHasKey( 'smtp', $providers );
		$this->assertSame( 'Generic SMTP', $providers['smtp']['label'] );
		$this->assertSame( array( 'host' ), $providers['smtp']['required'] );
		$this->assertSame(
			array(
				'username' => 'auth',
				'password' => 'auth',
			),
			$providers['smtp']['required_if']
		);
		$this->assertTrue( is_callable( $providers['smtp']['configure'] ) );
	}

	public function test_generic_smtp_defines_the_expected_fields() {
		$fields = $this->registry->get( 'smtp' )['fields'];

		$this->assertSame(
			array( 'host', 'port', 'encryption', 'auth', 'username', 'password' ),
			array_keys( $fields )
		);
		$this->assertSame( 'SMTP_HOST', $fields['host']['constant'] );
		$this->assertSame( 'SMTP_PASSWORD', $fields['password']['constant'] );
		$this->assertSame( 'password', $fields['password']['type'] );
	}

	public function test_filter_can_add_a_provider() {
		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['custom'] = array(
					'label'     => 'Custom',
					'configure' => '__return_null',
				);

				return $providers;
			}
		);

		$provider = $this->registry->get( 'custom' );

		$this->assertIsArray( $provider );
		$this->assertSame( array(), $provider['fields'] );
		$this->assertSame( array(), $provider['required'] );
		$this->assertSame( array(), $provider['required_if'] );
	}

	public function test_a_provider_can_send_without_configure() {
		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['api'] = array(
					'label' => 'API',
					'send'  => '__return_true',
				);

				return $providers;
			}
		);

		$provider = $this->registry->get( 'api' );

		$this->assertIsArray( $provider );
		$this->assertNull( $provider['configure'] );
		$this->assertSame( '__return_true', $provider['send'] );
	}

	public function test_google_is_registered_as_an_api_provider() {
		$provider = $this->registry->get( 'google' );

		$this->assertIsArray( $provider );
		$this->assertSame( 'Google (Gmail API)', $provider['label'] );
		$this->assertNull( $provider['configure'] );
		$this->assertTrue( is_callable( $provider['send'] ) );
		$this->assertSame( array( 'google_client_id', 'google_client_secret', 'google_refresh_token' ), $provider['required'] );
		$this->assertSame( 'password', $provider['fields']['google_client_secret']['type'] );
		$this->assertSame( 'password', $provider['fields']['google_refresh_token']['type'] );
		$this->assertSame( 'SMTP_GOOGLE_REFRESH_TOKEN', $provider['fields']['google_refresh_token']['constant'] );
	}

	public function test_the_default_provider_is_still_generic_smtp() {
		$this->assertSame( 'smtp', $this->registry->get_default_slug() );
	}

	public function test_smtp_providers_have_no_send_callback() {
		$this->assertNull( $this->registry->get( 'smtp' )['send'] );
	}

	public function test_providers_with_an_invalid_send_are_discarded() {
		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['bad-send']      = array(
					'label' => 'Bad send',
					'send'  => 'not_a_function',
				);
				$providers['bad-configure'] = array(
					'label'     => 'Bad configure',
					'configure' => 'not_a_function',
					'send'      => '__return_true',
				);

				return $providers;
			}
		);

		$this->assertNull( $this->registry->get( 'bad-send' ) );
		$this->assertNull( $this->registry->get( 'bad-configure' ) );
	}

	public function test_invalid_providers_are_discarded() {
		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['no-label']     = array( 'configure' => '__return_null' );
				$providers['no-configure'] = array( 'label' => 'No configure' );
				$providers['bad-callable'] = array(
					'label'     => 'Bad callable',
					'configure' => 'this_function_does_not_exist',
				);
				$providers['not-an-array'] = 'nope';
				$providers[0]              = array(
					'label'     => 'Numeric slug',
					'configure' => '__return_null',
				);

				return $providers;
			}
		);

		$this->assertSame( array( 'smtp', 'google', 'werkmail' ), array_keys( $this->registry->get_all() ) );
	}

	public function test_filter_returning_a_non_array_leaves_no_providers() {
		add_filter( 'smtp_providers', '__return_false' );

		$this->assertSame( array(), $this->registry->get_all() );
		$this->assertSame( '', $this->registry->get_default_slug() );
	}

	public function test_get_returns_null_for_an_unknown_provider() {
		$this->assertNull( $this->registry->get( 'unknown' ) );
	}

	public function test_default_slug_is_smtp() {
		$this->assertSame( 'smtp', $this->registry->get_default_slug() );
	}

	public function test_default_slug_falls_back_to_the_first_provider() {
		add_filter(
			'smtp_providers',
			static function () {
				return array(
					'first'  => array(
						'label'     => 'First',
						'configure' => '__return_null',
					),
					'second' => array(
						'label'     => 'Second',
						'configure' => '__return_null',
					),
				);
			}
		);

		$this->assertSame( 'first', $this->registry->get_default_slug() );
	}

	public function test_configure_generic_with_authentication_and_ssl() {
		$phpmailer = $this->get_phpmailer();

		$this->registry->configure_generic(
			$phpmailer,
			array(
				'host'       => 'smtp.example.com',
				'port'       => '465',
				'encryption' => 'ssl',
				'auth'       => true,
				'username'   => 'user',
				'password'   => 'secret',
			)
		);

		$this->assertSame( 'smtp', $phpmailer->Mailer );
		$this->assertSame( 'smtp.example.com', $phpmailer->Host );
		$this->assertSame( 465, $phpmailer->Port );
		$this->assertSame( 'ssl', $phpmailer->SMTPSecure );
		$this->assertTrue( $phpmailer->SMTPAuth );
		$this->assertSame( 'user', $phpmailer->Username );
		$this->assertSame( 'secret', $phpmailer->Password );
	}

	public function test_configure_generic_without_encryption_disables_auto_tls() {
		$phpmailer = $this->get_phpmailer();

		$this->registry->configure_generic(
			$phpmailer,
			array(
				'host'       => 'localhost',
				'port'       => 25,
				'encryption' => 'none',
				'auth'       => false,
				'username'   => 'ignored',
				'password'   => 'ignored',
			)
		);

		$this->assertSame( '', $phpmailer->SMTPSecure );
		$this->assertFalse( $phpmailer->SMTPAutoTLS );
		$this->assertFalse( $phpmailer->SMTPAuth );
		$this->assertSame( '', $phpmailer->Username );
		$this->assertSame( '', $phpmailer->Password );
	}

	public function test_providers_have_no_rate_limits_by_default() {
		$this->assertSame( array(), $this->registry->get( 'smtp' )['rate_limits'] );
	}

	public function test_provider_can_declare_rate_limits() {
		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['limited'] = array(
					'label'       => 'Limited',
					'configure'   => '__return_null',
					'rate_limits' => array(
						'per_second' => 2,
						'per_day'    => 2000,
					),
				);

				return $providers;
			}
		);

		$this->assertSame(
			array(
				'per_second' => 2,
				'per_day'    => 2000,
			),
			$this->registry->get( 'limited' )['rate_limits']
		);
	}

	public function test_invalid_rate_limits_are_dropped_without_discarding_the_provider() {
		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['broken'] = array(
					'label'       => 'Broken',
					'configure'   => '__return_null',
					'rate_limits' => 'a lot',
				);

				return $providers;
			}
		);

		$this->assertSame( array(), $this->registry->get( 'broken' )['rate_limits'] );
	}
}
