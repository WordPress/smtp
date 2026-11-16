<?php
/**
 * Class WordPress\SMTP\Admin\Connections_Tab
 *
 * @package smtp
 */

namespace WordPress\SMTP\Admin;

use WordPress\SMTP\Connections;
use WordPress\SMTP\Credentials;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Settings;

/**
 * Renders and handles the Connections tab of the Tools > SMTP screen.
 *
 * The default connection is the one configured in the Settings tab. This tab lists it and manages the
 * others: each one has its own provider, credentials, From address and sending limits.
 *
 * @since 1.2.0
 */
class Connections_Tab {

	/**
	 * Action and nonce name of saving a connection.
	 *
	 * @since 1.2.0
	 */
	const SAVE_ACTION = 'smtp_save_connection';

	/**
	 * Action and nonce name of deleting a connection.
	 *
	 * @since 1.2.0
	 */
	const DELETE_ACTION = 'smtp_delete_connection';

	/**
	 * Value of the `connection` query argument that opens the form to add a connection.
	 *
	 * @since 1.2.0
	 */
	const NEW_CONNECTION = 'new';

	/**
	 * ID used for the settings of the form to add a connection. It can not be a real ID, since
	 * {@see Connections::sanitize_id()} removes the dot.
	 *
	 * @since 1.2.0
	 */
	const NEW_SETTINGS_ID = '.new';

	/**
	 * Connections.
	 *
	 * @since 1.2.0
	 * @var Connections
	 */
	private $connections;

	/**
	 * Provider registry.
	 *
	 * @since 1.2.0
	 * @var Provider_Registry
	 */
	private $registry;

	/**
	 * Credentials storage.
	 *
	 * @since 1.2.0
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param Connections       $connections Connections.
	 * @param Provider_Registry $registry    Provider registry.
	 * @param Credentials       $credentials Credentials storage.
	 */
	public function __construct( Connections $connections, Provider_Registry $registry, Credentials $credentials ) {
		$this->connections = $connections;
		$this->registry    = $registry;
		$this->credentials = $credentials;
	}

