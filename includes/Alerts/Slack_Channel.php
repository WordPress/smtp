<?php
/**
 * Class WordPress\SMTP\Alerts\Slack_Channel
 *
 * @package smtp
 */

namespace WordPress\SMTP\Alerts;

use WordPress\SMTP\Admin\Admin_Page;

/**
 * Sends alerts to Slack through an Incoming Webhook.
 *
 * @since 1.2.0
 */
class Slack_Channel implements Alert_Channel {

	/**
	 * Seconds to wait for Slack, kept short so a slow API never delays the request that sent the email.
	 *
	 * @since 1.2.0
	 */
	const TIMEOUT = 5;

	/**
	 * Returns the channel ID.
	 *
	 * @since 1.2.0
	 *
	 * @return string Channel ID.
	 */
	public function get_id(): string {
		return 'slack';
	}

	/**
	 * Returns the channel name.
	 *
	 * @since 1.2.0
	 *
	 * @return string Channel name.
	 */
	public function get_label(): string {
		return __( 'Slack', 'smtp' );
	}

	/**
	 * Returns the settings fields.
	 *
	 * @since 1.2.0
	 *
	 * @return array[] Fields keyed by field key.
	 */
	public function get_fields(): array {
		return array(
			'webhook_url' => array(
				'label'       => __( 'Webhook URL', 'smtp' ),
				'type'        => 'password',
				'description' => __( 'The Incoming Webhook URL of your Slack channel. It starts with https://hooks.slack.com/ and is stored as a secret.', 'smtp' ),
			),
		);
	}

	/**
	 * Sanitizes the submitted values.
	 *
	 * @since 1.2.0
	 *
	 * @param string[] $input Submitted values keyed by field key.
	 * @return string[] Sanitized values keyed by field key.
	 */
	public function sanitize_settings( array $input ): array {
		$url = isset( $input['webhook_url'] ) && is_string( $input['webhook_url'] ) ? trim( $input['webhook_url'] ) : '';

		if ( '' !== $url && ! self::is_webhook_url( $url ) ) {
			add_settings_error(
				Alert_Settings::OPTION,
				'smtp_slack_webhook_url',
				__( 'The Slack webhook URL must start with https://hooks.slack.com/. The previously saved URL was kept.', 'smtp' )
			);
			$url = '';
		}

		return array( 'webhook_url' => $url );
	}

	/**
	 * Whether a webhook URL is set.
	 *
	 * @since 1.2.0
	 *
	 * @param string[] $values Saved values keyed by field key.
	 * @return bool True when alerts can be sent.
	 */
	public function is_configured( array $values ): bool {
		return isset( $values['webhook_url'] ) && self::is_webhook_url( (string) $values['webhook_url'] );
	}

	/**
	 * Posts the alert to the Slack webhook.
	 *
	 * @since 1.2.0
	 *
	 * @param Alert    $alert  Alert to deliver.
	 * @param string[] $values Saved values keyed by field key.
	 * @return true|\WP_Error True on success, an error otherwise.
	 */
	public function send( Alert $alert, array $values ) {
		if ( ! $this->is_configured( $values ) ) {
			return new \WP_Error( 'smtp_slack_not_configured', __( 'The Slack webhook URL is missing or invalid.', 'smtp' ) );
		}

		$response = wp_remote_post(
			$values['webhook_url'],
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => (string) wp_json_encode( array( 'text' => $this->build_message( $alert ) ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error(
				'smtp_slack_http_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: response body returned by Slack */
					__( 'Slack returned HTTP %1$d: %2$s', 'smtp' ),
					$code,
					sanitize_text_field( wp_remote_retrieve_body( $response ) )
				)
			);
		}

		return true;
	}

	/**
	 * Builds the Slack message, in Slack mrkdwn.
	 *
	 * @since 1.2.0
	 *
	 * @param Alert $alert Alert to describe.
	 * @return string Message text.
	 */
	public function build_message( Alert $alert ): string {
		$site = self::escape( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$link = self::link( home_url( '/' ), $site );

		if ( Alert::TYPE_TEST === $alert->get_type() ) {
			return sprintf(
				/* translators: %s: link to the site */
				__( ':white_check_mark: Test alert from %s. Alerts about failed emails will be posted here.', 'smtp' ),
				$link
			);
		}

		$lines = array(
			sprintf(
				/* translators: %s: link to the site */
				__( ':warning: *An email failed to send on %s*', 'smtp' ),
				$link
			),
		);

		if ( '' !== $alert->get_error() ) {
			/* translators: %s: error message */
			$lines[] = sprintf( __( '*Error:* %s', 'smtp' ), self::escape( $alert->get_error() ) );
		}

		if ( array() !== $alert->get_recipients() ) {
			/* translators: %s: comma-separated list of recipients */
			$lines[] = sprintf( __( '*To:* %s', 'smtp' ), self::escape( implode( ', ', $alert->get_recipients() ) ) );
		}

		if ( '' !== $alert->get_subject() ) {
			/* translators: %s: email subject */
			$lines[] = sprintf( __( '*Subject:* %s', 'smtp' ), self::escape( $alert->get_subject() ) );
		}

		if ( $alert->get_failures() > 1 ) {
			$lines[] = sprintf(
				/* translators: %d: number of failed emails */
				_n( '%d email failed since the previous alert.', '%d emails failed since the previous alert.', $alert->get_failures(), 'smtp' ),
				$alert->get_failures()
			);
		}

		$lines[] = self::link( add_query_arg( 'status', 'failed', Admin_Page::get_url( 'log' ) ), self::escape( __( 'View failed emails', 'smtp' ) ) );

		return implode( "\n", $lines );
	}

	/**
	 * Whether a URL is a Slack webhook URL.
	 *
	 * @since 1.2.0
	 *
	 * @param string $url URL.
	 * @return bool True for an https://hooks.slack.com/ URL.
	 */
	private static function is_webhook_url( string $url ): bool {
		return 1 === preg_match( '#^https://hooks\.slack\.com/[^\s]+$#', $url );
	}

	/**
	 * Builds a Slack mrkdwn link.
	 *
	 * The URL is not HTML-escaped, so query strings keep their `&`. Characters that would
	 * end the link early are percent-encoded.
	 *
	 * @since 1.2.0
	 *
	 * @param string $url  URL.
	 * @param string $text Link text, already escaped.
	 * @return string Slack link.
	 */
	private static function link( string $url, string $text ): string {
		$url = str_replace( array( '<', '>', '|', ' ' ), array( '%3C', '%3E', '%7C', '%20' ), esc_url_raw( $url ) );

		return '<' . $url . '|' . $text . '>';
	}

	/**
	 * Escapes the characters Slack mrkdwn reserves for links and mentions.
	 *
	 * @since 1.2.0
	 *
	 * @param string $text Plain text.
	 * @return string Escaped text.
	 */
	private static function escape( string $text ): string {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
	}
}
