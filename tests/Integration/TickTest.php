<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Integration;

use ZWPangram\Api\ApiException;
use ZWPangram\Cron\BatchBuilder;
use ZWPangram\Cron\BulkJob;
use ZWPangram\Queue\QueueState;
use ZWPangram\Store\ItemsRepository;
use ZWPangram\Support\ErrorLog;
use ZWPangram\Support\Lock;
use ZWPangram\Support\Settings;
use ZWPangram\Tests\Support\FakeClient;
use ZWPangram\Tests\Support\PluginTestCase;

final class TickTest extends PluginTestCase
{
    public function test_happy_path_submit_poll_and_write_results_over_two_pages(): void
    {
        [$a, $b] = [$this->post(), $this->post()];
        $this->repo->upsertPending([$a, $b], false);
        $this->client->queue('submitBulk', FakeClient::accepted('b1', [$a, $b]));

        $this->assertSame('submitted', $this->tick()->run());
        $this->assertRow($a, ['queue_status' => 'submitted', 'attempts' => 1]);
        $this->assertRow($b, ['bulk_id' => 'b1']);
        $job = BulkJob::get();
        $this->assertNotNull($job);
        $this->assertSame(2, $job['item_count']);
        $this->assertSame(Settings::apiKeyFingerprint(), $job['key_fingerprint']);
        $this->assertNull(BulkJob::pending());
        $payload = $this->client->callsTo('submitBulk')[0][0];
        $this->assertSame('post-' . $a, $payload['items'][0]['id']);
        $this->assertSame(Settings::MODEL, $payload['model']);

        $this->assertSame('poll-wait', $this->tick()->run());
        $this->due();
        $this->client->queue('getBulk', FakeClient::status('running'));
        $this->assertSame('poll-running', $this->tick()->run());

        $this->due();
        $this->client->queue('getBulk', FakeClient::status('succeeded'));
        $this->client->queue('getBulkResults', FakeClient::page([FakeClient::okItem($a)], [], 2));
        $this->client->queue('getBulkResults', FakeClient::page([FakeClient::okItem($b, ['prediction_short' => 'Human', 'fraction_ai' => 0.0, 'fraction_ai_assisted' => 0.0, 'fraction_human' => 1.0])], [], 2));
        $this->assertSame('page-processed', $this->tick()->run());
        $this->assertSame(1, BulkJob::get()['results_offset']);
        $this->assertRow($a, ['queue_status' => 'done']);
        $this->assertRow($b, ['queue_status' => 'submitted']);

        $this->assertSame('job-closed', $this->tick()->run());
        $this->assertNull(BulkJob::get());
        $rowB = $this->row($b);
        $this->assertRow($b, ['queue_status' => 'done', 'prediction_short' => 'Human', 'fraction_human' => 1.0, 'last_bulk_id' => 'b1', 'api_version' => '4.0', 'result_stale' => false]);
        $decoded = json_decode((string) $rowB['response_json'], true);
        $this->assertArrayNotHasKey('text', $decoded, 'text stripped by default');
        $this->assertArrayNotHasKey('text', $decoded['windows'][0]);
        $this->assertSame('idle', $this->tick()->run());
    }

    public function test_store_full_text_keeps_text_fields(): void
    {
        Settings::update(['store_full_text' => true]);
        $a = $this->post();
        $this->completeJob([$a], [FakeClient::okItem($a)]);
        $decoded = json_decode((string) $this->row($a)['response_json'], true);
        $this->assertSame('analyzed text', $decoded['text']);
    }

    public function test_short_and_out_of_scope_rows_never_reach_the_api(): void
    {
        $short = $this->post('one two');
        $draft = $this->post('one two three four five', ['post_status' => 'draft']);
        $this->repo->upsertPending([$short, $draft], false);
        $this->assertSame('nothing-to-send', $this->tick()->run());
        $this->assertSame([], $this->client->calls);
        $this->assertRow($short, ['result_status' => 'skipped']);
        $this->assertStringContainsString('Too short', (string) $this->row($short)['result_error']);
        $this->assertRow($draft, ['queue_status' => 'skipped', 'result_status' => null]);
    }

    public function test_unchanged_content_is_skipped_at_submit_unless_forced(): void
    {
        $a = $this->post();
        $this->completeJob([$a], [FakeClient::okItem($a)]);
        $this->repo->upsertPending([$a], false);
        $this->assertSame('nothing-to-send', $this->tick()->run());
        $this->assertRow($a, ['last_error' => 'unchanged', 'result_status' => 'ok']);

        $this->repo->upsertPending([$a], true);
        $this->client->queue('submitBulk', FakeClient::accepted('b2', [$a]));
        $this->assertSame('submitted', $this->tick()->run());
    }

