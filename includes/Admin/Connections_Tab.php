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
 * Renders and handles the extra connections inside the Settings tab of the Tools > SMTP screen.
 *
 * The Settings tab is where every connection is managed: the default one has its form there, and this
 * class adds the links to switch between connections plus the forms to add, edit and delete the others.
 * Each connection has its own provider, credentials, From address and sending limits. The ID of a
 * connection is generated from its name when it is added, and never changes.
 *
 * @since 1.3.0
 */
class Connections_Tab {

	/**
	 * Action and nonce name of saving a connection.
	 *
	 * @since 1.3.0
	 */
	const SAVE_ACTION = 'smtp_save_connection';

	/**
	 * Action and nonce name of deleting a connection.
	 *
	 * @since 1.3.0
	 */
	const DELETE_ACTION = 'smtp_delete_connection';

	/**
	 * Value of the `connection` query argument that opens the form to add a connection.
	 *
	 * @since 1.3.0
	 */
	const NEW_CONNECTION = 'new';

	/**
	 * ID used for the settings of the form to add a connection. It can not be a real ID, since
	 * {@see Connections::sanitize_id()} removes the dot.
	 *
	 * @since 1.3.0
	 */
	const NEW_SETTINGS_ID = '.new';

	/**
	 * Connections.
	 *
	 * @since 1.3.0
	 * @var Connections
	 */
	private $connections;

	/**
	 * Provider registry.
	 *
	 * @since 1.3.0
	 * @var Provider_Registry
	 */
	private $registry;

