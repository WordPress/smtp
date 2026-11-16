<?php
/**
 * Class WordPress\SMTP\Mailer
 *
 * @package smtp
 */

namespace WordPress\SMTP;

/**
 * Applies the configured provider and From settings to outgoing emails.
 *
 * Nothing is changed until the active provider is fully configured.
 *
 * @since 1.2.0
 */
class Mailer {

	/**
	 * Default number of seconds to wait for the SMTP server.
	 *
	 * PHPMailer waits 300 seconds by default, long enough for a request to hit a gateway timeout
	 * when a firewall silently drops the connection.
	 *
	 * @since 1.2.0
	 */
	const DEFAULT_TIMEOUT = 15;

	/**
	 * Settings.
	 *
	 * @since 1.2.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Provider registry.
	 *
	 * @since 1.2.0
	 * @var Provider_Registry
	 */
	private $registry;

	/**
	 * Delivery tracker.
	 *
	 * @since 1.2.0
	 * @var Delivery_Tracker
	 */
	private $tracker;

	/**
	 * Connections, or null to only use the default connection.
	 *
	 * @since 1.2.0
	 * @var Connections|null
	 */
	private $connections;

	/**
	 * ID of the connection the current email is sent through.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private $connection_id = Connections::DEFAULT_ID;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param Settings          $settings    Settings of the default connection.
	 * @param Provider_Registry $registry    Provider registry.
	 * @param Delivery_Tracker  $tracker     Delivery tracker.
	 * @param Connections|null  $connections Optional. Connections. Default null, which only uses the default connection.
	 */
	public function __construct( Settings $settings, Provider_Registry $registry, Delivery_Tracker $tracker, ?Connections $connections = null ) {
		$this->settings    = $settings;
		$this->registry    = $registry;
		$this->tracker     = $tracker;
		$this->connections = $connections;
	}

	/**
	 * Registers the hooks.
	 *
	 * @since 1.2.0
	 */
	public function add_hooks() {
		// Runs last, so the email data is the one wp_mail() is going to send.
		add_filter( 'wp_mail', array( $this, 'select_connection' ), PHP_INT_MAX );
		add_action( 'wp_mail_succeeded', array( $this, 'reset_connection' ), PHP_INT_MAX );
		add_action( 'wp_mail_failed', array( $this, 'reset_connection' ), PHP_INT_MAX );
		add_action( 'phpmailer_init', array( $this, 'configure' ) );
		add_filter( 'wp_mail_from', array( $this, 'filter_from_email' ), PHP_INT_MAX );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ), PHP_INT_MAX );
	}

	/**
	 * Picks the connection the email is sent through.
	 *
	 * @since 1.2.0
	 *
	 * @param array $args Email data, as passed to the `wp_mail` filter.
	 * @return array Email data, unchanged.
	 */
	public function select_connection( $args ) {
		$this->connection_id = $this->connections ? $this->connections->resolve( is_array( $args ) ? $args : array() ) : Connections::DEFAULT_ID;

		$this->tracker->set_connection( $this->connection_id, $this->get_settings() );

		return $args;
	}

	/**
	 * Goes back to the default connection, once the email was sent or failed.
	 *
	 * @since 1.2.0
	 */
	public function reset_connection() {
		$this->connection_id = Connections::DEFAULT_ID;
	}

	/**
	 * Lets the provider of the selected connection configure PHPMailer.
	 *
	 * @since 1.2.0
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 */
	public function configure( $phpmailer ) {
		$settings = $this->get_settings();

		if ( ! $settings->is_configured() ) {
			return;
		}

		$provider = $this->registry->get( $settings->get_provider_slug() );
		if ( ! $provider ) {
			return;
		}

		/**
		 * Filters the number of seconds to wait for the SMTP server to connect and to answer each command.
		 *
		 * The provider can still set its own value when it configures PHPMailer.
		 *
		 * @since 1.2.0
		 *
		 * @param int    $timeout  Timeout in seconds. Default 15.
		 * @param string $provider Slug of the provider of the connection used.
		 */
		$timeout = (int) apply_filters( 'smtp_timeout', self::DEFAULT_TIMEOUT, $settings->get_provider_slug() );

		if ( $timeout > 0 ) {
			// PHPMailer property names are not snake_case.
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			// Timeout only covers opening the connection. Each reply, starting with the greeting,
			// is awaited for up to Timelimit seconds, 300 by default.
			$phpmailer->Timeout = $timeout;

			// wp_mail() loads the SMTP class together with PHPMailer.
			if ( class_exists( '\\PHPMailer\\PHPMailer\\SMTP' ) ) {
				$phpmailer->getSMTPInstance()->Timelimit = $timeout;
			}
			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		call_user_func( $provider['configure'], $phpmailer, $settings->get_provider_values() );

		$this->tracker->remember( $phpmailer );
	}

	/**
	 * Filters the From email address.
	 *
	 * @since 1.2.0
	 *
	 * @param string $email From email address.
	 * @return string From email address.
	 */
	public function filter_from_email( $email ) {
		$settings = $this->get_settings();

		if ( ! $settings->is_configured() ) {
			return $email;
		}

		$from_email = (string) $settings->get( 'from_email' );
		if ( '' === $from_email || ! is_email( $from_email ) ) {
			return $email;
		}

		if ( $settings->get( 'force_from' ) || $this->get_default_from_email() === $email ) {
			return $from_email;
		}

		return $email;
	}

	/**
	 * Filters the From name.
	 *
	 * @since 1.2.0
	 *
	 * @param string $name From name.
	 * @return string From name.
	 */
	public function filter_from_name( $name ) {
		$settings = $this->get_settings();

		if ( ! $settings->is_configured() ) {
			return $name;
		}

		$from_name = (string) $settings->get( 'from_name' );
		if ( '' === $from_name ) {
			return $name;
		}

		if ( $settings->get( 'force_from' ) || 'WordPress' === $name ) {
			return $from_name;
		}

		return $name;
	}

	/**
	 * Returns the settings of the connection the current email is sent through.
	 *
	 * @since 1.2.0
	 *
	 * @return Settings Settings.
	 */
	private function get_settings(): Settings {
		if ( $this->connections ) {
			$settings = $this->connections->get_settings( $this->connection_id );

			if ( $settings ) {
				return $settings;
			}
		}

		return $this->settings;
	}

	/**
	 * Returns the From email address WordPress uses when none is set.
	 *
	 * @since 1.2.0
	 *
	 * @return string Default From email address.
	 */
	private function get_default_from_email(): string {
		$sitename = wp_parse_url( network_home_url(), PHP_URL_HOST );

		if ( is_string( $sitename ) && 0 === strpos( $sitename, 'www.' ) ) {
			$sitename = substr( $sitename, 4 );
		}

		return 'wordpress@' . $sitename;
	}
}
