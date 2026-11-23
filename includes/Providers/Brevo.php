<?php
/**
 * Class WordPress\SMTP\Providers\Brevo
 *
 * @package smtp
 */

namespace WordPress\SMTP\Providers;

/**
 * Sends email through the Brevo (formerly Sendinblue) transactional email API.
 *
 * The site stores a Brevo API key. Each email is posted as JSON to the `smtp/email` endpoint,
 * built from the recipients, content, headers and attachments PHPMailer holds, since the API does
 * not accept a raw MIME message.
 *
 * @since 1.3.0
 */
class Brevo {

	/**
	 * Brevo API endpoint that sends a transactional email.
	 *
	 * @since 1.3.0
	 */
	const SEND_URL = 'https://api.brevo.com/v3/smtp/email';

	/**
	 * Returns the provider definition.
	 *
	 * @since 1.3.0
	 *
	 * @return array Provider definition, see {@see \WordPress\SMTP\Provider_Registry}.
	 */
	public function get_provider(): array {
		return array(
			'label'       => __( 'Brevo (API)', 'smtp' ),
			'required'    => array( 'brevo_api_key' ),
			'fields'      => array(
				'brevo_api_key' => array(
					'label'       => __( 'API key', 'smtp' ),
					'type'        => 'password',
					'constant'    => 'SMTP_BREVO_API_KEY',
					'description' => __( 'Create it in Brevo under SMTP & API > API keys. The From email must be a sender or domain verified in Brevo.', 'smtp' ),
				),
			),
			'send'        => array( $this, 'send' ),
			// Brevo limits depend on the plan of the account, so none are set by default.
			'rate_limits' => array(),
		);
	}

	/**
	 * Delivers the email through the Brevo API.
	 *
	 * @since 1.3.0
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance, with the email ready to send.
	 * @param array                          $values    Resolved field values.
	 * @return true|\WP_Error True when Brevo accepted the email.
	 */
	public function send( $phpmailer, array $values ) {
		$body = $this->get_body( $phpmailer );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$response = wp_remote_post(
			self::SEND_URL,
			array(
				// PHPMailer property names are not snake_case.
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				'timeout' => (int) $phpmailer->Timeout,
				'headers' => array(
					'api-key'      => (string) $values['brevo_api_key'],
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'    => (string) wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		return new \WP_Error(
			'smtp_brevo_send',
			sprintf(
				/* translators: 1: HTTP status code, 2: error message returned by Brevo */
				__( 'Brevo refused the email (HTTP %1$d): %2$s', 'smtp' ),
				$code,
				self::get_error_message( $response )
			)
		);
	}

	/**
	 * Returns the request body of the Brevo API for the email.
	 *
	 * @since 1.3.0
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance, with the email ready to send.
	 * @return array|\WP_Error Request body, or an error when an attachment cannot be read.
	 */
	private function get_body( $phpmailer ) {
		// PHPMailer property names are not snake_case.
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$body = array(
			'sender'  => self::contact( $phpmailer->From, $phpmailer->FromName ),
			'to'      => self::contacts( $phpmailer->getToAddresses() ),
			'subject' => (string) $phpmailer->Subject,
		);

		// PHPMailer switches an HTML email with a text version to multipart/alternative before sending.
		if ( in_array( $phpmailer->ContentType, array( 'text/html', 'multipart/alternative' ), true ) ) {
			$body['htmlContent'] = (string) $phpmailer->Body;
			if ( '' !== (string) $phpmailer->AltBody ) {
				$body['textContent'] = (string) $phpmailer->AltBody;
			}
		} else {
			$body['textContent'] = (string) $phpmailer->Body;
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		$cc = self::contacts( $phpmailer->getCcAddresses() );
		if ( $cc ) {
			$body['cc'] = $cc;
		}

		$bcc = self::contacts( $phpmailer->getBccAddresses() );
		if ( $bcc ) {
			$body['bcc'] = $bcc;
		}

		// Brevo accepts a single Reply-To address.
		$reply_to = self::contacts( $phpmailer->getReplyToAddresses() );
		if ( $reply_to ) {
			$body['replyTo'] = $reply_to[0];
		}

		$headers = array();
		foreach ( $phpmailer->getCustomHeaders() as $header ) {
			$headers[ (string) $header[0] ] = (string) $header[1];
		}
		if ( $headers ) {
			$body['headers'] = $headers;
		}

		$attachments = $this->get_attachments( $phpmailer );
		if ( is_wp_error( $attachments ) ) {
			return $attachments;
		}
		if ( $attachments ) {
			$body['attachment'] = $attachments;
		}

		return $body;
	}

	/**
	 * Returns the attachments of the email in the format of the Brevo API.
	 *
	 * Brevo has no inline (`cid:`) attachments, so inline images are sent as regular attachments.
	 *
	 * @since 1.3.0
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 * @return array[]|\WP_Error Attachments, or an error when a file cannot be read.
	 */
	private function get_attachments( $phpmailer ) {
		$attachments = array();

		foreach ( $phpmailer->getAttachments() as $attachment ) {
			// Each attachment is: path or content, file name, name, encoding, type, is string, disposition, cid.
			$name = '' !== (string) $attachment[2] ? (string) $attachment[2] : (string) $attachment[1];

			if ( ! empty( $attachment[5] ) ) {
				$content = (string) $attachment[0];
			} else {
				$path    = (string) $attachment[0];
				$content = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local attachment file.
				if ( false === $content ) {
					return new \WP_Error(
						'smtp_brevo_attachment',
						sprintf(
							/* translators: %s: attachment file name */
							__( 'Could not read the attachment %s.', 'smtp' ),
							$name
						)
					);
				}
			}

			$attachments[] = array(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The Brevo API requires base64 attachment content.
				'content' => base64_encode( $content ),
				'name'    => $name,
			);
		}

		return $attachments;
	}

	/**
	 * Returns a list of PHPMailer addresses in the format of the Brevo API.
	 *
	 * @since 1.3.0
	 *
	 * @param array $addresses Addresses as `array( email, name )` pairs.
	 * @return array[] Contacts.
	 */
	private static function contacts( array $addresses ): array {
		$contacts = array();
		foreach ( $addresses as $address ) {
			$contacts[] = self::contact( (string) $address[0], (string) ( $address[1] ?? '' ) );
		}

		return $contacts;
	}

	/**
	 * Returns a contact in the format of the Brevo API.
	 *
	 * @since 1.3.0
	 *
	 * @param string $email Email address.
	 * @param string $name  Display name, omitted when empty.
	 * @return array Contact.
	 */
	private static function contact( string $email, string $name ): array {
		$contact = array( 'email' => $email );
		if ( '' !== $name ) {
			$contact['name'] = $name;
		}

		return $contact;
	}

	/**
	 * Returns the error message of a Brevo API response.
	 *
	 * @since 1.3.0
	 *
	 * @param array $response HTTP response.
	 * @return string Error message, or the response body when it has no known error format.
	 */
	private static function get_error_message( $response ): string {
		$raw  = (string) wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );

		// Brevo errors have a code, such as `unauthorized`, and a message.
		if ( is_array( $body ) && isset( $body['message'] ) && is_string( $body['message'] ) ) {
			$code = isset( $body['code'] ) && is_string( $body['code'] ) ? $body['code'] : '';

			return '' === $code ? $body['message'] : $code . ': ' . $body['message'];
		}

		$raw = trim( wp_strip_all_tags( $raw ) );

		return '' === $raw ? __( 'No error message was returned.', 'smtp' ) : substr( $raw, 0, 200 );
	}
}
