<?php
/**
 * Class WordPress\SMTP\Providers\Microsoft
 *
 * @package smtp
 */

namespace WordPress\SMTP\Providers;

/**
 * Sends email through the Microsoft Graph API with OAuth 2.0, for Microsoft 365 and Outlook.com.
 *
 * The site stores the OAuth application (client) ID, the client secret, the directory (tenant) and a
 * refresh token with the `Mail.Send` and `offline_access` scopes. Before each email, the refresh
 * token is exchanged for an access token, cached until it expires, and the MIME message PHPMailer
 * built is posted to `/me/sendMail` as a base64 encoded MIME message.
 *
 * @since 1.3.0
 */
class Microsoft {

	/**
	 * Microsoft identity platform token endpoint. `%s` is the tenant.
	 *
	 * @since 1.3.0
	 */
	const TOKEN_URL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

	/**
	 * Microsoft Graph endpoint that sends a MIME message as the signed-in user.
	 *
	 * @since 1.3.0
	 */
	const SEND_URL = 'https://graph.microsoft.com/v1.0/me/sendMail';

	/**
	 * Scopes requested when the refresh token is exchanged.
	 *
	 * @since 1.3.0
	 */
	const SCOPE = 'https://graph.microsoft.com/Mail.Send offline_access';

	/**
	 * Tenant used when the field is empty. It accepts work, school and personal accounts.
	 *
	 * @since 1.3.0
	 */
	const DEFAULT_TENANT = 'common';

	/**
	 * Prefix of the transients that cache access tokens.
	 *
	 * @since 1.3.0
	 */
	const TOKEN_TRANSIENT_PREFIX = 'smtp_microsoft_token_';

	/**
	 * Returns the provider definition.
	 *
	 * @since 1.3.0
	 *
	 * @return array Provider definition, see {@see \WordPress\SMTP\Provider_Registry}.
	 */
	public function get_provider(): array {
		return array(
			'label'       => __( 'Microsoft 365 / Outlook (Graph API)', 'smtp' ),
			'required'    => array( 'microsoft_client_id', 'microsoft_client_secret', 'microsoft_refresh_token' ),
			'fields'      => array(
				'microsoft_tenant'        => array(
					'label'       => __( 'Directory (tenant) ID', 'smtp' ),
					'type'        => 'text',
					'default'     => self::DEFAULT_TENANT,
					'constant'    => 'SMTP_MICROSOFT_TENANT',
					'description' => __( 'Directory (tenant) ID or domain of the Microsoft Entra ID app. Use "common" for an app that accepts any work, school or personal account.', 'smtp' ),
				),
				'microsoft_client_id'     => array(
					'label'       => __( 'Application (client) ID', 'smtp' ),
					'type'        => 'text',
					'constant'    => 'SMTP_MICROSOFT_CLIENT_ID',
					'description' => __( 'Application (client) ID of an app registered in Microsoft Entra ID with the delegated Mail.Send permission.', 'smtp' ),
				),
				'microsoft_client_secret' => array(
					'label'    => __( 'Client secret', 'smtp' ),
					'type'     => 'password',
					'constant' => 'SMTP_MICROSOFT_CLIENT_SECRET',
				),
				'microsoft_refresh_token' => array(
					'label'       => __( 'Refresh token', 'smtp' ),
					'type'        => 'password',
					'constant'    => 'SMTP_MICROSOFT_REFRESH_TOKEN',
					'description' => __( 'Refresh token of the Microsoft account that sends the email, with the Mail.Send and offline_access scopes. Microsoft sends from that account, or from an alias it is allowed to send as.', 'smtp' ),
				),
			),
			'send'        => array( $this, 'send' ),
			// Microsoft throttles per mailbox (30 messages per minute, 10,000 per day), not per application.
			'rate_limits' => array(),
		);
	}

