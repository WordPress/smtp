<?php
/**
 * Class WordPress\SMTP\Credentials
 *
 * @package smtp
 */

namespace WordPress\SMTP;

/**
 * Reads and writes secret values (passwords, API keys).
 *
 * All access to secrets goes through this class so the storage can be replaced
 * (for example by the core credentials API) without touching the rest of the plugin.
 *
 * @since 1.2.0
 */
class Credentials {

	/**
	 * Name of the option that stores the secrets.
	 *
	 * @since 1.2.0
	 */
	const OPTION = 'smtp_credentials';

	/**
	 * Returns a stored secret.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key Secret key.
	 * @return string Secret value, or an empty string when not set.
	 */
	public function get( string $key ): string {
		$secrets = get_option( self::OPTION, array() );

		if ( ! is_array( $secrets ) || ! isset( $secrets[ $key ] ) || ! is_string( $secrets[ $key ] ) ) {
			return '';
		}

		return $secrets[ $key ];
	}

	/**
	 * Stores a secret.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key   Secret key.
	 * @param string $value Secret value.
	 */
	public function set( string $key, string $value ) {
		$secrets = get_option( self::OPTION, array() );
		if ( ! is_array( $secrets ) ) {
			$secrets = array();
		}

		$secrets[ $key ] = $value;

		// Not autoloaded: secrets are only needed when sending mail or saving settings.
		update_option( self::OPTION, $secrets, false );
	}

	/**
	 * Deletes every secret whose key starts with a prefix.
	 *
	 * @since 1.3.0
	 *
	 * @param string $prefix Key prefix. An empty prefix deletes nothing.
	 */
	public function delete_prefixed( string $prefix ) {
		if ( '' === $prefix ) {
			return;
		}

		$secrets = get_option( self::OPTION, array() );
		if ( ! is_array( $secrets ) ) {
			return;
		}

		foreach ( array_keys( $secrets ) as $key ) {
			if ( 0 === strpos( (string) $key, $prefix ) ) {
				unset( $secrets[ $key ] );
			}
		}

		update_option( self::OPTION, $secrets, false );
	}

	/**
	 * Removes a stored secret. The option is deleted once it holds no secret.
	 *
	 * @since 1.3.0
	 *
	 * @param string $key Secret key.
	 */
	public function delete( string $key ) {
		$secrets = get_option( self::OPTION, array() );

		if ( ! is_array( $secrets ) || ! array_key_exists( $key, $secrets ) ) {
			return;
		}

		unset( $secrets[ $key ] );

		if ( empty( $secrets ) ) {
			delete_option( self::OPTION );

			return;
		}

		update_option( self::OPTION, $secrets, false );
	}
}
