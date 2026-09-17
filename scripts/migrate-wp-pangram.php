<?php
/**
 * Migrate a single site from WP Pangram to ZuidWest Pangram.
 *
 * Usage:
 *   wp eval-file scripts/migrate-wp-pangram.php status
 *   wp eval-file scripts/migrate-wp-pangram.php migrate
 *   wp eval-file scripts/migrate-wp-pangram.php verify
 *
 * Run this once per site (use --url on multisite). Both plugins must be
 * inactive for `migrate`. Legacy data is copied and deliberately retained
 * so that the old plugin remains available as a rollback path.
 *
 * @package ZW_Pangram
 */

defined('ABSPATH') || exit;

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('This migration must be run with WP-CLI.');
}

// This maintenance script intentionally reads and writes plugin-owned tables directly.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

global $wpdb;

$zw_pangram_mode = isset($args[0]) && is_string($args[0]) ? sanitize_key($args[0]) : 'status';
if (!in_array($zw_pangram_mode, ['status', 'migrate', 'verify'], true)) {
    WP_CLI::error('Unknown mode. Use status, migrate, or verify.');
}

$zw_pangram_legacy_table = $wpdb->prefix . 'pangram_items';
$zw_pangram_target_table = $wpdb->prefix . 'zw_pangram_items';
$zw_pangram_marker_option = 'zw_pangram_legacy_migration';
$zw_pangram_columns = [
    'id',
    'post_id',
    'queue_status',
    'claim_token',
    'claimed_at',
    'bulk_id',
    'last_bulk_id',
    'submitted_hash',
    'submitted_modified_gmt',
    'attempts',
    'next_attempt_at',
    'force_rescan',
    'rescan_requested',
    'queued_at',
    'last_error',
    'result_status',
    'prediction_short',
    'fraction_ai',
    'fraction_ai_assisted',
    'fraction_human',
    'headline',
    'model',
    'api_version',
    'scanned_at',
    'result_hash',
    'result_modified_gmt',
    'result_stale',
    'result_error',
    'response_json',
    'updated_at',
];
$zw_pangram_option_map = [
    'wp_pangram_settings' => 'zw_pangram_settings',
    'wp_pangram_queue_state' => 'zw_pangram_queue_state',
    'wp_pangram_error_log' => 'zw_pangram_error_log',
    'wp_pangram_unit_cap' => 'zw_pangram_unit_cap',
    'wp_pangram_stats_version' => 'zw_pangram_stats_version',
    'wp_pangram_results_per_page' => 'zw_pangram_results_per_page',
];

/** @var Closure(string): bool $zw_pangram_table_exists */
$zw_pangram_table_exists = static function (string $table) use ($wpdb): bool {
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
};

/** @var Closure(string): int $zw_pangram_row_count */
$zw_pangram_row_count = static function (string $table) use ($wpdb): int {
    return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
};

/** @var Closure(string): bool $zw_pangram_option_exists */
$zw_pangram_option_exists = static function (string $option) use ($wpdb): bool {
    return null !== $wpdb->get_var($wpdb->prepare(
        'SELECT option_id FROM %i WHERE option_name = %s LIMIT 1',
        $wpdb->options,
        $option
    ));
};

/** @var Closure(string): bool $zw_pangram_plugin_active */
$zw_pangram_plugin_active = static function (string $mainFile): bool {
    $active = array_map('strval', (array) get_option('active_plugins', []));
    $network = array_map('strval', array_keys((array) get_site_option('active_sitewide_plugins', [])));
    foreach (array_merge($active, $network) as $plugin) {
        if ($plugin === $mainFile || str_ends_with($plugin, '/' . $mainFile)) {
            return true;
        }
    }
    return false;
};

/** @var Closure(): bool $zw_pangram_tables_match */
$zw_pangram_tables_match = static function () use (
    $wpdb,
    $zw_pangram_columns,
    $zw_pangram_legacy_table,
    $zw_pangram_target_table,
    $zw_pangram_table_exists,
    $zw_pangram_row_count
): bool {
    if (!$zw_pangram_table_exists($zw_pangram_legacy_table) || !$zw_pangram_table_exists($zw_pangram_target_table)) {
        return false;
    }
    if ($zw_pangram_row_count($zw_pangram_legacy_table) !== $zw_pangram_row_count($zw_pangram_target_table)) {
        return false;
    }

    $comparisons = implode(' AND ', array_map(
        static fn (string $column): string => sprintf('s.`%1$s` <=> t.`%1$s`', $column),
        $zw_pangram_columns
    ));
    $different = $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM %i AS s LEFT JOIN %i AS t ON t.id = s.id WHERE t.id IS NULL OR NOT ({$comparisons}) LIMIT 1",
        $zw_pangram_legacy_table,
        $zw_pangram_target_table
    ));
    if ($different !== null) {
        return false;
    }

    $extra = $wpdb->get_var($wpdb->prepare(
        'SELECT 1 FROM %i AS t LEFT JOIN %i AS s ON s.id = t.id WHERE s.id IS NULL LIMIT 1',
        $zw_pangram_target_table,
        $zw_pangram_legacy_table
    ));
    return $extra === null;
};

