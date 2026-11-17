# SMTP – AI Coding Agent Instructions

This file provides context for AI coding agents (Claude Code, OpenAI Codex, Gemini CLI, GitHub Copilot, etc.) working on the SMTP plugin repository.

## Project Overview

**SMTP** is a minimal, community-maintained WordPress plugin that sends the emails of a site through an SMTP server. It is designed to follow WordPress.org canonical plugin conventions (slug and text domain `smtp`, owned by `wordpressdotorg`).

- Settings under _Tools > SMTP_: provider, host, port, encryption, authentication, From email, From name and "Force from".
- Credentials can be defined as constants in `wp-config.php` (`SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USER`, `SMTP_PASSWORD`, `SMTP_FROM_EMAIL`). A constant overrides the saved value and locks the field.
- Extensible through the `smtp_providers` filter. Generic SMTP is registered through the same filter.
- Metadata-only email log (no message body, no attachments) with automatic retention.
- A test email tab that shows the connection log with credentials redacted.
- An Alerts tab that notifies external channels (Slack first) when an email fails, extensible through the `smtp_alert_channels` filter.

Existing users of the legacy SMTP plugin keep their settings through an automatic import (see "Legacy plugin migration").

Out of scope for the first release: HTTP-API providers (tracked in [#1](https://github.com/WordPress/smtp/issues/1)), multisite network settings.

## Repository Structure

```
smtp/
├── smtp.php                  # Main plugin file: header, constants, requirements checks, bootstrap
├── uninstall.php             # Removes options, secrets, the log table and the cron event
├── readme.txt                # WordPress.org readme (Stable tag, changelog)
├── assets/js/alerts.js       # Shows the fields of the selected alert channel, enqueued by Alerts_Tab
├── includes/                 # PSR-4 autoloaded under WordPress\SMTP\
│   ├── Plugin_Main.php       # Wires every component, activation/deactivation, purge schedule
│   ├── Provider_Registry.php # smtp_providers filter, provider validation, Generic SMTP provider
│   ├── Rate_Limits.php       # Sending limits of a provider: declared, saved override, smtp_rate_limits filter
│   ├── Connections.php       # Stored connections (smtp_connections), smtp_connection_for_email, resolve()
│   ├── Settings.php          # Field resolution (constant > saved value > default) and sanitization
│   ├── Credentials.php       # The only place that reads/writes secrets (Secrets API or option)
│   ├── Secrets_API.php       # Thin wrapper over the WordPress 7.2 Secrets API, replaced by a fake in tests
│   ├── Migration.php         # One-time import of the legacy SMTP plugin (smtp_options) settings
│   ├── Mailer.php            # phpmailer_init and wp_mail_from(_name) integration
│   ├── Delivery_Tracker.php  # Detects when another plugin delivers the email instead of this one
│   ├── Log_Repository.php    # Log table schema (dbDelta) and queries
│   ├── Logger.php            # wp_mail_succeeded / wp_mail_failed listeners and retention purge
│   ├── Privacy.php           # Personal data exporter and eraser for the log, privacy policy text
│   ├── Alerts/               # Failure alerts sent outside of email
│   │   ├── Alert.php                  # Value object describing the event
│   │   ├── Alert_Channel.php          # Channel contract
│   │   ├── Alert_Channel_Registry.php # smtp_alert_channels filter
│   │   ├── Alert_Settings.php         # smtp_alert_settings option, channel secrets through Credentials
│   │   ├── Alert_Dispatcher.php       # wp_mail_failed listener, throttling, delivery state
│   │   └── Slack_Channel.php          # Slack Incoming Webhook channel
│   ├── Error_Hints.php       # Explains failed sends, based on the connection log
│   ├── CLI/                  # WP-CLI commands, registered only when WP_CLI is defined
│   │   ├── Command.php       # wp smtp status, wp smtp test
│   │   └── Settings_Command.php # wp smtp settings set
│   └── Admin/                # Tools > SMTP screen
│       ├── Admin_Page.php    # Tabs (the Email log tab comes first once configured), Settings API registration, field rendering
│       ├── Connections_Tab.php # Settings tab connections: switch, add, edit and delete connections
│       ├── Field_Renderer.php  # Renders a settings field, shared by the default and the extra connections
│       ├── Alerts_Tab.php    # Alerts tab (channel select, fields shown per channel), admin-post handler for the test alert
│       ├── Log_List_Table.php
│       ├── Failure_Notice.php # Admin notice about recently failed emails, dismissible per user
│       └── Test_Email.php    # admin-post handler for the test email
├── tests/
│   └── phpunit/
│       ├── bootstrap.php
│       ├── tests/            # PHPUnit test classes (mirror includes/)
│       └── utils/            # Shared test helpers (traits)
├── .github/workflows/        # php-lint.yml, php-test.yml, props-bot.yml
├── phpcs.xml.dist            # WordPress Coding Standards ruleset
├── phpunit.xml.dist
├── .wp-env.json              # Development site
└── .wp-env.tests.json        # Test stack (PHP 7.4, ports 8920/8921)
```

## Architecture

### Providers

A provider is an array registered through the `smtp_providers` filter:

- `label` (string): human readable name.
- `fields` (array): settings fields keyed by field key. Each field accepts `label`, `type` (`text`, `number`, `email`, `password`, `select`, `checkbox`), `default`, `options`, `description`, `constant` and `max` (highest accepted value of a `number` field, shared by the settings screen, the CLI and the HTML input).
- `required` (string[]): field keys that must have a value before the provider is used.
- `required_if` (array): `field => condition field` pairs. The field must have a value when the condition field is enabled. Generic SMTP uses it for `username` and `password` when `auth` is on.
- `configure` (callable): receives the `PHPMailer` instance and the resolved field values.
- `rate_limits` (array, optional): default sending limits. See "Sending limits".

`Provider_Registry::get_all()` discards invalid providers. Providers only configure PHPMailer; they must not send mail themselves.

### Connections

- A connection is a provider with its own settings, secrets, From address and limits. The settings of Tools > SMTP (`smtp_settings`) are the **default connection** (ID `default`), so nothing changes for single-connection sites. Other connections live in `smtp_connections` (`id => label + settings`, not autoloaded), and `Connections` is the only class that reads and writes them.
- `Settings` is scoped to a connection by its constructor (`new Settings( $registry, $credentials, $id )`). Constants only lock the default connection, and the secrets of the others go to `Credentials` under `connection:{id}:{field}`. The colon can not be in an ID, so one ID prefix never matches another (`a` and `a_b`). **Never build a secret key by hand: use `Connections::get_secret_prefix()`.**
- `Mailer` picks the connection in the `wp_mail` filter (last priority, so it sees the final email data) with `Connections::resolve()`, which applies `smtp_connection_for_email`. `phpmailer_init` and the From filters then read the settings of that connection, and `Delivery_Tracker` credits its provider. An unknown or incomplete connection falls back to the default one so email is never dropped, and `smtp_connection_fallback` fires so the mismatch can be logged.
- `wp_smtp_mail()` adds a one-shot `smtp_connection_for_email` callback at `PHP_INT_MAX` around `wp_mail()`. `wp_smtp_get_connections()` describes the connections without credentials. Both live in `smtp.php`.
- The log table has a `connection_id` column (schema version 2). It is empty for the default connection, so entries logged before connections existed stay correct, and for emails that were not delivered by a connection.
- `Admin\Connections_Tab` adds the connection links and forms to the Settings tab (add, edit, delete; the ID comes from the name) (admin-post handlers with nonce and capability checks), and `Admin\Field_Renderer` renders a field for the default and the extra connections.
- `wp smtp` commands only handle the default connection.

### Sending limits

- `Rate_Limits` only **reports** limits (`per_second`, `per_minute`, `per_hour`, `per_day`, `concurrency` for messages, and `recipients` with the interval keys). It never queues, delays or throttles email. They are configured values, not remaining capacity.
- A limit resolves as: provider default (`rate_limits`), saved override of the active provider (`rate_limit_*` fields, shown in the Sending limits section of the Settings tab), then the `smtp_rate_limits` filter. `Rate_Limits::normalize()` keeps only known keys with positive whole numbers, so a missing key means no known limit.
- Other plugins read them with `wp_smtp_get_rate_limits( $provider = '' )` (defined in `smtp.php`).

### Settings and credentials

- A field value resolves in this order: constant, secret (for `password` fields) or saved option, field default. See `Settings::resolve()`.
- Options: `smtp_settings` (everything except secrets), `smtp_credentials` (secrets when the Secrets API is not used, not autoloaded) and `smtp_secret_keys` (keys stored with the Secrets API).
- **Never read or write secrets outside `Credentials`.** It stores them with the WordPress 7.2 Secrets API as `smtp/<key>` when `Secrets_API::is_writable()`, and in `smtp_credentials` otherwise. Only `Secrets_API` calls the core functions, so tests inject `Test_Utils\Fake_Secrets_API` instead of redefining them.
- Detect the API with `wp_is_secrets_api_available()`, not `function_exists()`: the functions exist without a usable `WP_SECRETS_KEY`.
- `Credentials::migrate()` runs on `admin_init`. It moves each option value to the API, reads it back, then removes it from the option. Until then reads fall back to the option.
- A key listed in `smtp_secret_keys` is only read from the API. When it can not be read (a `WP_Error`, or the API is gone), `get()` returns `''` and an admin notice asks to check `WP_SECRETS_KEY` or enter the password again. **Never fall back to a plain text value**, and never store a value in the option when a write to the API fails: `Credentials::set()` returns the `WP_Error`. A key listed in `smtp_secret_keys` is also never written to the option when the API is not writable any more: `set()` returns a `WP_Error` instead.
- `Credentials::migrate()` runs on `admin_init`. After a run that could not move every secret, the `smtp_secrets_migration_retry` transient makes it wait an hour. `has_unreadable()` is cached per request.
- **Never print a saved secret into HTML.** Password fields render empty with a placeholder, and an empty submission keeps the current secret.
- The plugin must do nothing until the active provider has its `required` and `required_if` fields (`Settings::is_configured()`), so activating it never breaks a site's email.

### Legacy plugin migration

This plugin replaces the SMTP plugin 1.x published under the same slug, so sites upgrade in place and the activation hook does not run. `Migration::maybe_migrate()` runs on `init` (priority 0) and on activation, once, guarded by the `smtp_legacy_migrated` option.

- Never overwrite existing `smtp_settings`, never modify or delete the legacy `smtp_options` option (it is removed on uninstall only).
- The legacy password is decrypted with mcrypt when available. When it can not be, the user is asked to enter it again through an admin notice. Never block or fail the import because of the password.
- Any change to the mapping needs a matching test in `Migration_Tests`.

### WP-CLI

- Commands live in `includes/CLI/` and stay thin: the logic belongs in `Settings` and `Test_Email`, so the admin screen and the commands share it.
- `Settings::sanitize()` expects a complete form and resets whatever is missing. To change some values, use `Settings::update()`.
- Commands validate their input and fail, since `Settings::sanitize()` silently replaces invalid values.
- **Never accept a secret as an argument, and never print one.**
- `--debug` is a global WP-CLI flag, so commands cannot use it as their own.

### Email log

- Table `{prefix}smtp_logs`, created with `dbDelta()` by `Log_Repository::maybe_upgrade()` (schema version in the `smtp_db_version` option).
- Only metadata is stored: recipient, subject, status, error, provider and connection. **Never store the message body, headers or attachments.**
- The `provider` column holds the slug of the provider that delivered the email, `default` for the WordPress mailer, or `other` when another plugin delivered it (it replaced `wp_mail()`, short-circuited it with `pre_wp_mail`, or changed PHPMailer after the provider configured it). `Delivery_Tracker` decides which; the Settings and Test tabs warn when another plugin controls outgoing email.
- Entries older than 30 days are deleted daily by the `smtp_purge_logs` cron event.
- `Failure_Notice` shows users with `manage_options` how many emails failed in the last 24 hours, on every admin screen except Tools > SMTP. Dismissing it stores the time in the `smtp_failures_dismissed` user meta, and it comes back when a newer email fails.
- `Privacy` registers a personal data exporter and eraser (key `smtp`) that find entries by recipient address, and adds suggested text to the privacy policy guide. Keep that text in sync with what the log stores.

### Alerts

- A channel implements `Alerts\Alert_Channel` and is registered through the `smtp_alert_channels` filter. Channels only format and deliver an `Alert`; the dispatcher decides when.
- **A channel must never use `wp_mail()`**: alerts exist because email may be broken. A static guard in `Alert_Dispatcher` stops a failure raised while dispatching from triggering another alert.
- Options: `smtp_alert_settings` (`channel`: the selected channel ID or `''` for none, and `channels`: the non-secret fields of every channel) and `smtp_alert_state` (per channel: last sent, throttled count, last delivery error), both removed on uninstall. Values of `password` channel fields go to `Credentials` under `alert_{channel}_{field}`.
- Alerts are throttled per channel (15 minutes by default); failures in between are reported in the next alert.
- Alert messages contain the same metadata as the log (error, recipients, subject), never the body.

### Hooks provided

- `smtp_providers` (filter): add or modify providers.
- `smtp_log_enabled` (filter): return `false` to skip logging an email. Receives the mail data and the status.
- `smtp_log_retention_days` (filter): days to keep log entries. Default `30`; `0` or less disables the purge.
- `smtp_failure_notice_period` (filter): seconds the recent failures admin notice looks back. Default one day.
- `smtp_rate_limits` (filter): change the sending limits of a provider. Receives the limits, the provider slug and the connection ID.
- `smtp_connection_for_email` (filter): pick the connection an email is sent through. Receives the connection ID (`default`) and the mail data.
- `smtp_connection_fallback` (action): an email asked for an unknown or incomplete connection and goes through the default one. Receives the ID asked for, the reason (`unknown` or `incomplete`) and the mail data.
- `smtp_timeout` (filter): seconds PHPMailer waits for the SMTP server to connect and to answer each command. Default `15`; `0` or less keeps the PHPMailer default (300). Receives the provider slug.
- `smtp_alert_channels` (filter): add alert channels (`Alert_Channel` instances).
- `smtp_alert_enabled` (filter): return `false` to skip the alert for a failed email. Receives the `Alert`.
- `smtp_alert_throttle` (filter): seconds between two alerts on the same channel. Default `900`; `0` disables throttling.

## Coding Standards

Enforced by `phpcs.xml.dist` (WordPress, WordPress-Extra, WordPress-Docs and PHPCompatibilityWP):

- **PHP minimum version**: 7.4. **WordPress minimum version**: 6.3.
- **Namespace**: `WordPress\SMTP\` (PSR-4, mapped to `includes/`). Test helpers use `WordPress\SMTP\Test_Utils\` (mapped to `tests/phpunit/utils/`).
- Use tabs for indentation, and Yoda conditions.
- Text domain: `smtp`.
- Global prefixes: `wp_smtp`, `WP_SMTP`, `smtp`, `WordPress\SMTP`.
- All public methods and classes in `includes/` must have PHPDoc blocks with `@since`.
- Do **not** add docblocks to unit test classes or methods (excluded in `phpcs.xml.dist` for `tests/*`).
- Sanitize input, escape output, and use `$wpdb->prepare()` for every query with dynamic values.
- PHPMailer exposes camelCase properties (`$phpmailer->SMTPSecure`); wrap those lines with the `UsedPropertyNotSnakeCase` `phpcs:disable`/`phpcs:enable` comments, as in `Provider_Registry::configure_generic()`.
- All user-facing text must be in English and translatable.
- Ensure `composer lint` and `composer phpstan` pass before considering a change complete.
- Don't add new entries to `phpstan-baseline.neon`; fix the error instead.

## Development Commands

All commands run from the plugin root.

| Command | Purpose |
|---|---|
| `composer install` | Install PHP dependencies, including the PHPCS tooling and PHPUnit |
| `composer lint` | Run PHPCS with `phpcs.xml.dist` |
| `composer phpstan` | Run PHPStan (level 5) with `phpstan.neon.dist` |
| `composer format` | Fix coding standards issues with PHPCBF |
| `npm ci` | Install Node dependencies (`@wordpress/env`) |
| `npm run wp-env:start:tests` | Start the Docker based test stack (requires Docker) |
| `npm run wp-env:stop:tests` | Stop the test stack |
| `npm run test-php` | Run PHPUnit inside the test stack |
| `npm run test-php-coverage` | Run PHPUnit with a Clover coverage report in `build/logs/` |
| `npm run wp-env start` | Start the development site |

**Run a single test class or method:**

```bash
npm run test-php -- --filter Settings_Tests
npm run test-php -- --filter Settings_Tests::test_sanitize_is_idempotent
```

## Tests

- `tests/phpunit/tests/` — all PHPUnit test classes, one per class in `includes/` and named `<Class>_Tests` (for example `Settings_Tests`, `Admin/Admin_Page_Tests`). Files end in `_Tests.php`, which is what `phpunit.xml.dist` picks up.
- `tests/phpunit/utils/` — shared helpers, as traits (`With_Log_Table`, `With_PHPMailer`), and `Alerts\Fake_Channel`, an alert channel that records what it receives, and `Fake_Secrets_API`, an in-memory Secrets API.
- `tests/phpunit/utils/` — shared helpers, as traits (`With_Log_Table`, `With_PHPMailer`), and `wp-cli-stub.php`, a stand-in for WP-CLI, which is not loaded in the test stack.
- `tests/phpunit/bootstrap.php` — loads the Composer autoloader and the WordPress test suite, and forces the plugin to be active.
- Test classes extend `WP_UnitTestCase` and use `set_up()` / `tear_down()`.
- The log table is created with `dbDelta()`, which the WordPress test suite turns into a temporary table. Call `create_log_table()` (from `With_Log_Table`) in `set_up()` for any test that sends mail or touches the log.
- Constants can not be undefined in PHP. Tests that need a constant use a unique name (for example `SMTP_TESTS_LOCKED_TOKEN`) and attach it to a provider through the `smtp_providers` filter.

### Test Enforcement

- Every new class, method or behavior change in `includes/` **must** come with tests in the matching file under `tests/phpunit/tests/`. A bug fix needs a test that fails without the fix.
- Run `npm run test-php` and `composer lint` locally before pushing. Do not mark a task as done while either fails.
- Security-sensitive behavior must always be covered: secrets are never printed or logged, output is escaped, capability and nonce checks reject unauthorized requests, and credentials are redacted from the test email connection log.
- CI runs on every pull request to `trunk` and `feature/**`:
  - `php-test.yml` runs the PHPUnit suite on PHP 7.4, 8.0, 8.1, 8.2 and 8.3 with the latest WordPress, on PHP 7.4 with WordPress 6.3, and on PHP 8.4 with WordPress trunk (experimental, allowed to fail).
  - `php-lint.yml` runs `composer lint` and `composer phpstan`.
- Both workflows must be green before merging. Configure them as required status checks in the branch protection rules of `trunk`.
- Never skip, delete or weaken a test to make a change pass. Fix the code or discuss the test change in the pull request.

## Key Files

- `smtp.php` — plugin header, `WP_SMTP_*` constants, PHP/autoloader requirement notices, activation and deactivation hooks.
- `includes/Plugin_Main.php` — the composition root. Add new components here.
- `includes/Settings.php` — the only class that knows how a field value is resolved and sanitized.
- `includes/Credentials.php` — the only class that touches secrets.
- `includes/Provider_Registry.php` — the provider contract and the Generic SMTP provider.

## Release Process

Current version: `1.2.0` (declared in the `smtp.php` header and the `WP_SMTP_VERSION` constant).

1. Bump the version in the `smtp.php` header (`Version:`), and the `WP_SMTP_VERSION` constant in `smtp.php`. Keep both locations in sync.
2. Check every `@since` tag against the version actually being released. The version a contributor wrote when opening a PR can be wrong by release time (for example `@since 1.2.1` in a PR, but the release ships as `1.3.0`). Search with `grep -rnE "@since" --include=*.php smtp.php uninstall.php includes/` (also search `n.e.x.t`, `NEXT`, `x.x.x`, `TBD`) and:
   - Replace any placeholder (`n.e.x.t`, `NEXT`, `x.x.x`, `TBD`) with the release version.
   - Replace any `@since` that is higher than the release version, or that points to a version that is not this release, for code added in this release, with the release version.
   - Confirm that no code added in this release carries an older version, and that `@since` of existing code was not changed. Use `git diff <previous-tag>...HEAD` to see what was added.
   - Do the same for `@deprecated` and for versions mentioned in `_deprecated_*()` calls and in docblocks of hooks (`apply_filters`/`do_action`).
   The release must not be tagged while any `@since` is a placeholder or a version that will never be published.
3. Update the `readme.txt` **Changelog** section with a summary of notable changes.
4. Update the `readme.txt` **Stable tag** to match the new version, and **Tested up to** when needed.
5. Build the distribution with production dependencies only: `composer install --no-dev -o`. Files excluded from the release are listed in `.distignore`.
6. Open a pull request, get it merged into `trunk` and tag the release.

There is no deploy workflow in this repository yet: publishing to WordPress.org is done by the Plugins Team, not from CI. Do not add or describe an automated deploy until a workflow exists.

## CI/CD

- `.github/workflows/php-lint.yml` — runs `composer lint` and `composer phpstan` on pushes to `trunk` and on pull requests when PHP, PHPCS or PHPStan files change.
- `.github/workflows/php-test.yml` — starts the `wp-env` test stack and runs `npm run test-php` across the PHP and WordPress matrix, when PHP, test or tooling files change.
- `.github/workflows/props-bot.yml` — runs [Props Bot](https://github.com/WordPress/props-bot-action) on pull requests to comment the list of contributors to credit. Add the `props-bot` label to refresh it.
- Documentation goes in `/docs/` (if added) and must be listed in `.distignore`.

## Contribution Guidelines

- All code is licensed under **GPL-2.0-or-later**.
- Open issues and pull requests on [GitHub](https://github.com/WordPress/smtp).
- Keep the plugin minimal: prefer extension points (filters) over built-in features.
