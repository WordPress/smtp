<?php
/**
 * Class WordPress\SMTP\Rate_Limits
 *
 * @package smtp
 */

namespace WordPress\SMTP;

/**
 * Resolves the sending limits of a provider.
 *
 * The limits are configured values, not a guarantee of the remaining capacity: the account may be shared
 * with other applications. This class only reports them, it never throttles or queues email.
 *
 * Limits are an array with these optional keys. A missing key means there is no known limit.
 *
 * - `per_second`, `per_minute`, `per_hour`, `per_day` (int): maximum number of messages in that interval.
 * - `concurrency` (int): maximum number of simultaneous connections or sending operations.
 * - `recipients` (array): the same interval keys, for providers that count recipients instead of messages.
 *
 * A limit resolves in this order: the default declared by the provider (`rate_limits`), the value saved by
 * the administrator for the active provider of the connection, then the `smtp_rate_limits` filter. An instance
 * reports the limits of the connection its settings belong to.
 *
 * @since 1.2.0
 */
class Rate_Limits {

	/**
	 * Interval keys, from the shortest to the longest.
	 *
	 * @since 1.2.0
	 */
	const INTERVALS = array( 'per_second', 'per_minute', 'per_hour', 'per_day' );

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
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param Settings          $settings Settings.
	 * @param Provider_Registry $registry Provider registry.
	 */
	public function __construct( Settings $settings, Provider_Registry $registry ) {
		$this->settings = $settings;
		$this->registry = $registry;
	}

	/**
	 * Returns the sending limits of a provider.
	 *
	 * @since 1.2.0
	 *
	 * @param string $slug Provider slug. Defaults to the active provider.
	 * @return array Limits, see the class description. Empty when none is known.
	 */
	public function get( string $slug = '' ): array {
		$active = $this->settings->get_provider_slug();
		$slug   = '' === $slug ? $active : $slug;

		$provider = $this->registry->get( $slug );
		$limits   = $provider ? $provider['rate_limits'] : array();

		// Saved values belong to the active provider only.
		if ( $slug === $active ) {
			foreach ( self::get_overridable_keys() as $key ) {
				$value = (int) $this->settings->get( self::get_field_key( $key ) );
				if ( $value > 0 ) {
					$limits[ $key ] = $value;
				}
			}
		}

		/**
		 * Filters the sending limits of a provider.
		 *
		 * Use it to set limits that depend on the account or the plan. The values are configured limits, not
		 * the remaining capacity, since the account may be shared with other applications.
		 *
		 * @since 1.2.0
		 *
		 * @param array  $limits   Limits: `per_second`, `per_minute`, `per_hour`, `per_day` and `concurrency`
		 *                         for messages, and `recipients` with the interval keys for recipients. Keys
		 *                         without a limit are missing.
		 * @param string $provider   Provider slug.
		 * @param string $connection ID of the connection the limits are for, `default` for the default one.
		 */
		$limits = apply_filters( 'smtp_rate_limits', $limits, $slug, $this->settings->get_connection_id() );

		return self::normalize( $limits );
	}

	/**
	 * Cleans up limits declared by a provider or returned by a filter.
	 *
	 * Only known keys with a positive whole number are kept, in a stable order.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $limits Raw limits.
	 * @return array Normalized limits.
	 */
	public static function normalize( $limits ): array {
		if ( ! is_array( $limits ) ) {
			return array();
		}

		$clean = array();

		foreach ( self::get_overridable_keys() as $key ) {
			$value = self::to_limit( $limits[ $key ] ?? null );
			if ( $value > 0 ) {
				$clean[ $key ] = $value;
			}
		}

		$recipients = array();
		if ( isset( $limits['recipients'] ) && is_array( $limits['recipients'] ) ) {
			foreach ( self::INTERVALS as $key ) {
				$value = self::to_limit( $limits['recipients'][ $key ] ?? null );
				if ( $value > 0 ) {
					$recipients[ $key ] = $value;
				}
			}
		}

		if ( $recipients ) {
			$clean['recipients'] = $recipients;
		}

		return $clean;
	}

	/**
	 * Returns the settings fields that let an administrator override the message limits.
	 *
	 * Recipient limits can only be set by the provider or the `smtp_rate_limits` filter.
	 *
	 * @since 1.2.0
	 *
	 * @return array[] Fields keyed by field key.
	 */
	public static function get_override_fields(): array {
		$labels = array(
			'per_second'  => __( 'Emails per second', 'smtp' ),
			'per_minute'  => __( 'Emails per minute', 'smtp' ),
			'per_hour'    => __( 'Emails per hour', 'smtp' ),
			'per_day'     => __( 'Emails per day', 'smtp' ),
			'concurrency' => __( 'Simultaneous connections', 'smtp' ),
		);

		$fields = array();
		foreach ( self::get_overridable_keys() as $key ) {
			$fields[ self::get_field_key( $key ) ] = array(
				'label'   => $labels[ $key ],
				'type'    => 'number',
				'default' => '',
				'section' => 'limits',
			);
		}

		return $fields;
	}

	/**
	 * Returns the limit keys an administrator can override.
	 *
	 * @since 1.2.0
	 *
	 * @return string[] Limit keys.
	 */
	private static function get_overridable_keys(): array {
		return array_merge( self::INTERVALS, array( 'concurrency' ) );
	}

	/**
	 * Returns the settings field key of a limit.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key Limit key.
	 * @return string Field key.
	 */
	private static function get_field_key( string $key ): string {
		return 'rate_limit_' . $key;
	}

	/**
	 * Casts a raw limit to a whole number.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $value Raw value.
	 * @return int The limit, or 0 when it is not a positive whole number.
	 */
	private static function to_limit( $value ): int {
		if ( is_int( $value ) ) {
			return max( 0, $value );
		}

		if ( is_string( $value ) && ctype_digit( $value ) ) {
			return (int) $value;
		}

		return 0;
	}
}
