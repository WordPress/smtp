<?php
/**
 * Tests for the Rate_Limits class.
 *
 * @package smtp
 */

use WordPress\SMTP\Credentials;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Rate_Limits;
use WordPress\SMTP\Settings;

class Rate_Limits_Tests extends WP_UnitTestCase {

	/**
	 * @var Rate_Limits
	 */
	private $limits;

	public function set_up() {
		parent::set_up();

		$registry     = new Provider_Registry();
		$this->limits = new Rate_Limits( new Settings( $registry, new Credentials() ), $registry );
	}

	private function add_limited_provider( array $rate_limits ) {
		add_filter(
			'smtp_providers',
			static function ( $providers ) use ( $rate_limits ) {
				$providers['limited'] = array(
					'label'       => 'Limited',
					'configure'   => '__return_null',
					'rate_limits' => $rate_limits,
				);

				return $providers;
			}
		);
	}

	private function activate_provider( string $slug ) {
		update_option( Settings::OPTION, array( 'provider' => $slug ) );
	}

	public function test_there_are_no_limits_by_default() {
		$this->assertSame( array(), $this->limits->get() );
	}

	public function test_it_returns_the_limits_declared_by_the_provider() {
		$this->add_limited_provider(
			array(
				'per_second'  => 2,
				'per_minute'  => 60,
				'concurrency' => 1,
				'recipients'  => array( 'per_day' => 500 ),
			)
		);

		$this->assertSame(
			array(
				'per_second'  => 2,
				'per_minute'  => 60,
				'concurrency' => 1,
				'recipients'  => array( 'per_day' => 500 ),
			),
			$this->limits->get( 'limited' )
		);
	}

	public function test_it_defaults_to_the_active_provider() {
		$this->add_limited_provider( array( 'per_hour' => 500 ) );
		$this->activate_provider( 'limited' );

		$this->assertSame( array( 'per_hour' => 500 ), $this->limits->get() );
	}

	public function test_an_unknown_provider_has_no_limits() {
		$this->assertSame( array(), $this->limits->get( 'unknown' ) );
	}

	public function test_a_saved_value_overrides_the_default_of_the_active_provider() {
		$this->add_limited_provider(
			array(
				'per_second' => 2,
				'per_day'    => 2000,
			)
		);
		update_option(
			Settings::OPTION,
			array(
				'provider'              => 'limited',
				'rate_limit_per_second' => 10,
				'rate_limit_per_hour'   => 300,
			)
		);

		$this->assertSame(
			array(
				'per_second' => 10,
				'per_hour'   => 300,
				'per_day'    => 2000,
			),
			$this->limits->get()
		);
	}

	public function test_saved_values_do_not_apply_to_another_provider() {
		$this->add_limited_provider( array( 'per_second' => 2 ) );
		update_option(
			Settings::OPTION,
			array(
				'provider'              => 'smtp',
				'rate_limit_per_second' => 10,
			)
		);

		$this->assertSame( array( 'per_second' => 2 ), $this->limits->get( 'limited' ) );
	}

	public function test_filter_can_change_the_limits() {
		$this->add_limited_provider( array( 'per_second' => 2 ) );
		add_filter(
			'smtp_rate_limits',
			static function ( $limits, $provider ) {
				$limits['per_minute'] = 'limited' === $provider ? 30 : 1;

				return $limits;
			},
			10,
			2
		);

		$this->assertSame(
			array(
				'per_second' => 2,
				'per_minute' => 30,
			),
			$this->limits->get( 'limited' )
		);
	}

	public function test_filter_result_is_normalized() {
		add_filter( 'smtp_rate_limits', '__return_true' );

		$this->assertSame( array(), $this->limits->get() );
	}

	public function test_normalize_keeps_positive_whole_numbers_only() {
		$this->assertSame(
			array(
				'per_second' => 2,
				'per_minute' => 60,
				'recipients' => array( 'per_hour' => 100 ),
			),
			Rate_Limits::normalize(
				array(
					'per_second'  => 2,
					'per_minute'  => '60',
					'per_hour'    => 0,
					'per_day'     => -5,
					'concurrency' => 'many',
					'unknown'     => 10,
					'recipients'  => array(
						'per_hour'   => 100,
						'per_day'    => 1.5,
						'per_minute' => null,
					),
				)
			)
		);
	}

	public function test_normalize_ignores_non_arrays() {
		$this->assertSame( array(), Rate_Limits::normalize( 'nope' ) );
		$this->assertSame( array(), Rate_Limits::normalize( array( 'recipients' => 'nope' ) ) );
	}

	public function test_global_function_returns_the_limits_of_the_active_provider() {
		$this->add_limited_provider( array( 'per_minute' => 60 ) );
		$this->activate_provider( 'limited' );

		$this->assertSame( array( 'per_minute' => 60 ), wp_smtp_get_rate_limits() );
		$this->assertSame( array(), wp_smtp_get_rate_limits( 'smtp' ) );
	}
}
