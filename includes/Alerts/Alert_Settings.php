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
	 * Whether alerts are turned on.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True when alerts are enabled.
	 */
	public function is_enabled(): bool {
		$options = $this->get_saved_options();

		return ! empty( $options['enabled'] );
	}

	/**
	 * Whether a channel is turned on.
	 *
	 * @since 1.2.0
	 *
	 * @param string $id Channel ID.
	 * @return bool True when the channel is enabled.
	 */
	public function is_channel_enabled( string $id ): bool {
		$options = $this->get_saved_options();

		return ! empty( $options['channels'][ $id ]['enabled'] );
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
	 * Returns the channels that will receive alerts: enabled and configured.
	 *
	 * Ignores the global switch, see `is_enabled()`.
	 *
	 * @since 1.2.0
	 *
	 * @return Alert_Channel[] Channels keyed by channel ID.
	 */
	public function get_active_channels(): array {
		$active = array();

		foreach ( $this->registry->get_all() as $id => $channel ) {
			if ( $this->is_channel_enabled( $id ) && $channel->is_configured( $this->get_channel_values( $channel ) ) ) {
				$active[ $id ] = $channel;
			}
		}

		return $active;
	}

	/**
	 * Sanitizes the submitted alert settings.
	 *
	 * An empty secret keeps the saved one.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $input Submitted settings.
	 * @return array Sanitized settings.
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = array(
			'enabled'  => ! empty( $input['enabled'] ),
			'channels' => array(),
		);

		foreach ( $this->registry->get_all() as $id => $channel ) {
			$raw = isset( $input['channels'][ $id ] ) && is_array( $input['channels'][ $id ] ) ? $input['channels'][ $id ] : array();

			$submitted = array();
			foreach ( array_keys( $channel->get_fields() ) as $key ) {
				$submitted[ $key ] = isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? $raw[ $key ] : '';
			}

			$values = $channel->sanitize_settings( $submitted );

			$clean['channels'][ $id ] = array( 'enabled' => ! empty( $raw['enabled'] ) );

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
