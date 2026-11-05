<?php
/**
 * Class WordPress\SMTP\Admin\Alerts_Tab
 *
 * @package smtp
 */

namespace WordPress\SMTP\Admin;

use WordPress\SMTP\Alerts\Alert_Channel;
use WordPress\SMTP\Alerts\Alert_Dispatcher;
use WordPress\SMTP\Alerts\Alert_Settings;

/**
 * Registers, renders and handles the Alerts tab of the Tools > SMTP screen.
 *
 * @since 1.2.0
 */
class Alerts_Tab {

	/**
	 * Settings group of the alert settings.
	 *
	 * @since 1.2.0
	 */
	const OPTION_GROUP = 'smtp_alerts';

	/**
	 * Action and nonce name of the test alert.
	 *
	 * @since 1.2.0
	 */
	const TEST_ACTION = 'smtp_send_test_alert';

	/**
	 * Alert settings.
	 *
	 * @since 1.2.0
	 * @var Alert_Settings
	 */
	private $settings;

	/**
	 * Alert dispatcher.
	 *
	 * @since 1.2.0
	 * @var Alert_Dispatcher
	 */
	private $dispatcher;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param Alert_Settings   $settings   Alert settings.
	 * @param Alert_Dispatcher $dispatcher Alert dispatcher.
	 */
	public function __construct( Alert_Settings $settings, Alert_Dispatcher $dispatcher ) {
		$this->settings   = $settings;
		$this->dispatcher = $dispatcher;
	}

