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

	private function render(): string {
		ob_start();
		$this->tab->render();

		return ob_get_clean();
	}

	public function test_add_hooks_registers_the_admin_post_actions() {
		$this->tab->add_hooks();

		$this->assertSame( 10, has_action( 'admin_post_smtp_save_connection', array( $this->tab, 'handle_save' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_smtp_delete_connection', array( $this->tab, 'handle_delete' ) ) );
	}

	public function test_get_url_points_to_the_tab_and_the_connection() {
		$this->assertStringContainsString( 'tools.php?page=smtp&tab=connections', Connections_Tab::get_url() );
		$this->assertStringContainsString( 'connection=sponsors', Connections_Tab::get_url( 'sponsors' ) );
		$this->assertStringContainsString( 'notice=saved', Connections_Tab::get_url( '', array( 'notice' => 'saved' ) ) );
	}

	public function test_the_list_shows_the_default_connection_first() {
		$html = $this->render();

		$this->assertStringContainsString( 'Default', $html );
		$this->assertStringContainsString( 'Edit in Settings', $html );
		$this->assertStringContainsString( 'connection=new', $html );
		$this->assertStringNotContainsString( 'Delete', $html );
	}

	public function test_the_list_shows_the_other_connections_with_their_status() {
		$this->add_connection();
		$this->connections->save( 'empty', 'Empty', array( 'provider' => 'smtp' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'Sponsors', $html );
		$this->assertStringContainsString( 'sponsors@example.org', $html );
		$this->assertStringContainsString( 'Ready', $html );
		$this->assertStringContainsString( 'Incomplete', $html );
		$this->assertStringContainsString( 'connection=sponsors', $html );
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

		$html = $this->render();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_the_form_to_add_a_connection_has_an_editable_id() {
		$_GET['connection'] = 'new';

		$html = $this->render();

		$this->assertStringContainsString( 'name="connection_is_new"', $html );
		$this->assertStringContainsString( 'name="connection_id"', $html );
		$this->assertStringNotContainsString( 'readonly', $html );
		$this->assertStringContainsString( 'name="smtp_settings[host]"', $html );
		$this->assertStringContainsString( 'name="smtp_settings[rate_limit_per_minute]"', $html );
	}

	public function test_the_form_to_edit_a_connection_keeps_its_id_and_never_prints_the_password() {
		$this->add_connection();
		$_GET['connection'] = 'sponsors';

		$html = $this->render();

		$this->assertStringContainsString( 'readonly', $html );
		$this->assertStringContainsString( 'value="smtp.sponsors.example"', $html );
		$this->assertStringNotContainsString( 'sponsors-secret', $html );
		$this->assertStringNotContainsString( 'connection_is_new', $html );
	}

	public function test_an_unknown_connection_shows_the_list() {
		$_GET['connection'] = 'missing';

		$this->assertStringContainsString( 'Add connection', $this->render() );
		$this->assertStringNotContainsString( 'name="connection_id"', $this->render() );
	}

	public function test_the_default_connection_is_edited_in_the_settings_tab() {
		$_GET['connection'] = 'default';

		$this->assertStringNotContainsString( 'name="connection_id"', $this->render() );
	}

	public function test_notices_are_shown() {
		$_GET['notice'] = 'saved';

		$this->assertStringContainsString( 'Connection saved.', $this->render() );

		$_GET['notice'] = 'bogus';

		$this->assertStringNotContainsString( 'notice-success', $this->render() );
	}

	public function test_save_adds_a_connection_and_redirects_to_its_form() {
		$this->nonce( Connections_Tab::SAVE_ACTION );
		$_POST = array(
			'connection_is_new' => '1',
			'connection_id'     => 'Sponsors',
			'connection_label'  => 'Sponsors team',
			'smtp_settings'     => array(
				'provider' => 'smtp',
				'host'     => 'smtp.sponsors.example',
				'password' => 'sponsors-secret',
			),
		);

		$location = $this->run_handler( 'handle_save' );

		$this->assertStringContainsString( 'connection=sponsors', $location );
		$this->assertStringContainsString( 'notice=saved', $location );
		$this->assertSame( 'Sponsors team', $this->connections->get_label( 'sponsors' ) );
		$this->assertSame( 'sponsors-secret', $this->connections->get_settings( 'sponsors' )->get( 'password' ) );
	}

	public function test_save_updates_a_connection() {
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

		$this->run_handler( 'handle_save' );

		$settings = $this->connections->get_settings( 'sponsors' );
		$this->assertSame( 'Renamed', $this->connections->get_label( 'sponsors' ) );
		$this->assertSame( 'smtp.new.example', $settings->get( 'host' ) );
		$this->assertSame( 'sponsors-secret', $settings->get( 'password' ) );
	}

	public function test_save_does_not_overwrite_an_existing_connection_when_adding() {
		$this->add_connection();
		$this->nonce( Connections_Tab::SAVE_ACTION );
		$_POST = array(
			'connection_is_new' => '1',
			'connection_id'     => 'sponsors',
			'connection_label'  => 'Other',
			'smtp_settings'     => array( 'host' => 'smtp.other.example' ),
		);

		$location = $this->run_handler( 'handle_save' );

		$this->assertStringContainsString( 'notice=exists', $location );
		$this->assertSame( 'Sponsors', $this->connections->get_label( 'sponsors' ) );
		$this->assertSame( 'smtp.sponsors.example', $this->connections->get_settings( 'sponsors' )->get( 'host' ) );
	}

	public function test_save_rejects_an_invalid_id() {
		$this->nonce( Connections_Tab::SAVE_ACTION );
		$_POST = array(
			'connection_is_new' => '1',
			'connection_id'     => 'default',
			'connection_label'  => 'Default',
			'smtp_settings'     => array( 'host' => 'smtp.example.com' ),
		);

		$location = $this->run_handler( 'handle_save' );

		$this->assertStringContainsString( 'notice=invalid_id', $location );
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
