<?php
/**
 * Tests for the Log_List_Table class.
 *
 * @package smtp
 */

use WordPress\SMTP\Settings;
use WordPress\SMTP\Credentials;
use WordPress\SMTP\Connections;
use WordPress\SMTP\Admin\Log_List_Table;
use WordPress\SMTP\Log_Repository;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Test_Utils\Traits\With_Log_Table;

class Log_List_Table_Tests extends WP_UnitTestCase {
	use With_Log_Table;

	/**
	 * @var Log_Repository
	 */
	private $repository;

	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$this->repository = new Log_Repository();
		$this->create_log_table();
	}

	public function tear_down() {
		unset( $_GET['status'], $_REQUEST['paged'] );

		parent::tear_down();
	}

	private function add_entry( string $status = 'sent', string $subject = 'Subject' ) {
		$this->repository->insert(
			array(
				'recipient' => 'to@example.org',
				'subject'   => $subject,
				'status'    => $status,
				'error'     => 'failed' === $status ? 'Boom' : '',
				'provider'  => 'smtp',
			)
		);
	}

	private function render_table(): string {
		$table = new Log_List_Table( $this->repository, new Provider_Registry() );
		$table->prepare_items();

		ob_start();
		$table->views();
		$table->display();

		return ob_get_clean();
	}

	public function test_columns() {
		$table = new Log_List_Table( $this->repository, new Provider_Registry() );

		$this->assertSame(
			array( 'created_at', 'recipient', 'subject', 'status', 'error', 'provider' ),
			array_keys( $table->get_columns() )
		);
	}

	public function test_empty_log_shows_a_message() {
		$this->assertStringContainsString( 'No emails logged yet.', $this->render_table() );
	}

	public function test_entries_are_listed() {
		$this->add_entry( 'sent', 'Welcome' );
		$this->add_entry( 'failed', 'Reset' );

		$html = $this->render_table();

		$this->assertStringContainsString( 'Welcome', $html );
		$this->assertStringContainsString( 'Reset', $html );
		$this->assertStringContainsString( 'to@example.org', $html );
		$this->assertStringContainsString( 'Boom', $html );
	}

	public function test_values_are_escaped() {
		$this->repository->insert(
			array(
				'recipient' => 'to@example.org',
				'subject'   => '<script>alert(1)</script>',
				'status'    => 'sent',
				'error'     => '',
				'provider'  => 'smtp',
			)
		);

		$html = $this->render_table();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_views_show_the_counts() {
		$this->add_entry( 'sent' );
		$this->add_entry( 'sent' );
		$this->add_entry( 'failed' );

		$html = $this->render_table();

		$this->assertStringContainsString( 'All <span class="count">(3)</span>', $html );
		$this->assertStringContainsString( 'Sent <span class="count">(2)</span>', $html );
		$this->assertStringContainsString( 'Failed <span class="count">(1)</span>', $html );
	}

	public function test_status_filter_limits_the_entries() {
		$this->add_entry( 'sent', 'Delivered' );
		$this->add_entry( 'failed', 'Rejected' );
		$_GET['status'] = 'failed';

		$html = $this->render_table();

		$this->assertStringContainsString( 'Rejected', $html );
		$this->assertStringNotContainsString( 'Delivered', $html );
		$this->assertStringContainsString( 'class="current" aria-current="page"', $html );
	}

	public function test_unknown_status_filter_is_ignored() {
		$this->add_entry( 'sent', 'Delivered' );
		$_GET['status'] = 'bogus';

		$this->assertStringContainsString( 'Delivered', $this->render_table() );
	}

	public function test_entries_are_paginated() {
		for ( $i = 1; $i <= Log_List_Table::PER_PAGE + 1; $i++ ) {
			$this->add_entry( 'sent', "Mail number {$i}" );
		}

		$first_page = $this->render_table();

		$_REQUEST['paged'] = 2;
		$second_page       = $this->render_table();

		$this->assertStringContainsString( 'Mail number ' . ( Log_List_Table::PER_PAGE + 1 ), $first_page );
		$this->assertStringNotContainsString( 'Mail number 1<', $first_page );
		$this->assertStringContainsString( 'Mail number 1<', $second_page );
	}

	public function test_provider_column_shows_labels() {
		foreach ( array( 'smtp', 'default', 'other', 'unknown-provider' ) as $provider ) {
			$this->repository->insert(
				array(
					'recipient' => 'to@example.org',
					'subject'   => 'Subject',
					'status'    => 'sent',
					'error'     => '',
					'provider'  => $provider,
				)
			);
		}

		$html = $this->render_table();

		$this->assertStringContainsString( 'Generic SMTP', $html );
		$this->assertStringContainsString( 'WordPress default', $html );
		$this->assertStringContainsString( 'Another plugin', $html );
		$this->assertStringContainsString( 'unknown-provider', $html );
	}

	private function get_connections(): Connections {
		$registry    = new Provider_Registry();
		$credentials = new Credentials();

		return new Connections( $registry, $credentials, new Settings( $registry, $credentials ) );
	}

	private function render_table_with( Connections $connections ): string {
		$table = new Log_List_Table( $this->repository, new Provider_Registry(), $connections );
		$table->prepare_items();

		ob_start();
		$table->display();

		return ob_get_clean();
	}

	public function test_the_connection_column_is_hidden_without_other_connections() {
		$table = new Log_List_Table( $this->repository, new Provider_Registry(), $this->get_connections() );

		$this->assertArrayNotHasKey( 'connection', $table->get_columns() );
	}

	public function test_the_connection_column_is_shown_with_other_connections() {
		$connections = $this->get_connections();
		$connections->save( 'sponsors', 'Sponsors', array( 'host' => 'smtp.example.com' ) );
		$table = new Log_List_Table( $this->repository, new Provider_Registry(), $connections );

		$this->assertSame(
			array( 'created_at', 'recipient', 'subject', 'status', 'error', 'provider', 'connection' ),
			array_keys( $table->get_columns() )
		);
	}

	public function test_entries_show_the_connection_that_sent_them() {
		$connections = $this->get_connections();
		$connections->save( 'sponsors', 'Sponsors team', array( 'host' => 'smtp.example.com' ) );
		$base = array(
			'recipient' => 'to@example.org',
			'subject'   => 'Subject',
			'status'    => 'sent',
			'error'     => '',
		);
		$this->repository->insert(
			array_merge(
				$base,
				array(
					'provider'      => 'smtp',
					'connection_id' => 'sponsors',
				)
			)
		);
		$this->repository->insert( array_merge( $base, array( 'provider' => 'smtp' ) ) );
		$this->repository->insert( array_merge( $base, array( 'provider' => 'other' ) ) );
		$this->repository->insert(
			array_merge(
				$base,
				array(
					'provider'      => 'smtp',
					'connection_id' => 'removed',
				)
			)
		);

		$html = $this->render_table_with( $connections );

		$this->assertStringContainsString( '>Sponsors team<', $html );
		$this->assertStringContainsString( '>Default<', $html );
		$this->assertStringContainsString( '>removed<', $html );
		$this->assertStringContainsString( '>—<', $html );
	}
}
