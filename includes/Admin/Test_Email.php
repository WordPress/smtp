<?php
/**
 * Class WordPress\SMTP\Admin\Test_Email
 *
 * @package smtp
 */

namespace WordPress\SMTP\Admin;

use WordPress\SMTP\Connections;
use WordPress\SMTP\Delivery_Tracker;
use WordPress\SMTP\Settings;

/**
 * Sends a test email to the current user and reports the outcome.
 *
 * @since 1.2.0
 */
class Test_Email {

	/**
	 * Action and nonce name.
	 *
	 * @since 1.2.0
	 */
	const ACTION = 'smtp_send_test';

	/**
	 * Settings.
	 *
	 * @since 1.2.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Delivery tracker.
	 *
	 * @since 1.2.0
	 * @var Delivery_Tracker
	 */
	private $tracker;

	/**
	 * Connections, or null to only test the default connection.
	 *
	 * @since 1.3.0
	 * @var Connections|null
	 */
	private $connections;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param Settings         $settings    Settings of the default connection.
	 * @param Delivery_Tracker $tracker     Delivery tracker.
	 * @param Connections|null $connections Optional. Connections. Default null, which only tests the default connection.
	 */
	public function __construct( Settings $settings, Delivery_Tracker $tracker, ?Connections $connections = null ) {
		$this->settings    = $settings;
		$this->tracker     = $tracker;
		$this->connections = $connections;
	}

	/**
	 * Registers the hooks.
	 *
	 * @since 1.2.0
	 */
	public function add_hooks() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Returns the last test result of a user and forgets it.
	 *
	 * @since 1.2.0
	 *
	 * @param int $user_id User ID.
	 * @return array|null Result with `success`, `to`, `error`, `debug` and `provider`, or null when there is none.
	 */
	public static function pull_result( int $user_id ): ?array {
		$key    = self::ACTION . '_' . $user_id;
		$result = get_transient( $key );

		if ( ! is_array( $result ) ) {
			return null;
		}

		delete_transient( $key );

		return $result;
	}

