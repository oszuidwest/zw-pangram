<?php
/**
 * Plugin Name:       ZuidWest Pangram
 * Plugin URI:        https://github.com/oszuidwest/zw-pangram
 * Description:       Scores existing posts with the Pangram Labs AI text detector via its bulk API and reports the results in wp-admin.
 * Version:           0.1.0
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Author:            Streekomroep ZuidWest
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zw-pangram
 * Domain Path:       /languages
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('ZW_PANGRAM_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version']);
define('ZW_PANGRAM_FILE', __FILE__);
define('ZW_PANGRAM_DIR', plugin_dir_path(__FILE__));
define('ZW_PANGRAM_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'ZWPangram\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
require_once __DIR__ . '/src/functions.php';

// Register the recurrence before activation can schedule it.
add_filter('cron_schedules', [\ZWPangram\Cron\Scheduler::class, 'addSchedule']); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- One-minute queue tick, documented in README.

register_activation_hook(__FILE__, [\ZWPangram\Activation::class, 'activate']);
register_deactivation_hook(__FILE__, [\ZWPangram\Activation::class, 'deactivate']);

add_action('plugins_loaded', [\ZWPangram\Plugin::class, 'boot']);
