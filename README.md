# ZuidWest Pangram

ZuidWest Pangram checks existing WordPress posts with the [Pangram Labs](https://www.pangram.com) AI-text detector.

From **Tools > Pangram** you can:

- select and queue posts for analysis;
- review results and compare scores by author;
- open the details of a scanned post, including Pangram's per-segment breakdown;
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

1. Download the latest zip from [GitHub Releases](https://github.com/oszuidwest/zw-pangram/releases/latest).
2. Install and activate the plugin.
3. Open **Tools > Pangram > Settings** and enter your API key.
4. Test the connection, then use **Tools > Pangram > Scan** to queue posts.

For production, the API key can be defined in `wp-config.php`:

```php
define('ZW_PANGRAM_API_KEY', 'your-key');
```

WP-Cron processes the queue in the background. On low-traffic sites, configure a system cron to run WordPress cron regularly.

Eligible posts are scanned in the order they were queued. A retry waits until its scheduled retry time. Queuing a completed post again puts it at the back of the queue.

## Stored responses

Every successful scan stores Pangram's response so that **Details** on a result can show the long-form assessment and the per-segment breakdown: label, confidence, AI-assistance score, humanizer flag, word count and character range per segment. By default the analyzed text is stripped from the response before it is stored, so the details view shows segment metadata only. Enable **Store the full response** under Settings to keep the segment text for future scans; **Purge stored response text** removes it again from every row. Pangram Labs receives the full text of every queued post regardless of this setting.

## Migrating from WP Pangram

The old and new plugin slugs use different tables, options, cron hooks, and API-key constants. Use the repository's WP-CLI script instead of activating ZuidWest Pangram as a fresh installation.

1. In WP Pangram, pause the queue and wait until no bulk job is open and no items are processing or submitted.
2. Back up the WordPress database.
3. Deactivate both WP Pangram and ZuidWest Pangram. Do not delete WP Pangram yet.
4. Inspect the current state, migrate, and verify:

```bash
wp eval-file scripts/migrate-wp-pangram.php status
wp eval-file scripts/migrate-wp-pangram.php migrate
wp eval-file scripts/migrate-wp-pangram.php verify
```

On multisite, run all three commands separately for every site with `--url=<site-url>`. The script deliberately retains the legacy table and options for rollback and never prints the API key. If `WP_PANGRAM_API_KEY` is defined in `wp-config.php`, rename it to `ZW_PANGRAM_API_KEY` before activating ZuidWest Pangram.

After verification, activate ZuidWest Pangram, test the API connection and queue, and check that only `zw_pangram_tick` is scheduled. Keep the old plugin and data until the production migration has been accepted.

## Development

```bash
composer install
npm ci
composer lint
composer stan
npm run lint
npm run env:start
npm run test:php
```

Docker is required for the PHPUnit test environment.

`npm run lint` runs Biome over the admin JavaScript and CSS in `assets/`; `npm run lint:fix` applies its fixes.

Reporting-query measurements and the author-statistics latency budget are documented in [docs/performance.md](docs/performance.md).

## License

GPL-2.0-or-later