    public function test_edit_between_submit_and_result_marks_stale_and_requeues(): void
    {
        $a = $this->post();
        $this->startJob([$a]);
        wp_update_post(['ID' => $a, 'post_content' => 'Completely different content with enough words here.']);

        $this->finishJob([FakeClient::okItem($a)]);
        $this->assertRow($a, ['result_status' => 'ok', 'result_stale' => true, 'queue_status' => 'pending', 'force' => true, 'attempts' => 0, 'last_bulk_id' => 'b1']);
    }

    public function test_enqueue_during_open_job_only_rescans_when_forced(): void
    {
        [$a, $b] = [$this->post(), $this->post()];
        $this->startJob([$a, $b]);
        $this->repo->upsertPending([$a], false);
        $this->repo->upsertPending([$b], true);
        $this->finishJob([FakeClient::okItem($a), FakeClient::okItem($b)]);
        $this->assertRow($a, ['queue_status' => 'done']);
        $this->assertRow($b, ['queue_status' => 'pending', 'last_error' => 'Rescan requested.', 'rescan_requested' => false]);
    }

    public function test_failed_items_at_submission_and_in_results(): void
    {
        [$a, $b, $c] = [$this->post(), $this->post(), $this->post()];
        $this->startJob([$a, $b, $c], [$a, $b], [$c]);
        $this->assertRow($c, ['queue_status' => 'failed', 'result_error' => 'rejected']);
        $this->assertSame(2, BulkJob::get()['item_count']);

        $this->due();
        $this->client->queue('getBulk', FakeClient::status('partial'));
        $this->client->queue('getBulkResults', FakeClient::page([FakeClient::okItem($a)], [FakeClient::failedItem($b, 'model crashed')]));
        $this->assertSame('job-closed', $this->tick()->run());
        $this->assertRow($a, ['queue_status' => 'done']);
        $this->assertRow($b, ['queue_status' => 'failed', 'result_status' => 'failed', 'result_error' => 'model crashed', 'last_bulk_id' => 'b1']);
    }

    public function test_page_with_only_failed_items_counts_as_progress(): void
    {
        [$a, $b] = [$this->post(), $this->post()];
        $this->startJob([$a, $b]);
        $this->due();
        $this->client->queue('getBulk', FakeClient::status('partial'));
        $this->client->queue('getBulkResults', FakeClient::page([], [FakeClient::failedItem($a)], 2));
        $this->client->queue('getBulkResults', FakeClient::page([FakeClient::okItem($b)], [], 2));
        $this->assertSame('page-processed', $this->tick()->run());
        $this->assertSame(1, BulkJob::get()['results_offset']);
        $this->assertSame('job-closed', $this->tick()->run());
    }

    public function test_replay_after_page_failure_is_idempotent(): void
    {
        [$a, $b] = [$this->post(), $this->post()];
        $this->startJob([$a, $b]);
        wp_update_post(['ID' => $a, 'post_content' => 'Edited so that the first result is stale and requeued now.']);

        $this->due();
        $this->client->queue('getBulk', FakeClient::status('succeeded'));
        $this->client->queue('getBulkResults', FakeClient::page([FakeClient::okItem($a)], [], 2));
        $this->client->queue('getBulkResults', new ApiException('boom', 500));
        $this->assertSame('page-processed', $this->tick()->run());
        $this->assertSame('pending', $this->row($a)['queue_status'], 'stale result requeued');
        $this->due();
        $this->assertSame('poll-error', $this->tick()->run());
        $this->assertSame(1, BulkJob::get()['results_offset'], 'offset kept after failed page');
        $this->assertSame(1, BulkJob::get()['poll_failures']);

        // The API repeats the processed item at the next offset.
        $this->client = new FakeClient();
        $this->client->queue('getBulk', FakeClient::status('succeeded'));
        $this->client->queue('getBulkResults', FakeClient::page([FakeClient::okItem($a, ['prediction_short' => 'Human', 'fraction_ai' => 0.0, 'fraction_ai_assisted' => 0.0, 'fraction_human' => 1.0]), FakeClient::okItem($b)], [], 2));
        $this->due();
        $this->assertSame('job-closed', $this->tick()->run());
        $this->assertRow($a, ['prediction_short' => 'AI', 'queue_status' => 'pending']);
        $this->assertRow($b, ['queue_status' => 'done']);
    }

    public function test_watchdog_requeues_after_24_hours(): void
    {
        $a = $this->post();
        $this->startJob([$a]);
        BulkJob::update(['submitted_at' => time() - BulkJob::WATCHDOG_SECONDS - 1, 'next_poll_at' => 0]);
        $this->assertSame('watchdog', $this->tick()->run());
        $this->assertNull(BulkJob::get());
        $this->assertRow($a, ['queue_status' => 'pending', 'attempts' => 1]);
    }