	/**
	 * Registers the hooks.
	 *
	 * @since 1.2.0
	 */
	public function add_hooks() {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( $this, 'handle_delete' ) );
	}

	/**
	 * Returns the connections.
	 *
	 * @since 1.2.0
	 *
	 * @return Connections Connections.
	 */
	public function get_connections(): Connections {
		return $this->connections;
	}

	/**
	 * Returns the URL of the tab, or of the form of a connection.
	 *
	 * @since 1.2.0
	 *
	 * @param string               $connection Optional. Connection ID, or `new`.
	 * @param array<string,string> $args       Optional. Extra query arguments.
	 * @return string Admin URL.
	 */
	public static function get_url( string $connection = '', array $args = array() ): string {
		$url = Admin_Page::get_url( 'connections' );

		if ( '' !== $connection ) {
			$args['connection'] = $connection;
		}

		return add_query_arg( $args, $url );
	}

	/**
	 * Handles saving a connection.
	 *
	 * @since 1.2.0
	 */
	public function handle_save() {
		check_admin_referer( self::SAVE_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'smtp' ), '', array( 'response' => 403 ) );
		}

		// The nonce was verified above. Values are sanitized by Connections::save() and Settings::sanitize().
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$id        = isset( $_POST['connection_id'] ) ? sanitize_text_field( wp_unslash( $_POST['connection_id'] ) ) : '';
		$label     = isset( $_POST['connection_label'] ) ? sanitize_text_field( wp_unslash( $_POST['connection_label'] ) ) : '';
		$is_new    = ! empty( $_POST['connection_is_new'] );
		$submitted = isset( $_POST[ Settings::OPTION ] ) && is_array( $_POST[ Settings::OPTION ] ) ? wp_unslash( $_POST[ Settings::OPTION ] ) : array();
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$clean_id = Connections::sanitize_id( $id );

		if ( '' === $clean_id ) {
			$this->redirect( self::get_url( $is_new ? self::NEW_CONNECTION : $id, array( 'notice' => 'invalid_id' ) ) );
		}

		// Adding a connection must not overwrite one that has the same ID.
		if ( $is_new && $this->connections->exists( $clean_id ) ) {
			$this->redirect( self::get_url( self::NEW_CONNECTION, array( 'notice' => 'exists' ) ) );
		}

		$saved = $this->connections->save( $clean_id, $label, $submitted );

		$this->redirect( self::get_url( is_wp_error( $saved ) ? '' : $saved, array( 'notice' => is_wp_error( $saved ) ? 'invalid_id' : 'saved' ) ) );
	}

	/**
	 * Handles deleting a connection.
	 *
	 * @since 1.2.0
	 */
	public function handle_delete() {
		check_admin_referer( self::DELETE_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'smtp' ), '', array( 'response' => 403 ) );
		}

		// The nonce was verified above.
		$id = isset( $_POST['connection_id'] ) ? sanitize_text_field( wp_unslash( $_POST['connection_id'] ) ) : '';

		$this->connections->delete( $id );

		$this->redirect( self::get_url( '', array( 'notice' => 'deleted' ) ) );
	}

	/**
	 * Renders the tab: the list of connections, or the form of one of them.
	 *
	 * @since 1.2.0
	 */
	public function render() {
		// Read-only navigation parameters, no state is changed.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['connection'] ) ? sanitize_text_field( wp_unslash( $_GET['connection'] ) ) : '';
		$notice    = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->render_notice( $notice );

		if ( self::NEW_CONNECTION === $requested ) {
			$this->render_form( self::NEW_SETTINGS_ID );
		} elseif ( '' !== $requested && Connections::DEFAULT_ID !== $requested && $this->connections->exists( $requested ) ) {
			$this->render_form( $requested );
		} else {
			$this->render_list();
		}
	}

	/**
	 * Renders the notice of the last action.
	 *
	 * @since 1.2.0
	 *
	 * @param string $notice Notice code.
	 */
	private function render_notice( string $notice ) {
		$messages = array(
			'saved'      => array( 'success', __( 'Connection saved.', 'smtp' ) ),
			'deleted'    => array( 'success', __( 'Connection deleted.', 'smtp' ) ),
			'exists'     => array( 'error', __( 'A connection with that ID already exists.', 'smtp' ) ),
			'invalid_id' => array( 'error', __( 'Enter an ID with letters, numbers, dashes or underscores. "default" is reserved.', 'smtp' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		?>
		<div class="notice notice-<?php echo esc_attr( $messages[ $notice ][0] ); ?> inline">
			<p><?php echo esc_html( $messages[ $notice ][1] ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders the list of connections.
	 *
	 * @since 1.2.0
	 */
	private function render_list() {
		?>
		<p>
			<?php esc_html_e( 'A connection is a provider with its own credentials, From address and sending limits. Emails use the default connection unless a plugin or your code selects another one.', 'smtp' ); ?>
		</p>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'smtp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'ID', 'smtp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Provider', 'smtp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'From', 'smtp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'smtp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'smtp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $this->connections->get_ids() as $id ) : ?>
					<?php $this->render_row( $id ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( self::get_url( self::NEW_CONNECTION ) ); ?>">
				<?php esc_html_e( 'Add connection', 'smtp' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Renders a row of the list.
	 *
	 * @since 1.2.0
	 *
	 * @param string $id Connection ID.
	 */
	private function render_row( string $id ) {
		$settings = $this->connections->get_settings( $id );
		if ( ! $settings ) {
			return;
		}

		$provider   = $this->registry->get( $settings->get_provider_slug() );
		$is_default = Connections::DEFAULT_ID === $id;
		$from       = (string) $settings->get( 'from_email' );
		?>
		<tr>
			<td><strong><?php echo esc_html( $this->connections->get_label( $id ) ); ?></strong></td>
			<td><code><?php echo esc_html( $id ); ?></code></td>
			<td><?php echo esc_html( $provider ? (string) $provider['label'] : $settings->get_provider_slug() ); ?></td>
			<td><?php echo '' !== $from ? esc_html( $from ) : '&mdash;'; ?></td>
			<td><?php echo $settings->is_configured() ? esc_html__( 'Ready', 'smtp' ) : esc_html__( 'Incomplete', 'smtp' ); ?></td>
			<td>
				<?php if ( $is_default ) : ?>
					<a href="<?php echo esc_url( Admin_Page::get_url( 'settings' ) ); ?>"><?php esc_html_e( 'Edit in Settings', 'smtp' ); ?></a>
				<?php else : ?>
					<a href="<?php echo esc_url( self::get_url( $id ) ); ?>"><?php esc_html_e( 'Edit', 'smtp' ); ?></a>
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline" onsubmit="return confirm( '<?php echo esc_js( __( 'Delete this connection and its saved credentials?', 'smtp' ) ); ?>' );">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::DELETE_ACTION ); ?>" />
						<input type="hidden" name="connection_id" value="<?php echo esc_attr( $id ); ?>" />
						<?php wp_nonce_field( self::DELETE_ACTION ); ?>
						| <button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete', 'smtp' ); ?></button>
					</form>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders the form to add or edit a connection.
	 *
	 * @since 1.2.0
	 *
	 * @param string $id Connection ID, or the ID of the settings of a new connection.
	 */
	private function render_form( string $id ) {
		$is_new   = self::NEW_SETTINGS_ID === $id;
		$settings = new Settings( $this->registry, $this->credentials, $id );
		$current  = $settings->get_provider_slug();
		?>
		<h2>
			<?php
			echo $is_new
				? esc_html__( 'Add connection', 'smtp' )
				: esc_html( sprintf( /* translators: %s: connection name */ __( 'Edit %s', 'smtp' ), $this->connections->get_label( $id ) ) );
			?>
		</h2>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
			<?php wp_nonce_field( self::SAVE_ACTION ); ?>
			<?php if ( $is_new ) : ?>
				<input type="hidden" name="connection_is_new" value="1" />
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="smtp-connection-id"><?php esc_html_e( 'ID', 'smtp' ); ?></label></th>
					<td>
						<input
							type="text"
							class="regular-text"
							id="smtp-connection-id"
							name="connection_id"
							value="<?php echo esc_attr( $is_new ? '' : $id ); ?>"
							pattern="[a-z0-9_\-]+"
							maxlength="<?php echo esc_attr( (string) Connections::MAX_ID_LENGTH ); ?>"
							<?php echo $is_new ? 'required' : 'readonly'; ?>
						/>
						<p class="description">
							<?php esc_html_e( 'Used in code to pick this connection, for example "sponsors". Lowercase letters, numbers, dashes and underscores. It can not be changed later.', 'smtp' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="smtp-connection-label"><?php esc_html_e( 'Name', 'smtp' ); ?></label></th>
					<td>
						<input
							type="text"
							class="regular-text"
							id="smtp-connection-label"
							name="connection_label"
							value="<?php echo esc_attr( $is_new ? '' : $this->connections->get_label( $id ) ); ?>"
						/>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="smtp-provider"><?php esc_html_e( 'Provider', 'smtp' ); ?></label></th>
					<td>
						<select id="smtp-provider" name="<?php echo esc_attr( Settings::OPTION ); ?>[provider]">
							<?php foreach ( $this->registry->get_all() as $slug => $provider ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>>
									<?php echo esc_html( $provider['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'After changing the provider, save the connection to see its fields.', 'smtp' ); ?>
						</p>
					</td>
				</tr>
				<?php
				foreach ( $settings->get_fields() as $key => $field ) {
					?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( 'smtp-' . $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
						<td><?php Field_Renderer::render( $key, $field, $settings, Settings::OPTION . '[' . $key . ']' ); ?></td>
					</tr>
					<?php
				}
				?>
			</table>
			<?php submit_button( $is_new ? __( 'Add connection', 'smtp' ) : __( 'Save connection', 'smtp' ) ); ?>
			<a href="<?php echo esc_url( self::get_url() ); ?>"><?php esc_html_e( 'Back to the connections', 'smtp' ); ?></a>
		</form>
		<?php
	}

	/**
	 * Redirects and stops.
	 *
	 * @since 1.2.0
	 *
	 * @param string $url Admin URL.
	 */
	private function redirect( string $url ) {
		wp_safe_redirect( $url );
		exit;
	}
}