	/**
	 * Credentials storage.
	 *
	 * @since 1.3.0
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
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
	 * @since 1.3.0
	 */
	public function add_hooks() {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( $this, 'handle_delete' ) );
	}

	/**
	 * Returns the connections.
	 *
	 * @since 1.3.0
	 *
	 * @return Connections Connections.
	 */
	public function get_connections(): Connections {
		return $this->connections;
	}

	/**
	 * Returns the URL of the Settings tab, or of the form of a connection.
	 *
	 * @since 1.3.0
	 *
	 * @param string               $connection Optional. Connection ID, or `new`.
	 * @param array<string,string> $args       Optional. Extra query arguments.
	 * @return string Admin URL.
	 */
	public static function get_url( string $connection = '', array $args = array() ): string {
		$url = Admin_Page::get_url( 'settings' );

		if ( '' !== $connection ) {
			$args['connection'] = $connection;
		}

		return add_query_arg( $args, $url );
	}

	/**
	 * Handles saving a connection.
	 *
	 * @since 1.3.0
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

		if ( $is_new ) {
			// The ID comes from the name and is unique, so adding a connection never overwrites another one.
			$clean_id = $this->connections->generate_id( $label );

			if ( '' === $clean_id ) {
				$this->redirect( self::get_url( self::NEW_CONNECTION, array( 'notice' => 'missing_name' ) ) );
			}
		} else {
			$clean_id = Connections::sanitize_id( $id );

			if ( '' === $clean_id || ! $this->connections->exists( $clean_id ) ) {
				$this->redirect( self::get_url( '', array( 'notice' => 'not_found' ) ) );
			}
		}

		$saved = $this->connections->save( $clean_id, $label, $submitted );

		$this->redirect( self::get_url( is_wp_error( $saved ) ? '' : $saved, array( 'notice' => is_wp_error( $saved ) ? 'not_found' : 'saved' ) ) );
	}

	/**
	 * Handles deleting a connection.
	 *
	 * @since 1.3.0
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
	 * Returns the ID of the connection the Settings tab must show.
	 *
	 * @since 1.3.0
	 *
	 * @return string `new`, the ID of an extra connection, or the default ID.
	 */
	public function get_requested(): string {
		// Read-only navigation parameter, no state is changed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['connection'] ) ? sanitize_text_field( wp_unslash( $_GET['connection'] ) ) : '';

		if ( self::NEW_CONNECTION === $requested ) {
			return $requested;
		}

		return '' !== $requested && $this->connections->exists( $requested ) ? $requested : Connections::DEFAULT_ID;
	}

	/**
	 * Renders the notice of the last action and the links to switch between connections.
	 *
	 * @since 1.3.0
	 *
	 * @param string $current Connection being shown: `new`, an ID or the default ID.
	 */
	public function render_navigation( string $current ) {
		// Read-only navigation parameter, no state is changed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->render_notice( isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '' );

		$links = array();
		foreach ( $this->connections->get_ids() as $id ) {
			$links[] = array(
				'url'     => self::get_url( Connections::DEFAULT_ID === $id ? '' : $id ),
				'label'   => $this->connections->get_label( $id ),
				'current' => $id === $current,
			);
		}
		$links[] = array(
			'url'     => self::get_url( self::NEW_CONNECTION ),
			'label'   => __( 'Add connection', 'smtp' ),
			'current' => self::NEW_CONNECTION === $current,
		);

		?>
		<ul class="subsubsub">
			<?php foreach ( $links as $index => $link ) : ?>
				<li>
					<a href="<?php echo esc_url( $link['url'] ); ?>"<?php echo $link['current'] ? ' class="current" aria-current="page"' : ''; ?>><?php echo esc_html( $link['label'] ); ?></a><?php echo $index < count( $links ) - 1 ? ' |' : ''; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<br class="clear" />
		<?php
	}

	/**
	 * Renders the notice of the last action.
	 *
	 * @since 1.3.0
	 *
	 * @param string $notice Notice code.
	 */
	private function render_notice( string $notice ) {
		$messages = array(
			'saved'        => array( 'success', __( 'Connection saved.', 'smtp' ) ),
			'deleted'      => array( 'success', __( 'Connection deleted.', 'smtp' ) ),
			'missing_name' => array( 'error', __( 'Enter a name with letters or numbers. The ID of the connection is generated from it.', 'smtp' ) ),
			'not_found'    => array( 'error', __( 'That connection does not exist.', 'smtp' ) ),
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
	 * Renders the form to add or edit a connection.
	 *
	 * @since 1.3.0
	 *
	 * @param string $id Connection ID, or the ID of the settings of a new connection.
	 */
	public function render_form( string $id ) {
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
			<?php else : ?>
				<input type="hidden" name="connection_id" value="<?php echo esc_attr( $id ); ?>" />
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="smtp-connection-label"><?php esc_html_e( 'Name', 'smtp' ); ?></label></th>
					<td>
						<input
							type="text"
							class="regular-text"
							id="smtp-connection-label"
							name="connection_label"
							value="<?php echo esc_attr( $is_new ? '' : $this->connections->get_label( $id ) ); ?>"
							required
						/>
						<p class="description">
							<?php
							echo $is_new
								? esc_html__( 'The ID used in code to pick this connection is generated from the name, for example "sponsors".', 'smtp' )
								: wp_kses(
									sprintf( /* translators: %s: connection ID */ __( 'ID in code: %s. It does not change if you rename the connection.', 'smtp' ), '<code>' . esc_html( $id ) . '</code>' ),
									array( 'code' => array() )
								);
							?>
						</p>
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
				$limits_toggled = false;
				foreach ( $settings->get_fields() as $key => $field ) {
					if ( ! $limits_toggled && 0 === strpos( $key, 'rate_limit_' ) ) {
						$limits_toggled = true;
						?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Sending limits', 'smtp' ); ?></th>
							<td><?php Field_Renderer::render_limits_toggle( $settings ); ?></td>
						</tr>
						<?php
					}
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
		</form>
		<?php if ( ! $is_new ) : ?>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" onsubmit="return confirm( '<?php echo esc_js( __( 'Delete this connection and its saved credentials?', 'smtp' ) ); ?>' );">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::DELETE_ACTION ); ?>" />
				<input type="hidden" name="connection_id" value="<?php echo esc_attr( $id ); ?>" />
				<?php wp_nonce_field( self::DELETE_ACTION ); ?>
				<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete this connection', 'smtp' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Redirects and stops.
	 *
	 * @since 1.3.0
	 *
	 * @param string $url Admin URL.
	 */
	private function redirect( string $url ) {
		wp_safe_redirect( $url );
		exit;
	}
}