	/**
	 * Handles the test email request.
	 *
	 * @since 1.2.0
	 */
	public function handle() {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'smtp' ), '', array( 'response' => 403 ) );
		}

		$user = wp_get_current_user();

		// The nonce was verified above. An empty field falls back to the email of the current user.
		// The address is validated as typed: sanitize_email() would turn a list of addresses into a different, valid one.
		$requested  = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		$to         = '' === $requested ? $user->user_email : $requested;
		$connection = isset( $_POST['connection'] ) ? sanitize_text_field( wp_unslash( $_POST['connection'] ) ) : '';

		$this->finish( $user->ID, $this->send( $to, $connection ) );
	}

	/**
	 * Sends a test email and captures the connection log.
	 *
	 * @since 1.2.0
	 *
	 * @param string $to         Recipient address.
	 * @param string $connection Optional. ID of the connection to test. Default empty, the connection the email would normally use.
	 * @return array Result with `success`, `to`, `error`, `debug`, `provider` and `connection_id`. The credentials are removed from `debug`.
	 */
	public function send( string $to, string $connection = '' ): array {
		if ( ! is_email( $to ) ) {
			return array(
				'success'       => false,
				'to'            => $to,
				'error'         => __( 'Please enter a valid email address.', 'smtp' ),
				'debug'         => '',
				'provider'      => '',
				'connection_id' => '' === $connection ? Connections::DEFAULT_ID : $connection,
			);
		}

		// Pick the connection like a plugin would, with the filter.
		$select = null;
		if ( $this->connections && '' !== $connection && $this->connections->exists( $connection ) ) {
			$select = static function () use ( $connection ) {
				return $connection;
			};
			add_filter( 'smtp_connection_for_email', $select, PHP_INT_MAX );
		}

		$debug         = array();
		$error         = '';
		$provider      = '';
		$connection_id = Connections::DEFAULT_ID;
		$auth          = array( '', '' );

		// PHPMailer property names are not snake_case.
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$capture_debug = static function ( $phpmailer ) use ( &$debug, &$auth ) {
			$phpmailer->SMTPDebug   = 2;
			$phpmailer->Debugoutput = static function ( $message ) use ( &$debug ) {
				$debug[] = trim( (string) $message );
			};
			// Runs after the provider configured PHPMailer, so these are the credentials sent to the server, whatever the provider calls its fields.
			$auth = array( (string) $phpmailer->Username, (string) $phpmailer->Password );
		};
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$capture_error = static function ( $wp_error ) use ( &$error ) {
			$error = $wp_error->get_error_message();
		};
		// Read before the tracker forgets the email, at the end of the same hooks.
		$tracker          = $this->tracker;
		$capture_provider = static function () use ( &$provider, &$connection_id, $tracker ) {
			$provider      = $tracker->get_provider();
			$connection_id = $tracker->get_connection_id();
		};

		add_action( 'phpmailer_init', $capture_debug, PHP_INT_MAX );
		add_action( 'wp_mail_failed', $capture_error );
		add_action( 'wp_mail_succeeded', $capture_provider );
		add_action( 'wp_mail_failed', $capture_provider );

		$sent = wp_mail(
			$to,
			/* translators: %s: site name */
			sprintf( __( 'SMTP test email from %s', 'smtp' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			__( 'This is a test email sent by the SMTP plugin. If you are reading it, your settings work.', 'smtp' )
		);

		if ( $select ) {
			remove_filter( 'smtp_connection_for_email', $select, PHP_INT_MAX );
		}

		remove_action( 'phpmailer_init', $capture_debug, PHP_INT_MAX );
		remove_action( 'wp_mail_failed', $capture_error );
		remove_action( 'wp_mail_succeeded', $capture_provider );
		remove_action( 'wp_mail_failed', $capture_provider );

		return array(
			'success'       => $sent,
			'to'            => $to,
			'error'         => $error,
			'debug'         => $this->redact( implode( "\n", $debug ), $connection_id, $auth ),
			'provider'      => $provider,
			'connection_id' => $connection_id,
		);
	}

	/**
	 * Stores the result for the Test tab and redirects to it.
	 *
	 * @since 1.2.0
	 *
	 * @param int   $user_id User ID.
	 * @param array $result  Result with `success`, `to`, `error`, `debug` and `provider`.
	 */
	private function finish( int $user_id, array $result ) {
		set_transient( self::ACTION . '_' . $user_id, $result, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( Admin_Page::get_url( 'test' ) );
		exit;
	}

	/**
	 * Removes the credentials from the connection log, in plain text and base64.
	 *
	 * @since 1.2.0
	 *
	 * @param string $text          Connection log.
	 * @param string $connection_id Optional. ID of the connection that was used. Default the default connection.
	 * @param array  $auth          Optional. Username and password PHPMailer authenticated with. Default empty.
	 * @return string Connection log without credentials.
	 */
	private function redact( string $text, string $connection_id = Connections::DEFAULT_ID, array $auth = array( '', '' ) ): string {
		// The fields and the values must come from the same connection: another provider has other field keys.
		$settings = ( $this->connections ? $this->connections->get_settings( $connection_id ) : null ) ?? $this->settings;
		$fields   = $settings->get_fields();
		$values   = $settings->get_provider_values();
		$username = isset( $values['username'] ) ? (string) $values['username'] : '';
		$secrets  = array();

		// Every password field is a secret, not only the one named `password`: providers added
		// through the smtp_providers filter may call it `api_key` or `token`.
		$passwords   = array_intersect_key( $values, wp_list_filter( $fields, array( 'type' => 'password' ) ) );
		$passwords[] = $auth[1];

		foreach ( $passwords as $value ) {
			$value = (string) $value;
			if ( '' === $value ) {
				continue;
			}

			$secrets[] = $value;
			$secrets[] = base64_encode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Needed to match the SMTP AUTH exchange.
			$secrets[] = base64_encode( "\0" . $username . "\0" . $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Needed to match the SMTP AUTH exchange.
			$secrets[] = base64_encode( "\0" . $auth[0] . "\0" . $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Needed to match the SMTP AUTH exchange.
		}

		// Longest first, so a secret that contains another one is replaced as a whole.
		usort(
			$secrets,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		return str_replace( $secrets, '[redacted]', $text );
	}
}