/** @var Closure(): bool $zw_pangram_options_match */
$zw_pangram_options_match = static function () use ($zw_pangram_option_map, $zw_pangram_option_exists): bool {
    foreach ($zw_pangram_option_map as $legacy => $target) {
        if (!$zw_pangram_option_exists($legacy)) {
            continue;
        }
        if (!$zw_pangram_option_exists($target) || get_option($legacy) !== get_option($target)) {
            return false;
        }
    }
    return true;
};

/** @var Closure(): bool $zw_pangram_usermeta_matches */
$zw_pangram_usermeta_matches = static function () use ($wpdb): bool {
    $userIds = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
        'SELECT DISTINCT user_id FROM %i WHERE meta_key = %s',
        $wpdb->usermeta,
        'wp_pangram_results_per_page'
    )));
    foreach ($userIds as $userId) {
        if (!metadata_exists('user', $userId, 'zw_pangram_results_per_page')) {
            return false;
        }
        if (get_user_meta($userId, 'wp_pangram_results_per_page', true) !== get_user_meta($userId, 'zw_pangram_results_per_page', true)) {
            return false;
        }
    }
    return true;
};

/** @var Closure(string): array<string, int> $zw_pangram_queue_counts */
$zw_pangram_queue_counts = static function (string $table) use ($wpdb): array {
    $counts = [];
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT queue_status, COUNT(*) AS item_count FROM %i GROUP BY queue_status ORDER BY queue_status',
        $table
    ), ARRAY_A);
    foreach ((array) $rows as $row) {
        if (is_array($row) && isset($row['queue_status'], $row['item_count'])) {
            $counts[(string) $row['queue_status']] = (int) $row['item_count'];
        }
    }
    return $counts;
};

/** @var Closure(): void $zw_pangram_print_status */
$zw_pangram_print_status = static function () use (
    $wpdb,
    $zw_pangram_legacy_table,
    $zw_pangram_target_table,
    $zw_pangram_marker_option,
    $zw_pangram_table_exists,
    $zw_pangram_row_count,
    $zw_pangram_queue_counts,
    $zw_pangram_plugin_active,
    $zw_pangram_option_exists
): void {
    WP_CLI::log('Site: ' . home_url('/'));
    WP_CLI::log('Blog ID: ' . (string) get_current_blog_id());
    WP_CLI::log('WP Pangram active: ' . ($zw_pangram_plugin_active('wp-pangram.php') ? 'yes' : 'no'));
    WP_CLI::log('ZuidWest Pangram active: ' . ($zw_pangram_plugin_active('zw-pangram.php') ? 'yes' : 'no'));

    foreach ([$zw_pangram_legacy_table => 'Legacy table', $zw_pangram_target_table => 'Target table'] as $table => $label) {
        if (!$zw_pangram_table_exists($table)) {
            WP_CLI::log($label . ': missing (' . $table . ')');
            continue;
        }
        WP_CLI::log(sprintf('%s: %s rows (%s)', $label, number_format_i18n($zw_pangram_row_count($table)), $table));
        WP_CLI::log('  Queue: ' . wp_json_encode($zw_pangram_queue_counts($table)));
    }

    WP_CLI::log('Legacy open job: ' . ($zw_pangram_option_exists('wp_pangram_open_job') ? 'present' : 'absent'));
    WP_CLI::log('Legacy pending submission: ' . ($zw_pangram_option_exists('wp_pangram_pending_submission') ? 'present' : 'absent'));
    WP_CLI::log('Legacy stored API key: ' . (!empty(((array) get_option('wp_pangram_settings', []))['api_key']) ? 'present' : 'absent'));
    WP_CLI::log('Legacy API-key constant: ' . (defined('WP_PANGRAM_API_KEY') ? 'defined' : 'not defined'));
    WP_CLI::log('ZW API-key constant: ' . (defined('ZW_PANGRAM_API_KEY') ? 'defined' : 'not defined'));
    WP_CLI::log('Legacy cron event: ' . (wp_next_scheduled('wp_pangram_tick') === false ? 'absent' : 'scheduled'));
    WP_CLI::log('ZW cron event: ' . (wp_next_scheduled('zw_pangram_tick') === false ? 'absent' : 'scheduled'));
    WP_CLI::log('Migration marker: ' . ($zw_pangram_option_exists($zw_pangram_marker_option) ? 'present' : 'absent'));

    $legacyScreenOptions = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(DISTINCT user_id) FROM %i WHERE meta_key = %s',
        $wpdb->usermeta,
        'wp_pangram_results_per_page'
    ));
    WP_CLI::log('Legacy per-page user settings: ' . (string) $legacyScreenOptions);
};

