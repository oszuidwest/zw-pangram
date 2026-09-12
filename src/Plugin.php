<?php
/**
 * Composition root.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram;

use ZWPangram\Admin\Actions;
use ZWPangram\Admin\AdminPage;
use ZWPangram\Admin\Ajax;
use ZWPangram\Admin\CsvExport;
use ZWPangram\Admin\SettingsTab;
use ZWPangram\Cron\Scheduler;
use ZWPangram\Hooks\PostHooks;

/**
 * Registers plugin hooks.
 */
final class Plugin
{
    /** Boots the plugin. */
    public static function boot(): void
    {
        // Schema must be current before any cron callback runs against it.
        if (!Activation::maybeUpgrade()) {
            add_action('admin_notices', static function (): void {
                wp_admin_notice(esc_html__('ZuidWest Pangram could not create its database table. See the error log and your database permissions.', 'zw-pangram'), ['type' => 'error']);
            });
            return;
        }

        Scheduler::register();
        PostHooks::register();

        if (is_admin()) {
            AdminPage::register();
            SettingsTab::registerSettings();
            Actions::register();
            Ajax::register();
            CsvExport::register();
        }
    }
}
