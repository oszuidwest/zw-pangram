<?php
/**
 * Seeds Pangram results without firing post hooks.
 *
 * Usage (inside wp-env, from the plugin directory):
 *   wp eval-file scripts/seed-fixtures.php 50000
 *
 * @package ZW_Pangram
 */


use ZWPangram\Store\ItemsRepository;
use ZWPangram\Support\Dates;

if (!defined('WP_CLI') || !WP_CLI) {
    exit("Run through WP-CLI: wp eval-file scripts/seed-fixtures.php <count>\n");
}

$zw_pangram_count = isset($args[0]) && is_numeric($args[0]) ? max(1, (int) $args[0]) : 50000;
global $wpdb;

$zw_pangram_authors = [];
for ($i = 1; $i <= 25; $i++) {
    $login = 'pangram_author_' . $i;
    $user = get_user_by('login', $login);
    $zw_pangram_authors[] = $user instanceof WP_User ? (int) $user->ID : (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => 'author', 'display_name' => 'Author ' . $i]);
}

$zw_pangram_labels = ['AI', 'Mixed', 'Human'];
$zw_pangram_items = ItemsRepository::tableName();
$zw_pangram_batch = 500;
$zw_pangram_done = 0;
$zw_pangram_start = microtime(true);
$zw_pangram_content = str_repeat('Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor. ', 20);

while ($zw_pangram_done < $zw_pangram_count) {
    $n = min($zw_pangram_batch, $zw_pangram_count - $zw_pangram_done);
    $post_values = [];
    for ($i = 0; $i < $n; $i++) {
        $idx = $zw_pangram_done + $i;
        $author = $zw_pangram_authors[$idx % count($zw_pangram_authors)];
        $date = Dates::utcIn(-$idx * 600);
        $title = 'Seeded post ' . $idx;
        $post_values[] = $wpdb->prepare('(%d, %s, %s, %s, %s, %s, %s, %s, %s, %s)', $author, $date, $date, $zw_pangram_content, $title, 'publish', 'post', 'seeded-post-' . $idx, $date, $date);
    }
    $wpdb->query("INSERT INTO {$wpdb->posts} (post_author, post_date, post_date_gmt, post_content, post_title, post_status, post_type, post_name, post_modified, post_modified_gmt) VALUES " . implode(',', $post_values));
    $first_id = (int) $wpdb->insert_id;

    $item_values = [];
    for ($i = 0; $i < $n; $i++) {
        $post_id = $first_id + $i;
        $idx = $zw_pangram_done + $i;
        $status = $idx % 20 === 0 ? 'failed' : 'ok';
        $label = $zw_pangram_labels[$idx % 3];
        $ai = $status === 'ok' ? round(wp_rand(0, 10000) / 10000, 4) : null;
        $assisted = $status === 'ok' ? round((1 - $ai) * (wp_rand(0, 100) / 100), 4) : null;
        $human = $status === 'ok' ? round(1 - $ai - $assisted, 4) : null;
        $scanned = Dates::utcIn(-$idx * 60);
        $item_values[] = $wpdb->prepare(
            '(%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s)',
            $post_id,
            'done',
            $status,
            $status === 'ok' ? $label : null,
            $ai,
            $assisted,
            $human,
            $status === 'ok' ? 'Seeded ' . $label : null,
            'pangram-4',
            $scanned,
            $idx % 50 === 0 ? 1 : 0,
            $status === 'ok' ? '{"seeded":true}' : null,
            $scanned
        );
    }
    $wpdb->query("INSERT INTO {$zw_pangram_items} (post_id, queue_status, result_status, prediction_short, fraction_ai, fraction_ai_assisted, fraction_human, headline, model, scanned_at, result_stale, response_json, updated_at) VALUES " . implode(',', $item_values));

    $zw_pangram_done += $n;
    if ($zw_pangram_done % 5000 === 0 || $zw_pangram_done === $zw_pangram_count) {
        WP_CLI::log(sprintf('%d / %d posts seeded (%.1fs)', $zw_pangram_done, $zw_pangram_count, microtime(true) - $zw_pangram_start));
    }
}
(new ItemsRepository())->bumpStatsVersion();
WP_CLI::success(sprintf('Seeded %d posts with results.', $zw_pangram_count));
