<?php
/**
 * Plugin settings.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Support;

use ZWPangram\Queue\QueueState;

/**
 * Provides typed access to the plugin settings option.
 *
 * @phpstan-type SettingsArray array{
 *   api_key: string,
 *   batch_size: int,
 *   min_words: int,
 *   post_types: list<string>,
 *   post_statuses: list<string>,
 *   include_password_protected: bool,
 *   store_full_text: bool
 * }
 */
final class Settings
{
    public const OPTION = 'zw_pangram_settings';
    public const GROUP = 'zw_pangram';
    public const MODEL = 'pangram-4';
    public const BATCH_MIN = 1;
    public const BATCH_MAX = 1000;
    public const MIN_WORDS_MIN = 1;
    public const MIN_WORDS_MAX = 10000;
    public const ALLOWED_STATUSES = ['publish', 'future', 'draft', 'pending', 'private'];

    /**
     * Returns default settings.
     *
     * @return SettingsArray
     */
    public static function defaults(): array
    {
        return [
            'api_key' => '',
            'batch_size' => 20,
            'min_words' => 50,
            'post_types' => ['post'],
            'post_statuses' => ['publish'],
            'include_password_protected' => false,
            'store_full_text' => false,
        ];
    }

    /**
     * Returns normalized settings with defaults.
     *
     * @return SettingsArray
     */
    public static function get(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        /** @var SettingsArray $merged */
        $merged = array_replace(self::defaults(), self::normalize($stored));
        return $merged;
    }

    /**
     * Stores normalized settings without autoloading them.
     *
     * @param array<string, mixed> $values Values to store (merged over current).
     */
    public static function update(array $values): void
    {
        $current = self::get();
        update_option(self::OPTION, self::normalize(array_replace($current, $values)), false);
    }

    /** Checks whether wp-config defines the API key. */
    public static function apiKeyFromConstant(): bool
    {
        if (!defined('ZW_PANGRAM_API_KEY')) {
            return false;
        }
        $key = constant('ZW_PANGRAM_API_KEY');
        return is_string($key) && trim($key) !== '';
    }

    /** Returns the configured API key, preferring wp-config. */
    public static function apiKey(): string
    {
        if (self::apiKeyFromConstant()) {
            return trim((string) constant('ZW_PANGRAM_API_KEY'));
        }
        return self::get()['api_key'];
    }

    /**
     * Creates a non-reversible API key fingerprint.
     *
     * @param string|null $key Key to fingerprint, defaults to the effective key.
     */
    public static function apiKeyFingerprint(?string $key = null): string
    {
        $key ??= self::apiKey();
        if ($key === '') {
            return '';
        }
        return substr(hash_hmac('sha256', $key, wp_salt('auth')), 0, 16);
    }

    /**
     * Returns post types eligible for scanning.
     *
     * @return list<string>
     */
    public static function allowedPostTypes(): array
    {
        $types = get_post_types(['show_ui' => true], 'names');
        $excluded = ['attachment', 'revision', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles', 'wp_font_family', 'wp_font_face'];
        return array_values(array_filter(array_map('strval', $types), static fn (string $t): bool => !in_array($t, $excluded, true)));
    }

    /**
     * Sanitizes Settings API input.
     *
     * @param mixed $input Raw submitted values.
     * @return SettingsArray
     */
    public static function sanitize(mixed $input): array
    {
        $current = self::get();
        $input = is_array($input) ? $input : [];

        $apiKey = $current['api_key'];
        if (!empty($input['remove_api_key'])) {
            $apiKey = '';
        } elseif (isset($input['api_key']) && is_string($input['api_key']) && trim($input['api_key']) !== '') {
            $apiKey = trim($input['api_key']);
        }

        $next = self::normalize([
            'api_key' => $apiKey,
            'batch_size' => $input['batch_size'] ?? $current['batch_size'],
            'min_words' => $input['min_words'] ?? $current['min_words'],
            'post_types' => $input['post_types'] ?? [],
            'post_statuses' => $input['post_statuses'] ?? [],
            'include_password_protected' => !empty($input['include_password_protected']),
            'store_full_text' => !empty($input['store_full_text']),
        ]);

        // A newly stored key can resolve an automatic missing-key pause.
        if ($current['api_key'] === '' && $next['api_key'] !== '' && !self::apiKeyFromConstant()) {
            $state = QueueState::get();
            if ($state['paused'] && $state['automatic']) {
                QueueState::resume();
            }
        }

        return $next;
    }

    /**
     * Normalizes settings and clamps numeric ranges.
     *
     * @param array<string, mixed> $values Values to normalize.
     * @return SettingsArray
     */
    private static function normalize(array $values): array
    {
        $defaults = self::defaults();

        return [
            'api_key' => isset($values['api_key']) && is_string($values['api_key']) ? trim($values['api_key']) : '',
            'batch_size' => self::clamp($values['batch_size'] ?? null, self::BATCH_MIN, self::BATCH_MAX, $defaults['batch_size']),
            'min_words' => self::clamp($values['min_words'] ?? null, self::MIN_WORDS_MIN, self::MIN_WORDS_MAX, $defaults['min_words']),
            'post_types' => self::subsetOrAll($values['post_types'] ?? null, self::allowedPostTypes(), $defaults['post_types']),
            'post_statuses' => self::subsetOrAll($values['post_statuses'] ?? null, self::ALLOWED_STATUSES, $defaults['post_statuses']),
            'include_password_protected' => !empty($values['include_password_protected']),
            'store_full_text' => !empty($values['store_full_text']),
        ];
    }

    /**
     * Filters values against an allow-list with a fallback.
     *
     * @param mixed        $input    Raw list.
     * @param list<string> $allowed  Allow-list.
     * @param list<string> $fallback Used when the intersection is empty.
     * @return list<string>
     */
    public static function subsetOrAll(mixed $input, array $allowed, array $fallback): array
    {
        $values = is_array($input) ? array_map('strval', $input) : [];
        return array_values(array_intersect($values, $allowed)) ?: $fallback;
    }

    /**
     * Clamps numeric input to a range.
     *
     * @param mixed $value   Raw value.
     * @param int   $min     Minimum.
     * @param int   $max     Maximum.
     * @param int   $default Fallback for non-numeric input.
     */
    private static function clamp(mixed $value, int $min, int $max, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }
        return max($min, min($max, (int) $value));
    }
}
