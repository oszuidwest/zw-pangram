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
$zw_pangram_author_stats_budget_ms = 150.0;
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
    'author stats (uncached)' => static fn () => (new AuthorStats())->compute(ResultsFilters::fromRequest([])),
];

$zw_pangram_percentile = static function (array $values, float $p): float {
    sort($values);
    $index = (int) ceil($p * count($values)) - 1;
    return $values[max(0, min(count($values) - 1, $index))];
};

$zw_pangram_items = ItemsRepository::tableName();
$zw_pangram_item_count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $zw_pangram_items));
$zw_pangram_success_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE result_status = 'ok'", $zw_pangram_items));
$zw_pangram_author_count = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(DISTINCT p.post_author) FROM %i AS p INNER JOIN %i AS i ON i.post_id = p.ID WHERE i.result_status = 'ok'",
    $wpdb->posts,
    $zw_pangram_items
));
$zw_pangram_database_version = (string) $wpdb->get_var('SELECT VERSION()');

WP_CLI::log(sprintf('Environment: WordPress %s; PHP %s; database %s.', get_bloginfo('version'), PHP_VERSION, $zw_pangram_database_version));
WP_CLI::log(sprintf(
    'Dataset: %d plugin rows (%d successful) across %d authors; %d measured runs after one warm-up.',
    $zw_pangram_item_count,
    $zw_pangram_success_count,
    $zw_pangram_author_count,
    $zw_pangram_runs
));
WP_CLI::log(sprintf('Author statistics latency budget: p95 <= %.1f ms.', $zw_pangram_author_stats_budget_ms));
WP_CLI::log('');
WP_CLI::log(sprintf('%-45s %10s %10s %10s', 'query', 'median ms', 'p95 ms', 'max ms'));
$zw_pangram_results = [];
foreach ($zw_pangram_cases as $name => $fn) {
    $fn(); // Prime caches before measuring.
    $times = [];
    for ($i = 0; $i < $zw_pangram_runs; $i++) {
        $t = microtime(true);
        $fn();
        $times[] = (microtime(true) - $t) * 1000;
    }
    $median = $zw_pangram_percentile($times, 0.5);
    $p95 = $zw_pangram_percentile($times, 0.95);
    $maximum = max($times);
    $zw_pangram_results[$name] = ['median' => $median, 'p95' => $p95, 'max' => $maximum];
    WP_CLI::log(sprintf('%-45s %10.1f %10.1f %10.1f', $name, $median, $p95, $maximum));
}

$zw_pangram_author_stats_p95 = $zw_pangram_results['author stats (uncached)']['p95'];
WP_CLI::log(sprintf(
    'Author statistics budget: %s (p95 %.1f ms; limit %.1f ms).',
    $zw_pangram_author_stats_p95 <= $zw_pangram_author_stats_budget_ms ? 'PASS' : 'FAIL',
    $zw_pangram_author_stats_p95,
    $zw_pangram_author_stats_budget_ms
));

WP_CLI::log('');
WP_CLI::log('EXPLAIN of the list query ordered by AI fraction:');
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
