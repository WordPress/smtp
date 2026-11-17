<?php
/**
 * Class WordPress\SMTP\Connections
 *
 * @package smtp
 */

namespace WordPress\SMTP;

/**
 * Stores the SMTP connections and picks the one an email is sent through.
 *
 * A connection is a provider with its own settings, credentials, From address and sending limits.
 * The settings saved under Tools > SMTP are the **default connection**, so sites that never add a
 * connection keep working unchanged. Other connections are stored in the `smtp_connections` option,
 * keyed by ID, and their secrets go to {@see Credentials} under `connection:{id}:{field}`.
 *
 * @since 1.2.0
 */
class Connections {

	/**
	 * ID of the default connection, the settings saved under Tools > SMTP.
	 *
	 * @since 1.2.0
	 */
	const DEFAULT_ID = 'default';

	/**
	 * Name of the option that stores the other connections.
	 *
	 * @since 1.2.0
	 */
	const OPTION = 'smtp_connections';

	/**
	 * Maximum length of a connection ID, the size of the log column.
	 *
	 * @since 1.2.0
	 */
	const MAX_ID_LENGTH = 50;

	/**
	 * Provider registry.
	 *
	 * @since 1.2.0
	 * @var Provider_Registry
	 */
	private $registry;

	/**
	 * Credentials storage.
	 *
	 * @since 1.2.0
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Settings of the default connection.
	 *
	 * @since 1.2.0
	 * @var Settings
	 */
	private $default;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param Provider_Registry $registry    Provider registry.
	 * @param Credentials       $credentials Credentials storage.
	 * @param Settings          $default_settings Settings of the default connection.
	 */
	public function __construct( Provider_Registry $registry, Credentials $credentials, Settings $default_settings ) {
		$this->registry    = $registry;
		$this->credentials = $credentials;
		$this->default     = $default_settings;
	}

	/**
	 * Returns the stored connections, without the default one.
	 *
	 * @since 1.2.0
	 *
	 * @return array[] Entries with `label` and `settings`, keyed by connection ID.
	 */
	public static function get_stored(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$connections = array();
		foreach ( $stored as $id => $connection ) {
			if ( ! is_string( $id ) || self::DEFAULT_ID === $id || ! is_array( $connection ) ) {
				continue;
			}

			$connections[ $id ] = array(
				'label'    => isset( $connection['label'] ) && is_string( $connection['label'] ) ? $connection['label'] : $id,
				'settings' => isset( $connection['settings'] ) && is_array( $connection['settings'] ) ? $connection['settings'] : array(),
			);
		}

		return $connections;
	}

	/**
	 * Returns the saved settings of a connection.
	 *
	 * @since 1.2.0
	 *
	 * @param string $id Connection ID.
	 * @return array Saved settings, empty when the connection does not exist.
	 */
	public static function get_stored_settings( string $id ): array {
		$stored = self::get_stored();

		return $stored[ $id ]['settings'] ?? array();
	}

