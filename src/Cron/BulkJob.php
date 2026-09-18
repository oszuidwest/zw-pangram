<?php
/**
 * Persists bulk job state.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Cron;

use ZWPangram\Support\Settings;

/**
 * Stores the open job and pre-submission marker without autoloading.
 *
 * @phpstan-type Job array{
 *   bulk_id: string, claim_token: string, key_fingerprint: string, model: string, submitted_at: int, item_count: int,
 *   post_ids: list<int>|null, status: string, results_offset: int, next_poll_at: int, poll_failures: int
 * }
 * @phpstan-type Pending array{claim_token: string, started_at: int}
 */
final class BulkJob
{
    public const OPTION = 'zw_pangram_open_job';
    public const PENDING_OPTION = 'zw_pangram_pending_submission';
    public const WATCHDOG_SECONDS = 24 * HOUR_IN_SECONDS;
    public const PENDING_UNKNOWN_AFTER = 300;
    public const MAX_POLL_BACKOFF = 1800;
    public const RESULTS_PAGE_SIZE = 100;

    /**
     * Returns the open job.
     *
     * @return Job|null
     */
    public static function get(): ?array
    {
        $job = get_option(self::OPTION, null);
        if (!is_array($job) || !isset($job['bulk_id'], $job['claim_token']) || !is_string($job['bulk_id']) || $job['bulk_id'] === '') {
            return null;
        }
        return [
            'bulk_id' => (string) $job['bulk_id'],
            'claim_token' => (string) $job['claim_token'],
            'key_fingerprint' => (string) ($job['key_fingerprint'] ?? ''),
            'model' => (string) ($job['model'] ?? Settings::MODEL),
            'submitted_at' => (int) ($job['submitted_at'] ?? 0),
            'item_count' => (int) ($job['item_count'] ?? 0),
            'post_ids' => isset($job['post_ids']) && is_array($job['post_ids']) ? array_values(array_map('intval', $job['post_ids'])) : null,
            'status' => (string) ($job['status'] ?? 'queued'),
            'results_offset' => (int) ($job['results_offset'] ?? 0),
            'next_poll_at' => (int) ($job['next_poll_at'] ?? 0),
            'poll_failures' => (int) ($job['poll_failures'] ?? 0),
        ];
    }

    /**
     * Stores an accepted bulk job together with the accepted post IDs.
     *
     * The IDs let Recovery link exactly the accepted rows when the process dies before markSubmitted().
     *
     * @param string    $bulkId         Bulk ID.
     * @param string    $claimToken     Submitted claim token.
     * @param string    $keyFingerprint API key fingerprint.
     * @param list<int> $postIds        Accepted post IDs.
     */
    public static function open(string $bulkId, string $claimToken, string $keyFingerprint, array $postIds): void
    {
        update_option(self::OPTION, [
            'bulk_id' => $bulkId,
            'claim_token' => $claimToken,
            'key_fingerprint' => $keyFingerprint,
            'model' => Settings::MODEL,
            'submitted_at' => time(),
            'item_count' => count($postIds),
            'post_ids' => $postIds,
            'status' => 'queued',
            'results_offset' => 0,
            'next_poll_at' => time() + MINUTE_IN_SECONDS,
            'poll_failures' => 0,
        ], false);
    }

    /**
     * Updates the open job.
     *
     * @param array<string, mixed> $changes Changes.
     */
    public static function update(array $changes): void
    {
        $job = self::get();
        if ($job === null) {
            return;
        }
        update_option(self::OPTION, array_replace($job, $changes), false);
    }

    /** Removes the open job. */
    public static function close(): void
    {
        delete_option(self::OPTION);
    }

    /**
     * Returns the pre-submission marker.
     *
     * @return Pending|null
     */
    public static function pending(): ?array
    {
        $p = get_option(self::PENDING_OPTION, null);
        if (!is_array($p) || !isset($p['claim_token']) || !is_string($p['claim_token']) || $p['claim_token'] === '') {
            return null;
        }
        return ['claim_token' => $p['claim_token'], 'started_at' => (int) ($p['started_at'] ?? 0)];
    }

    /**
     * Stores the pre-submission marker.
     *
     * @param string $claimToken Claim token.
     */
    public static function markPending(string $claimToken): void
    {
        update_option(self::PENDING_OPTION, ['claim_token' => $claimToken, 'started_at' => time()], false);
    }

    /** Removes the pre-submission marker. */
    public static function clearPending(): void
    {
        delete_option(self::PENDING_OPTION);
    }
}
