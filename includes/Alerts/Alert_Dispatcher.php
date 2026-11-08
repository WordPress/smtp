<?php
/**
 * Class WordPress\SMTP\Alerts\Alert_Dispatcher
 *
 * @package smtp
 */

namespace WordPress\SMTP\Alerts;

/**
 * Sends an alert to every active channel when an email fails, with throttling.
 *
 * @since 1.2.0
 */
class Alert_Dispatcher {

	/**
	 * Name of the option that stores the delivery state of each channel.
	 *
	 * @since 1.2.0
	 */
	const STATE_OPTION = 'smtp_alert_state';

	/**
	 * Default minimum number of seconds between two alerts on the same channel.
	 *
	 * @since 1.2.0
	 */
	const DEFAULT_THROTTLE = 15 * MINUTE_IN_SECONDS;

	/**
	 * Alert settings.
	 *
	 * @since 1.2.0
	 * @var Alert_Settings
	 */
	private $settings;

	/**
	 * Whether alerts are being dispatched, to avoid loops if a channel triggers another failure.
	 *
	 * Shared by every instance, so no dispatcher reacts to a failure caused by another one.
	 *
	 * @since 1.2.0
	 * @var bool
	 */
	private static $dispatching = false;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param Alert_Settings $settings Alert settings.
	 */
	public function __construct( Alert_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers the hooks.
	 *
	 * @since 1.2.0
	 */
	public function add_hooks() {
		// After the logger, so the failed email is already in the log the alert links to.
		add_action( 'wp_mail_failed', array( $this, 'handle_failure' ), 20 );
	}

	/**
	 * Sends an alert for a failed email.
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_Error $error Error, as passed to `wp_mail_failed`.
	 */
	public function handle_failure( $error ) {
		if ( ! $error instanceof \WP_Error || self::$dispatching || ! $this->settings->is_enabled() ) {
			return;
		}

		$alert = Alert::from_mail_error( $error );

		/**
		 * Filters whether an alert is sent for a failed email.
		 *
		 * @since 1.2.0
		 *
		 * @param bool  $enabled Whether to send the alert. Default true.
		 * @param Alert $alert   Alert about to be sent.
		 */
		if ( ! apply_filters( 'smtp_alert_enabled', true, $alert ) ) {
			return;
		}

		self::$dispatching = true;

		try {
			foreach ( $this->settings->get_active_channels() as $channel ) {
				$this->dispatch( $channel, $alert );
			}
		} finally {
			self::$dispatching = false;
		}
	}

	/**
	 * Sends a test alert to a channel, ignoring the switches and the throttling.
	 *
	 * @since 1.2.0
	 *
	 * @param Alert_Channel $channel Channel.
	 * @return true|\WP_Error True on success, an error otherwise.
	 */
	public function send_test( Alert_Channel $channel ) {
		$values = $this->settings->get_channel_values( $channel );

		if ( ! $channel->is_configured( $values ) ) {
			return new \WP_Error(
				'smtp_alert_channel_not_configured',
				/* translators: %s: channel name */
				sprintf( __( '%s is not configured yet. Save its settings first.', 'smtp' ), $channel->get_label() )
			);
		}

		return $channel->send( new Alert( Alert::TYPE_TEST ), $values );
	}

	/**
	 * Returns the delivery state of a channel.
	 *
	 * @since 1.2.0
	 *
	 * @param string $id Channel ID.
	 * @return array State with `last_sent`, `suppressed`, `last_error` and `last_error_time`.
	 */
	public function get_state( string $id ): array {
		$states = get_option( self::STATE_OPTION, array() );
		$state  = is_array( $states ) && isset( $states[ $id ] ) && is_array( $states[ $id ] ) ? $states[ $id ] : array();

		return array(
			'last_sent'       => isset( $state['last_sent'] ) ? (int) $state['last_sent'] : 0,
			'suppressed'      => isset( $state['suppressed'] ) ? (int) $state['suppressed'] : 0,
			'last_error'      => isset( $state['last_error'] ) ? (string) $state['last_error'] : '',
			'last_error_time' => isset( $state['last_error_time'] ) ? (int) $state['last_error_time'] : 0,
		);
	}

	/**
	 * Sends an alert to a channel unless it is throttled.
	 *
	 * @since 1.2.0
	 *
	 * @param Alert_Channel $channel Channel.
	 * @param Alert         $alert   Alert.
	 */
	private function dispatch( Alert_Channel $channel, Alert $alert ) {
		$id    = $channel->get_id();
		$state = $this->get_state( $id );
		$now   = $alert->get_time();

		/**
		 * Filters the minimum number of seconds between two alerts on the same channel.
		 *
		 * Failures in between are counted and reported with the next alert.
		 *
		 * @since 1.2.0
		 *
		 * @param int    $seconds    Seconds. Default 900 (15 minutes). `0` sends an alert for every failure.
		 * @param string $channel_id Channel ID.
		 */
		$throttle = (int) apply_filters( 'smtp_alert_throttle', self::DEFAULT_THROTTLE, $id );

		if ( $throttle > 0 && $state['last_sent'] > 0 && $now - $state['last_sent'] < $throttle ) {
			++$state['suppressed'];
			$this->save_state( $id, $state );

			return;
		}

		$result = $channel->send( $alert->with_failures( $state['suppressed'] + 1 ), $this->settings->get_channel_values( $channel ) );

		$state['last_sent']  = $now;
		$state['suppressed'] = 0;

		if ( is_wp_error( $result ) ) {
			$state['last_error']      = sanitize_text_field( $result->get_error_message() );
			$state['last_error_time'] = $now;
		} else {
			$state['last_error']      = '';
			$state['last_error_time'] = 0;
		}

		$this->save_state( $id, $state );
	}

	/**
	 * Stores the delivery state of a channel.
	 *
	 * @since 1.2.0
	 *
	 * @param string $id    Channel ID.
	 * @param array  $state State.
	 */
	private function save_state( string $id, array $state ) {
		$states = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $states ) ) {
			$states = array();
		}

		$states[ $id ] = $state;

		update_option( self::STATE_OPTION, $states, false );
	}
}
