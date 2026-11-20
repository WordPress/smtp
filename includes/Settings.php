<?php
/**
 * Class WordPress\SMTP\Settings
 *
 * @package smtp
 */

namespace WordPress\SMTP;

/**
 * Reads, resolves and sanitizes the plugin settings.
 *
 * Values defined through constants always win over saved values.
 *
 * @since 1.2.0
 */
class Settings {

	/**
	 * Name of the option that stores the settings.
	 *
	 * @since 1.2.0
	 */
	const OPTION = 'smtp_settings';

	/**
	 * Name of the option that keeps the settings of version 1, for one release after the upgrade.
	 *
	 * @since 1.3.0
	 */
	const V1_OPTION = 'smtp_settings_v1';

	/**
	 * Version of the settings format.
	 *
	 * @since 1.3.0
	 */
	const VERSION = 2;

	/**
	 * ID of the main connection. Constants apply to this connection only.
	 *
	 * @since 1.3.0
	 */
	const MAIN = 'main';

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
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param Provider_Registry $registry    Provider registry.
	 * @param Credentials       $credentials Credentials storage.
	 */
	public function __construct( Provider_Registry $registry, Credentials $credentials ) {
		$this->registry    = $registry;
		$this->credentials = $credentials;
	}

	/**
	 * Returns the slug of the active provider of a connection.
	 *
	 * @since 1.2.0
	 * @since 1.3.0 Added the `$connection` parameter.
	 *
	 * @param string $connection Connection ID. Defaults to the main connection.
	 * @return string Provider slug.
	 */
	public function get_provider_slug( string $connection = self::MAIN ): string {
		$stored = $this->get_stored();
		$saved  = $stored['connections'][ $connection ] ?? array();
		$slug   = isset( $saved['provider'] ) && is_string( $saved['provider'] ) ? $saved['provider'] : '';

		if ( '' !== $slug && null !== $this->registry->get( $slug ) ) {
			return $slug;
		}

		return $this->registry->get_default_slug();
	}

	/**
	 * Returns the normalized fields of a provider plus the general fields.
	 *
	 * @since 1.2.0
	 * @since 1.3.0 Added the `$connection` parameter.
	 *
	 * @param string $slug       Provider slug. Defaults to the active provider of the connection.
	 * @param string $connection Connection ID. Defaults to the main connection.
	 * @return array[] Fields keyed by field key.
	 */
	public function get_fields( string $slug = '', string $connection = self::MAIN ): array {
		if ( '' === $slug ) {
			$slug = $this->get_provider_slug( $connection );
		}

		$provider = $this->registry->get( $slug );
		$fields   = $provider ? $provider['fields'] : array();

		// Constants such as SMTP_HOST describe the main connection only.
		if ( self::MAIN !== $connection ) {
			foreach ( $fields as $key => $field ) {
				if ( is_array( $field ) ) {
					$fields[ $key ]['constant'] = '';
				}
			}
		}

		$fields = array_merge( $fields, self::get_general_fields() );

		foreach ( $fields as $key => $field ) {
			$fields[ $key ] = wp_parse_args(
				is_array( $field ) ? $field : array(),
				array(
					'label'       => $key,
					'type'        => 'text',
					'default'     => '',
					'options'     => array(),
					'description' => '',
					'constant'    => '',
					'max'         => 0,
				)
			);
		}

		return $fields;
	}

	/**
	 * Returns the resolved value of a field.
	 *
	 * General fields (From, Reply-To, etc.) are shared by every connection.
	 *
	 * @since 1.2.0
	 * @since 1.3.0 Added the `$connection` parameter.
	 *
	 * @param string $key        Field key.
	 * @param string $connection Connection ID. Defaults to the main connection.
	 * @return string|int|bool Field value, or an empty string for unknown fields.
	 */
	public function get( string $key, string $connection = self::MAIN ) {
		$fields = $this->get_fields( '', $connection );

		if ( ! isset( $fields[ $key ] ) ) {
			return '';
		}

		return $this->resolve( $key, $fields[ $key ], $connection );
	}

