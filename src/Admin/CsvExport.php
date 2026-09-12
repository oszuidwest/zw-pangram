<?php
/**
 * CSV export.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Admin;

/**
 * Streams the filtered results as CSV.
 */
final class CsvExport
{
    public const ACTION = 'zw_pangram_export_csv';
    public const NONCE = 'zw_pangram_export';
    public const PAGE_SIZE = 500;

    /** Registers the admin-post export hook. */
    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
    }

    /**
     * Builds a nonce-protected export URL.
     *
     * @param ResultsFilters $filters Export filters.
     */
    public static function url(ResultsFilters $filters): string
    {
        return wp_nonce_url(add_query_arg(array_merge(['action' => self::ACTION], $filters->toArgs()), admin_url('admin-post.php')), self::NONCE);
    }

    /** Sends export headers, streams the CSV, and exits. */
    public static function handle(): void
    {
        AdminPage::guard(self::NONCE);
        $filters = ResultsFilters::fromRequest(wp_unslash($_GET)); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Recommended -- Nonce verified in guard(); validated field by field.

        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Hosts may prohibit runtime changes.
        }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="pangram-results-' . gmdate('Ymd-His') . '.csv"');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        self::stream($filters);
        exit;
    }

    /**
     * Streams filtered results to php://output.
     *
     * @param ResultsFilters $filters Export filters.
     */
    public static function stream(ResultsFilters $filters): void
    {
        $out = fopen('php://output', 'w');
        if ($out === false) {
            return;
        }
        echo "\xEF\xBB\xBF";
        fputcsv($out, ['post_id', 'title', 'url', 'edit_url', 'author', 'post_date', 'label', 'headline', 'fraction_ai', 'fraction_ai_assisted', 'fraction_human', 'scanned_at', 'stale', 'status', 'error', 'model']);

        $query = new ResultsQuery();
        $page = 1;
        while (true) {
            $rows = $query->rows($filters, self::PAGE_SIZE, $page);
            if ($rows === []) {
                break;
            }
            _prime_post_caches(array_column($rows, 'ID'), true, false); // Category-based permalinks may require term caches.
            cache_users(array_unique(array_column($rows, 'post_author')));
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'sanitizeCell'], [
                    (string) $row['ID'],
                    $row['post_title'],
                    (string) get_permalink($row['ID']),
                    (string) get_edit_post_link($row['ID'], 'raw'),
                    (string) get_the_author_meta('display_name', $row['post_author']),
                    $row['post_date'],
                    (string) ($row['prediction_short'] ?? ''),
                    (string) ($row['headline'] ?? ''),
                    $row['fraction_ai'] === null ? '' : number_format($row['fraction_ai'], 4, '.', ''),
                    $row['fraction_ai_assisted'] === null ? '' : number_format($row['fraction_ai_assisted'], 4, '.', ''),
                    $row['fraction_human'] === null ? '' : number_format($row['fraction_human'], 4, '.', ''),
                    (string) ($row['scanned_at'] ?? ''),
                    $row['result_stale'] ? '1' : '0',
                    $row['result_status'],
                    (string) ($row['result_error'] ?? ''),
                    (string) ($row['model'] ?? ''),
                ]));
            }
            fflush($out);
            wp_cache_flush_runtime();
            if (count($rows) < self::PAGE_SIZE) {
                break;
            }
            $page++;
        }
    }

    /**
     * Prefixes cells that spreadsheet apps may parse as formulas.
     *
     * @param string $value Cell value.
     */
    public static function sanitizeCell(string $value): string
    {
        $trimmed = ltrim($value, " \t\r\n");
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
