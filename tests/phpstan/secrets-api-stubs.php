<?php
/**
 * Stubs of the WordPress 7.2 Secrets API, for PHPStan only.
 *
 * The WordPress stubs do not include it yet. Remove this file once they do.
 *
 * @package smtp
 */

// phpcs:disable

class WP_Secret {
	public function reveal(): string {}
}

/** @return bool */
function wp_is_secrets_api_available() {}

/** @return bool */
function wp_secrets_provider_is_writable() {}

/** @return WP_Secret|null|WP_Error */
function wp_get_secret( string $name ) {}

/** @return bool|WP_Error */
function wp_set_secret( string $name, string $value ) {}

/** @return bool|WP_Error */
function wp_delete_secret( string $name ) {}
