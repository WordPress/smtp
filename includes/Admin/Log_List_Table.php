<?php
/**
 * Class WordPress\SMTP\Admin\Log_List_Table
 *
 * @package smtp
 */

namespace WordPress\SMTP\Admin;

use WordPress\SMTP\Connections;
use WordPress\SMTP\Delivery_Tracker;
use WordPress\SMTP\Log_Repository;
use WordPress\SMTP\Provider_Registry;

/**
 * Lists the email log.
 *
 * @since 1.2.0
 */
class Log_List_Table extends \WP_List_Table {

	/**
	 * Entries shown per page.
	 *
	 * @since 1.2.0
	 */
	const PER_PAGE = 20;

	/**
	 * Log repository.
	 *
	 * @since 1.2.0
	 * @var Log_Repository
	 */
	private $repository;

	/**
	 * Provider registry.
	 *
	 * @since 1.2.0
	 * @var Provider_Registry
	 */
	private $registry;

	/**
	 * Connections, or null when the connection of each email is not shown.
	 *
	 * @since 1.2.0
	 * @var Connections|null
	 */
	private $connections;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param Log_Repository    $repository  Log repository.
	 * @param Provider_Registry $registry    Provider registry.
	 * @param Connections|null  $connections Optional. Connections. Default null. The Connection column is shown when there is more than one.
	 */
	public function __construct( Log_Repository $repository, Provider_Registry $registry, ?Connections $connections = null ) {
		parent::__construct(
			array(
				'singular' => 'email',
				'plural'   => 'emails',
				'ajax'     => false,
			)
		);

		$this->repository  = $repository;
		$this->registry    = $registry;
		$this->connections = $connections;
	}

	/**
	 * Returns the table columns.
	 *
	 * @since 1.2.0
	 *
	 * @return string[] Column labels keyed by column slug.
	 */
	public function get_columns() {
		$columns = array(
			'created_at' => __( 'Date', 'smtp' ),
			'recipient'  => __( 'To', 'smtp' ),
			'subject'    => __( 'Subject', 'smtp' ),
			'status'     => __( 'Status', 'smtp' ),
			'error'      => __( 'Error', 'smtp' ),
			'provider'   => __( 'Provider', 'smtp' ),
		);

		// Single connection sites keep the table they always had.
		if ( $this->connections && $this->connections->has_extra() ) {
			$columns['connection'] = __( 'Connection', 'smtp' );
		}

		return $columns;
	}

	/**
	 * Returns the status filter links.
	 *
	 * @since 1.2.0
	 *
	 * @return string[] Links keyed by status.
	 */
	protected function get_views() {
		$current = $this->get_current_status();
		$base    = Admin_Page::get_url( 'log' );

		$statuses = array(
			''       => __( 'All', 'smtp' ),
			'sent'   => __( 'Sent', 'smtp' ),
			'failed' => __( 'Failed', 'smtp' ),
		);

		$views = array();
		foreach ( $statuses as $status => $label ) {
			$views[ '' === $status ? 'all' : $status ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
				esc_url( '' === $status ? $base : add_query_arg( 'status', $status, $base ) ),
				$status === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				$this->repository->count( $status )
			);
		}

		return $views;
	}

	/**
	 * Loads the entries for the current page.
	 *
	 * @since 1.2.0
	 */
	public function prepare_items() {
		$status = $this->get_current_status();
		$total  = $this->repository->count( $status );
		$paged  = $this->get_pagenum();

		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = $this->repository->get_items( $status, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Renders a column.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $item        Log entry.
	 * @param string $column_name Column slug.
	 * @return string Column content.
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'created_at':
				$local = get_date_from_gmt( $item['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
				return esc_html( $local );

			case 'status':
				return esc_html( 'sent' === $item['status'] ? __( 'Sent', 'smtp' ) : __( 'Failed', 'smtp' ) );

			case 'provider':
				return esc_html( $this->get_provider_label( (string) $item['provider'] ) );

			case 'connection':
				return esc_html( $this->get_connection_label( $item ) );

			case 'recipient':
			case 'subject':
			case 'error':
				return esc_html( (string) $item[ $column_name ] );
		}

		return '';
	}

	/**
	 * Message shown when there are no entries.
	 *
	 * @since 1.2.0
	 */
	public function no_items() {
		esc_html_e( 'No emails logged yet.', 'smtp' );
	}

	/**
	 * Returns the label of a logged provider.
	 *
	 * @since 1.2.0
	 *
	 * @param string $provider Logged provider: a provider slug, `default` or `other`.
	 * @return string Provider label.
	 */
	private function get_provider_label( string $provider ): string {
		if ( Delivery_Tracker::PROVIDER_DEFAULT === $provider ) {
			return __( 'WordPress default', 'smtp' );
		}

		if ( Delivery_Tracker::PROVIDER_OTHER === $provider ) {
			return __( 'Another plugin', 'smtp' );
		}

		$definition = $this->registry->get( $provider );

		return $definition ? (string) $definition['label'] : $provider;
	}

	/**
	 * Returns the name of the connection that sent an email.
	 *
	 * @since 1.2.0
	 *
	 * @param array $item Log entry.
	 * @return string Connection name, or a dash when no connection delivered the email.
	 */
	private function get_connection_label( array $item ): string {
		if ( ! $this->connections ) {
			return '';
		}

		$id = (string) ( $item['connection_id'] ?? '' );

		if ( '' !== $id ) {
			return $this->connections->get_label( $id );
		}

		// Entries of the default connection have no ID. Those that went through the WordPress mailer or
		// another plugin did not use a connection at all.
		$provider = (string) $item['provider'];
		if ( in_array( $provider, array( Delivery_Tracker::PROVIDER_DEFAULT, Delivery_Tracker::PROVIDER_OTHER ), true ) ) {
			return '—';
		}

		return $this->connections->get_label( Connections::DEFAULT_ID );
	}

	/**
	 * Returns the status filter from the request.
	 *
	 * @since 1.2.0
	 *
	 * @return string `sent`, `failed` or an empty string.
	 */
	private function get_current_status(): string {
		// Read-only filter parameter, no state is changed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		return in_array( $status, array( 'sent', 'failed' ), true ) ? $status : '';
	}
}
