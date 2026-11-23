<?php
/**
 * Class WordPress\SMTP\Providers\Google
 *
 * @package smtp
 */

namespace WordPress\SMTP\Providers;

/**
 * Sends email through the Gmail API with OAuth 2.0.
 *
 * The site stores the OAuth client ID, the client secret and a refresh token with the
 * `https://www.googleapis.com/auth/gmail.send` scope. Before each email, the refresh token is
 * exchanged for an access token, cached until it expires, and the MIME message PHPMailer built
 * is posted to `users.messages.send` as a raw message.
 *
 * @since 1.3.0
 */
class Google {

	/**
	 * Google OAuth 2.0 token endpoint.
	 *
	 * @since 1.3.0
	 */
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/**
	 * Gmail API endpoint that sends a raw message.
	 *
	 * @since 1.3.0
	 */
	const SEND_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

	/**
	 * Prefix of the transients that cache access tokens.
	 *
	 * @since 1.3.0
	 */
	const TOKEN_TRANSIENT_PREFIX = 'smtp_google_token_';

	/**
	 * Returns the provider definition.
	 *
	 * @since 1.3.0
	 *
	 * @return array Provider definition, see {@see \WordPress\SMTP\Provider_Registry}.
	 */
	public function get_provider(): array {
		return array(
			'label'       => __( 'Google (Gmail API)', 'smtp' ),
			'required'    => array( 'google_client_id', 'google_client_secret', 'google_refresh_token' ),
			'fields'      => array(
				'google_client_id'     => array(
					'label'       => __( 'Client ID', 'smtp' ),
					'type'        => 'text',
					'constant'    => 'SMTP_GOOGLE_CLIENT_ID',
					'description' => __( 'OAuth 2.0 client ID of a Google Cloud project with the Gmail API enabled.', 'smtp' ),
				),
				'google_client_secret' => array(
					'label'    => __( 'Client secret', 'smtp' ),
					'type'     => 'password',
					'constant' => 'SMTP_GOOGLE_CLIENT_SECRET',
				),
				'google_refresh_token' => array(
					'label'       => __( 'Refresh token', 'smtp' ),
					'type'        => 'password',
					'constant'    => 'SMTP_GOOGLE_REFRESH_TOKEN',
					'description' => __( 'Refresh token of the Google account that sends the email, with the https://www.googleapis.com/auth/gmail.send scope. Gmail sends from that account, or from one of its verified "Send mail as" addresses.', 'smtp' ),
				),
			),
			'send'        => array( $this, 'send' ),
			// Gmail does not publish per-second limits, only daily quotas that depend on the account.
			'rate_limits' => array(),
		);
	}

