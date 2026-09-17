<?php
/**
 * Remove plugin-owned data on uninstall.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

$zw_pangram_cleanup = static function (): void {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Drop the plugin-owned table on uninstall.
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'zw_pangram_items'));

    foreach ([
        'zw_pangram_settings',
        'zw_pangram_db_version',
        'zw_pangram_queue_state',
        'zw_pangram_open_job',
        'zw_pangram_pending_submission',
        'zw_pangram_error_log',
        'zw_pangram_tick_lock',
        'zw_pangram_last_tick',
        'zw_pangram_unit_cap',
        'zw_pangram_schedule_error',
        'zw_pangram_results_per_page',
    ] as $zw_pangram_option) {
        delete_option($zw_pangram_option);
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prefix delete of plugin user meta (screen options).
    $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE meta_key = %s', $wpdb->usermeta, 'zw_pangram_results_per_page'));

    wp_clear_scheduled_hook('zw_pangram_tick');
};

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $zw_pangram_site_id) {
        switch_to_blog((int) $zw_pangram_site_id);
        $zw_pangram_cleanup();
        restore_current_blog();
    }
} else {
    $zw_pangram_cleanup();
}

wp_cache_flush();