	/**
	 * Returns the active provider of a connection and the resolved values of its fields.
	 *
	 * @since 1.3.0
	 *
	 * @param string $id Connection ID.
	 * @return array|null Array with `provider` (slug) and `values` (keyed by field key), or null when
	 *                    the connection does not exist.
	 */
	public function get_connection( string $id ): ?array {
		$stored = $this->get_stored();

		if ( self::MAIN !== $id && ! isset( $stored['connections'][ $id ] ) ) {
			return null;
		}

		return array(
			'provider' => $this->get_provider_slug( $id ),
			'values'   => $this->get_provider_values( $id ),
		);
	}

	/**
	 * Returns the resolved values of the active provider fields of a connection.
	 *
	 * @since 1.2.0
	 * @since 1.3.0 Added the `$connection` parameter.
	 *
	 * @param string $connection Connection ID. Defaults to the main connection.
	 * @return array Values keyed by field key.
	 */
	public function get_provider_values( string $connection = self::MAIN ): array {
		$provider = $this->registry->get( $this->get_provider_slug( $connection ) );
		$fields   = $this->get_fields( '', $connection );
		$values   = array();

		if ( ! $provider ) {
			return $values;
		}

		foreach ( array_keys( $provider['fields'] ) as $key ) {
			$values[ $key ] = $this->resolve( $key, $fields[ $key ], $connection );
		}

		return $values;
	}

