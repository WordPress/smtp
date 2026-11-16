<?php
/**
 * Class WordPress\SMTP\Credentials
 *
 * @package smtp
 */

namespace WordPress\SMTP;

use WordPress\SMTP\Admin\Admin_Page;

/**
 * Reads and writes secret values (passwords, API keys).
 *
 * All access to secrets goes through this class so the storage can be replaced without touching
 * the rest of the plugin. Secrets are stored with the WordPress Secrets API when it is available,
 * and in the `smtp_credentials` option otherwise.
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
	 * Name of the option that lists the keys stored with the Secrets API.
	 *
	 * @since 1.3.0
	 */
	const KEYS_OPTION = 'smtp_secret_keys';

	/**
	 * Prefix of the secret names, `plugin-slug/`.
	 *
	 * @since 1.3.0
	 */
	const SECRET_PREFIX = 'smtp/';

	/**
	 * Storage reported by get_storage() when the Secrets API is used.
	 *
	 * @since 1.3.0
	 */
	const STORAGE_SECRETS = 'secrets';

	/**
	 * Storage reported by get_storage() when the option is used.
	 *
	 * @since 1.3.0
	 */
	const STORAGE_OPTION = 'option';

	/**
	 * Secrets API.
	 *
	 * @since 1.3.0
	 * @var Secrets_API
	 */
	private $secrets;

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
	 *
	 * @param Secrets_API|null $secrets Secrets API. Defaults to the one of WordPress core.
	 */
	public function __construct( ?Secrets_API $secrets = null ) {
		$this->secrets = $secrets ?? new Secrets_API();
	}

	/**
	 * Registers the hooks.
	 *
	 * @since 1.3.0
	 */
	public function add_hooks() {
		// Reads fall back to the option until then, so there is no need to run it on every request.
		add_action( 'admin_init', array( $this, 'migrate' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Returns a stored secret.
	 *
	 * A secret that is stored with the Secrets API but can not be read is never replaced by a value
	 * from the option: it returns an empty string, so the provider is treated as not configured.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key Secret key.
	 * @return string Secret value, or an empty string when not set or not readable.
	 */
	public function get( string $key ): string {
		$value = $this->read( $key );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Stores a secret.
	 *
	 * When the Secrets API is available and the write fails, nothing is stored: the value is never
	 * written in plain text instead.
	 *
	 * @since 1.2.0
	 * @since 1.3.0 Returns the result.
	 *
	 * @param string $key   Secret key.
	 * @param string $value Secret value.
	 * @return true|\WP_Error True on success, or the reason the secret could not be stored.
	 */
	public function set( string $key, string $value ) {
		if ( $this->secrets->is_writable() ) {
			$result = $this->secrets->set( self::SECRET_PREFIX . $key, $value );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$this->set_migrated( $key, true );
			$this->remove_from_option( array( $key ) );

			return true;
		}

		$secrets = $this->get_option();

		$secrets[ $key ] = $value;

		// Not autoloaded: secrets are only needed when sending mail or saving settings.
		update_option( self::OPTION, $secrets, false );

		// The Secrets API is gone and the value was entered again, so the option is the storage now.
		$this->set_migrated( $key, false );

		return true;
	}

	/**
	 * Moves the secrets stored in the option to the Secrets API.
	 *
	 * Each value is read back before it is removed from the option. Values that could not be moved
	 * stay in the option and are tried again on the next run.
	 *
	 * @since 1.3.0
	 *
	 * @return int Number of secrets moved.
	 */
	public function migrate(): int {
		if ( ! $this->secrets->is_writable() ) {
			return 0;
		}

		$moved = array();

		foreach ( $this->get_option() as $key => $value ) {
			if ( ! is_string( $key ) || ! is_string( $value ) ) {
				continue;
			}

			$name = self::SECRET_PREFIX . $key;
			if ( is_wp_error( $this->secrets->set( $name, $value ) ) || $this->secrets->get( $name ) !== $value ) {
				continue;
			}

			$this->set_migrated( $key, true );
			$moved[] = $key;
		}

		$this->remove_from_option( $moved );

		return count( $moved );
	}

	/**
	 * Returns the storage used for new secrets.
	 *
	 * @since 1.3.0
	 *
	 * @return string Credentials::STORAGE_SECRETS or Credentials::STORAGE_OPTION.
	 */
	public function get_storage(): string {
		return $this->secrets->is_writable() ? self::STORAGE_SECRETS : self::STORAGE_OPTION;
	}

	/**
	 * Whether a secret stored with the Secrets API can not be read.
	 *
	 * This happens when the key material changed, or when the API is not available any more, for
	 * example after the feature plugin was deactivated.
	 *
	 * @since 1.3.0
	 *
	 * @return bool True when at least one secret can not be read.
	 */
	public function has_unreadable(): bool {
		foreach ( $this->get_migrated_keys() as $key ) {
			if ( is_wp_error( $this->read( $key ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Shows a notice when stored secrets can not be read.
	 *
	 * @since 1.3.0
	 */
	public function render_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! $this->has_unreadable() ) {
			return;
		}

		?>
		<div class="notice notice-error">
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: 1: WP_SECRETS_KEY, 2: wp-config.php, 3: URL of the SMTP settings page */
						__( 'The SMTP plugin can not read the passwords it stored with the WordPress Secrets API, so it does not use them. Check that %1$s in %2$s has not changed and that the Secrets API is still available. If the passwords are lost, <a href="%3$s">enter them again</a>.', 'smtp' ),
						'<code>WP_SECRETS_KEY</code>',
						'<code>wp-config.php</code>',
						esc_url( Admin_Page::get_url( 'settings' ) )
					),
					array(
						'a'    => array( 'href' => array() ),
						'code' => array(),
					)
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Reads a secret from the storage it was saved in.
	 *
	 * @since 1.3.0
	 *
	 * @param string $key Secret key.
	 * @return string|null|\WP_Error The value, null when not set, or an error when it can not be read.
	 */
	private function read( string $key ) {
		if ( in_array( $key, $this->get_migrated_keys(), true ) ) {
			if ( ! $this->secrets->is_available() ) {
				return new \WP_Error( 'smtp_secrets_unavailable', __( 'The Secrets API is not available.', 'smtp' ) );
			}

			$value = $this->secrets->get( self::SECRET_PREFIX . $key );

			// It was stored, so a missing secret was deleted outside the plugin.
			return null === $value ? new \WP_Error( 'smtp_secret_missing', __( 'The secret is missing from the Secrets API.', 'smtp' ) ) : $value;
		}

		$secrets = $this->get_option();
		if ( isset( $secrets[ $key ] ) && is_string( $secrets[ $key ] ) ) {
			return $secrets[ $key ];
		}

		// Also covers secrets provided by the host, and a key list overwritten by a concurrent save.
		return $this->secrets->is_available() ? $this->secrets->get( self::SECRET_PREFIX . $key ) : null;
	}

	/**
	 * Returns the secrets stored in the option.
	 *
	 * @since 1.3.0
	 *
	 * @return array Secrets keyed by key.
	 */
	private function get_option(): array {
		$secrets = get_option( self::OPTION, array() );

		return is_array( $secrets ) ? $secrets : array();
	}

	/**
	 * Removes secrets from the option, and the option itself once it is empty.
	 *
	 * @since 1.3.0
	 *
	 * @param string[] $keys Secret keys.
	 */
	private function remove_from_option( array $keys ) {
		$secrets = $this->get_option();
		$left    = array_diff_key( $secrets, array_flip( $keys ) );

		if ( count( $left ) === count( $secrets ) ) {
			return;
		}

		if ( array() === $left ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, $left, false );
		}
	}

	/**
	 * Returns the keys stored with the Secrets API.
	 *
	 * @since 1.3.0
	 *
	 * @return string[] Secret keys.
	 */
	private function get_migrated_keys(): array {
		$keys = get_option( self::KEYS_OPTION, array() );

		return is_array( $keys ) ? $keys : array();
	}

	/**
	 * Adds a key to, or removes it from, the keys stored with the Secrets API.
	 *
	 * @since 1.3.0
	 *
	 * @param string $key      Secret key.
	 * @param bool   $migrated Whether the key is stored with the Secrets API.
	 */
	private function set_migrated( string $key, bool $migrated ) {
		$keys = $this->get_migrated_keys();
		$has  = in_array( $key, $keys, true );

		if ( $migrated === $has ) {
			return;
		}

		$keys = $migrated ? array_merge( $keys, array( $key ) ) : array_values( array_diff( $keys, array( $key ) ) );

		update_option( self::KEYS_OPTION, $keys, false );
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
}
