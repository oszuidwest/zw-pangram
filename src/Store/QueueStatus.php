<?php
/**
 * Queue statuses.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Store;

/**
 * Queue state of an item.
 *
 * The queue_status column tracks the work in flight and result_status (ResultStatus) the last stored outcome. Both are
 * orthogonal: a row can be Pending for a rescan, or Skipped as unchanged, while result_status still holds the ok
 * result of the previous scan. ItemsRepository owns every transition:
 *
 * - None, Done, Failed, Skipped -> Pending: upsertPending() when a post is enqueued or rescanned.
 * - Pending -> Processing: claim() under a claim token.
 * - Processing -> Pending: release() (batch full, not mentioned by the API, stale claim), unstage() (request
 *   rejected as a whole, attempt refunded) or backoff() (retryable error, attempt consumed).
 * - Processing -> Submitted: markSubmitted() once the bulk job accepted the item.
 * - Processing -> Skipped: writeSkipped() (too short) or markSkippedInQueue() (out of scope, unchanged content).
 * - Processing -> Failed: writeFailed() (too long, attempts exhausted, rejected at submission).
 * - Submitted -> Done or Failed: writeOk() or writeFailed() from a results page. Submitted -> Pending when the
 *   content changed during the scan, a rescan was requested, or requeueBulk() returns the rows of a lost, timed
 *   out, abandoned or incomplete job.
 * - Pending, Done, Failed, Skipped -> None: clearQueue().
 *
 * Cron\Recovery repairs writes interrupted between these steps: Processing rows of a job that was persisted
 * before the crash are linked to it, and stale Processing claims or Submitted rows without an open job go back
 * to Pending.
 */
enum QueueStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Processing = 'processing';
    case Submitted = 'submitted';
    case Done = 'done';
    case Failed = 'failed';
    case Skipped = 'skipped';

    /** Statuses of items that still await a scan outcome. */
    public const IN_FLIGHT = [self::Pending, self::Processing, self::Submitted];
}
