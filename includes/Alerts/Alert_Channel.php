<?php
/**
 * Interface WordPress\SMTP\Alerts\Alert_Channel
 *
 * @package smtp
 */

namespace WordPress\SMTP\Alerts;

/**
 * Contract for a channel that delivers alerts (Slack, Microsoft Teams, a webhook…).
 *
 * Register channels through the `smtp_alert_channels` filter. A channel must never use
 * `wp_mail()`: alerts exist precisely because email may be broken.
 *
 * @since 1.2.0
 */
interface Alert_Channel {

	/**
	 * Returns the unique channel ID, used in option keys and form names.
	 *
	 * @since 1.2.0
	 *
	 * @return string Channel ID (lowercase letters, numbers, dashes and underscores).
	 */
	public function get_id(): string;

	/**
	 * Returns the human readable channel name.
	 *
	 * @since 1.2.0
	 *
	 * @return string Channel name.
	 */
	public function get_label(): string;

	/**
	 * Returns the settings fields of the channel.
	 *
	 * Each field accepts `label`, `type` (`text`, `url` or `password`) and `description`.
	 * Values of `password` fields are stored as secrets and never printed back.
	 *
	 * @since 1.2.0
	 *
	 * @return array[] Fields keyed by field key.
	 */
	public function get_fields(): array;

	/**
	 * Sanitizes the submitted values of the channel fields.
	 *
	 * Return an empty string for an invalid value: for a `password` field it keeps the saved secret.
	 * Report problems with `add_settings_error()`.
	 *
	 * @since 1.2.0
	 *
	 * @param string[] $input Submitted values keyed by field key.
	 * @return string[] Sanitized values keyed by field key.
	 */
	public function sanitize_settings( array $input ): array;

	/**
	 * Whether the channel has everything it needs to send alerts.
	 *
	 * @since 1.2.0
	 *
	 * @param string[] $values Saved values keyed by field key.
	 * @return bool True when alerts can be sent.
	 */
	public function is_configured( array $values ): bool;

	/**
	 * Delivers an alert.
	 *
	 * @since 1.2.0
	 *
	 * @param Alert    $alert  Alert to deliver.
	 * @param string[] $values Saved values keyed by field key.
	 * @return true|\WP_Error True on success, an error otherwise.
	 */
	public function send( Alert $alert, array $values );
}
