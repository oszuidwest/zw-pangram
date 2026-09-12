<?php
/**
 * Define runtime constants for PHPStan without loading plugin bootstrap hooks.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

if (!defined('ZW_PANGRAM_VERSION')) {
    $zw_pangram_header = file_get_contents(__DIR__ . '/zw-pangram.php');
    if (
        $zw_pangram_header === false
        || !preg_match('/^[ \t*]*Version:[ \t]*(.+)$/mi', $zw_pangram_header, $zw_pangram_match)
    ) {
        throw new RuntimeException('Unable to read the ZuidWest Pangram version from the plugin header.');
    }
    define('ZW_PANGRAM_VERSION', trim($zw_pangram_match[1]));
    unset($zw_pangram_header, $zw_pangram_match);
}
defined('ZW_PANGRAM_FILE') || define('ZW_PANGRAM_FILE', __DIR__ . '/zw-pangram.php');
defined('ZW_PANGRAM_DIR') || define('ZW_PANGRAM_DIR', __DIR__ . '/');
defined('ZW_PANGRAM_URL') || define('ZW_PANGRAM_URL', 'https://example.test/');