	/**
	 * Delivers the email through the Gmail API.
	 *
	 * @since 1.3.0
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance, with the MIME message built.
	 * @param array                          $values    Resolved field values.
	 * @return true|\WP_Error True when Gmail accepted the email.
	 */
	public function send( $phpmailer, array $values ) {
		// PHPMailer property names are not snake_case.
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$timeout = (int) $phpmailer->Timeout;

		$token = $this->get_access_token( $values, $timeout );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post(
			self::SEND_URL,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => (string) wp_json_encode( array( 'raw' => self::base64url( $this->get_raw_message( $phpmailer ) ) ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		if ( 401 === $code ) {
			// The token was revoked or expired early: the next email asks for a new one.
			delete_transient( $this->get_token_transient( $values ) );
		}

		return new \WP_Error(
			'smtp_google_send',
			sprintf(
				/* translators: 1: HTTP status code, 2: error message returned by Gmail */
				__( 'Gmail refused the email (HTTP %1$d): %2$s', 'smtp' ),
				$code,
				self::get_error_message( $response )
			)
		);
	}

	/**
	 * Returns a valid access token, from the cache or from the token endpoint.
	 *
	 * @since 1.3.0
	 *
	 * @param array $values  Resolved field values.
	 * @param int   $timeout Request timeout in seconds.
	 * @return string|\WP_Error Access token, or an error when Google did not issue one.
	 */
	private function get_access_token( array $values, int $timeout ) {
		$transient = $this->get_token_transient( $values );

		$cached = get_transient( $transient );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => $timeout,
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'client_id'     => (string) $values['google_client_id'],
					'client_secret' => (string) $values['google_client_secret'],
					'refresh_token' => (string) $values['google_refresh_token'],
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) || empty( $body['access_token'] ) || ! is_string( $body['access_token'] ) ) {
			return new \WP_Error(
				'smtp_google_token',
				sprintf(
					/* translators: 1: HTTP status code, 2: error message returned by Google */
					__( 'Google did not issue an access token (HTTP %1$d): %2$s', 'smtp' ),
					$code,
					self::get_error_message( $response )
				)
			);
		}

		$expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
		// Renews the token a minute early, so it does not expire while an email is sent.
		$ttl = $expires_in - MINUTE_IN_SECONDS;
		if ( $ttl > 0 ) {
			set_transient( $transient, $body['access_token'], $ttl );
		}

		return $body['access_token'];
	}

	/**
	 * Returns the name of the transient that caches the access token of a set of credentials.
	 *
	 * Each connection may use another Google account, so the name depends on the credentials.
	 *
	 * @since 1.3.0
	 *
	 * @param array $values Resolved field values.
	 * @return string Transient name.
	 */
	private function get_token_transient( array $values ): string {
		$credentials = (string) ( $values['google_client_id'] ?? '' ) . '|' . (string) ( $values['google_refresh_token'] ?? '' );

		return self::TOKEN_TRANSIENT_PREFIX . md5( $credentials );
	}

	/**
	 * Returns the MIME message for the Gmail API, with the Bcc recipients.
	 *
	 * PHPMailer only writes the Bcc header when it hands the message to `mail()` or sendmail,
	 * and another `phpmailer_init` callback may have switched it to SMTP. Gmail reads the
	 * recipients from the headers of a raw message and removes the Bcc header before delivery.
	 *
	 * @since 1.3.0
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance, with the MIME message built.
	 * @return string MIME message.
	 */
	private function get_raw_message( $phpmailer ): string {
		$message = $phpmailer->getSentMIMEMessage();
		$bcc     = $phpmailer->getBccAddresses();
		$headers = preg_split( '/\r?\n\r?\n/', $message, 2 );

		if ( ! empty( $bcc ) && ! preg_match( '/^Bcc:/mi', (string) $headers[0] ) ) {
			$message = $phpmailer->addrAppend( 'Bcc', $bcc ) . $message;
		}

		return $message;
	}

	/**
	 * Returns the error message of a Google API response.
	 *
	 * @since 1.3.0
	 *
	 * @param array $response HTTP response.
	 * @return string Error message, or the response body when it has no known error format.
	 */
	private static function get_error_message( $response ): string {
		$raw  = (string) wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );

		if ( is_array( $body ) ) {
			// Gmail API errors nest the message in an error object.
			if ( isset( $body['error']['message'] ) && is_string( $body['error']['message'] ) ) {
				return $body['error']['message'];
			}
			// OAuth token endpoint errors use an error code and an optional description.
			if ( isset( $body['error'] ) && is_string( $body['error'] ) ) {
				$description = isset( $body['error_description'] ) && is_string( $body['error_description'] ) ? $body['error_description'] : '';

				return '' === $description ? $body['error'] : $body['error'] . ': ' . $description;
			}
		}

		$raw = trim( wp_strip_all_tags( $raw ) );

		return '' === $raw ? __( 'No error message was returned.', 'smtp' ) : substr( $raw, 0, 200 );
	}

	/**
	 * Encodes a string with the URL-safe base64 alphabet, without padding.
	 *
	 * @since 1.3.0
	 *
	 * @param string $data Data to encode.
	 * @return string Encoded data.
	 */
	private static function base64url( string $data ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The Gmail API requires base64url raw messages.
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
