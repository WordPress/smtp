<?php
/**
 * Secrets API used by the tests.
 *
 * @package smtp
 */

namespace WordPress\SMTP\Test_Utils;

use WordPress\SMTP\Secrets_API;

/**
 * Keeps secrets in memory instead of calling the core functions.
 */
class Fake_Secrets_API extends Secrets_API {

	/**
	 * Whether the API is available.
	 *
	 * @var bool
	 */
	public $available = true;

	/**
	 * Whether the API accepts writes.
	 *
	 * @var bool
	 */
	public $writable = true;

	/**
	 * Stored secrets, keyed by name.
	 *
	 * @var string[]
	 */
	public $secrets = array();

	/**
	 * Names of the secrets that can not be read.
	 *
	 * @var string[]
	 */
	public $unreadable = array();

	/**
	 * Error returned by set(), if any.
	 *
	 * @var \WP_Error|null
	 */
	public $set_error;

	public function is_available(): bool {
		return $this->available;
	}

	public function is_writable(): bool {
		return $this->available && $this->writable;
	}

	public function get( string $name ) {
		if ( in_array( $name, $this->unreadable, true ) ) {
			return new \WP_Error( 'secret_decryption_failed', 'Can not decrypt.' );
		}

		return $this->secrets[ $name ] ?? null;
	}

	public function set( string $name, string $value ) {
		if ( $this->set_error ) {
			return $this->set_error;
		}

		$this->secrets[ $name ] = $value;

		return true;
	}

	public function delete( string $name ) {
		unset( $this->secrets[ $name ] );
	}
}
