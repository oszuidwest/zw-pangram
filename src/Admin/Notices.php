<?php
/**
 * Admin notices.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Admin;

use ZWPangram\Cron\BulkJob;
use ZWPangram\Cron\Scheduler;
use ZWPangram\Queue\QueueState;
use ZWPangram\Support\Settings;

/**
 * Health and feedback notices on the plugin page.
 */
final class Notices
{
    /** Renders queue health notices on the plugin screen. */
    public static function renderHealth(): void
    {
        if (Settings::apiKey() === '') {
            self::notice('warning', __('No Pangram API key is configured. Add one in the Settings tab; the queue stays paused until then.', 'zw-pangram'));
        }

        $health = Scheduler::health();
        if ($health['schedule_error'] !== '') {
            /* translators: %s: error message */
            self::notice('error', sprintf(__('The cron event could not be scheduled: %s', 'zw-pangram'), $health['schedule_error']));
        }
        if ($health['cron_disabled']) {
            self::notice('warning', __('DISABLE_WP_CRON is enabled. Make sure a system cron runs wp-cron.php (or "wp cron event run --due-now") every minute, otherwise the queue will not move.', 'zw-pangram'));
        } elseif ($health['stale'] && $health['last_tick'] > 0) {
            /* translators: %s: human readable time */
            self::notice('warning', sprintf(__('The queue tick last ran %s ago. WP-Cron only runs on page views; consider a system cron.', 'zw-pangram'), human_time_diff($health['last_tick'], time())));
        }

        $state = QueueState::get();
        if ($state['paused']) {
            $message = $state['automatic']
                /* translators: %s: reason */
                ? sprintf(__('The queue was paused automatically: %s', 'zw-pangram'), $state['reason'])
                : __('The queue is paused.', 'zw-pangram');
            self::notice('warning', $message);
        }

        $job = BulkJob::get();
        if ($job !== null && Settings::apiKey() !== '' && $job['key_fingerprint'] !== Settings::apiKeyFingerprint()) {
            self::notice('error', __('The open Pangram job was created with a different API key. Restore that key, or abandon the job in the Scan tab.', 'zw-pangram'));
        }
    }

    /** Renders feedback selected by an admin-post redirect code. */
    public static function renderActionFeedback(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only feedback codes after a nonce-protected redirect.
        $code = isset($_GET['zw_pangram_msg']) ? sanitize_key((string) wp_unslash($_GET['zw_pangram_msg'])) : '';
        if ($code === '') {
            return;
        }
        $n = isset($_GET['n']) ? (int) $_GET['n'] : 0;
        $extra = isset($_GET['extra']) ? sanitize_text_field((string) wp_unslash($_GET['extra'])) : '';
        // phpcs:enable
        $messages = [
            /* translators: 1: number of posts, 2: details */
            'enqueued' => sprintf(__('%1$d posts added to the queue. %2$s', 'zw-pangram'), $n, $extra),
            'paused' => __('Queue paused.', 'zw-pangram'),
            'resumed' => __('Queue resumed.', 'zw-pangram'),
            /* translators: %d: number of rows */
            'cleared' => sprintf(__('Queue cleared (%d rows).', 'zw-pangram'), $n),
            'log_cleared' => __('Error log cleared.', 'zw-pangram'),
            /* translators: %s: tick outcome */
            'ticked' => sprintf(__('Tick executed: %s', 'zw-pangram'), $extra),
            /* translators: %d: number of rows */
            'abandoned' => sprintf(__('Open job abandoned; %d rows affected.', 'zw-pangram'), $n),
            'rescan' => __('Post added to the queue for a forced rescan.', 'zw-pangram'),
            /* translators: %d: number of rows */
            'purged' => sprintf(__('Stored response text removed from %d rows.', 'zw-pangram'), $n),
        ];
        if (!isset($messages[$code])) {
            return;
        }
        self::notice('success', $messages[$code]);
    }

    /**
     * Prints a dismissible admin notice.
     *
     * @param string $type    Notice type: success, warning, error, or info.
     * @param string $message Notice text.
     */
    private static function notice(string $type, string $message): void
    {
        wp_admin_notice(esc_html($message), ['type' => $type, 'dismissible' => true]);
    }
}
