<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Integration;

use ZWPangram\Store\ItemsRepository;
use ZWPangram\Tests\Support\PluginTestCase;

final class ItemsRepositoryTest extends PluginTestCase
{
    public function test_upsert_creates_pending_rows_and_resets_terminal_rows(): void
    {
        $p = $this->post();
        $this->repo->upsertPending([$p], false);
        $row = $this->row($p);
        $this->assertRow($p, ['queue_status' => 'pending', 'force' => false]);

        $this->repo->markSkippedInQueue($row, 'unchanged');
        $this->assertRow($p, ['queue_status' => 'skipped']);

        $this->repo->upsertPending([$p], true);
        $this->assertRow($p, ['queue_status' => 'pending', 'force' => true, 'attempts' => 0, 'last_error' => null]);
    }

    public function test_claims_are_exclusive_and_ordered(): void
    {
        $ids = [$this->post(), $this->post(), $this->post()];
        $this->repo->upsertPending($ids, false);
        $a = $this->repo->claim(2, 'tokenA');
        $b = $this->repo->claim(2, 'tokenB');
        $this->assertSame([$ids[0], $ids[1]], array_column($a, 'post_id'));
        $this->assertSame([$ids[2]], array_column($b, 'post_id'));
        $this->assertRow($ids[0], ['queue_status' => 'processing']);
        $this->assertSame([], $this->repo->claim(2, 'tokenC'));
    }

    public function test_reenqueued_terminal_row_joins_the_back_of_the_queue(): void
    {
        global $wpdb;
        $old = $this->post();
        $new = $this->post();

        $this->repo->upsertPending([$old], false);
        $this->repo->markSkippedInQueue($this->row($old), 'done');
        $this->repo->upsertPending([$new], false);
        $wpdb->update(ItemsRepository::tableName(), ['queued_at' => '2026-01-01 00:00:00'], ['post_id' => $new]);
        $this->repo->upsertPending([$old], true);

        $this->assertSame([$new], array_column($this->repo->claim(1, 'fifo'), 'post_id'));
    }

    public function test_reenqueued_pending_row_keeps_its_queue_position(): void
    {
        global $wpdb;
        $a = $this->post();
        $b = $this->post();

        $this->repo->upsertPending([$a, $b], false);
        $wpdb->update(ItemsRepository::tableName(), ['queued_at' => '2026-01-01 00:00:00'], ['post_id' => $b]);
        $this->repo->upsertPending([$b], true);

        $this->assertSame([$b], array_column($this->repo->claim(1, 'fifo'), 'post_id'));
    }

    public function test_upsert_does_not_touch_in_flight_rows_except_rescan_flag_on_force(): void
    {
        $p = $this->post();
        $this->submitRow($p, 'tok', 'bulk-1');

        $this->repo->upsertPending([$p], false);
        $this->assertRow($p, ['queue_status' => 'submitted', 'bulk_id' => 'bulk-1', 'submitted_hash' => 'h', 'attempts' => 1, 'rescan_requested' => false]);

        $this->repo->upsertPending([$p], true);
        $this->assertRow($p, ['queue_status' => 'submitted', 'rescan_requested' => true, 'bulk_id' => 'bulk-1']);
    }

    public function test_stage_submission_consumes_attempt_and_fails_exhausted_rows(): void
    {
        $p = $this->post();
        $this->repo->upsertPending([$p], false);
        for ($i = 1; $i <= 3; $i++) {
            $rows = $this->repo->claim(1, "t{$i}");
            $this->assertCount(1, $rows, "claim {$i}");
            $staged = $this->repo->stageSubmission("t{$i}", [$p => ['hash' => 'h', 'modified_gmt' => '2026-01-01 00:00:00']]);
            $this->assertArrayHasKey($p, $staged);
            $this->assertSame($i, $staged[$p]['attempts']);
            $this->repo->release("t{$i}");
        }
        $this->repo->claim(1, 't4');
        $staged = $this->repo->stageSubmission('t4', [$p => ['hash' => 'h', 'modified_gmt' => '2026-01-01 00:00:00']]);
        $this->assertSame([], $staged);
        $row = $this->row($p);
        $this->assertRow($p, ['queue_status' => 'failed', 'result_status' => 'failed']);
        $this->assertStringContainsString('Too many', (string) $row['result_error']);
    }

