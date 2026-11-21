# SMTP

![SMTP](.wordpress-org/banner-1544x500.png)

![Required PHP Version](https://img.shields.io/wordpress/plugin/required-php/smtp?label=Requires%20PHP) ![Required WordPress Version](https://img.shields.io/wordpress/plugin/wp-version/smtp?label=Requires%20WordPress) ![WordPress Tested Up To](https://img.shields.io/wordpress/plugin/tested/smtp?label=WordPress) [![GPL-2.0-or-later License](https://img.shields.io/github/license/wordpress/smtp.svg?label=License)](https://www.gnu.org/licenses/gpl-2.0.html)

![Plugin Version](https://img.shields.io/wordpress/plugin/v/smtp?label=Version) ![WordPress.org Rating](https://img.shields.io/wordpress/plugin/rating/smtp?label=WP.org%20Rating) ![WordPress Plugin Downloads](https://img.shields.io/wordpress/plugin/dt/smtp?label=WP.org%20Downloads) ![WordPress Plugin Active Installs](https://img.shields.io/wordpress/plugin/installs/smtp?label=WP.org%20Active%20Installs)

[![PHP Unit Testing](https://github.com/WordPress/smtp/actions/workflows/php-test.yml/badge.svg)](https://github.com/WordPress/smtp/actions/workflows/php-test.yml) [![PHP Linting](https://github.com/WordPress/smtp/actions/workflows/php-lint.yml/badge.svg)](https://github.com/WordPress/smtp/actions/workflows/php-lint.yml)

Send WordPress emails through an SMTP server, with an extensible provider API and a simple email log.

This is the technical documentation for developers. The user-facing description lives in [`readme.txt`](readme.txt).

- Requires WordPress 6.3+ and PHP 7.4+.
- Namespace `WordPress\SMTP\`, slug and text domain `smtp`.
- Repository: [github.com/wordpress/smtp](https://github.com/wordpress/smtp)

## Constants

Constants defined in `wp-config.php` override the saved value and lock the field in the settings screen. They are the recommended way to provide credentials in production.

| Constant | Field |
|---|---|
| `SMTP_HOST` | Host |
| `SMTP_PORT` | Port |
| `SMTP_ENCRYPTION` | Encryption (`none`, `ssl` or `tls`) |
| `SMTP_USER` | Username |
| `SMTP_PASSWORD` | Password |
| `SMTP_FROM_EMAIL` | From email |

## Adding a provider

Providers are added with the `smtp_providers` filter. A provider configures the `PHPMailer` instance used by `wp_mail()`, so it works with any service that offers SMTP. The built-in Generic SMTP provider is registered through the same filter.

```php
add_filter( 'smtp_providers', function ( $providers ) {
	$providers['my-provider'] = array(
		'label'     => 'My provider',
		'required'  => array( 'api_user' ),
		'fields'    => array(
			'api_user' => array(
				'label'    => 'API user',
				'type'     => 'text',
				'constant' => 'MY_PROVIDER_USER',
			),
			'api_key'  => array(
				'label'    => 'API key',
				'type'     => 'password',
				'constant' => 'MY_PROVIDER_KEY',
			),
		),
		'configure' => function ( $phpmailer, $values ) {
			$phpmailer->isSMTP();
			$phpmailer->Host     = 'smtp.my-provider.example';
			$phpmailer->Port     = 587;
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = $values['api_user'];
			$phpmailer->Password = $values['api_key'];
		},
	);

	return $providers;
} );
```

| Key | Description |
|---|---|
| `label` (string) | Name shown in the provider select. Required. |
| `configure` (callable) | Receives the `PHPMailer` instance and the resolved field values. Required. |
| `fields` (array) | Settings fields, keyed by field key. |
| `required` (string[]) | Field keys that must have a value before the provider is used. Until then, mail is sent as usual. |
| `required_if` (array) | Field keys that must have a value when another field is enabled, as `field => condition field` pairs. For example `array( 'password' => 'auth' )`. |
| `rate_limits` (array) | Optional default sending limits, see [Sending limits](#sending-limits). |

Each field accepts:

| Key | Description |
|---|---|
| `label` | Field label. |
| `type` | `text` (default), `number`, `email`, `password`, `select` or `checkbox`. |
| `default` | Value used when nothing is saved. |
| `options` | Value => label pairs, for `select`. |
| `description` | Help text. |
| `constant` | Name of a constant that overrides the value and locks the field. |
| `max` | Highest accepted value of a `number` field. Higher values are rejected by `wp smtp settings set` and reset to the default on the settings screen, with a notice. |

Fields of type `password` are stored apart from the other settings, are never printed back into the page, and are redacted from the connection log of the test email.

Providers with an invalid definition (no label, or a `configure` that is not callable) are ignored. Providers that talk to an HTTP API instead of SMTP are not supported yet, see [#1](https://github.com/WordPress/smtp/issues/1).

## Multiple connections

A connection is a provider with its own settings, credentials, From address and sending limits. The settings saved under _Tools > SMTP > Settings_ are the **default connection**, so existing sites keep working unchanged. Add the others from the same _Settings_ tab with **Add connection**: the ID used in code (such as `sponsors`) is generated from the name.

Emails use the default connection, unless the connection is picked for that email:

```php
// Send one email through a connection.
wp_smtp_mail( 'sponsors', 'recipient@example.com', 'Subject', 'Message' );

// Or route emails you do not send yourself, such as the ones of another plugin.
add_filter( 'smtp_connection_for_email', function ( $connection_id, $mail_data ) {
	if ( false !== strpos( (string) $mail_data['subject'], 'Sponsor' ) ) {
		return 'sponsors';
	}

	return $connection_id;
}, 10, 2 );
```

`wp_smtp_mail()` takes the connection ID first and then the arguments of `wp_mail()`. The filter receives the connection ID (`default` unless another callback changed it) and the email data (`to`, `subject`, `message`, `headers` and `attachments`). An unknown connection, or one that is not fully configured, falls back to the default connection, so the email is still sent. The `smtp_connection_fallback` action fires in that case, with the ID that was asked for and the reason (`unknown` or `incomplete`), so you can log it or alert someone.

Other plugins list the connections without any access to the credentials:

```php
foreach ( wp_smtp_get_connections() as $id => $connection ) {
	// $connection['label'], ['provider'], ['configured'] and ['rate_limits'].
}
```

Notes:

- The mailbox used to authenticate (the username) and the From address are separate fields, so a connection can send from a different address than the one it logs in with. A provider can still reject From addresses it does not own.
- Constants such as `SMTP_HOST` only configure the default connection.
- Each connection has its own sending limits, see [Sending limits](#sending-limits). The `smtp_rate_limits` filter also receives the connection ID.
- The email log has a **Connection** column once there is more than one connection.
- Deleting a connection also deletes its saved credentials. `wp smtp` commands only handle the default connection for now.

## Sending limits

Email providers limit how much you can send. A provider can declare its limits with the optional `rate_limits` key, and every value is optional, since not every provider publishes a limit for every interval:

```php
$providers['my-provider'] = array(
	// ...
	'rate_limits' => array(
		'per_second'  => 2,
		'per_minute'  => 60,
		'per_hour'    => 500,
		'per_day'     => 2000,
		'concurrency' => 1,
		// For services that count recipients instead of messages.
		'recipients'  => array( 'per_day' => 5000 ),
	),
);
```

| Key | Description |
|---|---|
| `per_second`, `per_minute`, `per_hour`, `per_day` | Maximum number of messages in that interval. |
| `concurrency` | Maximum number of simultaneous connections or sending operations. |
| `recipients` | The same interval keys, for the maximum number of recipients. |

Limits resolve in this order:

1. The default declared by the provider.
2. The value saved under _Tools > SMTP > Sending limits_, for the active provider. It covers the message limits and `concurrency`, since the right values often depend on the account or plan.
3. The `smtp_rate_limits` filter, which receives `$limits` and the provider slug.

Other plugins read the result with `wp_smtp_get_rate_limits( $provider = '' )`, which defaults to the active provider of the default connection. For the limits of each connection use `wp_smtp_get_connections()`. Keys without a known limit are missing, so the result is an empty array when there are none:

```php
if ( function_exists( 'wp_smtp_get_rate_limits' ) ) {
	$limits = wp_smtp_get_rate_limits();
	$per_minute = $limits['per_minute'] ?? null;
}
```

The values are configured limits, not the remaining capacity: the same account may be shared with other applications. The plugin does not queue, delay or throttle email. It only reports the limits, so queue plugins can use them to schedule messages.

## WP-CLI

Set up and check a site without the admin screen:

```bash
wp smtp settings set host=smtp.example.com port=587 encryption=tls username=me@example.com
printf '%s' "$SMTP_PASSWORD" | wp smtp settings set password=-
wp smtp test someone@example.com
wp smtp status --format=json
```

| Command | Description |
|---|---|
| `wp smtp settings set <setting>...` | Changes the given settings and keeps the others. Nothing is saved when a value is invalid. |
| `wp smtp test [<to>] [--connection-log]` | Sends a test email, to the admin email by default. Exits with a non-zero code on failure, with a hint on what to check when the problem is recognized. |
| `wp smtp status [--format=<format>]` | Shows the active provider, each setting and whether it comes from a constant, the database or the default. |

Like saving the admin form, `settings set` saves every field, so afterwards `status` reports `database` for all of them. The exceptions are fields set by a constant, which show `constant`, and passwords that were never set, which show `default`.

Passwords cannot be passed as arguments, since those end up in the shell history. Use `password=-` to read the value from STDIN, or to get a hidden prompt in a terminal. Passwords are never printed, and they are removed from the output of `--connection-log`.

## Other filters

| Filter | Description |
|---|---|
| `smtp_log_enabled` | Return `false` to skip logging an email. Receives `$enabled`, `$mail_data` and `$status` (`sent` or `failed`). |
| `smtp_log_retention_days` | Number of days log entries are kept. Default `30`. A value of `0` or less disables the purge. |
| `smtp_failure_notice_period` | Seconds the admin notice about failed emails looks back. Default one day. |
| `smtp_rate_limits` | Change the sending limits of a provider. Receives `$limits`, the provider slug and the connection ID. See [Sending limits](#sending-limits). |
| `smtp_connection_for_email` | Pick the connection an email is sent through. Receives `$connection_id` (`default`) and `$mail_data`. See [Multiple connections](#multiple-connections). |
| `smtp_connection_fallback` | Action. Fires when an email asked for a connection that does not exist or is not fully configured and goes through the default one. Receives `$connection_id`, `$reason` (`unknown` or `incomplete`) and `$mail_data`. |
| `smtp_timeout` | Seconds to wait for the SMTP server to connect and to answer each command. Default `15`. A value of `0` or less keeps the PHPMailer default of 300 seconds. |
| `smtp_alert_channels` | Add alert channels. Receives an array of `WordPress\SMTP\Alerts\Alert_Channel` instances. |
| `smtp_alert_enabled` | Return `false` to skip the alert for a failed email. Receives `$enabled` and the `Alert`. |
| `smtp_alert_throttle` | Minimum number of seconds between two alerts on the same channel. Default `900`. Receives `$seconds` and the channel ID. `0` sends an alert for every failure. |

## Migration from the legacy plugin

This plugin replaces the SMTP plugin by Sorin Iclanzan (1.x) that was published under the same slug, so sites upgrade in place and the activation hook does not run. `WordPress\SMTP\Migration` imports the legacy settings once, on `init` (and on activation).

| Legacy (`smtp_options`) | This plugin |
|---|---|
| `host` | Host, without a scheme |
| `port` | Port (`25` when invalid) |
| `smtp_secure` (`''`, `ssl`, `tls`) | Encryption (`none`, `ssl`, `tls`) |
| `username` | Username. Authentication is on when it is not empty, like before |
| `password` | Password, decrypted and stored as a secret |
| *(always the first user)* | From email and From name of user 1, with "Force from" on |

- Nothing is imported when `smtp_settings` already exists, or when the legacy plugin still has its activation placeholder (`http://localhost`, no username).
- The legacy option is never modified. It is deleted on uninstall, together with `smtp_legacy_migrated` and `smtp_migration_notice`.
- The legacy plugin encrypted the password with `mcrypt` (Rijndael-256, CBC) and a key hardcoded in its source. `mcrypt` was removed in PHP 7.2 and nothing else supports Rijndael-256, so the password is only imported on servers that still provide it. Otherwise an admin notice asks the user to enter it again.

## Email log

Every email sent with `wp_mail()` is logged in the `{prefix}smtp_logs` table with its recipient, subject, status, error and provider. The message body, headers and attachments are never stored. A daily cron event (`smtp_purge_logs`) deletes old entries.

## Alerts

The _Alerts_ tab sends a notification outside of email when `wp_mail()` fails, because a broken mailer cannot report itself. Slack (Incoming Webhook) is the first channel.

- Pick the channel in _Send failure alerts via_ (`None` by default). Only the fields of the selected channel are shown, and alerts are sent once it is configured. The fields of the other channels are kept.
- The Slack webhook URL is stored as a secret through `Credentials` and never printed back.
- To avoid flooding a channel, at most one alert is sent every 15 minutes per channel. Failures in between are counted and reported with the next alert.
- When an alert cannot be delivered, the error is shown in the tab. It never triggers another alert.
- The message contains the error, the recipients and the subject (the same metadata as the email log), and a link to the failed entries of the log.

Add a channel by implementing `WordPress\SMTP\Alerts\Alert_Channel` and registering it:

```php
add_filter( 'smtp_alert_channels', function ( $channels ) {
	$channels[] = new My_Teams_Channel();

	return $channels;
} );
```

A channel declares its fields (`text`, `url` or `password`), sanitizes them, says whether it is configured and delivers an `Alert`. It must never use `wp_mail()`.

## Development

```bash
composer install        # PHP dependencies and coding standards tooling
composer lint           # Run PHPCS
composer format         # Fix coding standards issues
npm ci                  # Node dependencies (@wordpress/env)
npm run wp-env:start:tests
npm run test-php        # Run PHPUnit in the test stack (requires Docker)
```

Run a single test class or method:

```bash
npm run test-php -- --filter Settings_Tests
```

See [`AGENTS.md`](AGENTS.md) for the project structure, coding standards and test requirements.

## Releasing

Build the distribution with production dependencies only:

```bash
composer install --no-dev -o
```

Files that are not part of the release are listed in `.distignore`.

## License

GPL-2.0-or-later.
