<?php
/**
 * Tests for the Connections_Tab class.
 *
 * @package smtp
 */

use WordPress\SMTP\Admin\Connections_Tab;
use WordPress\SMTP\Connections;
use WordPress\SMTP\Credentials;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Settings;

class Connections_Tab_Tests extends WP_UnitTestCase {

	/**
	 * @var Connections_Tab
	 */
	private $tab;

	/**
	 * @var Connections
	 */
	private $connections;

	public function set_up() {
		parent::set_up();

		$registry          = new Provider_Registry();
		$credentials       = new Credentials();
		$this->connections = new Connections( $registry, $credentials, new Settings( $registry, $credentials ) );
		$this->tab         = new Connections_Tab( $this->connections, $registry, $credentials );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		unset( $_REQUEST['_wpnonce'], $_POST, $_GET['connection'], $_GET['notice'] );
		$_POST = array();

		parent::tear_down();
	}

	private function add_connection( string $id = 'sponsors' ) {
		$this->connections->save(
			$id,
			'Sponsors',
			array(
				'provider'   => 'smtp',
				'host'       => 'smtp.sponsors.example',
				'auth'       => '1',
				'username'   => 'sponsors-user',
				'password'   => 'sponsors-secret',
				'from_email' => 'sponsors@example.org',
			)
		);
	}

	private function nonce( string $action ) {
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
	}