	/**
	 * Delivers the email through the Microsoft Graph API.
	 *
	 * @since 1.3.0
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance, with the MIME message built.
	 * @param array                          $values    Resolved field values.
	 * @return true|\WP_Error True when Microsoft accepted the email.
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
					'Content-Type'  => 'text/plain',
				),
				'body'    => self::base64( $this->get_raw_message( $phpmailer ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		// Graph answers 202 Accepted with an empty body.
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		if ( 401 === $code ) {
			// The token was revoked or expired early: the next email asks for a new one.
			delete_transient( $this->get_token_transient( $values ) );
		}

		return new \WP_Error(
			'smtp_microsoft_send',
			sprintf(
				/* translators: 1: HTTP status code, 2: error message returned by Microsoft */
				__( 'Microsoft refused the email (HTTP %1$d): %2$s', 'smtp' ),
				$code,
				self::get_error_message( $response )
			)
		);
	}

	/**
	 * Returns the token endpoint of a tenant.
	 *
	 * @since 1.3.0
	 *
	 * @param array $values Resolved field values.
	 * @return string Token endpoint URL.
	 */
	public static function get_token_url( array $values ): string {
		return sprintf( self::TOKEN_URL, rawurlencode( self::get_tenant( $values ) ) );
	}

	/**
	 * Returns the tenant of a set of credentials.
	 *
	 * @since 1.3.0
	 *
	 * @param array $values Resolved field values.
	 * @return string Tenant ID or domain.
	 */
	private static function get_tenant( array $values ): string {
		$tenant = trim( (string) ( $values['microsoft_tenant'] ?? '' ) );

		return '' === $tenant ? self::DEFAULT_TENANT : $tenant;
	}

	/**
	 * Returns a valid access token, from the cache or from the token endpoint.
	 *
	 * @since 1.3.0
	 *
	 * @param array $values  Resolved field values.
	 * @param int   $timeout Request timeout in seconds.
	 * @return string|\WP_Error Access token, or an error when Microsoft did not issue one.
	 */
	private function get_access_token( array $values, int $timeout ) {
		$transient = $this->get_token_transient( $values );

		$cached = get_transient( $transient );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			self::get_token_url( $values ),
			array(
				'timeout' => $timeout,
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'client_id'     => (string) $values['microsoft_client_id'],
					'client_secret' => (string) $values['microsoft_client_secret'],
					'refresh_token' => (string) $values['microsoft_refresh_token'],
					'scope'         => self::SCOPE,
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
				'smtp_microsoft_token',
				sprintf(
					/* translators: 1: HTTP status code, 2: error message returned by Microsoft */
					__( 'Microsoft did not issue an access token (HTTP %1$d): %2$s', 'smtp' ),
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
	 * Each connection may use another Microsoft account, so the name depends on the credentials.
	 *
	 * @since 1.3.0
	 *
	 * @param array $values Resolved field values.
	 * @return string Transient name.
	 */
	private function get_token_transient( array $values ): string {
		$credentials = self::get_tenant( $values ) . '|' . (string) ( $values['microsoft_client_id'] ?? '' ) . '|' . (string) ( $values['microsoft_refresh_token'] ?? '' );

		return self::TOKEN_TRANSIENT_PREFIX . md5( $credentials );
	}

	/**
	 * Returns the MIME message for Microsoft Graph, with the Bcc recipients.
	 *
	 * PHPMailer only writes the Bcc header when it hands the message to `mail()` or sendmail,
	 * and another `phpmailer_init` callback may have switched it to SMTP. Graph reads the
	 * recipients from the headers of a MIME message and removes the Bcc header before delivery.
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
	 * Returns the error message of a Microsoft API response.
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
			// Graph errors nest the message in an error object.
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
	 * Encodes a string with base64, as Graph requires for a MIME message.
	 *
	 * @since 1.3.0
	 *
	 * @param string $data Data to encode.
	 * @return string Encoded data.
	 */
	private static function base64( string $data ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The Graph API requires a base64 encoded MIME message.
		return base64_encode( $data );
	}
}