	/**
	 * Registers the hooks.
	 *
	 * @since 1.2.0
	 */
	public function add_hooks() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_' . self::TEST_ACTION, array( $this, 'handle_test' ) );
	}

	/**
	 * Registers the alert settings.
	 *
	 * @since 1.2.0
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			Alert_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Returns the last test alert result of a user and forgets it.
	 *
	 * @since 1.2.0
	 *
	 * @param int $user_id User ID.
	 * @return array|null Result with `success`, `channel` and `error`, or null when there is none.
	 */
	public static function pull_result( int $user_id ): ?array {
		$key    = self::TEST_ACTION . '_' . $user_id;
		$result = get_transient( $key );

		if ( ! is_array( $result ) ) {
			return null;
		}

		delete_transient( $key );

		return $result;
	}

	/**
	 * Handles the test alert request.
	 *
	 * @since 1.2.0
	 */
	public function handle_test() {
		check_admin_referer( self::TEST_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'smtp' ), '', array( 'response' => 403 ) );
		}

		// The nonce was verified above.
		$id      = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '';
		$channel = $this->settings->get_registry()->get( $id );

		if ( ! $channel ) {
			$result = array(
				'success' => false,
				'channel' => $id,
				'error'   => __( 'Unknown alert channel.', 'smtp' ),
			);
		} else {
			$sent   = $this->dispatcher->send_test( $channel );
			$result = array(
				'success' => true === $sent,
				'channel' => $channel->get_label(),
				'error'   => is_wp_error( $sent ) ? $sent->get_error_message() : '',
			);
		}

		set_transient( self::TEST_ACTION . '_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( Admin_Page::get_url( 'alerts' ) );
		exit;
	}

	/**
	 * Renders the Alerts tab.
	 *
	 * @since 1.2.0
	 */
	public function render() {
		$result   = self::pull_result( get_current_user_id() );
		$channels = $this->settings->get_registry()->get_all();
		$name     = Alert_Settings::OPTION;

		if ( $result ) {
			?>
			<div class="notice <?php echo $result['success'] ? 'notice-success' : 'notice-error'; ?> inline">
				<p>
					<?php
					if ( $result['success'] ) {
						/* translators: %s: channel name */
						echo esc_html( sprintf( __( 'Test alert sent to %s.', 'smtp' ), $result['channel'] ) );
					} else {
						/* translators: %s: error message */
						echo esc_html( sprintf( __( 'The test alert could not be sent: %s', 'smtp' ), $result['error'] ) );
					}
					?>
				</p>
			</div>
			<?php
		}
		?>
		<p>
			<?php esc_html_e( 'Get notified outside of email when an email can not be sent, so a broken mailer does not go unnoticed.', 'smtp' ); ?>
		</p>
		<form action="options.php" method="post">
			<?php settings_fields( self::OPTION_GROUP ); ?>
			<input type="hidden" name="_wp_http_referer" value="<?php echo esc_url( Admin_Page::get_url( 'alerts' ) ); ?>" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Alerts', 'smtp' ); ?></th>
					<td>
						<label for="smtp-alerts-enabled">
							<input type="checkbox" id="smtp-alerts-enabled" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $this->settings->is_enabled() ); ?> />
							<?php esc_html_e( 'Send an alert when an email fails to send', 'smtp' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'To avoid flooding a channel, alerts are sent at most every 15 minutes by default. Failures in between are counted in the next alert.', 'smtp' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php
			foreach ( $channels as $channel ) {
				$this->render_channel( $channel );
			}

			submit_button();
			?>
		</form>
		<?php
		foreach ( $channels as $channel ) {
			if ( ! $channel->is_configured( $this->settings->get_channel_values( $channel ) ) ) {
				continue;
			}
			?>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display: inline-block; margin-right: 8px;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::TEST_ACTION ); ?>" />
				<input type="hidden" name="channel" value="<?php echo esc_attr( $channel->get_id() ); ?>" />
				<?php wp_nonce_field( self::TEST_ACTION ); ?>
				<?php
				submit_button(
					/* translators: %s: channel name */
					sprintf( __( 'Send test alert to %s', 'smtp' ), $channel->get_label() ),
					'secondary',
					'submit',
					false
				);
				?>
			</form>
			<?php
		}
	}

	/**
	 * Renders the settings of a channel.
	 *
	 * @since 1.2.0
	 *
	 * @param Alert_Channel $channel Channel.
	 */
	private function render_channel( Alert_Channel $channel ) {
		$id     = $channel->get_id();
		$name   = Alert_Settings::OPTION . '[channels][' . $id . ']';
		$values = $this->settings->get_channel_values( $channel );
		$state  = $this->dispatcher->get_state( $id );
		?>
		<h2><?php echo esc_html( $channel->get_label() ); ?></h2>
		<?php if ( '' !== $state['last_error'] ) : ?>
			<div class="notice notice-error inline">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: date and time, 2: error message */
							__( 'The last alert could not be delivered (%1$s): %2$s', 'smtp' ),
							wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $state['last_error_time'] ),
							$state['last_error']
						)
					);
					?>
				</p>
			</div>
		<?php endif; ?>
		<?php if ( $this->settings->is_channel_enabled( $id ) && ! $channel->is_configured( $values ) ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					/* translators: %s: channel name */
					echo esc_html( sprintf( __( '%s is enabled but not configured, so no alert will be sent to it.', 'smtp' ), $channel->get_label() ) );
					?>
				</p>
			</div>
		<?php endif; ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable', 'smtp' ); ?></th>
				<td>
					<label for="<?php echo esc_attr( 'smtp-alert-' . $id . '-enabled' ); ?>">
						<input type="checkbox" id="<?php echo esc_attr( 'smtp-alert-' . $id . '-enabled' ); ?>" name="<?php echo esc_attr( $name . '[enabled]' ); ?>" value="1" <?php checked( $this->settings->is_channel_enabled( $id ) ); ?> />
						<?php
						/* translators: %s: channel name */
						echo esc_html( sprintf( __( 'Send alerts to %s', 'smtp' ), $channel->get_label() ) );
						?>
					</label>
				</td>
			</tr>
			<?php
			foreach ( $channel->get_fields() as $key => $field ) {
				$field_id    = 'smtp-alert-' . $id . '-' . $key;
				$type        = in_array( $field['type'] ?? '', array( 'url', 'password' ), true ) ? $field['type'] : 'text';
				$value       = $values[ $key ] ?? '';
				$description = (string) ( $field['description'] ?? '' );
				?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( (string) ( $field['label'] ?? $key ) ); ?></label></th>
					<td>
						<?php if ( 'password' === $type ) : ?>
							<?php // The saved secret is never printed back into the page. ?>
							<input
								type="password"
								class="regular-text"
								id="<?php echo esc_attr( $field_id ); ?>"
								name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>"
								value=""
								autocomplete="new-password"
								placeholder="<?php echo '' !== $value ? esc_attr( str_repeat( '•', 8 ) ) : ''; ?>"
							/>
							<?php
							if ( '' !== $value ) {
								$description = trim( $description . ' ' . __( 'A value is saved. Leave empty to keep it.', 'smtp' ) );
							}
							?>
						<?php else : ?>
							<input
								type="<?php echo esc_attr( $type ); ?>"
								class="regular-text"
								id="<?php echo esc_attr( $field_id ); ?>"
								name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>"
								value="<?php echo esc_attr( $value ); ?>"
							/>
						<?php endif; ?>
						<?php if ( '' !== $description ) : ?>
							<p class="description"><?php echo esc_html( $description ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<?php
			}
			?>
		</table>
		<?php
	}
}
