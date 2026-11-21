<?php
/**
 * Plugin Name: SMTP
 * Plugin URI: https://github.com/WordPress/smtp
 * Description: Send WordPress emails through an SMTP server, with an extensible provider API and a simple email log.
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Version: 1.2.0
 * Author: WordPress Plugins Team
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: smtp
 *
 * @package smtp
 */

use WordPress\SMTP\Plugin_Main;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'WP_SMTP_VERSION', '1.2.0' );
define( 'WP_SMTP_MINIMUM_PHP', '7.4' );
define( 'WP_SMTP_MAIN_FILE', __FILE__ );

/**
 * Checks basic requirements and loads the plugin.
 *
 * @since 1.2.0
 */
function wp_smtp_load() {
	if ( version_compare( phpversion(), WP_SMTP_MINIMUM_PHP, '<' ) ) {
		add_action( 'admin_notices', 'wp_smtp_display_php_version_notice' );
		return;
	}

	$autoload = plugin_dir_path( WP_SMTP_MAIN_FILE ) . 'vendor/autoload.php';
	if ( ! file_exists( $autoload ) ) {
		add_action( 'admin_notices', 'wp_smtp_display_autoload_notice' );
		return;
	}

	require_once $autoload;

	register_activation_hook( WP_SMTP_MAIN_FILE, array( Plugin_Main::class, 'activate' ) );
	register_deactivation_hook( WP_SMTP_MAIN_FILE, array( Plugin_Main::class, 'deactivate' ) );

	$plugin = new Plugin_Main( WP_SMTP_MAIN_FILE );
	$plugin->add_hooks();
}

/**
 * Returns the sending limits of an SMTP provider.
 *
 * The limits are configured values, not the remaining capacity: the account may be shared with other
 * applications. Keys without a known limit are missing, so the result is empty when there are none.
 *
 * @since 1.2.0
 *
 * @param string $provider Provider slug. Defaults to the active provider.
 * @return array {
 *     Limits.
 *
 *     @type int   $per_second  Maximum messages per second.
 *     @type int   $per_minute  Maximum messages per minute.
 *     @type int   $per_hour    Maximum messages per hour.
 *     @type int   $per_day     Maximum messages per day.
 *     @type int   $concurrency Maximum simultaneous connections or sending operations.
 *     @type int[] $recipients  Maximum recipients per `per_second`, `per_minute`, `per_hour` and `per_day`.
 * }
 */
function wp_smtp_get_rate_limits( string $provider = '' ): array {
	// The classes are not loaded when a requirement check failed.
	if ( ! class_exists( WordPress\SMTP\Rate_Limits::class ) ) {
		return array();
	}

	$registry = new WordPress\SMTP\Provider_Registry();
	$settings = new WordPress\SMTP\Settings( $registry, new WordPress\SMTP\Credentials() );

	return ( new WordPress\SMTP\Rate_Limits( $settings, $registry ) )->get( $provider );
}

/**
 * Displays an admin notice when the PHP version is not supported.
 *
 * @since 1.2.0
 */
function wp_smtp_display_php_version_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: 1: required PHP version, 2: current PHP version */
				esc_html__( 'SMTP requires PHP %1$s or newer. Your site is running PHP %2$s.', 'smtp' ),
				esc_html( WP_SMTP_MINIMUM_PHP ),
				esc_html( phpversion() )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Displays an admin notice when the Composer autoloader is missing.
 *
 * @since 1.2.0
 */
function wp_smtp_display_autoload_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: %s: composer install command */
				esc_html__( 'SMTP is missing its autoloader. Please run %s in the plugin directory.', 'smtp' ),
				'<code>composer install</code>'
			);
			?>
		</p>
	</div>
	<?php
}

wp_smtp_load();
