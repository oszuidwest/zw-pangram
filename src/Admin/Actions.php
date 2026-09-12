<?php
/**
 * Admin-post handlers.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Admin;

use ZWPangram\Cron\BulkJob;
use ZWPangram\Cron\Tick;
use ZWPangram\Queue\Enqueuer;
use ZWPangram\Queue\QueueState;
use ZWPangram\Queue\ScanFilters;
use ZWPangram\Store\ItemsRepository;
use ZWPangram\Support\ErrorLog;

/**
 * Handles protected admin-post actions and redirects back to the plugin page.
 */
final class Actions
{
    public const NONCE_ENQUEUE = 'zw_pangram_enqueue';
    public const NONCE_QUEUE_STATE = 'zw_pangram_queue_state';
    public const NONCE_CLEAR_QUEUE = 'zw_pangram_clear_queue';
    public const NONCE_ABANDON = 'zw_pangram_abandon_job';
    public const NONCE_CLEAR_LOG = 'zw_pangram_clear_log';
    public const NONCE_RUN_TICK = 'zw_pangram_run_tick';
    public const NONCE_PURGE = 'zw_pangram_purge_text';
    public const NONCE_RESCAN = 'zw_pangram_rescan_';

    /** Registers admin-post hooks. */
    public static function register(): void
    {
        add_action('admin_post_zw_pangram_enqueue', [self::class, 'enqueue']);
        add_action('admin_post_zw_pangram_pause', [self::class, 'pause']);
        add_action('admin_post_zw_pangram_resume', [self::class, 'resume']);
        add_action('admin_post_zw_pangram_clear_queue', [self::class, 'clearQueue']);
        add_action('admin_post_zw_pangram_abandon_job', [self::class, 'abandonJob']);
        add_action('admin_post_zw_pangram_clear_log', [self::class, 'clearLog']);
        add_action('admin_post_zw_pangram_run_tick', [self::class, 'runTick']);
        add_action('admin_post_zw_pangram_purge_text', [self::class, 'purgeText']);
        add_action('admin_post_zw_pangram_rescan', [self::class, 'rescan']);
    }

    /** Adds posts matching the scan form to the queue. */
    public static function enqueue(): void
    {
        AdminPage::guard(self::NONCE_ENQUEUE);
        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Hosts may prohibit runtime changes.
        }
        $filters = ScanFilters::fromRequest(wp_unslash($_POST)); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- Nonce verified in guard(); validated field by field in ScanFilters.
        $totals = (new Enqueuer(new ItemsRepository()))->enqueue($filters);
        $extra = sprintf(
            /* translators: 1: matched, 2: unchanged, 3: already queued */
            __('Matched %1$d, unchanged %2$d, already queued %3$d.', 'zw-pangram'),
            $totals['matched'],
            $totals['unchanged'],
            $totals['already_queued']
        );
        self::redirect('scan', ['zw_pangram_msg' => 'enqueued', 'n' => $totals['enqueued'], 'extra' => $extra]);
    }

    /** Pauses the queue manually. */
    public static function pause(): void
    {
        AdminPage::guard(self::NONCE_QUEUE_STATE);
        QueueState::pause(__('Paused by an administrator.', 'zw-pangram'), false);
        self::redirect('scan', ['zw_pangram_msg' => 'paused']);
    }

    /** Resumes the queue and clears any automatic pause. */
    public static function resume(): void
    {
        AdminPage::guard(self::NONCE_QUEUE_STATE);
        QueueState::resume();
        self::redirect('scan', ['zw_pangram_msg' => 'resumed']);
    }

    /** Empties the queue. */
    public static function clearQueue(): void
    {
        AdminPage::guard(self::NONCE_CLEAR_QUEUE);
        $n = (new ItemsRepository())->clearQueue();
        self::redirect('scan', ['zw_pangram_msg' => 'cleared', 'n' => $n]);
    }

    /** Closes the active job and requeues or fails its rows. */
    public static function abandonJob(): void
    {
        AdminPage::guard(self::NONCE_ABANDON);
        $job = BulkJob::get();
        $n = 0;
        if ($job !== null) {
            $fail = isset($_POST['outcome']) && $_POST['outcome'] === 'fail'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in guard().
            $n = (new ItemsRepository())->requeueBulk($job['bulk_id'], $fail, $fail ? 'Job abandoned by an administrator.' : 'Job abandoned by an administrator; requeued.');
            BulkJob::close();
            BulkJob::clearPending();
            ErrorLog::add(sprintf('Open job abandoned by an administrator (%s).', $fail ? 'rows failed' : 'rows requeued'), ['bulk_id' => $job['bulk_id']]);
        }
        self::redirect('scan', ['zw_pangram_msg' => 'abandoned', 'n' => $n]);
    }

    /** Clears the error log. */
    public static function clearLog(): void
    {
        AdminPage::guard(self::NONCE_CLEAR_LOG);
        ErrorLog::clear();
        self::redirect('scan', ['zw_pangram_msg' => 'log_cleared']);
    }

    /** Processes one queue tick synchronously. */
    public static function runTick(): void
    {
        AdminPage::guard(self::NONCE_RUN_TICK);
        $outcome = Tick::create()->run();
        self::redirect('scan', ['zw_pangram_msg' => 'ticked', 'extra' => $outcome]);
    }

    /** Removes stored text from every response. */
    public static function purgeText(): void
    {
        AdminPage::guard(self::NONCE_PURGE);
        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Hosts may prohibit runtime changes.
        }
        $repo = new ItemsRepository();
        $total = 0;
        $after = 0;
        do {
            [$n, $after] = $repo->purgeText(200, $after);
            $total += $n;
        } while ($after > 0);
        self::redirect('settings', ['zw_pangram_msg' => 'purged', 'n' => $total]);
    }

    /** Forces a rescan of one post. */
    public static function rescan(): void
    {
        $postId = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard() right below with the post-specific action.
        AdminPage::guard(self::NONCE_RESCAN . $postId);
        if ($postId > 0 && get_post($postId) instanceof \WP_Post) {
            (new ItemsRepository())->upsertPending([$postId], true);
        }
        self::redirect('results', ['zw_pangram_msg' => 'rescan']);
    }

    /**
     * Redirects to a plugin tab.
     *
     * @param string               $tab  Tab slug.
     * @param array<string, mixed> $args Additional query arguments.
     */
    private static function redirect(string $tab, array $args): void
    {
        wp_safe_redirect(AdminPage::url($tab, $args));
        exit;
    }
}
