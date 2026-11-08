<?php
/**
 * Class WordPress\SMTP\Alerts\Alert_Channel_Registry
 *
 * @package smtp
 */

namespace WordPress\SMTP\Alerts;

/**
 * Collects the available alert channels through the `smtp_alert_channels` filter.
 *
 * @since 1.2.0
 */
class Alert_Channel_Registry {

	/**
	 * Returns every valid alert channel.
	 *
	 * @since 1.2.0
	 *
	 * @return Alert_Channel[] Channels keyed by channel ID.
	 */
	public function get_all(): array {
		/**
		 * Filters the available alert channels.
		 *
		 * Channels must implement `WordPress\SMTP\Alerts\Alert_Channel`. Anything else is discarded.
		 *
		 * @since 1.2.0
		 *
		 * @param Alert_Channel[] $channels Channels. Slack is registered by default.
		 */
		$channels = apply_filters( 'smtp_alert_channels', array( new Slack_Channel() ) );
		$valid    = array();

		foreach ( is_array( $channels ) ? $channels : array() as $channel ) {
			if ( ! $channel instanceof Alert_Channel ) {
				continue;
			}

			$id = $channel->get_id();
			if ( '' === $id || sanitize_key( $id ) !== $id || isset( $valid[ $id ] ) ) {
				continue;
			}

			$valid[ $id ] = $channel;
		}

		return $valid;
	}

	/**
	 * Returns a channel by ID.
	 *
	 * @since 1.2.0
	 *
	 * @param string $id Channel ID.
	 * @return Alert_Channel|null Channel, or null when it does not exist.
	 */
	public function get( string $id ): ?Alert_Channel {
		$channels = $this->get_all();

		return $channels[ $id ] ?? null;
	}
}
