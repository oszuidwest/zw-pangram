<?php
/**
 * Recovers interrupted cron state.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Cron;

use ZWPangram\Store\ItemsRepository;
use ZWPangram\Store\QueueStatus;
use ZWPangram\Support\ErrorLog;

/**
 * Repairs state left between non-atomic writes.
 */
final class Recovery
{
    /**
     * Creates a recovery service.
     *
     * @param ItemsRepository $repo Repository.
     */
    public function __construct(private readonly ItemsRepository $repo)
    {
    }

    /** Repairs interrupted queue state. */
    public function run(): void
    {
        $job = BulkJob::get();
        $pending = BulkJob::pending();

        // Settle processing rows of a job persisted before interruption: accepted rows join the job, the rest go back to the queue.
        if ($job !== null) {
            $accepted = [];
            $unaccepted = [];
            foreach ($this->repo->byToken($job['claim_token']) as $row) {
                if ($row['queue_status'] !== QueueStatus::Processing->value) {
                    continue;
                }
                // Jobs stored before post_ids existed can only be linked by token.
                if ($job['post_ids'] === null || in_array($row['post_id'], $job['post_ids'], true)) {
                    $accepted[] = $row['post_id'];
                } else {
                    $unaccepted[] = $row['post_id'];
                }
            }
            if ($accepted !== []) {
                $this->repo->markSubmitted($job['claim_token'], $accepted, $job['bulk_id']);
                ErrorLog::add(sprintf('Recovered %d rows into bulk job after an interrupted submission.', count($accepted)), ['bulk_id' => $job['bulk_id']]);
            }
            if ($unaccepted !== []) {
                // Their rejection or omission was lost with the crash; the next submission settles them for real.
                $this->repo->release($job['claim_token'], $unaccepted);
                ErrorLog::add(sprintf('Requeued %d rows the bulk job did not accept after an interrupted submission.', count($unaccepted)), ['bulk_id' => $job['bulk_id']]);
            }
            if ($pending !== null && $pending['claim_token'] === $job['claim_token']) {
                BulkJob::clearPending();
                $pending = null;
            }
        }

        // A stale unmatched marker has an unknown submission outcome.
        if ($pending !== null && $pending['started_at'] <= time() - BulkJob::PENDING_UNKNOWN_AFTER) {
            $rows = $this->repo->byToken($pending['claim_token']);
            $released = 0;
            foreach ($rows as $row) {
                if ($row['queue_status'] !== QueueStatus::Processing->value) {
                    continue;
                }
                if ($row['attempts'] >= ItemsRepository::MAX_ATTEMPTS) {
                    $this->repo->writeFailed($row, 'Submission outcome unknown after a crash; retries exhausted.');
                    continue;
                }
                $released++;
            }
            $this->repo->release($pending['claim_token']);
            BulkJob::clearPending();
            $pending = null;
            ErrorLog::add(sprintf('Submission outcome unknown after an interrupted request; %d rows requeued. Pangram may have billed the lost job.', $released));
        }

        // Preserve claims belonging to a known job or pending request.
        $active = [];
        if ($job !== null) {
            $active[] = $job['claim_token'];
        }
        if ($pending !== null) {
            $active[] = $pending['claim_token'];
        }
        $stale = $this->repo->releaseStaleProcessing($active);
        if ($stale > 0) {
            ErrorLog::add(sprintf('Released %d stale processing rows.', $stale));
        }

        // Requeue submitted rows unrelated to the open job.
        $orphans = $this->repo->requeueSubmittedExcept($job['bulk_id'] ?? null);
        if ($orphans > 0) {
            ErrorLog::add(sprintf('Requeued %d rows that were submitted without an open job.', $orphans));
        }
    }
}