    public function test_poll_errors(): void
    {
        $a = $this->post();
        $this->startJob([$a]);

        $this->due();
        $this->client->queue('getBulk', new ApiException('server', 500));
        $this->assertSame('poll-error', $this->tick()->run());
        $job = BulkJob::get();
        $this->assertSame(1, $job['poll_failures']);
        $this->assertGreaterThan(time() + 60, $job['next_poll_at']);
        $this->assertSame('poll-wait', $this->tick()->run());

        $this->due();
        $this->client->queue('getBulk', new ApiException('forbidden', 403));
        $this->assertSame('poll-auth', $this->tick()->run());
        $this->assertTrue(QueueState::isPaused());
        $this->assertNotNull(BulkJob::get());

        QueueState::resume();
        $this->due();
        $this->client->queue('getBulk', new ApiException('gone', 404));
        $this->assertSame('job-missing', $this->tick()->run());
        $this->assertNull(BulkJob::get());
        $this->assertRow($a, ['queue_status' => 'pending']);
    }

    public function test_auth_submit_error_rolls_back_and_pauses(): void
    {
        $a = $this->post();
        $this->repo->upsertPending([$a], false);
        $this->client->queue('submitBulk', new ApiException('bad key', 401));
        $this->assertSame('submit-error', $this->tick()->run());
        $this->assertTrue(QueueState::isPaused());
        $this->assertRow($a, ['attempts' => 0, 'queue_status' => 'pending']);
        $this->assertNull(BulkJob::pending());
        $this->assertSame('paused', $this->tick()->run());
    }

    public function test_oversized_submit_adapts_the_cap_then_fails_a_single_item(): void
    {
        [$a, $b] = [$this->post(), $this->post()];
        $this->repo->upsertPending([$a, $b], false);
        $this->client->queue('submitBulk', new ApiException('too large', 413));
        $this->assertSame('submit-error', $this->tick()->run());
        $this->assertSame(0, $this->row($a)['attempts']);
        $this->assertSame(1, BatchBuilder::cap(), 'floor(2 units / 2)');

        $this->client = new FakeClient();
        $this->client->queue('submitBulk', new ApiException('too large', 413));
        $this->assertSame('submit-error', $this->tick()->run());
        $this->assertCount(1, $this->client->callsTo('submitBulk')[0][0]['items']);
        $this->assertRow($a, ['queue_status' => 'failed']);
        $this->assertStringContainsString('413', (string) $this->row($a)['result_error']);
        $this->assertRow($b, ['queue_status' => 'pending']);
        $this->assertSame(1, BatchBuilder::cap(), 'single-item 413 leaves the cap alone');
    }

    public function test_retryable_submit_errors_consume_attempts_and_back_off(): void
    {
        $a = $this->post();
        $this->repo->upsertPending([$a], false);
        $this->client->queue('submitBulk', new ApiException('slow down', 429));
        $this->assertSame('submit-error', $this->tick()->run());
        $this->assertRow($a, ['queue_status' => 'pending', 'attempts' => 1]);
        $this->assertNotNull($this->row($a)['next_attempt_at']);
        $this->assertSame('idle', $this->tick()->run(), 'not due');

        $this->makeDue($a);
        $this->client = new FakeClient();
        $this->client->queue('submitBulk', new ApiException('timeout', 0));
        $this->assertSame('submit-error', $this->tick()->run());
        $this->assertSame(2, $this->row($a)['attempts']);
    }

    public function test_invalid_submit_fails_every_staged_row(): void
    {
        [$a, $b] = [$this->post(), $this->post()];
        $this->repo->upsertPending([$a, $b], false);
        $this->client->queue('submitBulk', new ApiException('invalid', 422));
        $this->assertSame('submit-error', $this->tick()->run());
        $this->assertRow($a, ['queue_status' => 'failed']);
        $this->assertRow($b, ['queue_status' => 'failed', 'result_error' => 'invalid']);
    }

    public function test_three_retryable_failures_exhaust_attempts(): void
    {
        $a = $this->post();
        $this->repo->upsertPending([$a], false);
        for ($i = 0; $i < 3; $i++) {
            $this->makeDue($a);
            $this->client = new FakeClient();
            $this->client->queue('submitBulk', new ApiException('down', 503));
            $this->assertSame('submit-error', $this->tick()->run());
        }
        $this->assertRow($a, ['queue_status' => 'failed', 'attempts' => 3]);
        $this->makeDue($a);
        $this->client = new FakeClient();
        $this->assertSame('idle', $this->tick()->run(), 'no fourth POST');
    }

    public function test_nothing_accepted_releases_rows(): void
    {
        $a = $this->post();
        $this->repo->upsertPending([$a], false);
        $this->client->queue('submitBulk', ['bulk_id' => 'b1', 'status' => 'failed', 'total_items' => 1, 'accepted_items' => [], 'failed_items' => []]);
        $this->assertSame('nothing-accepted', $this->tick()->run());
        $this->assertNull(BulkJob::get());
        $this->assertRow($a, ['queue_status' => 'pending']);
    }

