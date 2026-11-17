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
	 * Whether a provider callback is delivering an email.
	 *
	 * @since 1.3.0
	 * @var bool
	 */
	private static $sending = false;

	/**
	 * Returns whether a provider callback is delivering an email.
	 *
	 * @since 1.3.0
	 *
	 * @return bool True while a `send` callback runs.
	 */
	public static function is_sending(): bool {
		return self::$sending;
	}

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
		$this->secrets   = self::get_redacted_values( $secrets, $values );
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
		$transport = $this->transport;
		$values    = $this->values;
		$secrets   = $this->secrets;

		$this->transport = null;
		$this->values    = array();
		$this->secrets   = array();

		if ( self::$sending ) {
			// A send callback called wp_mail(). Sending it could loop forever.
			$result = new \WP_Error( 'smtp_transport_nested', __( 'An email can not be sent while the provider is sending another one.', 'smtp' ) );
		} elseif ( null === $transport ) {
			return parent::send();
		} elseif ( ! $this->preSend() ) {
			// Validates the addresses and builds the MIME message, for APIs that accept raw emails.
			return false;
		} else {
			self::$sending = true;
			try {
				$result = call_user_func( $transport, $this, $values );
			} catch ( \Throwable $e ) {
				// wp_mail() only catches PHPMailer exceptions, anything else would be a fatal error.
				$result = new \WP_Error( 'smtp_transport', $e->getMessage() );
			} finally {
				self::$sending = false;
			}
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

	/**
	 * Returns the secrets and the encoded forms an API may echo, longest first.
	 *
	 * @since 1.3.0
	 *
	 * @param string[] $secrets Secret values.
	 * @param array    $values  Resolved field values, to match Basic auth tokens like `user:secret`.
	 * @return string[] Values to replace in error messages.
	 */
	private static function get_redacted_values( array $secrets, array $values ): array {
		$redacted = array();

		foreach ( $secrets as $secret ) {
			$secret = (string) $secret;
			if ( '' === $secret ) {
				continue;
			}

			$redacted[] = $secret;
			$redacted[] = base64_encode( $secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Matches a key the API echoes encoded.
			$redacted[] = rawurlencode( $secret );
			$redacted[] = urlencode( $secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- Form bodies encode spaces as +.
			$redacted[] = substr( (string) wp_json_encode( $secret ), 1, -1 );
			foreach ( array( JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) as $flags ) {
				$redacted[] = substr( (string) wp_json_encode( $secret, $flags ), 1, -1 );
			}

			// Basic auth encodes the user and the secret together. The user may be empty.
			$redacted[] = base64_encode( ':' . $secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Matches an echoed Basic auth header.
			foreach ( $values as $value ) {
				if ( is_scalar( $value ) ) {
					$redacted[] = base64_encode( $value . ':' . $secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Matches an echoed Basic auth header.
				}
			}
		}

		$redacted = array_values( array_unique( array_filter( $redacted, 'strlen' ) ) );

		// Longest first, so a secret that contains another one is replaced as a whole.
		usort(
			$redacted,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		return $redacted;
	}
}