	/**
	 * Whether the active provider has everything it needs to send mail.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True when mail should be sent through the provider.
	 */
	public function is_configured(): bool {
		$provider = $this->registry->get( $this->get_provider_slug() );

		if ( ! $provider ) {
			return false;
		}

		$values = $this->get_provider_values();
		foreach ( $provider['required'] as $key ) {
			if ( empty( $values[ $key ] ) ) {
				return false;
			}
		}

		foreach ( $provider['required_if'] as $key => $condition ) {
			if ( ! empty( $values[ $condition ] ) && '' === ( $values[ $key ] ?? '' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the value of a field is defined by a constant.
	 *
	 * @since 1.2.0
	 *
	 * @param array $field Normalized field.
	 * @return bool True when locked by a constant.
	 */
	public function is_locked( array $field ): bool {
		return '' !== $field['constant'] && defined( $field['constant'] );
	}

	/**
	 * Sanitizes the submitted settings.
	 *
	 * Accepts the flat settings form, where the fields belong to the provider the form was shown for,
	 * or settings already in the stored format. Secrets are never written to the settings option, they
	 * go to the credentials storage.
	 *
	 * @since 1.2.0
	 * @since 1.3.0 Returns the version 2 format, and keeps the values of the other providers.
	 *
	 * @param mixed $input Submitted settings.
	 * @return array Sanitized settings.
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();

		// update_option() runs this callback again on the stored format.
		if ( self::is_v2( $input ) ) {
			$stored = self::to_v2( $input, $this->registry->get_default_slug() );

			foreach ( $stored['connections'] as $id => $connection ) {
				$id     = (string) $id;
				$active = $this->get_valid_slug( $connection['provider'] );
				$slugs  = array_unique( array_merge( array_keys( $connection['values'] ), array( $active ) ) );

				foreach ( $slugs as $slug ) {
					// Values of a provider that is not registered right now are kept for when it comes back.
					if ( null === $this->registry->get( (string) $slug ) ) {
						continue;
					}

					$values = $connection['values'][ $slug ] ?? array();
					$values = is_array( $values ) ? $values : array();
					$stored = $this->build( $stored, (string) $slug, $active, array_merge( $values, $stored['general'] ), $id );
				}
			}

			return $stored;
		}

		$current = $this->get_provider_slug();
		$slug    = isset( $input['provider'] ) && is_string( $input['provider'] ) ? $this->get_valid_slug( $input['provider'] ) : $current;

		// The form shows the fields of the current provider. A new provider shows its own after saving.
		return $this->build( $this->get_stored(), $current, $slug, $input );
	}

	/**
	 * Saves some of the settings and keeps the others.
	 *
	 * Settings::sanitize() expects a complete form and resets whatever is missing, so the values are
	 * merged over the current ones first. Locked fields and secrets that are not passed are left alone.
	 *
	 * @since 1.2.0
	 *
	 * @param array $values Values to change, keyed by field key. May include `provider`.
	 */
	public function update( array $values ) {
		$slug = isset( $values['provider'] ) && is_string( $values['provider'] ) && null !== $this->registry->get( $values['provider'] )
			? $values['provider']
			: $this->get_provider_slug();

		$current = array();

		foreach ( $this->get_fields( $slug ) as $key => $field ) {
			if ( 'password' !== $field['type'] && ! $this->is_locked( $field ) ) {
				$current[ $key ] = $this->resolve( $key, $field, self::MAIN, $slug );
			}
		}

		update_option( self::OPTION, $this->build( $this->get_stored(), $slug, $slug, array_merge( $current, $values ) ) );
	}

	/**
	 * Upgrades the settings of version 1 to version 2, once.
	 *
	 * The settings of version 1 are kept in Settings::V1_OPTION, and the secrets under their old
	 * keys, so the previous version can be restored by hand.
	 *
	 * @since 1.3.0
	 */
	public function maybe_upgrade() {
		$options = get_option( self::OPTION );

		if ( ! is_array( $options ) || self::is_v2( $options ) ) {
			return;
		}

		update_option( self::V1_OPTION, $options, false );

		// Version 1 keyed the secrets by field only. Every secret is copied, so providers that are not
		// registered yet keep theirs. Alert channels use their own `alert_` keys.
		foreach ( $this->credentials->get_keys() as $key ) {
			if ( 0 !== strpos( $key, 'alert_' ) ) {
				$this->credentials->set( self::get_secret_key( self::MAIN, $key ), $this->credentials->get( $key ) );
			}
		}

		update_option( self::OPTION, self::to_v2( $options, $this->registry->get_default_slug() ) );
	}

	/**
	 * Converts settings to the version 2 format.
	 *
	 * Version 1 stored one flat array with the active provider, its fields and the general fields.
	 * Version 2 stores the general fields once, and the values of each provider per connection.
	 *
	 * @since 1.3.0
	 *
	 * @param array  $options      Settings in the version 1 or version 2 format.
	 * @param string $default_slug Provider used when version 1 settings have none.
	 * @return array Settings in the version 2 format.
	 */
	public static function to_v2( array $options, string $default_slug = '' ): array {
		if ( ! self::is_v2( $options ) ) {
			// Version 1.2 had one `force_from` setting for the From email and name.
			if ( array_key_exists( 'force_from', $options ) ) {
				$force = ! empty( $options['force_from'] );
				unset( $options['force_from'] );

				// These sites keep the Return-Path chosen by their server.
				$options += array(
					'force_from_email' => $force,
					'force_from_name'  => $force,
					'return_path'      => false,
				);
			}

			$slug = isset( $options['provider'] ) && is_string( $options['provider'] ) && '' !== $options['provider'] ? $options['provider'] : $default_slug;
			unset( $options['provider'] );

			$general = array_intersect_key( $options, self::get_general_fields() );

			$options = array(
				'version'     => self::VERSION,
				'general'     => $general,
				'connections' => array(
					self::MAIN => array(
						'provider' => $slug,
						'values'   => array( $slug => array_diff_key( $options, $general ) ),
					),
				),
				'primary'     => self::MAIN,
			);
		}

		// Only the known keys are kept.
		$clean = array(
			'version'     => self::VERSION,
			'general'     => is_array( $options['general'] ?? null ) ? $options['general'] : array(),
			'connections' => array(),
			'primary'     => is_string( $options['primary'] ?? null ) && '' !== $options['primary'] ? $options['primary'] : self::MAIN,
		);

		$connections = is_array( $options['connections'] ?? null ) ? $options['connections'] : array();
		foreach ( $connections as $id => $connection ) {
			if ( ! is_string( $id ) || '' === $id || ! is_array( $connection ) ) {
				continue;
			}

			$clean['connections'][ $id ] = array(
				'provider' => is_string( $connection['provider'] ?? null ) ? $connection['provider'] : '',
				'values'   => is_array( $connection['values'] ?? null ) ? $connection['values'] : array(),
			);
		}

		if ( ! isset( $clean['connections'][ self::MAIN ] ) ) {
			$clean['connections'][ self::MAIN ] = array(
				'provider' => $default_slug,
				'values'   => array(),
			);
		}

		return $clean;
	}

	/**
	 * Whether settings are in the version 2 format.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $options Settings.
	 * @return bool True for the version 2 format.
	 */
	private static function is_v2( $options ): bool {
		// `wp option patch` saves numbers as strings.
		return is_array( $options ) && isset( $options['version'] ) && is_scalar( $options['version'] ) && self::VERSION === (int) $options['version'];
	}

	/**
	 * Returns the key under which a secret of a connection is stored.
	 *
	 * @since 1.3.0
	 *
	 * @param string $connection Connection ID.
	 * @param string $field_key  Field key.
	 * @return string Secret key.
	 */
	public static function get_secret_key( string $connection, string $field_key ): string {
		return $connection . '_' . $field_key;
	}

	/**
	 * Returns where the value of a field comes from.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key Field key.
	 * @return string One of `constant`, `database` or `default`. Empty for unknown fields.
	 */
	public function get_source( string $key ): string {
		$fields = $this->get_fields();

		if ( ! isset( $fields[ $key ] ) ) {
			return '';
		}

		if ( $this->is_locked( $fields[ $key ] ) ) {
			return 'constant';
		}

		if ( 'password' === $fields[ $key ]['type'] ) {
			return '' !== $this->credentials->get( self::get_secret_key( self::MAIN, $key ) ) ? 'database' : 'default';
		}

		return array_key_exists( $key, $this->get_saved_values( self::MAIN, $this->get_provider_slug() ) ) ? 'database' : 'default';
	}

	/**
	 * Returns the fields shared by every provider.
	 *
	 * @since 1.2.0
	 *
	 * @return array[] Fields keyed by field key.
	 */
	private static function get_general_fields(): array {
		return array(
			'from_email'       => array(
				'label'       => __( 'From email', 'smtp' ),
				'type'        => 'email',
				'constant'    => 'SMTP_FROM_EMAIL',
				'description' => __( 'Leave empty to keep the address chosen by WordPress or other plugins.', 'smtp' ),
			),
			'from_name'        => array(
				'label'    => __( 'From name', 'smtp' ),
				'type'     => 'text',
				'constant' => 'SMTP_FROM_NAME',
			),
			'force_from_email' => array(
				'label'       => __( 'Force From email', 'smtp' ),
				'type'        => 'checkbox',
				'default'     => false,
				'constant'    => 'SMTP_FORCE_FROM_EMAIL',
				'description' => __( 'Always use the From email above, even when another plugin sets its own.', 'smtp' ),
			),
			'force_from_name'  => array(
				'label'       => __( 'Force From name', 'smtp' ),
				'type'        => 'checkbox',
				'default'     => false,
				'constant'    => 'SMTP_FORCE_FROM_NAME',
				'description' => __( 'Always use the From name above, even when another plugin sets its own.', 'smtp' ),
			),
			'return_path'      => array(
				'label'       => __( 'Return-Path', 'smtp' ),
				'type'        => 'checkbox',
				'default'     => true,
				'constant'    => 'SMTP_RETURN_PATH',
				'description' => __( 'Use the From email as Return-Path, so bounces go to it and SPF checks match the From domain.', 'smtp' ),
			),
			'reply_to'         => array(
				'label'       => __( 'Default Reply-To', 'smtp' ),
				'type'        => 'email',
				'constant'    => 'SMTP_REPLY_TO',
				'description' => __( 'Used when an email has no Reply-To of its own. Leave empty to add none.', 'smtp' ),
			),
			'force_reply_to'   => array(
				'label'       => __( 'Force Reply-To', 'smtp' ),
				'type'        => 'checkbox',
				'default'     => false,
				'constant'    => 'SMTP_FORCE_REPLY_TO',
				'description' => __( 'Replace any Reply-To set by WordPress or other plugins with the default Reply-To above.', 'smtp' ),
			),
			'reply_to_sender'  => array(
				'label'       => __( 'Keep the sender as Reply-To', 'smtp' ),
				'type'        => 'checkbox',
				'default'     => false,
				'description' => __( 'When the From email is forced and the email has no Reply-To, use the original From email as Reply-To.', 'smtp' ),
			),
		);
	}

	/**
	 * Resolves the value of a field: constant, then secret or saved option, then default.
	 *
	 * @since 1.2.0
	 * @since 1.3.0 Added the `$connection` and `$slug` parameters.
	 *
	 * @param string $key        Field key.
	 * @param array  $field      Normalized field.
	 * @param string $connection Connection ID.
	 * @param string $slug       Provider slug. Defaults to the active provider of the connection.
	 * @return string|int|bool Field value.
	 */
	private function resolve( string $key, array $field, string $connection = self::MAIN, string $slug = '' ) {
		if ( $this->is_locked( $field ) ) {
			return $this->cast( constant( $field['constant'] ), $field );
		}

		if ( 'password' === $field['type'] ) {
			return $this->credentials->get( self::get_secret_key( $connection, $key ) );
		}

		$options = $this->get_saved_values( $connection, '' === $slug ? $this->get_provider_slug( $connection ) : $slug );
		if ( array_key_exists( $key, $options ) ) {
			return $this->cast( $options[ $key ], $field );
		}

		return $this->cast( $field['default'], $field );
	}

	/**
	 * Casts a value to the type of its field.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $value Raw value.
	 * @param array $field Normalized field.
	 * @return string|int|bool Cast value.
	 */
	private function cast( $value, array $field ) {
		switch ( $field['type'] ) {
			case 'checkbox':
				return (bool) $value;
			case 'number':
				return (int) $value;
			default:
				return is_scalar( $value ) ? (string) $value : '';
		}
	}

	/**
	 * Tells the admin that an invalid number was replaced by its default.
	 *
	 * The settings screen shows it after saving. Settings::sanitize() can run twice when the option
	 * is first created, so the same message is only added once.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key   Field key.
	 * @param array  $field Normalized field.
	 */
	private function report_reset( string $key, array $field ) {
		if ( ! function_exists( 'add_settings_error' ) ) {
			return;
		}

		$code = 'smtp_invalid_' . $key;
		foreach ( get_settings_errors( self::OPTION ) as $error ) {
			if ( $code === $error['code'] ) {
				return;
			}
		}

		$message = (int) $field['max'] > 0
			/* translators: 1: field label, 2: highest allowed number, 3: default value */
			? sprintf( __( '%1$s must be a number between 1 and %2$d, so it was reset to %3$s.', 'smtp' ), $field['label'], (int) $field['max'], (string) $field['default'] )
			/* translators: 1: field label, 2: default value */
			: sprintf( __( '%1$s must be a positive number, so it was reset to %2$s.', 'smtp' ), $field['label'], (string) $field['default'] );

		add_settings_error( self::OPTION, $code, $message );
	}

	/**
	 * Returns the saved general values and the saved values of a provider of a connection.
	 *
	 * @since 1.3.0
	 *
	 * @param string $connection Connection ID.
	 * @param string $slug       Provider slug.
	 * @return array Saved values keyed by field key.
	 */
	private function get_saved_values( string $connection, string $slug ): array {
		$stored = $this->get_stored();
		$values = $stored['connections'][ $connection ]['values'][ $slug ] ?? array();

		return array_merge( is_array( $values ) ? $values : array(), $stored['general'] );
	}

	/**
	 * Returns the saved settings in the version 2 format.
	 *
	 * Settings that were not upgraded yet are converted on the fly.
	 *
	 * @since 1.3.0
	 *
	 * @return array Saved settings.
	 */
	private function get_stored(): array {
		$options = get_option( self::OPTION, array() );

		return self::to_v2( is_array( $options ) ? $options : array(), $this->registry->get_default_slug() );
	}

	/**
	 * Returns the slug when it is a registered provider, or the default provider.
	 *
	 * @since 1.3.0
	 *
	 * @param string $slug Provider slug.
	 * @return string Provider slug.
	 */
	private function get_valid_slug( string $slug ): string {
		return null !== $this->registry->get( $slug ) ? $slug : $this->registry->get_default_slug();
	}

	/**
	 * Stores sanitized values of a provider of a connection, and the general values.
	 *
	 * The values replace the saved values of that provider. The values of the other providers stay.
	 *
	 * @since 1.3.0
	 *
	 * @param array  $stored      Settings in the version 2 format.
	 * @param string $values_slug Provider the values belong to.
	 * @param string $active_slug Provider to make active.
	 * @param array  $input       Raw values keyed by field key, general fields included.
	 * @param string $connection  Connection ID.
	 * @return array Settings in the version 2 format.
	 */
	private function build( array $stored, string $values_slug, string $active_slug, array $input, string $connection = self::MAIN ): array {
		$clean   = $this->sanitize_fields( $input, $this->get_fields( $values_slug, $connection ), $connection );
		$general = array_intersect_key( $clean, self::get_general_fields() );

		$stored['general'] = $general;

		$stored['connections'][ $connection ]['provider']               = $active_slug;
		$stored['connections'][ $connection ]['values'][ $values_slug ] = array_diff_key( $clean, $general );

		return $stored;
	}

	/**
	 * Sanitizes raw values against their fields. Locked fields are skipped.
	 *
	 * @since 1.3.0
	 *
	 * @param array   $input      Raw values keyed by field key.
	 * @param array[] $fields     Normalized fields.
	 * @param string  $connection Connection ID, for the secrets.
	 * @return array Sanitized values.
	 */
	private function sanitize_fields( array $input, array $fields, string $connection = self::MAIN ): array {
		$clean = array();

		foreach ( $fields as $key => $field ) {
			if ( $this->is_locked( $field ) ) {
				continue;
			}

			$raw = $input[ $key ] ?? null;

			switch ( $field['type'] ) {
				case 'checkbox':
					$clean[ $key ] = ! empty( $raw );
					break;

				case 'password':
					// An empty submission keeps the current secret.
					if ( is_string( $raw ) && '' !== $raw ) {
						$this->credentials->set( self::get_secret_key( $connection, $key ), $raw );
					}
					break;

				case 'select':
					$clean[ $key ] = is_string( $raw ) && isset( $field['options'][ $raw ] ) ? $raw : $field['default'];
					break;

				case 'number':
					$number = absint( $raw );
					$max    = (int) $field['max'];
					$valid  = $number > 0 && ( $max <= 0 || $number <= $max );

					$clean[ $key ] = $valid ? $number : $field['default'];

					if ( ! $valid && null !== $raw && '' !== $raw ) {
						$this->report_reset( $key, $field );
					}
					break;

				case 'email':
					$clean[ $key ] = is_string( $raw ) ? sanitize_email( $raw ) : '';
					break;

				default:
					$clean[ $key ] = is_string( $raw ) ? sanitize_text_field( $raw ) : '';
			}
		}

		return $clean;
	}
}