    public function test_unstage_rolls_back_attempt(): void
    {
        $p = $this->post();
        $this->stageRow($p);
        $this->assertSame(1, $this->row($p)['attempts']);
        $this->repo->unstage('t');
        $this->assertRow($p, ['queue_status' => 'pending', 'attempts' => 0, 'claim_token' => null]);
    }

    public function test_write_failed_clears_previous_success_and_unchanged_keeps_it(): void
    {
        $p = $this->post();
        $this->storeOk($p);
        $row = $this->row($p);
        $this->assertRow($p, ['result_status' => 'ok', 'fraction_ai' => 0.8, 'result_hash' => 'h', 'last_bulk_id' => 'b1', 'queue_status' => 'done']);

        $this->repo->markSkippedInQueue($row, 'unchanged');
        $row = $this->row($p);
        $this->assertRow($p, ['result_status' => 'ok', 'fraction_ai' => 0.8, 'last_error' => 'unchanged']);

        $this->repo->writeFailed($row, 'boom', 'b2');
        $row = $this->row($p);
        $this->assertRow($p, [
            'result_status' => 'failed', 'fraction_ai' => null, 'prediction_short' => null, 'headline' => null,
            'response_json' => null, 'result_hash' => null, 'last_bulk_id' => 'b2', 'result_error' => 'boom',
        ]);

        $this->repo->writeSkipped($row, 'too short');
        $this->assertRow($p, ['result_status' => 'skipped', 'queue_status' => 'skipped']);
    }

    public function test_clear_queue_keeps_in_flight_rows_and_results(): void
    {
        [$a, $b, $c] = [$this->post(), $this->post(), $this->post()];
        $this->repo->upsertPending([$a, $b, $c], false);
        $this->submitRow($a);
        $this->repo->claim(1, 't2');
        $this->assertSame(1, $this->repo->clearQueue());
        $this->assertRow($a, ['queue_status' => 'submitted']);
        $this->assertRow($b, ['queue_status' => 'processing']);
        $this->assertRow($c, ['queue_status' => 'none']);
    }

    public function test_release_stale_processing_respects_active_tokens(): void
    {
        [$a, $b] = [$this->post(), $this->post()];
        $this->repo->upsertPending([$a, $b], false);
        $this->repo->claim(1, 'active');
        $this->repo->claim(1, 'dead');
        global $wpdb;
        $wpdb->query("UPDATE " . ItemsRepository::tableName() . " SET claimed_at = '2020-01-01 00:00:00'");
        $this->assertSame(1, $this->repo->releaseStaleProcessing(['active']));
        $this->assertRow($a, ['queue_status' => 'processing']);
        $this->assertRow($b, ['queue_status' => 'pending']);
    }

    public function test_requeue_bulk_and_requeue_submitted_except(): void
    {
        [$a, $b] = [$this->post(), $this->post()];
        $this->repo->upsertPending([$a, $b], false);
        $this->repo->claim(2, 't');
        $this->repo->stageSubmission('t', [$a => ['hash' => 'h', 'modified_gmt' => '2026-01-01 00:00:00'], $b => ['hash' => 'h', 'modified_gmt' => '2026-01-01 00:00:00']]);
        $this->repo->markSubmitted('t', [$a], 'b1');
        $this->repo->markSubmitted('t', [$b], 'b2');
        $this->assertSame(1, $this->repo->requeueSubmittedExcept('b1'));
        $this->assertRow($b, ['queue_status' => 'pending', 'attempts' => 1]);
        $this->assertSame(1, $this->repo->requeueBulk('b1', true, 'abandoned'));
        $this->assertRow($a, ['queue_status' => 'failed']);
    }

    public function test_purge_removes_stored_text_but_keeps_result_metadata(): void
    {
        $p = $this->post();
        $this->storeOk($p, ['response_json' => wp_json_encode(['text' => 'secret', 'windows' => [['text' => 'w', 'label' => 'AI']]])]);
        $this->assertSame(1, $this->repo->purgeText(10)[0]);
        $decoded = json_decode((string) $this->row($p)['response_json'], true);
        $this->assertArrayNotHasKey('text', $decoded);
        $this->assertArrayNotHasKey('text', $decoded['windows'][0]);
        $this->assertSame('AI', $decoded['windows'][0]['label']);
        $this->assertSame([0, 0], $this->repo->purgeText(10));
    }

}