if ($zw_pangram_mode === 'status') {
    $zw_pangram_print_status();
    exit(0);
}

if (!$zw_pangram_table_exists($zw_pangram_legacy_table)) {
    WP_CLI::error('Legacy table not found: ' . $zw_pangram_legacy_table);
}

if ($zw_pangram_mode === 'verify') {
    $tablesMatch = $zw_pangram_tables_match();
    $optionsMatch = $zw_pangram_options_match();
    $usermetaMatches = $zw_pangram_usermeta_matches();
    $zw_pangram_print_status();
    WP_CLI::log('Table contents match: ' . ($tablesMatch ? 'yes' : 'no'));
    WP_CLI::log('Migrated options match: ' . ($optionsMatch ? 'yes' : 'no'));
    WP_CLI::log('Per-page user settings match: ' . ($usermetaMatches ? 'yes' : 'no'));
    if (!$tablesMatch || !$optionsMatch || !$usermetaMatches) {
        WP_CLI::error('Migration verification failed. No data was changed by verify.');
    }
    WP_CLI::success('Legacy and ZuidWest Pangram data match.');
    exit(0);
}

if ($zw_pangram_plugin_active('wp-pangram.php') || $zw_pangram_plugin_active('zw-pangram.php')) {
    WP_CLI::error('Deactivate both WP Pangram and ZuidWest Pangram before migrating.');
}
if ($zw_pangram_option_exists('wp_pangram_open_job')) {
    WP_CLI::error('A legacy bulk job is still open. Reactivate WP Pangram and let it finish before migrating.');
}
if ($zw_pangram_option_exists('wp_pangram_pending_submission')) {
    WP_CLI::error('A legacy submission has an unknown outcome. Resolve it with WP Pangram before migrating.');
}
if ($zw_pangram_option_exists('zw_pangram_open_job') || $zw_pangram_option_exists('zw_pangram_pending_submission')) {
    WP_CLI::error('ZuidWest Pangram still has bulk-job state. Resolve it before migrating.');
}

$zw_pangram_legacy_queue = $zw_pangram_queue_counts($zw_pangram_legacy_table);
if (($zw_pangram_legacy_queue['processing'] ?? 0) > 0 || ($zw_pangram_legacy_queue['submitted'] ?? 0) > 0) {
    WP_CLI::error('Legacy processing/submitted rows remain. Let WP Pangram recover or finish them before migrating.');
}
if (($zw_pangram_legacy_queue['pending'] ?? 0) > 0) {
    $zw_pangram_legacy_state = get_option('wp_pangram_queue_state', []);
    if (!is_array($zw_pangram_legacy_state) || empty($zw_pangram_legacy_state['paused'])) {
        WP_CLI::error('Pending legacy rows remain, but the legacy queue was not paused. Reactivate WP Pangram, pause it, and deactivate it again.');
    }
}

foreach ($zw_pangram_option_map as $legacy => $target) {
    if (!$zw_pangram_option_exists($legacy) || !$zw_pangram_option_exists($target)) {
        continue;
    }
    if (get_option($legacy) !== get_option($target)) {
        WP_CLI::error(sprintf('Conflicting target option %s already exists. Resolve it before migrating.', $target));
    }
}

$zw_pangram_legacy_user_ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
    'SELECT DISTINCT user_id FROM %i WHERE meta_key = %s',
    $wpdb->usermeta,
    'wp_pangram_results_per_page'
)));
foreach ($zw_pangram_legacy_user_ids as $zw_pangram_user_id) {
    if (!metadata_exists('user', $zw_pangram_user_id, 'zw_pangram_results_per_page')) {
        continue;
    }
    if (get_user_meta($zw_pangram_user_id, 'wp_pangram_results_per_page', true) !== get_user_meta($zw_pangram_user_id, 'zw_pangram_results_per_page', true)) {
        WP_CLI::error(sprintf('User %d already has a conflicting ZW results-per-page setting.', $zw_pangram_user_id));
    }
}

$zw_pangram_legacy_rows = $zw_pangram_row_count($zw_pangram_legacy_table);
$zw_pangram_copied_rows = 0;
if (!$zw_pangram_table_exists($zw_pangram_target_table)) {
    $zw_pangram_created = $wpdb->query($wpdb->prepare(
        'CREATE TABLE %i LIKE %i',
        $zw_pangram_target_table,
        $zw_pangram_legacy_table
    ));
    if ($zw_pangram_created === false) {
        WP_CLI::error('Could not create the target table: ' . $wpdb->last_error);
    }
    WP_CLI::log('Created target table ' . $zw_pangram_target_table . '.');
}

