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
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param Settings          $settings Settings.
	 * @param Provider_Registry $registry Provider registry.
	 * @param Delivery_Tracker  $tracker  Delivery tracker.
	 */
	public function __construct( Settings $settings, Provider_Registry $registry, Delivery_Tracker $tracker ) {
		$this->settings = $settings;
		$this->registry = $registry;
		$this->tracker  = $tracker;
	}

	/**
	 * Registers the hooks.
	 *
	 * @since 1.2.0
	 */
	public function add_hooks() {
		add_filter( 'wp_mail', array( $this, 'use_transport' ), PHP_INT_MAX );
		add_action( 'phpmailer_init', array( $this, 'configure' ) );
		add_filter( 'wp_mail_from', array( $this, 'filter_from_email' ), PHP_INT_MAX );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ), PHP_INT_MAX );
	}

	/**
	 * Lets the active provider configure PHPMailer.
	 *
	 * @since 1.2.0
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 */
	public function configure( $phpmailer ) {
		if ( ! $this->settings->is_configured() ) {
			return;
		}

		$provider = $this->registry->get( $this->settings->get_provider_slug() );
		if ( ! $provider ) {
			return;
		}

		/**
		 * Filters the number of seconds to wait for the SMTP server to connect and to answer each command.
		 *
		 * The provider can still set its own value when it configures PHPMailer. Providers that
		 * send through an API read it from `$phpmailer->Timeout`.
		 *
		 * @since 1.2.0
		 *
		 * @param int    $timeout  Timeout in seconds. Default 15.
		 * @param string $provider Slug of the active provider.
		 */
		$timeout = (int) apply_filters( 'smtp_timeout', self::DEFAULT_TIMEOUT, $this->settings->get_provider_slug() );

		if ( $timeout > 0 ) {
			// PHPMailer property names are not snake_case.
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			// Timeout only covers opening the connection. Each reply, starting with the greeting,
			// is awaited for up to Timelimit seconds, 300 by default.
			$phpmailer->Timeout = $timeout;

			// wp_mail() loads the SMTP class together with PHPMailer. API providers do not use it.
			if ( null === $provider['send'] && class_exists( '\\PHPMailer\\PHPMailer\\SMTP' ) ) {
				$phpmailer->getSMTPInstance()->Timelimit = $timeout;
			}
			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		$values = $this->settings->get_provider_values();

		if ( null !== $provider['configure'] ) {
			call_user_func( $provider['configure'], $phpmailer, $values );
		}

		if ( null !== $provider['send'] ) {
			// Another plugin put its own PHPMailer in place: the email does not go through the provider.
			// An empty snapshot never matches the real instance, so the tracker credits another plugin.
			if ( ! $phpmailer instanceof Transport_PHPMailer ) {
				$this->tracker->remember( null );
				return;
			}

			$phpmailer->set_transport( $provider['send'], $values, $this->get_secrets( $values ) );
		}

		$this->tracker->remember( $phpmailer );
	}

	/**
	 * Puts a PHPMailer that can send through a provider `send` callback in place.
	 *
	 * Runs on the `wp_mail` filter, before `wp_mail()` creates its PHPMailer instance. It does not
	 * read the provider of the email: other `wp_mail` callbacks may still change it, so `configure()`
	 * picks it on `phpmailer_init`, also when the saved provider is not configured. Without a
	 * callback, the instance sends like PHPMailer.
	 *
	 * @since 1.3.0
	 *
	 * @param array $atts Arguments of `wp_mail()`.
	 * @return array Unchanged arguments.
	 */
	public function use_transport( $atts ) {
		global $phpmailer;

		// A send callback called wp_mail(): a new instance keeps the email in progress intact,
		// and Transport_PHPMailer fails the new one. Not autoloaded, PHPMailer may not be loaded yet.
		$sending = class_exists( Transport_PHPMailer::class, false ) && Transport_PHPMailer::is_sending();
		if ( ! $sending && ( ! $this->has_send_provider() || $phpmailer instanceof Transport_PHPMailer ) ) {
			return $atts;
		}

		// Only replace the instance WordPress creates, never one set by another plugin.
		$current = is_object( $phpmailer ) ? get_class( $phpmailer ) : '';
		if ( ! in_array( $current, array( '', 'PHPMailer\\PHPMailer\\PHPMailer', 'WP_PHPMailer', Transport_PHPMailer::class ), true ) ) {
			return $atts;
		}

		// wp_mail() only loads these when it creates the instance itself.
		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

		$phpmailer = new Transport_PHPMailer( true ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- wp_mail() reuses this instance.

		// Same setup as wp_mail() gives its own instance.
		$phpmailer::$validator = static function ( $email ) {
			return (bool) is_email( $email );
		};

		// Translated PHPMailer errors, since WordPress 6.8. The strings are shared by every instance.
		if ( file_exists( ABSPATH . WPINC . '/class-wp-phpmailer.php' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-phpmailer.php';
			\WP_PHPMailer::setLanguage();
		}

		return $atts;
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
		if ( ! $this->settings->is_configured() ) {
			return $email;
		}

		$from_email = (string) $this->settings->get( 'from_email' );
		if ( '' === $from_email || ! is_email( $from_email ) ) {
			return $email;
		}

		if ( $this->settings->get( 'force_from' ) || $this->get_default_from_email() === $email ) {
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
		if ( ! $this->settings->is_configured() ) {
			return $name;
		}

		$from_name = (string) $this->settings->get( 'from_name' );
		if ( '' === $from_name ) {
			return $name;
		}

		if ( $this->settings->get( 'force_from' ) || 'WordPress' === $name ) {
			return $from_name;
		}

		return $name;
	}

	/**
	 * Returns whether a registered provider sends through a `send` callback.
	 *
	 * @since 1.3.0
	 *
	 * @return bool True when at least one provider has a `send` callback.
	 */
	private function has_send_provider(): bool {
		foreach ( $this->registry->get_all() as $provider ) {
			if ( null !== $provider['send'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the values of the password fields of the active provider.
	 *
	 * @since 1.3.0
	 *
	 * @param array $values Resolved field values.
	 * @return string[] Secret values.
	 */
	private function get_secrets( array $values ): array {
		$secrets = array();

		foreach ( $this->settings->get_fields() as $key => $field ) {
			if ( 'password' === $field['type'] && isset( $values[ $key ] ) ) {
				$secrets[] = (string) $values[ $key ];
			}
		}

		return $secrets;
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
