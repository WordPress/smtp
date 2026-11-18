<?php
/**
 * Class WordPress\SMTP\Secrets_API
 *
 * @package smtp
 */

namespace WordPress\SMTP;

/**
 * Thin wrapper around the Secrets API added in WordPress 7.2.
 *
 * Only Credentials uses it. The wrapper keeps the core functions in one place and lets the
 * tests replace them, since global functions can not be stubbed on sites that already have them.
 *
 * @since 1.3.0
 */
class Secrets_API {

	/**
	 * Whether stored secrets can be read.
	 *
	 * The functions can exist without a usable `WP_SECRETS_KEY`, so their presence is not enough.
	 *
	 * @since 1.3.0
	 *
	 * @return bool True when the API is loaded and has a usable key.
	 */
	public function is_available(): bool {
		return function_exists( 'wp_is_secrets_api_available' ) && wp_is_secrets_api_available();
	}

	/**
	 * Whether new secrets can be stored.
	 *
	 * Hosts can provide secrets through a read-only provider, where `wp_set_secret()` always fails.
	 *
	 * @since 1.3.0
	 *
	 * @return bool True when the API is available and accepts writes.
	 */
	public function is_writable(): bool {
		return $this->is_available() && wp_secrets_provider_is_writable();
	}

	/**
	 * Returns the value of a secret.
	 *
	 * @since 1.3.0
	 *
	 * @param string $name Secret name, `plugin-slug/secret-name`.
	 * @return string|null|\WP_Error The value, null when the secret was never stored, or an error
	 *                               when it exists but can not be read.
	 */
	public function get( string $name ) {
		$secret = wp_get_secret( $name );

		if ( null === $secret || is_wp_error( $secret ) ) {
			return $secret;
		}

		if ( $secret instanceof \WP_Secret ) {
			return $secret->reveal();
		}

		return new \WP_Error( 'smtp_secret_invalid', __( 'The Secrets API returned an unexpected value.', 'smtp' ) );
	}

	/**
	 * Stores a secret.
	 *
	 * @since 1.3.0
	 *
	 * @param string $name  Secret name, `plugin-slug/secret-name`.
	 * @param string $value Secret value.
	 * @return true|\WP_Error True on success, or the reason it failed.
	 */
	public function set( string $name, string $value ) {
		$result = wp_set_secret( $name, $value );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result ) {
			return new \WP_Error( 'smtp_secret_not_saved', __( 'The Secrets API could not save the value.', 'smtp' ) );
		}

		return true;
	}
}
