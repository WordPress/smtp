<?php
/**
 * Class WordPress\SMTP\Admin\Admin_Page
 *
 * @package smtp
 */

namespace WordPress\SMTP\Admin;

use WordPress\SMTP\Connections;
use WordPress\SMTP\Delivery_Tracker;
use WordPress\SMTP\Error_Hints;
use WordPress\SMTP\Log_Repository;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Settings;

/**
 * Registers and renders the Tools > SMTP screen.
 *
 * @since 1.2.0
 */
class Admin_Page {

	/**
	 * Menu and settings page slug.
	 *
	 * @since 1.2.0
	 */
	const PAGE_SLUG = 'smtp';

	/**
	 * Settings.
	 *
	 * @since 1.2.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Provider registry.
	 *
	 * @since 1.2.0
	 * @var Provider_Registry
	 */
	private $registry;

	/**
	 * Log repository.
	 *
	 * @since 1.2.0
	 * @var Log_Repository
	 */
	private $repository;

	/**
	 * Path to the main plugin file.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private $main_file;

	/**
	 * Delivery tracker.
	 *
	 * @since 1.2.0
	 * @var Delivery_Tracker
	 */
	private $tracker;

	/**
	 * Alerts tab, or null when alerts are not available.
	 *
	 * @since 1.2.0
	 * @var Alerts_Tab|null
	 */
	private $alerts_tab;

	/**
	 * Connections, or null when they are not available.
	 *
	 * @since 1.3.0
	 * @var Connections_Tab|null
	 */
	private $connections_tab;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param Settings             $settings        Settings of the default connection.
	 * @param Provider_Registry    $registry        Provider registry.
	 * @param Log_Repository       $repository      Log repository.
	 * @param string               $main_file       Absolute path to the main plugin file.
	 * @param Delivery_Tracker     $tracker         Delivery tracker.
	 * @param Alerts_Tab|null      $alerts_tab      Optional. Alerts tab. Default null, which hides the tab.
	 * @param Connections_Tab|null $connections_tab Optional. Connections of the Settings tab. Default null, which hides them.
	 */
	public function __construct( Settings $settings, Provider_Registry $registry, Log_Repository $repository, string $main_file, Delivery_Tracker $tracker, ?Alerts_Tab $alerts_tab = null, ?Connections_Tab $connections_tab = null ) {
		$this->settings        = $settings;
		$this->registry        = $registry;
		$this->repository      = $repository;
		$this->main_file       = $main_file;
		$this->tracker         = $tracker;
		$this->alerts_tab      = $alerts_tab;
		$this->connections_tab = $connections_tab;
	}

