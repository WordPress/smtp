<?php
/**
 * Class WordPress\SMTP\Providers\Werkmail
 *
 * @package smtp
 */

namespace WordPress\SMTP\Providers;

/**
 * Sends email through Werkmail over SMTP.
 *
 * Werkmail accepts mail on port 587 with STARTTLS and on port 465 with TLS from the first
 * byte. Both are encrypted, so the provider offers no plain-text option. The host is fixed
 * because every workspace uses the same submission endpoint.
 *
 * The sender address must belong to a domain verified in the Werkmail workspace, otherwise
 * the server refuses the message after the handshake.
 *
 * @since 1.3.0
 */
class Werkmail {

	/**
	 * Submission endpoint of Werkmail.
	 *
	 * @since 1.3.0
	 */
	const HOST = 'smtp.werkmail.eu';

	/**
	 * Returns the provider definition.
	 *
	 * @since 1.3.0
	 *
	 * @return array Provider definition, see {@see \WordPress\SMTP\Provider_Registry}.
	 */
	public function get_provider(): array {
		return array(
			'label'       => __( 'Werkmail', 'smtp' ),
			'required'    => array( 'username', 'password' ),
			'fields'      => array(
				'port'     => array(
					'label'       => __( 'Port', 'smtp' ),
					'type'        => 'select',
					'default'     => '587',
					'constant'    => 'SMTP_WERKMAIL_PORT',
					'options'     => array(
						'587' => __( '587 (TLS, STARTTLS)', 'smtp' ),
						'465' => __( '465 (SSL)', 'smtp' ),
					),
					'description' => __( 'Both ports are encrypted. Use 465 when the server blocks outgoing port 587.', 'smtp' ),
				),
				'username' => array(
					'label'       => __( 'Username', 'smtp' ),
					'type'        => 'text',
					'constant'    => 'SMTP_WERKMAIL_USERNAME',
					'description' => __( 'The login from the SMTP page of your Werkmail workspace. The From address above must use a domain verified there.', 'smtp' ),
				),
				'password' => array(
					'label'    => __( 'Password', 'smtp' ),
					'type'     => 'password',
					'constant' => 'SMTP_WERKMAIL_PASSWORD',
				),
			),
			'configure'   => array( $this, 'configure' ),
			// Default limit of a Werkmail SMTP login. The workspace owner can raise it.
			'rate_limits' => array( 'per_hour' => 500 ),
		);
	}

	/**
	 * Configures PHPMailer for Werkmail.
	 *
	 * @since 1.3.0
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 * @param array                          $values    Resolved field values.
	 */
	public function configure( $phpmailer, array $values ) {
		$port = isset( $values['port'] ) ? (int) $values['port'] : 587;
		$port = 465 === $port ? 465 : 587;

		// PHPMailer property names are not snake_case.
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->isSMTP();
		$phpmailer->Host = self::HOST;
		$phpmailer->Port = $port;

		// 465 is TLS from the first byte, 587 upgrades with STARTTLS.
		$phpmailer->SMTPSecure  = 465 === $port ? 'ssl' : 'tls';
		$phpmailer->SMTPAutoTLS = 587 === $port;

		$phpmailer->SMTPAuth = true;
		$phpmailer->Username = (string) $values['username'];
		$phpmailer->Password = (string) $values['password'];
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}
}
