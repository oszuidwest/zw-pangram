<?php
/**
 * Processes queue cron ticks.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Cron;

use ZWPangram\Activation;
use ZWPangram\Api\ApiException;
use ZWPangram\Api\Client;
use ZWPangram\Api\ClientInterface;
use ZWPangram\Api\ResponseValidator;
use ZWPangram\Queue\QueueState;
use ZWPangram\Store\ItemsRepository;
use ZWPangram\Support\ErrorLog;
use ZWPangram\Support\Lock;
use ZWPangram\Support\Settings;

/**
 * Recovers state, then polls one job or submits one batch.
 */
final class Tick
{
    /** @var callable(string): ClientInterface */
    private $clientFactory;

    /**
     * Configures queue processing dependencies.
     *
     * @param ItemsRepository                  $repo          Repository.
     * @param Lock                             $lock          Tick lock.
     * @param callable(string): ClientInterface $clientFactory Builds a client for an API key.
     */
    public function __construct(private readonly ItemsRepository $repo, private readonly Lock $lock, callable $clientFactory)
    {
        $this->clientFactory = $clientFactory;
    }

    /** Creates a tick with production dependencies. */
    public static function create(): self
    {
        return new self(new ItemsRepository(), new Lock(), static fn (string $key): ClientInterface => new Client($key));
    }

    /**
     * Runs one recovery, polling, or submission step.
     */
    public function run(): string
    {
        $token = $this->lock->acquire();
        if ($token === null) {
            return 'locked';
        }
        try {
            wp_prime_option_caches([Settings::OPTION, QueueState::OPTION, BulkJob::OPTION, BulkJob::PENDING_OPTION]);
            Scheduler::recordTick();
            if (!Activation::ensureTable()) {
                return 'no-table';
            }
            (new Recovery($this->repo))->run();

            $job = BulkJob::get();
            $key = Settings::apiKey();
            if ($key === '') {
                if (!QueueState::isPaused()) {
                    QueueState::pause(__('The Pangram API key is missing.', 'zw-pangram'), true);
                    ErrorLog::addOnce('Queue paused: API key missing.');
                }
                return 'no-key';
            }

            if ($job !== null) {
                if ($job['key_fingerprint'] !== Settings::apiKeyFingerprint($key)) {
                    if (!QueueState::isPaused()) {
                        QueueState::pause(__('The open Pangram job belongs to a different API key. Restore that key or abandon the job.', 'zw-pangram'), true);
                        ErrorLog::addOnce('Queue paused: open job belongs to a different API key.', ['bulk_id' => $job['bulk_id']]);
                    }
                    return 'foreign-job';
                }
                return $this->pollJob($job, ($this->clientFactory)($key));
            }

            if (QueueState::isPaused()) {
                return 'paused';
            }
            return $this->submitBatch(($this->clientFactory)($key));
        } finally {
            $this->lock->release($token);
        }
    }

