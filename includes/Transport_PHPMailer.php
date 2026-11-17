<?php
/**
 * Class WordPress\SMTP\Transport_PHPMailer
 *
 * @package smtp
 */

namespace WordPress\SMTP;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * PHPMailer that hands the email to a provider `send` callback instead of sending it itself.
 *
 * `wp_mail()` still builds the email: headers, recipients, attachments, From and content type
 * are parsed by WordPress and every `phpmailer_init` callback runs. Only the delivery changes.
 *
 * When no callback is set for the current email, it behaves like PHPMailer.
 *
 * @since 1.3.0
 */
class Transport_PHPMailer extends PHPMailer {

	/**
	 * Callback that delivers the current email, or null to send it with PHPMailer.
	 *
	 * @since 1.3.0
	 * @var callable|null
	 */
	private $transport = null;

	/**
	 * Resolved field values of the provider.
	 *
	 * @since 1.3.0
	 * @var array
	 */
	private $values = array();

	/**
	 * Values to remove from error messages.
	 *
	 * @since 1.3.0
	 * @var string[]
	 */
	private $secrets = array();

	/**
	 * Sets the callback that delivers the next email.
	 *
	 * It is used once: the email after that is sent with PHPMailer unless a callback is set again.
	 *
	 * @since 1.3.0
	 *
	 * @param callable $transport Receives this instance and the field values. Returns true or a `WP_Error`.
	 * @param array    $values    Resolved field values of the provider.
	 * @param string[] $secrets   Values to remove from error messages, like API keys.
	 */
	public function set_transport( callable $transport, array $values, array $secrets = array() ) {
		$this->transport = $transport;
		$this->values    = $values;
		$this->secrets   = array_values( array_filter( array_map( 'strval', $secrets ), 'strlen' ) );
	}

	/**
	 * Sends the email through the provider callback, or with PHPMailer when there is none.
	 *
	 * @since 1.3.0
	 *
	 * @return bool True when the email was sent.
	 *
	 * @throws Exception When the email can not be sent and exceptions are enabled.
	 */
	public function send() {
		if ( null === $this->transport ) {
			return parent::send();
		}

		$transport = $this->transport;
		$values    = $this->values;
		$secrets   = $this->secrets;

		$this->transport = null;
		$this->values    = array();
		$this->secrets   = array();

		// Validates the addresses and builds the MIME message, for APIs that accept raw emails.
		if ( ! $this->preSend() ) {
			return false;
		}

		try {
			$result = call_user_func( $transport, $this, $values );
		} catch ( \Throwable $e ) {
			// wp_mail() only catches PHPMailer exceptions, anything else would be a fatal error.
			$result = new \WP_Error( 'smtp_transport', $e->getMessage() );
		}

		if ( true === $result ) {
			return true;
		}

		$message = is_wp_error( $result ) ? $result->get_error_message() : '';
		if ( '' === $message ) {
			$message = __( 'The provider did not confirm that the email was sent.', 'smtp' );
		}

		// An API may repeat the key it received in its error.
		$message = str_replace( $secrets, '[redacted]', $message );

		$this->setError( $message );
		if ( $this->exceptions ) {
			throw new Exception( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Becomes the wp_mail_failed error, escaped where it is displayed.
		}

		return false;
	}
}