	/**
	 * Saves the settings of an existing connection, keeping its name.
	 *
	 * @since 1.2.0
	 *
	 * @param string $id       Connection ID.
	 * @param array  $settings Sanitized settings.
	 */
	public static function store_settings( string $id, array $settings ) {
		$stored = self::get_stored();

		$stored[ $id ] = array(
			'label'    => $stored[ $id ]['label'] ?? $id,
			'settings' => $settings,
		);

		// Not autoloaded: the connections are only needed when sending mail or on the admin screen.
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Returns the prefix of the credentials keys of a connection.
	 *
	 * @since 1.2.0
	 *
	 * @param string $id Connection ID.
	 * @return string Prefix.
	 */
	public static function get_secret_prefix( string $id ): string {
		// The colon can not be part of an ID, so the prefix of an ID never matches the secrets of another one.
		return 'connection:' . $id . ':';
	}

	/**
	 * Cleans up a connection ID: lowercase letters, numbers, dashes and underscores.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $id Raw ID.
	 * @return string Clean ID, empty when nothing is left or when it is the reserved default ID.
	 */
	public static function sanitize_id( $id ): string {
		$id = is_string( $id ) ? substr( sanitize_key( $id ), 0, self::MAX_ID_LENGTH ) : '';

		return self::DEFAULT_ID === $id ? '' : $id;
	}

	/**
	 * Generates the ID of a new connection from its name.
	 *
	 * The name goes through sanitize_title(). When the result is the reserved default ID or is already
	 * taken, a number is appended: `sponsors`, `sponsors-2`, `sponsors-3`...
	 *
	 * @since 1.2.0
	 *
	 * @param string $label Connection name.
	 * @return string Unique ID, empty when nothing is left of the name.
	 */
	public function generate_id( string $label ): string {
		$base = trim( substr( sanitize_title( $label ), 0, self::MAX_ID_LENGTH ), '-' );

		if ( '' === $base ) {
			return '';
		}

		$id     = $base;
		$number = 2;

		while ( self::DEFAULT_ID === $id || $this->exists( $id ) ) {
			$suffix = '-' . $number;
			$id     = substr( $base, 0, self::MAX_ID_LENGTH - strlen( $suffix ) ) . $suffix;
			++$number;
		}

		return $id;
	}

	/**
	 * Returns the IDs of every connection, the default one first.
	 *
	 * @since 1.2.0
	 *
	 * @return string[] Connection IDs.
	 */
	public function get_ids(): array {
		return array_merge( array( self::DEFAULT_ID ), array_keys( self::get_stored() ) );
	}

	/**
	 * Whether there are connections besides the default one.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True when at least one more connection is stored.
	 */
	public function has_extra(): bool {
		return array() !== self::get_stored();
	}

	/**
	 * Whether a connection exists.
	 *
	 * @since 1.2.0
	 *
	 * @param string $id Connection ID.
	 * @return bool True for the default connection and the stored ones.
	 */
	public function exists( string $id ): bool {
		return self::DEFAULT_ID === $id || isset( self::get_stored()[ $id ] );
	}

	/**
	 * Returns the name of a connection.
	 *
	 * @since 1.2.0
	 *
	 * @param string $id Connection ID.
	 * @return string Name, or the ID when the connection does not exist any more.
	 */
	public function get_label( string $id ): string {
		if ( self::DEFAULT_ID === $id ) {
			return __( 'Default', 'smtp' );
		}

		return self::get_stored()[ $id ]['label'] ?? $id;
	}

	/**
	 * Returns the settings of a connection.
	 *
	 * @since 1.2.0
	 *
	 * @param string $id Connection ID. Empty for the default connection.
	 * @return Settings|null Settings, or null when the connection does not exist.
	 */
	public function get_settings( string $id = '' ): ?Settings {
		if ( '' === $id || self::DEFAULT_ID === $id ) {
			return $this->default;
		}

		if ( ! isset( self::get_stored()[ $id ] ) ) {
			return null;
		}

		return new Settings( $this->registry, $this->credentials, $id );
	}

	/**
	 * Describes the connections for other plugins, without any credential.
	 *
	 * @since 1.2.0
	 *
	 * @return array[] Entries keyed by connection ID, each with `label`, `provider` (slug),
	 *                 `configured` (bool) and `rate_limits` (see {@see Rate_Limits}).
	 */
	public function describe(): array {
		$connections = array();

		foreach ( $this->get_ids() as $id ) {
			$settings = $this->get_settings( $id );
			if ( ! $settings ) {
				continue;
			}

			$connections[ $id ] = array(
				'label'       => $this->get_label( $id ),
				'provider'    => $settings->get_provider_slug(),
				'configured'  => $settings->is_configured(),
				'rate_limits' => ( new Rate_Limits( $settings, $this->registry ) )->get(),
			);
		}

		return $connections;
	}

	/**
	 * Picks the connection an email is sent through.
	 *
	 * An unknown or incomplete connection falls back to the default one, so the email is still sent.
	 *
	 * @since 1.2.0
	 *
	 * @param array $mail_data Email data: `to`, `subject`, `message`, `headers` and `attachments`.
	 * @return string Connection ID.
	 */
	public function resolve( array $mail_data ): string {
		/**
		 * Filters the SMTP connection an email is sent through.
		 *
		 * Return the ID of one of the connections listed under Tools > SMTP, see `wp_smtp_get_connections()`.
		 * An unknown connection, or one that is not fully configured, falls back to the default connection.
		 *
		 * @since 1.2.0
		 *
		 * @param string $connection_id Connection ID. Default `default`.
		 * @param array  $mail_data     Email data: `to`, `subject`, `message`, `headers` and `attachments`.
		 */
		$id = apply_filters( 'smtp_connection_for_email', self::DEFAULT_ID, $mail_data );

		if ( ! is_string( $id ) || self::DEFAULT_ID === $id ) {
			return self::DEFAULT_ID;
		}

		$settings = $this->get_settings( $id );

		return $settings && $settings->is_configured() ? $id : self::DEFAULT_ID;
	}

	/**
	 * Creates or updates a connection.
	 *
	 * @since 1.2.0
	 *
	 * @param string $id    Connection ID. Cleaned up with {@see Connections::sanitize_id()}.
	 * @param string $label Connection name.
	 * @param array  $input Submitted settings, like the ones of the Settings tab.
	 * @return string|\WP_Error The ID of the saved connection, or an error when the ID is not valid.
	 */
	public function save( string $id, string $label, array $input ) {
		$clean_id = self::sanitize_id( $id );

		if ( '' === $clean_id ) {
			return new \WP_Error(
				'smtp_invalid_connection_id',
				__( 'Enter an ID with letters, numbers, dashes or underscores. "default" is reserved.', 'smtp' )
			);
		}

		$label = sanitize_text_field( $label );

		$stored = self::get_stored();

		// The settings must exist before they are sanitized, since secrets are saved on the way.
		$stored[ $clean_id ] = array(
			'label'    => '' === $label ? $clean_id : $label,
			'settings' => $stored[ $clean_id ]['settings'] ?? array(),
		);
		update_option( self::OPTION, $stored, false );

		$settings = new Settings( $this->registry, $this->credentials, $clean_id );
		self::store_settings( $clean_id, $settings->sanitize( $input ) );

		return $clean_id;
	}

	/**
	 * Deletes a connection and its secrets.
	 *
	 * @since 1.2.0
	 *
	 * @param string $id Connection ID. The default connection can not be deleted.
	 * @return bool True when a connection was deleted.
	 */
	public function delete( string $id ): bool {
		$stored = self::get_stored();

		if ( ! isset( $stored[ $id ] ) ) {
			return false;
		}

		unset( $stored[ $id ] );
		update_option( self::OPTION, $stored, false );

		$this->credentials->delete_prefixed( self::get_secret_prefix( $id ) );

		return true;
	}
}