	/**
	 * Registers the hooks.
	 *
	 * @since 1.2.0
	 */
	public function add_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_script' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( $this->main_file ), array( $this, 'add_action_link' ) );
	}

	/**
	 * Adds the Tools > SMTP menu item.
	 *
	 * @since 1.2.0
	 */
	public function add_menu() {
		add_management_page(
			__( 'SMTP', 'smtp' ),
			__( 'SMTP', 'smtp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Returns the URL of the screen.
	 *
	 * @since 1.2.0
	 *
	 * @param string $tab Optional tab slug.
	 * @return string Admin URL.
	 */
	public static function get_url( string $tab = '' ): string {
		$url = admin_url( 'tools.php?page=' . self::PAGE_SLUG );

		return '' === $tab ? $url : add_query_arg( 'tab', $tab, $url );
	}

	/**
	 * Adds a Settings link to the plugin row.
	 *
	 * @since 1.2.0
	 *
	 * @param string[] $links Plugin action links.
	 * @return string[] Plugin action links.
	 */
	public function add_action_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::get_url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'smtp' ) . '</a>' );

		return $links;
	}

	/**
	 * Registers the setting and its fields.
	 *
	 * @since 1.2.0
	 */
	public function register_settings() {
		register_setting(
			self::PAGE_SLUG,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => array(),
			)
		);

		$sections = Field_Renderer::get_sections();

		foreach ( $sections as $section => $title ) {
			add_settings_section(
				'smtp_' . $section,
				$title,
				'limits' === $section ? array( $this, 'render_limits_section' ) : '__return_empty_string',
				self::PAGE_SLUG
			);
		}

		add_settings_field(
			'provider',
			__( 'Provider', 'smtp' ),
			array( $this, 'render_provider_field' ),
			self::PAGE_SLUG,
			'smtp_connection',
			array( 'label_for' => 'smtp-provider' )
		);

		foreach ( $this->settings->get_fields() as $key => $field ) {
			if ( ! Field_Renderer::has_row( $field ) ) {
				continue;
			}

			$args = array(
				'key'   => $key,
				'field' => $field,
			);

			if ( Field_Renderer::uses_row_label( $field ) ) {
				$args['label_for'] = 'smtp-' . $key;
			}

			// A field of an unknown section would not be rendered at all.
			$section = isset( $sections[ $field['section'] ] ) ? $field['section'] : 'connection';

			add_settings_field( $key, $field['label'], array( $this, 'render_field' ), self::PAGE_SLUG, 'smtp_' . $section, $args );
		}
	}

	/**
	 * Adds the scripts that show the sending limits when they are customized, and disable the
	 * fields that can not take effect.
	 *
	 * @since 1.3.0
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_script( $hook_suffix ) {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script( 'smtp-limits', plugins_url( 'assets/js/limits.js', $this->main_file ), array(), WP_SMTP_VERSION, true );
		wp_enqueue_script( 'smtp-fields', plugins_url( 'assets/js/fields.js', $this->main_file ), array(), WP_SMTP_VERSION, true );
	}

	/**
	 * Renders the checkbox that reveals the fields of the sending limits section.
	 *
	 * @since 1.2.0
	 */
	public function render_limits_section() {
		Field_Renderer::render_limits_toggle( $this->settings );
	}

	/**
	 * Renders the screen.
	 *
	 * @since 1.2.0
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs = $this->get_tabs();

		// Read-only navigation parameter, no state is changed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( ! isset( $tabs[ $current ] ) ) {
			$current = (string) key( $tabs );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a
						href="<?php echo esc_url( self::get_url( $slug ) ); ?>"
						class="nav-tab<?php echo $slug === $current ? ' nav-tab-active' : ''; ?>"
					>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			settings_errors();

			if ( 'log' === $current ) {
				$this->render_log_tab();
			} elseif ( 'test' === $current ) {
				$this->render_test_tab();
			} elseif ( 'alerts' === $current && $this->alerts_tab ) {
				$this->alerts_tab->render();
			} else {
				$this->render_settings_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Returns the tabs, the first one being the default.
	 *
	 * Once the plugin is configured the email log is what people come back for, so it goes first.
	 *
	 * @since 1.2.0
	 *
	 * @return string[] Tab labels keyed by tab slug.
	 */
	private function get_tabs(): array {
		$tabs = array(
			'settings' => __( 'Settings', 'smtp' ),
			'log'      => __( 'Email log', 'smtp' ),
			'test'     => __( 'Test', 'smtp' ),
		);

		if ( $this->alerts_tab ) {
			$tabs['alerts'] = __( 'Alerts', 'smtp' );
		}

		if ( $this->settings->is_configured() ) {
			$tabs = array( 'log' => $tabs['log'] ) + $tabs;
		}

		return $tabs;
	}

	/**
	 * Renders the provider select.
	 *
	 * @since 1.2.0
	 */
	public function render_provider_field() {
		$current = $this->settings->get_provider_slug();
		?>
		<select id="smtp-provider" name="<?php echo esc_attr( Settings::OPTION ); ?>[provider]">
			<?php foreach ( $this->registry->get_all() as $slug => $provider ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>>
					<?php echo esc_html( $provider['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description" style="max-width: 600px;">
			<?php esc_html_e( 'After changing the provider, save the settings to see its fields.', 'smtp' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders a settings field.
	 *
	 * @since 1.2.0
	 *
	 * @param array $args Field arguments: `key` and `field`.
	 */
	public function render_field( array $args ) {
		Field_Renderer::render( $args['key'], $args['field'], $this->settings, Settings::OPTION . '[' . $args['key'] . ']' );
	}

	/**
	 * Renders the Settings tab.
	 *
	 * @since 1.2.0
	 */
	private function render_settings_tab() {
		$requested = $this->connections_tab ? $this->connections_tab->get_requested() : Connections::DEFAULT_ID;

		if ( $this->connections_tab ) {
			$this->connections_tab->render_navigation( $requested );
		}

		// The default connection is the one of the Settings API form below. The others have their own form.
		if ( $this->connections_tab && Connections::DEFAULT_ID !== $requested ) {
			$this->connections_tab->render_form( Connections_Tab::NEW_CONNECTION === $requested ? Connections_Tab::NEW_SETTINGS_ID : $requested );

			return;
		}

		$this->render_other_mailer_notice();
		?>
		<form action="options.php" method="post">
			<?php
			settings_fields( self::PAGE_SLUG );
			?>
			<?php // Saving redirects back here, so the Settings tab must stay open even when the log is the default tab. ?>
			<input type="hidden" name="_wp_http_referer" value="<?php echo esc_url( self::get_url( 'settings' ) ); ?>" />
			<?php
			do_settings_sections( self::PAGE_SLUG );
			submit_button();
			?>
		</form>
		<?php
	}

	/**
	 * Renders the Email log tab.
	 *
	 * @since 1.2.0
	 */
	private function render_log_tab() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		$table = new Log_List_Table( $this->repository, $this->registry, $this->connections_tab ? $this->connections_tab->get_connections() : null );
		$table->prepare_items();
		?>
		<p>
			<?php esc_html_e( 'Only metadata is logged: the message body and attachments are never stored.', 'smtp' ); ?>
		</p>
		<?php
		$table->views();
		$table->display();
	}

	/**
	 * Warns when another plugin controls outgoing email.
	 *
	 * @since 1.2.0
	 */
	private function render_other_mailer_notice() {
		$owner = $this->tracker->get_wp_mail_owner();

		if ( '' !== $owner ) {
			$message = sprintf(
				/* translators: %s: plugin name */
				__( '%s replaces the WordPress mailer, so outgoing email does not use the settings of this plugin. Keep only one of the two plugins active.', 'smtp' ),
				$owner
			);
		} elseif ( Delivery_Tracker::PROVIDER_OTHER === $this->repository->get_latest_provider() ) {
			$message = __( 'The last email was delivered by another plugin, not with the settings of this plugin. Check whether another mailer plugin is active.', 'smtp' );
		} else {
			return;
		}
		?>
		<div class="notice notice-warning inline">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders the Test tab.
	 *
	 * @since 1.2.0
	 */
	private function render_test_tab() {
		$user   = wp_get_current_user();
		$result = Test_Email::pull_result( $user->ID );

		$this->render_other_mailer_notice();

		if ( ! $this->settings->is_configured() && '' === $this->tracker->get_wp_mail_owner() ) {
			?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'SMTP is not configured yet, so the test email will be sent with the default WordPress mailer.', 'smtp' ); ?></p>
			</div>
			<?php
		}

		if ( $result ) {
			?>
			<div class="notice <?php echo $result['success'] ? 'notice-success' : 'notice-error'; ?> inline">
				<p>
					<?php
					if ( $result['success'] ) {
						/* translators: %s: email address */
						echo esc_html( sprintf( __( 'Test email sent to %s.', 'smtp' ), $result['to'] ) );
					} else {
						/* translators: %s: error message */
						echo esc_html( sprintf( __( 'The test email could not be sent: %s', 'smtp' ), $result['error'] ) );
					}
					?>
				</p>
				<?php $hint = $result['success'] ? '' : Error_Hints::get( $result['debug'] ); ?>
				<?php if ( '' !== $hint ) : ?>
					<p><?php echo esc_html( $hint ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( isset( $result['provider'] ) && Delivery_Tracker::PROVIDER_OTHER === $result['provider'] ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'The test email was delivered by another plugin, so it does not test the settings of this plugin.', 'smtp' ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $result['from'] ) ) : ?>
				<h2><?php esc_html_e( 'Sender', 'smtp' ); ?></h2>
				<table class="widefat striped" role="presentation" style="max-width: 40em;">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'From', 'smtp' ); ?></th>
							<td><?php echo esc_html( $result['from'] ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Reply-To', 'smtp' ); ?></th>
							<td><?php echo '' !== $result['reply_to'] ? esc_html( $result['reply_to'] ) : esc_html__( 'None', 'smtp' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Return-Path', 'smtp' ); ?></th>
							<td><?php echo '' !== $result['return_path'] ? esc_html( $result['return_path'] ) : esc_html__( 'Same as From', 'smtp' ); ?></td>
						</tr>
					</tbody>
				</table>
			<?php endif; ?>
			<?php if ( '' !== $result['debug'] ) : ?>
				<h2><?php esc_html_e( 'Connection log', 'smtp' ); ?></h2>
				<pre style="max-width: 100%; overflow: auto; padding: 1em; background: #fff; border: 1px solid #c3c4c7;"><?php echo esc_html( $result['debug'] ); ?></pre>
			<?php endif; ?>
			<?php
		}
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( Test_Email::ACTION ); ?>" />
			<?php wp_nonce_field( Test_Email::ACTION ); ?>
			<table class="form-table" role="presentation">
				<?php if ( $this->connections_tab && $this->connections_tab->get_connections()->has_extra() ) : ?>
					<?php $connections = $this->connections_tab->get_connections(); ?>
					<tr>
						<th scope="row"><label for="smtp-test-connection"><?php esc_html_e( 'Connection', 'smtp' ); ?></label></th>
						<td>
							<select id="smtp-test-connection" name="connection">
								<?php foreach ( $connections->get_ids() as $connection_id ) : ?>
									<option value="<?php echo esc_attr( $connection_id ); ?>" <?php selected( $result['connection_id'] ?? Connections::DEFAULT_ID, $connection_id ); ?>>
										<?php echo esc_html( $connections->get_label( $connection_id ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><label for="smtp-test-to"><?php esc_html_e( 'Send to', 'smtp' ); ?></label></th>
					<td>
						<input
							type="email"
							class="regular-text"
							id="smtp-test-to"
							name="to"
							value="<?php echo esc_attr( $result ? $result['to'] : $user->user_email ); ?>"
							required
						/>
						<p class="description"><?php esc_html_e( 'The address that will receive the test email.', 'smtp' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Send test email', 'smtp' ), 'secondary' ); ?>
		</form>
		<?php
	}
}
