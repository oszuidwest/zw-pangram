<?php
/**
 * Per-author statistics.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Admin;

use ZWPangram\Store\ItemsRepository;
use ZWPangram\Support\Settings;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate over the plugin table; cached in a versioned transient.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- SQL is built from constants plus generated placeholder lists and always prepared.

/**
 * Aggregates successful results by author for a date range.
 *
 * @phpstan-type AuthorRow array{author_id: int, name: string, scanned: int, avg_ai: float, avg_assisted: float, n_ai: int, n_mixed: int, n_human: int}
 */
final class AuthorStats
{
    public const TTL = 10 * MINUTE_IN_SECONDS;

    /**
     * Returns author statistics ordered by scan count.
     *
     * @param ResultsFilters $filters Date-range filters.
     * @return list<AuthorRow>
     */
    public function compute(ResultsFilters $filters): array
    {
        $types = Settings::get()['post_types'];
        $key = 'zw_pangram_stats_' . ItemsRepository::statsVersion() . '_' . md5(wp_json_encode([$types, $filters->from, $filters->to]) ?: '');
        $cached = get_transient($key);
        if (is_array($cached)) {
            /** @var list<AuthorRow> $cached */
            return $cached;
        }

        global $wpdb;
        // Match the results table's successful-scan and date-range scope.
        $statsFilters = ResultsFilters::fromRequest([
            'status' => 'ok',
            'from' => $filters->from,
            'to' => $filters->to,
        ]);
        [$where, $args] = (new ResultsQuery())->where($statsFilters);
        $sql = "SELECT p.post_author, COUNT(*) AS scanned, AVG(i.fraction_ai) AS avg_ai, AVG(i.fraction_ai_assisted) AS avg_assisted,
                       SUM(i.prediction_short = 'AI') AS n_ai, SUM(i.prediction_short = 'Mixed') AS n_mixed, SUM(i.prediction_short = 'Human') AS n_human
                FROM %i AS p INNER JOIN %i AS i ON i.post_id = p.ID
                WHERE " . $where . '
                GROUP BY p.post_author ORDER BY scanned DESC, p.post_author ASC';
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $wpdb->posts, ItemsRepository::tableName(), ...$args), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders generated in where().

        $ids = array_map('intval', array_column($rows, 'post_author'));
        $names = $ids === [] ? [] : wp_list_pluck(get_users(['include' => $ids, 'fields' => ['ID', 'display_name']]), 'display_name', 'ID');

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $authorId = (int) $row['post_author'];
            $out[] = [
                'author_id' => $authorId,
                /* translators: %d: user ID */
                'name' => (string) ($names[$authorId] ?? sprintf(__('User #%d', 'zw-pangram'), $authorId)),
                'scanned' => (int) $row['scanned'],
                'avg_ai' => (float) $row['avg_ai'],
                'avg_assisted' => (float) $row['avg_assisted'],
                'n_ai' => (int) $row['n_ai'],
                'n_mixed' => (int) $row['n_mixed'],
                'n_human' => (int) $row['n_human'],
            ];
        }
        set_transient($key, $out, self::TTL);
        // Let subsequent writes advance the cache version within this request.
        ItemsRepository::resetRequestState();
        return $out;
    }
}