    /**
     * Polls the open job and processes at most one results page.
     *
     * @param array<string, mixed> $job    Open job.
     * @param ClientInterface      $client Client.
     */
    private function pollJob(array $job, ClientInterface $client): string
    {
        $bulkId = (string) $job['bulk_id'];
        if (time() < (int) $job['next_poll_at']) {
            return 'poll-wait';
        }
        if ((int) $job['submitted_at'] < time() - BulkJob::WATCHDOG_SECONDS) {
            $n = $this->repo->requeueBulk($bulkId, false, 'Bulk job timed out after 24 hours; requeued.');
            BulkJob::close();
            ErrorLog::add(sprintf('Bulk job timed out after 24 hours; %d rows requeued.', $n), ['bulk_id' => $bulkId]);
            return 'watchdog';
        }

        try {
            $status = $client->getBulk($bulkId);
        } catch (ApiException $e) {
            return $this->handlePollError($job, $e);
        }

        $statusValue = (string) $status['status'];
        if ($statusValue === 'queued' || $statusValue === 'running') {
            BulkJob::update(['status' => $statusValue, 'poll_failures' => 0, 'next_poll_at' => time() + MINUTE_IN_SECONDS]);
            return 'poll-' . $statusValue;
        }
        BulkJob::update(['status' => $statusValue, 'poll_failures' => 0]);

        // Limit terminal jobs to one results page per tick.
        $offset = (int) $job['results_offset'];
        try {
            $page = $client->getBulkResults($bulkId, $offset, BulkJob::RESULTS_PAGE_SIZE);
        } catch (ApiException $e) {
            return $this->handlePollError($job, $e);
        }
        /** @var list<array<string, mixed>> $items Validated result items. */
        $items = array_merge((array) ($page['items'] ?? []), (array) ($page['failed_items'] ?? []));
        $n = count($items);
        (new ResultWriter($this->repo, Settings::get()['store_full_text']))->writePage($job, $items);

        if ($n > 0 && $offset + $n < (int) $page['total_items']) {
            BulkJob::update(['results_offset' => $offset + $n, 'next_poll_at' => time()]);
            return 'page-processed';
        }
        BulkJob::update(['results_offset' => $offset + $n]);

        $leftovers = $this->repo->requeueBulk($bulkId, false, 'Not reported in the job results; requeued.');
        if ($leftovers > 0) {
            ErrorLog::add(sprintf('%d rows were not reported in the job results and were requeued.', $leftovers), ['bulk_id' => $bulkId]);
        }
        BulkJob::close();
        return 'job-closed';
    }

    /**
     * Applies the poll error policy.
     *
     * @param array<string, mixed> $job Open job.
     * @param ApiException         $e   Error.
     */
    private function handlePollError(array $job, ApiException $e): string
    {
        $bulkId = (string) $job['bulk_id'];
        $context = ['bulk_id' => $bulkId, 'http' => $e->status(), 'body' => $e->body()];
        if ($e->isNotFound()) {
            $n = $this->repo->requeueBulk($bulkId, false, 'Bulk job not found at Pangram; requeued.');
            BulkJob::close();
            ErrorLog::add(sprintf('Bulk job not found at Pangram; %d rows requeued.', $n), $context);
            return 'job-missing';
        }
        if ($e->isAuthOrBilling()) {
            if (!QueueState::isPaused()) {
                /* translators: %s: error message */
                QueueState::pause(sprintf(__('Pangram rejected the request while polling: %s', 'zw-pangram'), $e->getMessage()), true);
            }
            ErrorLog::addOnce('Polling rejected: ' . $e->getMessage(), $context);
            return 'poll-auth';
        }
        $failures = (int) $job['poll_failures'] + 1;
        $delay = min(BulkJob::MAX_POLL_BACKOFF, MINUTE_IN_SECONDS * (2 ** $failures));
        BulkJob::update(['poll_failures' => $failures, 'next_poll_at' => time() + $delay]);
        ErrorLog::addOnce('Polling failed: ' . $e->getMessage(), $context);
        return 'poll-error';
    }

