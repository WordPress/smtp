<?php
/**
 * Trait WordPress\SMTP\Test_Utils\Traits\With_Settings
 *
 * @package smtp
 */

namespace WordPress\SMTP\Test_Utils\Traits;

use WordPress\SMTP\Settings;

trait With_Settings {

	/**
	 * Returns the active provider of a connection, its values and the general values, as one flat array.
	 */
	protected function flatten_settings( $stored, string $connection = Settings::MAIN ): array {
		$stored = Settings::to_v2( is_array( $stored ) ? $stored : array(), 'smtp' );
		$slug   = $stored['connections'][ $connection ]['provider'] ?? '';
		$values = $stored['connections'][ $connection ]['values'][ $slug ] ?? array();

		return array_merge( array( 'provider' => $slug ), $values, $stored['general'] );
	}

	/**
	 * Returns the saved settings of the main connection as one flat array.
	 */
	protected function get_saved_settings(): array {
		return $this->flatten_settings( get_option( Settings::OPTION, array() ) );
	}

	/**
	 * Returns the key of a secret of the main connection.
	 */
	protected function main_secret( string $field ): string {
		return Settings::get_secret_key( Settings::MAIN, $field );
	}
}