$zw_pangram_target_rows = $zw_pangram_row_count($zw_pangram_target_table);
if ($zw_pangram_target_rows > 0) {
    if (!$zw_pangram_tables_match()) {
        WP_CLI::error('The target table already contains different data; refusing to merge or overwrite it.');
    }
    WP_CLI::log('Target table already contains an exact copy; skipping row copy.');
} elseif ($zw_pangram_legacy_rows > 0) {
    $zw_pangram_transactional_tables = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES AS t INNER JOIN information_schema.ENGINES AS e USING (ENGINE) WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME IN (%s, %s) AND e.TRANSACTIONS = 'YES'",
        $zw_pangram_legacy_table,
        $zw_pangram_target_table
    ));
    if ($zw_pangram_transactional_tables !== 2) {
        WP_CLI::error('The legacy and target tables must use transactional storage engines.');
    }
    $zw_pangram_column_sql = implode(', ', array_map(
        static fn (string $column): string => '`' . $column . '`',
        $zw_pangram_columns
    ));
    $wpdb->query('START TRANSACTION');
    $zw_pangram_inserted = $wpdb->query($wpdb->prepare(
        "INSERT INTO %i ({$zw_pangram_column_sql}) SELECT {$zw_pangram_column_sql} FROM %i",
        $zw_pangram_target_table,
        $zw_pangram_legacy_table
    ));
    if ($zw_pangram_inserted === false || $zw_pangram_inserted !== $zw_pangram_legacy_rows) {
        $zw_pangram_error = $wpdb->last_error;
        $wpdb->query('ROLLBACK');
        WP_CLI::error(sprintf(
            'Table copy failed: expected %1$d rows, copied %2$s. %3$s',
            $zw_pangram_legacy_rows,
            $zw_pangram_inserted === false ? 'none' : (string) $zw_pangram_inserted,
            $zw_pangram_error
        ));
    }
    if ($wpdb->query('COMMIT') === false) {
        WP_CLI::error('Could not commit the table copy: ' . $wpdb->last_error);
    }
    $zw_pangram_copied_rows = $zw_pangram_inserted;
    WP_CLI::log(sprintf('Copied %d rows to %s.', $zw_pangram_inserted, $zw_pangram_target_table));
}

if (!$zw_pangram_tables_match()) {
    WP_CLI::error('Post-copy table verification failed. The legacy table is unchanged; inspect the target before retrying.');
}

foreach ($zw_pangram_option_map as $legacy => $target) {
    if (!$zw_pangram_option_exists($legacy) || $zw_pangram_option_exists($target)) {
        continue;
    }
    if (!add_option($target, get_option($legacy), '', false)) {
        WP_CLI::error('Could not create target option ' . $target . '.');
    }
    WP_CLI::log(sprintf('Copied option %s to %s.', $legacy, $target));
}

foreach ($zw_pangram_legacy_user_ids as $zw_pangram_user_id) {
    if (metadata_exists('user', $zw_pangram_user_id, 'zw_pangram_results_per_page')) {
        continue;
    }
    $zw_pangram_screen_value = get_user_meta($zw_pangram_user_id, 'wp_pangram_results_per_page', true);
    if (add_user_meta($zw_pangram_user_id, 'zw_pangram_results_per_page', $zw_pangram_screen_value, true) === false) {
        WP_CLI::error(sprintf('Could not migrate the results-per-page setting for user %d.', $zw_pangram_user_id));
    }
}

if (wp_clear_scheduled_hook('wp_pangram_tick') === false) {
    WP_CLI::error('Could not clear the legacy cron hook.');
}
$zw_pangram_marker = [
    'source' => 'wp-pangram',
    'migrated_at_utc' => gmdate('c'),
    'blog_id' => get_current_blog_id(),
    'rows' => $zw_pangram_legacy_rows,
];
update_option($zw_pangram_marker_option, $zw_pangram_marker, false);
if (get_option($zw_pangram_marker_option, null) !== $zw_pangram_marker) {
    WP_CLI::error('Could not record the migration marker.');
}

if (!$zw_pangram_options_match() || !$zw_pangram_usermeta_matches()) {
    WP_CLI::error('Option or user-setting verification failed. Table data and legacy data remain intact.');
}

WP_CLI::success(sprintf(
    'Migration completed for blog %1$d: %2$d source rows verified, %3$d copied this run, and legacy data retained. Activate ZuidWest Pangram, verify it, and only then consider removing WP Pangram.',
    get_current_blog_id(),
    $zw_pangram_legacy_rows,
    $zw_pangram_copied_rows
));
