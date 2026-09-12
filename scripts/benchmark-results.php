<?php
/**
 * Benchmarks reporting queries and prints percentiles with query plans.
 *
 * Usage (inside wp-env, from the plugin directory):
 *   wp eval-file scripts/benchmark-results.php
 *
 * @package ZW_Pangram
 */


use ZWPangram\Admin\AuthorStats;
use ZWPangram\Admin\ResultsFilters;
use ZWPangram\Admin\ResultsQuery;
use ZWPangram\Store\ItemsRepository;

if (!defined('WP_CLI') || !WP_CLI) {
    exit("Run through WP-CLI: wp eval-file scripts/benchmark-results.php\n");
}

global $wpdb;
$zw_pangram_runs = 20;
$zw_pangram_query = new ResultsQuery();
$zw_pangram_cases = [
    'list default (date desc, page 1)' => static fn () => $zw_pangram_query->rows(ResultsFilters::fromRequest([]), 20, 1),
    'list page 500' => static fn () => $zw_pangram_query->rows(ResultsFilters::fromRequest([]), 20, 500),
    'count default' => static fn () => $zw_pangram_query->count(ResultsFilters::fromRequest([])),
    'list by fraction_ai' => static fn () => $zw_pangram_query->rows(ResultsFilters::fromRequest(['orderby' => 'fraction_ai']), 20, 1),
    'list stale only' => static fn () => $zw_pangram_query->rows(ResultsFilters::fromRequest(['stale' => '1']), 20, 1),
    'list label AI + author' => static fn () => $zw_pangram_query->rows(ResultsFilters::fromRequest(['label' => 'AI', 'author' => 2]), 20, 1),
    'title search' => static fn () => $zw_pangram_query->rows(ResultsFilters::fromRequest(['s' => 'post 4']), 20, 1),
    'csv page (500 rows)' => static fn () => $zw_pangram_query->rows(ResultsFilters::fromRequest([]), 500, 3),
    'author stats (uncached)' => static function () {
        delete_transient('zw_pangram_stats_' . ItemsRepository::statsVersion() . '_' . md5((string) wp_json_encode([['post'], null, null])));
        return (new AuthorStats())->compute(ResultsFilters::fromRequest([]));
    },
];

$zw_pangram_percentile = static function (array $values, float $p): float {
    sort($values);
    $index = (int) ceil($p * count($values)) - 1;
    return $values[max(0, min(count($values) - 1, $index))];
};

WP_CLI::log(sprintf('%-45s %10s %10s %10s', 'query', 'median ms', 'p95 ms', 'max ms'));
foreach ($zw_pangram_cases as $name => $fn) {
    $fn(); // Prime caches before measuring.
    $times = [];
    for ($i = 0; $i < $zw_pangram_runs; $i++) {
        $t = microtime(true);
        $fn();
        $times[] = (microtime(true) - $t) * 1000;
    }
    WP_CLI::log(sprintf('%-45s %10.1f %10.1f %10.1f', $name, $zw_pangram_percentile($times, 0.5), $zw_pangram_percentile($times, 0.95), max($times)));
}

WP_CLI::log('');
WP_CLI::log('EXPLAIN of the list query ordered by AI fraction:');
$zw_pangram_items = ItemsRepository::tableName();
$zw_pangram_explain = $wpdb->get_results(
    "EXPLAIN SELECT p.ID FROM {$wpdb->posts} AS p INNER JOIN {$zw_pangram_items} AS i ON i.post_id = p.ID AND i.result_status IS NOT NULL
     WHERE p.post_type IN ('post') AND p.post_status NOT IN ('trash','auto-draft')
     ORDER BY ISNULL(i.fraction_ai) ASC, i.fraction_ai DESC, p.ID DESC LIMIT 20",
    ARRAY_A
);
foreach ((array) $zw_pangram_explain as $row) {
    WP_CLI::log(wp_json_encode($row));
}
WP_CLI::log('EXPLAIN of the author statistics query:');
$zw_pangram_explain = $wpdb->get_results(
    "EXPLAIN SELECT p.post_author, COUNT(*) FROM {$wpdb->posts} AS p INNER JOIN {$zw_pangram_items} AS i ON i.post_id = p.ID AND i.result_status = 'ok'
     WHERE p.post_type IN ('post') AND p.post_status NOT IN ('trash','auto-draft') GROUP BY p.post_author",
    ARRAY_A
);
foreach ((array) $zw_pangram_explain as $row) {
    WP_CLI::log(wp_json_encode($row));
}
