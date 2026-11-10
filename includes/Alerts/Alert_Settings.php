<?php
/**
 * Class WordPress\SMTP\Alerts\Alert_Settings
 *
 * @package smtp
 */

namespace WordPress\SMTP\Alerts;

use WordPress\SMTP\Credentials;

/**
 * Reads and sanitizes the alert settings.
 *
 * Values of `password` fields are stored through `Credentials`, never in the settings option.
 *
 * @since 1.2.0
 */
class Alert_Settings {

	/**
	 * Name of the option that stores the alert settings.
	 *
	 * @since 1.2.0
	 */
	const OPTION = 'smtp_alert_settings';

	/**
	 * Channel registry.
	 *
	 * @since 1.2.0
	 * @var Alert_Channel_Registry
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
	 * @param Alert_Channel_Registry $registry    Channel registry.
	 * @param Credentials            $credentials Credentials storage.
	 */
	public function __construct( Alert_Channel_Registry $registry, Credentials $credentials ) {
		$this->registry    = $registry;
		$this->credentials = $credentials;
	}

	/**
	 * Returns the channel registry.
	 *
	 * @since 1.2.0
	 *
	 * @return Alert_Channel_Registry Channel registry.
	 */
	public function get_registry(): Alert_Channel_Registry {
		return $this->registry;
	}

	/**
	 * Returns the ID of the channel that receives alerts.
	 *
	 * @since 1.2.0
	 *
	 * @return string Channel ID, or an empty string when alerts are off or the channel no longer exists.
	 */
	public function get_selected_channel_id(): string {
		$options = $this->get_saved_options();
		$id      = isset( $options['channel'] ) && is_string( $options['channel'] ) ? $options['channel'] : '';

		return '' !== $id && null !== $this->registry->get( $id ) ? $id : '';
	}

	/**
	 * Whether alerts are turned on, meaning a channel is selected.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True when a channel is selected.
	 */
	public function is_enabled(): bool {
		return '' !== $this->get_selected_channel_id();
	}

	/**
	 * Returns the saved values of a channel, secrets included.
	 *
	 * @since 1.2.0
	 *
	 * @param Alert_Channel $channel Channel.
	 * @return string[] Values keyed by field key.
	 */
	public function get_channel_values( Alert_Channel $channel ): array {
		$options = $this->get_saved_options();
		$saved   = isset( $options['channels'][ $channel->get_id() ] ) && is_array( $options['channels'][ $channel->get_id() ] ) ? $options['channels'][ $channel->get_id() ] : array();
		$values  = array();

		foreach ( $channel->get_fields() as $key => $field ) {
			if ( 'password' === ( $field['type'] ?? '' ) ) {
				$values[ $key ] = $this->credentials->get( self::get_secret_key( $channel->get_id(), $key ) );
			} else {
				$values[ $key ] = isset( $saved[ $key ] ) && is_scalar( $saved[ $key ] ) ? (string) $saved[ $key ] : '';
			}
		}

		return $values;
	}

	/**
	 * Returns the channels that will receive alerts: the selected one, when it is configured.
	 *
	 * @since 1.2.0
	 *
	 * @return Alert_Channel[] Channels keyed by channel ID.
	 */
	public function get_active_channels(): array {
		$id = $this->get_selected_channel_id();

		if ( '' === $id ) {
			return array();
		}

		$channel = $this->registry->get( $id );

		return $channel && $channel->is_configured( $this->get_channel_values( $channel ) ) ? array( $id => $channel ) : array();
	}

	/**
	 * Sanitizes the submitted alert settings.
	 *
	 * The fields of every channel are kept, so switching channels does not lose them.
	 * An empty secret keeps the saved one.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $input Submitted settings.
	 * @return array Sanitized settings.
	 */
	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$channels = $this->registry->get_all();
		$selected = isset( $input['channel'] ) && is_string( $input['channel'] ) ? $input['channel'] : '';
		$clean    = array(
			'channel'  => isset( $channels[ $selected ] ) ? $selected : '',
			'channels' => array(),
		);

		foreach ( $channels as $id => $channel ) {
			$raw = isset( $input['channels'][ $id ] ) && is_array( $input['channels'][ $id ] ) ? $input['channels'][ $id ] : array();

			$submitted = array();
			foreach ( array_keys( $channel->get_fields() ) as $key ) {
				$submitted[ $key ] = isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? $raw[ $key ] : '';
			}

			$values = $channel->sanitize_settings( $submitted );

			$clean['channels'][ $id ] = array();

			foreach ( $channel->get_fields() as $key => $field ) {
				$value = isset( $values[ $key ] ) && is_string( $values[ $key ] ) ? $values[ $key ] : '';

				if ( 'password' === ( $field['type'] ?? '' ) ) {
					if ( '' !== $value ) {
						$this->credentials->set( self::get_secret_key( $id, $key ), $value );
					}
					continue;
				}

				$clean['channels'][ $id ][ $key ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * Returns the credentials key of a channel secret.
	 *
	 * @since 1.2.0
	 *
	 * @param string $channel_id Channel ID.
	 * @param string $field_key  Field key.
	 * @return string Credentials key.
	 */
	public static function get_secret_key( string $channel_id, string $field_key ): string {
		return 'alert_' . $channel_id . '_' . $field_key;
	}

	/**
	 * Returns the saved alert settings.
	 *
	 * @since 1.2.0
	 *
	 * @return array Saved settings.
	 */
	private function get_saved_options(): array {
		$options = get_option( self::OPTION, array() );

		return is_array( $options ) ? $options : array();
	}
}