    public function test_batch_limits(): void
    {
        Settings::update(['batch_size' => 5]);
        $big = $this->post(str_repeat('word ', 95000));
        $huge = $this->post(str_repeat('word ', 100100));
        $small = $this->post();
        $this->repo->upsertPending([$big, $huge, $small], false);
        $this->client->queue('submitBulk', static fn (array $payload): array => FakeClient::accepted('b1', array_map(static fn (array $i): int => (int) substr($i['id'], 5), $payload['items'])));
        $this->assertSame('submitted', $this->tick()->run());
        $items = $this->client->callsTo('submitBulk')[0][0]['items'];
        $this->assertCount(1, $items, 'a 950-unit first item is sent alone');
        $this->assertSame('post-' . $big, $items[0]['id']);
        $this->assertRow($huge, ['queue_status' => 'failed']);
        $this->assertStringContainsString('Too long', (string) $this->row($huge)['result_error']);
        $this->assertRow($small, ['queue_status' => 'pending']);
    }

    public function test_batch_byte_limit_releases_the_remaining_claims(): void
    {
        Settings::update(['batch_size' => 3, 'min_words' => 1]);
        $largeText = str_repeat('x', 2_100_000);
        [$a, $b, $c] = [$this->post($largeText), $this->post($largeText), $this->post('small')];
        $this->startJob([$a, $b, $c], [$a]);
        $payload = $this->client->callsTo('submitBulk')[0][0];
        $this->assertSame(['post-' . $a], array_column($payload['items'], 'id'));
        $this->assertRow($a, ['queue_status' => 'submitted']);
        $this->assertRow($b, ['queue_status' => 'pending']);
        $this->assertRow($c, ['queue_status' => 'pending']);
    }

    public function test_batch_cap_migrates_the_legacy_per_model_shape(): void
    {
        update_option(BatchBuilder::CAP_OPTION, ['pangram-3' => 800, Settings::MODEL => 123], false);
        $this->assertSame(123, BatchBuilder::cap());

        BatchBuilder::setCap(456);
        $this->assertSame(456, get_option(BatchBuilder::CAP_OPTION));
    }

    public function test_missing_or_foreign_key_pauses(): void
    {
        $a = $this->post();
        $this->startJob([$a]);

        Settings::update(['api_key' => 'another-key-9999']);
        $this->assertSame('foreign-job', $this->tick()->run());
        $this->assertTrue(QueueState::isPaused());
        $this->assertStringContainsString('different API key', QueueState::get()['reason']);

        QueueState::resume();
        Settings::update(['api_key' => '']);
        $this->assertSame('no-key', $this->tick()->run());
        $this->assertTrue(QueueState::isPaused());
        $this->assertNotNull(BulkJob::get(), 'job kept for abandon action');
    }

    public function test_lock_prevents_overlap(): void
    {
        $lock = new Lock();
        $token = $lock->acquire();
        $this->assertSame('locked', $this->tick()->run());
        $lock->release($token);
    }

    public function test_orphan_results_are_logged_not_written(): void
    {
        $a = $this->post();
        $this->startJob([$a]);
        $this->finishJob([FakeClient::okItem($a), FakeClient::okItem(999999), ['id' => 'weird', 'result' => []]]);
        $this->assertRow($a, ['queue_status' => 'done']);
        $messages = array_column(ErrorLog::all(), 'message');
        $this->assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'no longer has a queue row')));
        $this->assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'unexpected item id')));
    }

    /**
     * @param list<int>                  $ids
     * @param list<array<string, mixed>> $items
     */
    private function completeJob(array $ids, array $items): void
    {
        $this->startJob($ids);
        $this->finishJob($items);
    }

    /**
     * @param list<int> $queued
     * @param list<int>|null $accepted
     * @param list<int> $failed
     */
    private function startJob(array $queued, ?array $accepted = null, array $failed = []): void
    {
        $this->repo->upsertPending($queued, false);
        $this->client->queue('submitBulk', FakeClient::accepted('b1', $accepted ?? $queued, $failed));
        $this->assertSame('submitted', $this->tick()->run());
    }

    /** @param list<array<string, mixed>> $items */
    private function finishJob(array $items): void
    {
        $this->due();
        $this->client->queue('getBulk', FakeClient::status('succeeded'));
        $this->client->queue('getBulkResults', FakeClient::page($items));
        $this->assertSame('job-closed', $this->tick()->run());
    }

    private function due(): void
    {
        BulkJob::update(['next_poll_at' => 0]);
    }

    private function makeDue(int $postId): void
    {
        global $wpdb;
        $wpdb->update(ItemsRepository::tableName(), ['next_attempt_at' => null], ['post_id' => $postId]);
    }
}
