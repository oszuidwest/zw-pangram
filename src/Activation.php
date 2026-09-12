<?php
/**
 * Plugin lifecycle.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram;

use ZWPangram\Cron\Scheduler;
use ZWPangram\Store\ItemsRepository;
use ZWPangram\Support\ErrorLog;
use ZWPangram\Support\Lock;
use ZWPangram\Support\Settings;

/**
 * Manages the single-site plugin lifecycle and database schema.
 */
final class Activation
{
    public const DB_VERSION = '1';
    public const DB_VERSION_OPTION = 'zw_pangram_db_version';

    /**
     * Activates the plugin for a single site.
     *
     * @param bool $network_wide Whether activation is network-wide.
     */
    public static function activate(bool $network_wide = false): void
    {
        if ($network_wide && is_multisite()) {
            deactivate_plugins(plugin_basename(\ZW_PANGRAM_FILE), true, true);
            wp_die(
                esc_html__('ZuidWest Pangram does not support network activation. Activate it per site instead.', 'zw-pangram'),
                esc_html__('Plugin activation failed', 'zw-pangram'),
                ['back_link' => true]
            );
        }

        self::installSchema();
        add_option(Settings::OPTION, Settings::defaults(), '', false);
        Scheduler::ensureScheduled();
    }

    /** Unschedules work and removes the lock while retaining plugin data. */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(Scheduler::EVENT);
        delete_option(Lock::OPTION);
    }

    /**
     * Upgrades the schema before cron hooks are registered.
     *
     * Schema installation is idempotent, so a separate upgrade lock is unnecessary.
     *
     * @return bool Whether the schema is current.
     */
    public static function maybeUpgrade(): bool
    {
        return (string) get_option(self::DB_VERSION_OPTION, '') === self::DB_VERSION || self::installSchema();
    }

    /**
     * Restores a missing table after checking its memoized existence.
     *
     * @return bool Whether the table exists afterwards.
     */
    public static function ensureTable(): bool
    {
        if (ItemsRepository::tableExists()) {
            return true;
        }
        return self::installSchema();
    }

    /**
     * Installs the schema and records its verified version.
     *
     * @return bool Whether the schema is installed.
     */
    private static function installSchema(): bool
    {
        ItemsRepository::install();
        if (!ItemsRepository::tableExists()) {
            ErrorLog::add('The plugin table could not be created; check the database error log.');
            return false;
        }
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, true);
        return true;
    }
}
