<?php
/**
 * PHPUnit bootstrap: WordPress test library with the plugin loaded as an mu-plugin.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Prefer wp-env's matching test library and fall back to wp-phpunit.
$zw_pangram_tests_dir = getenv('WP_TESTS_DIR') ?: getenv('WP_PHPUNIT__DIR');
if (!is_string($zw_pangram_tests_dir) || !is_dir($zw_pangram_tests_dir)) {
    fwrite(STDERR, "Neither WP_TESTS_DIR nor WP_PHPUNIT__DIR points to the WordPress test library.\n");
    exit(1);
}

$zw_pangram_tests_config = getenv('WP_PHPUNIT__TESTS_CONFIG');
if (is_string($zw_pangram_tests_config) && $zw_pangram_tests_config !== '') {
    $zw_pangram_tests_config = str_starts_with($zw_pangram_tests_config, '/') ? $zw_pangram_tests_config : dirname(__DIR__) . '/' . $zw_pangram_tests_config;
    if (is_file($zw_pangram_tests_config)) {
        define('WP_TESTS_CONFIG_FILE_PATH', $zw_pangram_tests_config);
    }
}

require_once $zw_pangram_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__) . '/zw-pangram.php';
});

require $zw_pangram_tests_dir . '/includes/bootstrap.php';