	private function run_handler( string $method ): string {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( $location );
			}
		);

		try {
			$this->tab->$method();
		} catch ( RuntimeException $redirect ) {
			return $redirect->getMessage();
		}

		return '';
	}

	/**
	 * Renders what the Settings tab shows for the connections: the links and, except for the default connection, the form.
	 */
	private function render(): string {
		$requested = $this->tab->get_requested();

		ob_start();
		$this->tab->render_navigation( $requested );

		if ( Connections::DEFAULT_ID !== $requested ) {
			$this->tab->render_form( Connections_Tab::NEW_CONNECTION === $requested ? Connections_Tab::NEW_SETTINGS_ID : $requested );
		}

		return ob_get_clean();
	}

	public function test_add_hooks_registers_the_admin_post_actions() {
		$this->tab->add_hooks();

		$this->assertSame( 10, has_action( 'admin_post_smtp_save_connection', array( $this->tab, 'handle_save' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_smtp_delete_connection', array( $this->tab, 'handle_delete' ) ) );
	}

	public function test_get_url_points_to_the_settings_tab_and_the_connection() {
		$this->assertStringContainsString( 'tools.php?page=smtp&tab=settings', Connections_Tab::get_url() );
		$this->assertStringContainsString( 'connection=sponsors', Connections_Tab::get_url( 'sponsors' ) );
		$this->assertStringContainsString( 'notice=saved', Connections_Tab::get_url( '', array( 'notice' => 'saved' ) ) );
	}

	public function test_the_links_show_the_default_connection_first_and_mark_it_as_current() {
		$html = $this->render();

		$this->assertLessThan( strpos( $html, 'Add connection' ), strpos( $html, 'Default' ) );
		$this->assertStringContainsString( 'class="current" aria-current="page">Default', $html );
		$this->assertStringContainsString( 'connection=new', $html );
		$this->assertStringNotContainsString( 'Delete', $html );
	}

	public function test_the_links_show_the_other_connections() {
		$this->add_connection();
		$_GET['connection'] = 'sponsors';

		$html = $this->render();

		$this->assertStringContainsString( 'connection=sponsors', $html );
		$this->assertStringContainsString( 'class="current" aria-current="page">Sponsors', $html );
		$this->assertStringContainsString( 'value="smtp_delete_connection"', $html );
	}

	public function test_names_are_escaped() {
		update_option(
			Connections::OPTION,
			array(
				'xss' => array(
					'label'    => '<script>alert(1)</script>',
					'settings' => array(),
				),
			)
		);
		$_GET['connection'] = 'xss';

		$html = $this->render();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_the_form_to_add_a_connection_asks_for_a_name_and_no_id() {
		$_GET['connection'] = 'new';

		$html = $this->render();

		$this->assertStringContainsString( 'name="connection_is_new"', $html );
		$this->assertStringContainsString( 'name="connection_label"', $html );
		$this->assertStringNotContainsString( 'name="connection_id"', $html );
		$this->assertStringContainsString( 'name="smtp_settings[host]"', $html );
		$this->assertStringContainsString( 'name="smtp_settings[rate_limit_per_minute]"', $html );
	}

	public function test_the_form_of_a_connection_renders_the_fields_of_every_provider() {
		$_GET['connection'] = 'new';

		$html = $this->render();

		$this->assertStringNotContainsString( 'save the connection to see its fields', $html );
		$this->assertMatchesRegularExpression( '/id="smtp-host"[^>]*data-smtp-provider="smtp"/s', $html );
		$this->assertMatchesRegularExpression( '/<tr class="hidden">\s*<th scope="row">\s*<label for="smtp-google_client_id">/', $html );
		$this->assertMatchesRegularExpression( '/id="smtp-google_client_id"[^>]*disabled=\'disabled\'[^>]*data-smtp-provider="google"/s', $html );
	}

	public function test_the_form_of_a_connection_hides_the_limits_behind_a_checkbox() {
		$_GET['connection'] = 'new';

		$html = $this->render();

		$this->assertStringContainsString( 'id="smtp-customize-limits"', $html );
		$this->assertDoesNotMatchRegularExpression( '/id="smtp-customize-limits"[^>]*checked/', $html );
	}

	public function test_the_checkbox_of_a_connection_is_checked_when_it_has_limits() {
		$this->connections->save(
			'sponsors',
			'Sponsors',
			array(
				'host'                => 'smtp.example.com',
				'rate_limit_per_hour' => '100',
			)
		);
		$_GET['connection'] = 'sponsors';

		$this->assertMatchesRegularExpression( '/id="smtp-customize-limits"[^>]*checked/', $this->render() );
	}

	public function test_the_form_of_a_connection_groups_the_fields_like_the_settings_tab() {
		$_GET['connection'] = 'new';

		$html = $this->render();

		$this->assertMatchesRegularExpression( '/<h3>Connection<\/h3>.*?name="smtp_settings\[host\]".*?<h3>Sender<\/h3>.*?name="smtp_settings\[from_email\]".*?<h3>Replies and bounces<\/h3>.*?name="smtp_settings\[reply_to\]".*?<h3>Sending limits<\/h3>/s', $html );
		$this->assertSame( 1, substr_count( $html, 'id="smtp-force_from_email"' ) );
		$this->assertStringNotContainsString( '<label for="smtp-return_path">Return-Path</label>', $html );
	}

	public function test_the_form_to_edit_a_connection_keeps_its_id_and_never_prints_the_password() {
		$this->add_connection();
		$_GET['connection'] = 'sponsors';

		$html = $this->render();

		$this->assertStringContainsString( '<input type="hidden" name="connection_id" value="sponsors" />', $html );
		$this->assertStringContainsString( 'value="smtp.sponsors.example"', $html );
		$this->assertStringNotContainsString( 'sponsors-secret', $html );
		$this->assertStringNotContainsString( 'connection_is_new', $html );
	}

	public function test_an_unknown_connection_shows_the_default_one() {
		$_GET['connection'] = 'missing';

		$this->assertSame( Connections::DEFAULT_ID, $this->tab->get_requested() );
		$this->assertStringNotContainsString( 'name="connection_label"', $this->render() );
	}

	public function test_the_default_connection_has_no_form_of_its_own() {
		$_GET['connection'] = 'default';

		$this->assertSame( Connections::DEFAULT_ID, $this->tab->get_requested() );
		$this->assertStringNotContainsString( 'name="connection_id"', $this->render() );
	}

	public function test_notices_are_shown() {
		$_GET['notice'] = 'saved';

		$this->assertStringContainsString( 'Connection saved.', $this->render() );

		$_GET['notice'] = 'bogus';

		$this->assertStringNotContainsString( 'notice-success', $this->render() );
	}

	public function test_save_adds_a_connection_with_an_id_generated_from_the_name() {
		$this->nonce( Connections_Tab::SAVE_ACTION );
		$_POST = array(
			'connection_is_new' => '1',
			'connection_label'  => 'Sponsors Team!',
			'smtp_settings'     => array(
				'provider' => 'smtp',
				'host'     => 'smtp.sponsors.example',
				'password' => 'sponsors-secret',
			),
		);

		$location = $this->run_handler( 'handle_save' );

		$this->assertStringContainsString( 'connection=sponsors-team', $location );
		$this->assertStringContainsString( 'notice=saved', $location );
		$this->assertSame( 'Sponsors Team!', $this->connections->get_label( 'sponsors-team' ) );
		$this->assertSame( 'sponsors-secret', $this->connections->get_settings( 'sponsors-team' )->get( 'password' ) );
	}

	public function test_save_ignores_a_posted_id_when_adding() {
		$this->nonce( Connections_Tab::SAVE_ACTION );
		$_POST = array(
			'connection_is_new' => '1',
			'connection_id'     => 'other',
			'connection_label'  => 'Sponsors',
			'smtp_settings'     => array( 'host' => 'smtp.sponsors.example' ),
		);

		$this->run_handler( 'handle_save' );

		$this->assertTrue( $this->connections->exists( 'sponsors' ) );
		$this->assertFalse( $this->connections->exists( 'other' ) );
	}

	public function test_save_updates_a_connection_without_changing_its_id() {
		$this->add_connection();
		$this->nonce( Connections_Tab::SAVE_ACTION );
		$_POST = array(
			'connection_id'    => 'sponsors',
			'connection_label' => 'Renamed',
			'smtp_settings'    => array(
				'provider' => 'smtp',
				'host'     => 'smtp.new.example',
				'auth'     => '1',
				'username' => 'sponsors-user',
				'password' => '',
			),
		);

		$location = $this->run_handler( 'handle_save' );

		$settings = $this->connections->get_settings( 'sponsors' );
		$this->assertStringContainsString( 'connection=sponsors', $location );
		$this->assertSame( 'Renamed', $this->connections->get_label( 'sponsors' ) );
		$this->assertSame( 'smtp.new.example', $settings->get( 'host' ) );
		$this->assertSame( 'sponsors-secret', $settings->get( 'password' ) );
		$this->assertFalse( $this->connections->exists( 'renamed' ) );
	}

	public function test_save_does_not_overwrite_an_existing_connection_when_adding() {
		$this->add_connection();
		$this->nonce( Connections_Tab::SAVE_ACTION );
		$_POST = array(
			'connection_is_new' => '1',
			'connection_label'  => 'Sponsors',
			'smtp_settings'     => array( 'host' => 'smtp.other.example' ),
		);

		$location = $this->run_handler( 'handle_save' );

		$this->assertStringContainsString( 'connection=sponsors-2', $location );
		$this->assertSame( 'smtp.sponsors.example', $this->connections->get_settings( 'sponsors' )->get( 'host' ) );
		$this->assertSame( 'smtp.other.example', $this->connections->get_settings( 'sponsors-2' )->get( 'host' ) );
	}

	public function test_save_rejects_a_name_without_letters_or_numbers() {
		$this->nonce( Connections_Tab::SAVE_ACTION );
		$_POST = array(
			'connection_is_new' => '1',
			'connection_label'  => '!!!',
			'smtp_settings'     => array( 'host' => 'smtp.example.com' ),
		);

		$location = $this->run_handler( 'handle_save' );

		$this->assertStringContainsString( 'notice=missing_name', $location );
		$this->assertFalse( $this->connections->has_extra() );
	}

	public function test_save_does_not_create_a_connection_when_editing_an_unknown_one() {
		$this->nonce( Connections_Tab::SAVE_ACTION );
		$_POST = array(
			'connection_id'    => 'ghost',
			'connection_label' => 'Ghost',
			'smtp_settings'    => array( 'host' => 'smtp.example.com' ),
		);

		$location = $this->run_handler( 'handle_save' );

		$this->assertStringContainsString( 'notice=not_found', $location );
		$this->assertFalse( $this->connections->has_extra() );
	}

	public function test_save_rejects_users_without_the_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->nonce( Connections_Tab::SAVE_ACTION );
		$_POST = array(
			'connection_is_new' => '1',
			'connection_id'     => 'sponsors',
		);

		$this->expectException( WPDieException::class );

		$this->tab->handle_save();
	}

	public function test_save_rejects_an_invalid_nonce() {
		$_REQUEST['_wpnonce'] = 'invalid';
		$_POST                = array(
			'connection_is_new' => '1',
			'connection_id'     => 'sponsors',
		);

		$this->expectException( WPDieException::class );

		$this->tab->handle_save();
	}

	public function test_delete_removes_the_connection_and_its_secret() {
		$this->add_connection();
		$this->nonce( Connections_Tab::DELETE_ACTION );
		$_POST = array( 'connection_id' => 'sponsors' );

		$location = $this->run_handler( 'handle_delete' );

		$this->assertStringContainsString( 'notice=deleted', $location );
		$this->assertFalse( $this->connections->exists( 'sponsors' ) );
		$this->assertSame( '', ( new Credentials() )->get( 'connection:sponsors:password' ) );
	}

	public function test_delete_rejects_users_without_the_capability() {
		$this->add_connection();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->nonce( Connections_Tab::DELETE_ACTION );
		$_POST = array( 'connection_id' => 'sponsors' );

		try {
			$this->tab->handle_delete();
			$this->fail( 'Expected the request to be rejected.' );
		} catch ( WPDieException $e ) {
			$this->assertTrue( $this->connections->exists( 'sponsors' ) );
		}
	}

	public function test_delete_rejects_an_invalid_nonce() {
		$this->add_connection();
		$_REQUEST['_wpnonce'] = 'invalid';
		$_POST                = array( 'connection_id' => 'sponsors' );

		try {
			$this->tab->handle_delete();
			$this->fail( 'Expected the request to be rejected.' );
		} catch ( WPDieException $e ) {
			$this->assertTrue( $this->connections->exists( 'sponsors' ) );
		}
	}

	public function test_the_default_connection_can_not_be_deleted() {
		$this->nonce( Connections_Tab::DELETE_ACTION );
		$_POST = array( 'connection_id' => 'default' );

		$this->run_handler( 'handle_delete' );

		$this->assertTrue( $this->connections->exists( 'default' ) );
	}
}
