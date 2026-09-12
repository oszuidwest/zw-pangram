<?php
/**
 * Test configuration for the WordPress PHPUnit test library.
 *
 * Uses wp-env credentials with an isolated table prefix.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

$zw_pangram_env = static function (string $name, string $default): string {
    $value = getenv($name);
    return is_string($value) && $value !== '' ? $value : $default;
};

define('DB_NAME', $zw_pangram_env('WORDPRESS_DB_NAME', 'wordpress'));
define('DB_USER', $zw_pangram_env('WORDPRESS_DB_USER', 'root'));
define('DB_PASSWORD', $zw_pangram_env('WORDPRESS_DB_PASSWORD', 'password'));
define('DB_HOST', $zw_pangram_env('WORDPRESS_DB_HOST', 'mysql'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required by the test library.

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'ZuidWest Pangram tests');
define('WP_PHP_BINARY', 'php');
define('WP_DEBUG', true);
define('WPLANG', '');

if (!defined('ABSPATH')) {
    define('ABSPATH', $zw_pangram_env('ZW_PANGRAM_TESTS_ABSPATH', '/var/www/html/'));
}
