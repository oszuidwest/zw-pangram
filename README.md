# ZuidWest Pangram

ZuidWest Pangram checks existing WordPress posts with the [Pangram Labs](https://www.pangram.com) AI-text detector.

From **Tools > Pangram** you can:

- select and queue posts for analysis;
- review results and compare scores by author;
- filter results and export them as CSV.

Posts are sent only when you explicitly add them to the scan queue. Saving or publishing a post does not trigger a scan.

> **Privacy:** Pangram Labs receives the full plain text of every post you queue.

## Requirements

- WordPress 7.1+
- PHP 8.3+
- A Pangram API key with bulk access
- A working WP-Cron setup

Multisite is supported only when the plugin is activated per site.

## Installation

1. Download a release zip or build one with `scripts/build-plugin.sh`.
2. Install and activate the plugin.
3. Open **Tools > Pangram > Settings** and enter your API key.
4. Test the connection, then use **Tools > Pangram > Scan** to queue posts.

For production, the API key can be defined in `wp-config.php`:

```php
define('ZW_PANGRAM_API_KEY', 'your-key');
```

WP-Cron processes the queue in the background. On low-traffic sites, configure a system cron to run WordPress cron regularly.

## Development

```bash
composer install
npm ci
composer lint
composer stan
npm run lint:js
npm run env:start
npm run test:php
```

Docker is required for the PHPUnit test environment.

## License

GPL-2.0-or-later
