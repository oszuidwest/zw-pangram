<?php
/**
 * Admin-ajax handlers.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Admin;

use ZWPangram\Api\ApiException;
use ZWPangram\Api\Client;
use ZWPangram\Store\ItemsRepository;
use ZWPangram\Support\Settings;

/**
 * Provides connection testing and queue status to the admin page.
 */
final class Ajax
{
    public const NONCE = 'zw_pangram_ajax';

    /** Registers authenticated AJAX hooks. */
    public static function register(): void
    {
        add_action('wp_ajax_zw_pangram_test_connection', [self::class, 'testConnection']);
        add_action('wp_ajax_zw_pangram_queue_status', [self::class, 'queueStatus']);
    }

    /** Checks Pangram 4 access with the submitted or configured key. */
    public static function testConnection(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can(AdminPage::CAPABILITY)) {
            wp_send_json_error(['message' => __('Permission denied.', 'zw-pangram')], 403);
        }

        if (Settings::apiKeyFromConstant()) {
            $key = Settings::apiKey();
        } else {
            $submitted = isset($_POST['api_key']) ? sanitize_text_field((string) wp_unslash($_POST['api_key'])) : '';
            $key = $submitted !== '' ? $submitted : Settings::apiKey();
        }
        if ($key === '') {
            wp_send_json_error(['message' => __('Enter an API key first.', 'zw-pangram')]);
        }

        try {
            $models = (new Client($key))->models();
        } catch (ApiException $e) {
            wp_send_json_error(['message' => $e->getMessage(), 'http' => $e->status()]);
        }

        if (!in_array(Settings::MODEL, $models, true)) {
            wp_send_json_error(['message' => __('Pangram 4 is not available for this API key.', 'zw-pangram')]);
        }
        wp_send_json_success(['model' => Settings::MODEL]);
    }

    /** Returns queue counters to the Scan tab poller. */
    public static function queueStatus(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can(AdminPage::CAPABILITY)) {
            wp_send_json_error(['message' => __('Permission denied.', 'zw-pangram')], 403);
        }
        wp_send_json_success(['counts' => (new ItemsRepository())->counts()]);
    }
}
