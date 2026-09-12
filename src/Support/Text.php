<?php
/**
 * Prepares post content for Pangram.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Support;

/**
 * Prepares plain text for Pangram.
 */
final class Text
{
    /**
     * Prepares the text used for hashing and submission.
     *
     * @param string $content Raw post_content.
     */
    public static function prepare(string $content): string
    {
        $text = (string) preg_replace('/<!--\s*\/?wp:[^>]*-->/s', "\n", $content);
        $text = strip_shortcodes($text);
        $text = (string) preg_replace('#</(p|div|li|h[1-6]|blockquote|figcaption|tr|section|article|pre)>|<br\s*/?>#i', "\n", $text);
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = wp_scrub_utf8($text);
        $text = (string) preg_replace('/[ \t\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\R */u', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        /**
         * Filters the prepared text before hashing and submission.
         *
         * @param string $text    Prepared plain text.
         * @param string $content Original post_content.
         */
        return apply_filters('zw_pangram_prepared_text', $text, $content);
    }

    /**
     * Counts Unicode whitespace-separated words.
     *
     * @param string $text Prepared text.
     */
    public static function wordCount(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        $parts = preg_split('/\s+/u', $text);
        if ($parts === false) {
            return str_word_count($text);
        }
        return count($parts);
    }

    /**
     * Clips to a byte limit without breaking UTF-8.
     *
     * @param string $text  Text.
     * @param int    $bytes Maximum bytes.
     */
    public static function clip(string $text, int $bytes): string
    {
        return strlen($text) <= $bytes ? $text : mb_strcut($text, 0, $bytes - 3, 'UTF-8') . '...';
    }

    /**
     * Creates a content fingerprint for change detection.
     *
     * @param string $text Prepared text.
     */
    public static function hash(string $text): string
    {
        return hash('sha256', $text);
    }
}