    /**
     * Submits one batch.
     *
     * @param ClientInterface $client API client.
     */
    private function submitBatch(ClientInterface $client): string
    {
        $settings = Settings::get();
        $token = wp_generate_password(32, false, false);
        $rows = $this->repo->claim($settings['batch_size'], $token);
        if ($rows === []) {
            return 'idle';
        }

        $cap = BatchBuilder::cap();
        $builder = new BatchBuilder($this->repo);
        $snapshots = $builder->build($rows, $settings, $token, $cap);
        if ($snapshots === []) {
            return 'nothing-to-send';
        }

        $staged = $this->repo->stageSubmission($token, array_map(
            static fn (array $s): array => ['hash' => $s['hash'], 'modified_gmt' => $s['modified_gmt']],
            $snapshots
        ));
        // Build the payload only from rows that remain staged.
        $items = BatchBuilder::itemsFor($staged, $snapshots);
        if ($items === []) {
            return 'nothing-staged';
        }
        $payload = ['items' => $items, 'model' => Settings::MODEL];
        BulkJob::markPending($token);

        try {
            $response = $client->submitBulk($payload);
        } catch (ApiException $e) {
            $this->handleSubmitError($token, BatchBuilder::unitsFor($staged, $snapshots), $staged, $e);
            BulkJob::clearPending();
            return 'submit-error';
        }

        $bulkId = (string) $response['bulk_id'];
        $acceptedIds = $this->stagedPostIds((array) ($response['accepted_items'] ?? []), $staged);
        BulkJob::open($bulkId, $token, Settings::apiKeyFingerprint(), count($acceptedIds));

        /** @var list<array<string, mixed>> $failedItems Validated failed items. */
        $failedItems = (array) ($response['failed_items'] ?? []);
        foreach ($failedItems as $item) {
            $postId = ResponseValidator::postIdFromItemId((string) $item['id']);
            if ($postId === null || !isset($staged[$postId])) {
                continue;
            }
            $message = isset($item['error']) && is_string($item['error']) ? $item['error'] : 'Rejected at submission.';
            $this->repo->writeFailed($staged[$postId], $message, $bulkId);
            ErrorLog::add('Item rejected at submission: ' . $message, ['bulk_id' => $bulkId, 'post_id' => $postId]);
        }

        if ((string) $response['status'] === 'failed' || $acceptedIds === []) {
            // No accepted items means no open job may remain.
            BulkJob::close();
            $this->repo->release($token);
            BulkJob::clearPending();
            ErrorLog::add('Pangram accepted none of the submitted items.', ['bulk_id' => $bulkId]);
            return 'nothing-accepted';
        }

        $this->repo->markSubmitted($token, $acceptedIds, $bulkId);
        // Release still-processing staged rows omitted from the API response.
        $unmentioned = array_values(array_diff(array_keys($staged), $acceptedIds));
        if ($unmentioned !== []) {
            $this->repo->release($token, $unmentioned);
        }
        BulkJob::clearPending();
        BatchBuilder::setCap((int) ceil($cap * 1.25));
        return 'submitted';
    }

    /**
     * Returns staged post IDs in response order.
     *
     * @param list<array<string, mixed>>       $items  Validated accepted or failed items.
     * @param array<int, array<string, mixed>> $staged Staged rows keyed by post ID.
     * @return list<int>
     */
    private function stagedPostIds(array $items, array $staged): array
    {
        $ids = [];
        foreach ($items as $item) {
            $postId = ResponseValidator::postIdFromItemId((string) $item['id']);
            if ($postId !== null && isset($staged[$postId])) {
                $ids[] = $postId;
            }
        }
        return $ids;
    }

    /**
     * Applies the documented submission retry policy.
     *
     * @param string                           $token  Claim token.
     * @param int                              $units  Units sent.
     * @param array<int, array<string, mixed>> $staged Staged rows.
     * @param ApiException                     $e      Error.
     */
    private function handleSubmitError(string $token, int $units, array $staged, ApiException $e): void
    {
        $n = count($staged);
        $context = ['http' => $e->status(), 'body' => $e->body()];
        if ($e->isAuthOrBilling()) {
            $this->repo->unstage($token);
            /* translators: %s: error message */
            QueueState::pause(sprintf(__('Pangram rejected the submission: %s', 'zw-pangram'), $e->getMessage()), true);
            ErrorLog::add('Queue paused: ' . $e->getMessage(), $context);
            return;
        }
        if ($e->isTooLarge()) {
            if ($n === 1) {
                foreach ($staged as $row) {
                    $this->repo->writeFailed($row, 'Too large for the API (HTTP 413).');
                }
                ErrorLog::add('Single item rejected as too large (HTTP 413).', $context + ['post_id' => (int) array_key_first($staged)]);
                return;
            }
            BatchBuilder::setCap((int) floor($units / 2));
            $this->repo->unstage($token);
            ErrorLog::add(sprintf('Batch of %d items (%d units) rejected as too large; batch cap lowered.', $n, $units), $context);
            return;
        }
        if ($e->isRetryable()) {
            $this->repo->backoff($token, $e->getMessage());
            ErrorLog::add('Submission failed, will retry: ' . $e->getMessage(), $context);
            return;
        }
        foreach ($staged as $row) {
            $this->repo->writeFailed($row, $e->getMessage());
        }
        ErrorLog::add('Submission rejected: ' . $e->getMessage(), $context);
    }
}
