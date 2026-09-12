<?php
/**
 * Results query.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Admin;

use ZWPangram\Store\ItemsRepository;
use ZWPangram\Support\Dates;
use ZWPangram\Support\Settings;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reporting queries on the plugin table joined to posts.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- SQL is built from constants plus generated placeholder lists and always prepared.

/**
 * Builds consistent result queries for the list table and CSV export.
 *
 * @phpstan-type ResultRow array{
 *   ID: int, post_title: string, post_author: int, post_date: string, post_status: string, post_type: string,
 *   result_status: string, prediction_short: string|null, fraction_ai: float|null, fraction_ai_assisted: float|null,
 *   fraction_human: float|null, headline: string|null, scanned_at: string|null, result_stale: bool, result_error: string|null,
 *   model: string|null, queue_status: string
 * }
 */
final class ResultsQuery
{
    /**
     * Counts rows matching the filters.
     *
     * @param ResultsFilters $filters Filters.
     */
    public function count(ResultsFilters $filters): int
    {
        global $wpdb;
        [$where, $args] = $this->where($filters);
        $sql = 'SELECT COUNT(*) FROM %i AS p INNER JOIN %i AS i ON i.post_id = p.ID AND i.result_status IS NOT NULL WHERE ' . $where;
        return (int) $wpdb->get_var($wpdb->prepare($sql, $wpdb->posts, ItemsRepository::tableName(), ...$args)); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders generated in where().
    }

    /**
     * Returns one page of matching rows.
     *
     * @param ResultsFilters $filters Filters.
     * @param int            $perPage Page size.
     * @param int            $page    1-based page.
     * @return list<ResultRow>
     */
    public function rows(ResultsFilters $filters, int $perPage, int $page): array
    {
        global $wpdb;
        [$where, $args] = $this->where($filters);
        $sql = 'SELECT p.ID, p.post_title, p.post_author, p.post_date, p.post_status, p.post_type,
                       i.result_status, i.prediction_short, i.fraction_ai, i.fraction_ai_assisted, i.fraction_human, i.headline,
                       i.scanned_at, i.result_stale, i.result_error, i.model, i.queue_status
                FROM %i AS p INNER JOIN %i AS i ON i.post_id = p.ID AND i.result_status IS NOT NULL
                WHERE ' . $where . ' ORDER BY ' . $this->orderBy($filters) . ' LIMIT %d OFFSET %d';
        $args[] = max(1, $perPage);
        $args[] = max(0, ($page - 1) * $perPage);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $wpdb->posts, ItemsRepository::tableName(), ...$args), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders generated in where().
        $out = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'ID' => (int) $row['ID'],
                'post_title' => (string) $row['post_title'],
                'post_author' => (int) $row['post_author'],
                'post_date' => (string) $row['post_date'],
                'post_status' => (string) $row['post_status'],
                'post_type' => (string) $row['post_type'],
                'result_status' => (string) $row['result_status'],
                'prediction_short' => $row['prediction_short'] !== null ? (string) $row['prediction_short'] : null,
                'fraction_ai' => $row['fraction_ai'] !== null ? (float) $row['fraction_ai'] : null,
                'fraction_ai_assisted' => $row['fraction_ai_assisted'] !== null ? (float) $row['fraction_ai_assisted'] : null,
                'fraction_human' => $row['fraction_human'] !== null ? (float) $row['fraction_human'] : null,
                'headline' => $row['headline'] !== null ? (string) $row['headline'] : null,
                'scanned_at' => $row['scanned_at'] !== null ? (string) $row['scanned_at'] : null,
                'result_stale' => (bool) $row['result_stale'],
                'result_error' => $row['result_error'] !== null ? (string) $row['result_error'] : null,
                'model' => $row['model'] !== null ? (string) $row['model'] : null,
                'queue_status' => (string) $row['queue_status'],
            ];
        }
        return $out;
    }

    /**
     * Builds filter clauses and placeholder arguments.
     *
     * @param ResultsFilters $filters Filters.
     * @return array{0: string, 1: list<mixed>}
     */
    public function where(ResultsFilters $filters): array
    {
        global $wpdb;
        $types = Settings::get()['post_types'];
        $clauses = ['p.post_type IN (' . implode(', ', array_fill(0, count($types), '%s')) . ')', "p.post_status NOT IN ('trash', 'auto-draft')"];
        $args = $types;

        if ($filters->status !== null) {
            $clauses[] = 'i.result_status = %s';
            $args[] = $filters->status;
        }
        if ($filters->label !== null) {
            $clauses[] = 'i.prediction_short = %s';
            $args[] = $filters->label;
        }
        if ($filters->author > 0) {
            $clauses[] = 'p.post_author = %d';
            $args[] = $filters->author;
        }
        if ($filters->stale) {
            $clauses[] = 'i.result_stale = 1';
        }
        if ($filters->from !== null) {
            $clauses[] = 'p.post_date >= %s';
            $args[] = Dates::startOfDay($filters->from);
        }
        if ($filters->to !== null) {
            $clauses[] = 'p.post_date <= %s';
            $args[] = Dates::endOfDay($filters->to);
        }
        if ($filters->search !== '') {
            $clauses[] = 'p.post_title LIKE %s';
            $args[] = '%' . $wpdb->esc_like($filters->search) . '%';
        }
        return [implode(' AND ', $clauses), $args];
    }

    /**
     * Builds an ORDER BY clause from validated filter values.
     *
     * @param ResultsFilters $filters Filters.
     */
    private function orderBy(ResultsFilters $filters): string
    {
        $dir = $filters->order === 'ASC' ? 'ASC' : 'DESC';
        return match ($filters->orderby) {
            'date' => "p.post_date {$dir}, p.ID {$dir}",
            'scanned_at' => "ISNULL(i.scanned_at) ASC, i.scanned_at {$dir}, p.ID {$dir}",
            default => "ISNULL(i.fraction_ai) ASC, i.fraction_ai {$dir}, p.ID DESC",
        };
    }
}
