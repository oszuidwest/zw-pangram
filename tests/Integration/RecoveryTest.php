<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Integration;

use ZWPangram\Cron\BulkJob;
use ZWPangram\Cron\Recovery;
use ZWPangram\Store\ItemsRepository;
use ZWPangram\Support\ErrorLog;
use ZWPangram\Tests\Support\PluginTestCase;

final class RecoveryTest extends PluginTestCase
{
    public function test_links_processing_rows_to_recorded_job(): void
    {
        $a = $this->post();
        $this->repo->upsertPending([$a], false);
        $this->repo->claim(1, 'tok');
        $this->repo->stageSubmission('tok', [$a => ['hash' => 'h', 'modified_gmt' => '2026-01-01 00:00:00']]);
        BulkJob::markPending('tok');
        BulkJob::open('b1', 'tok', 'fp', 1);
        // Simulate a crash before markSubmitted().
        (new Recovery($this->repo))->run();
        $this->assertRow($a, ['queue_status' => 'submitted', 'bulk_id' => 'b1', 'submitted_hash' => 'h']);
        $this->assertNull(BulkJob::pending());
    }

    public function test_old_marker_without_job_releases_rows_once_and_caps_attempts(): void
    {
        $a = $this->post();
        $this->repo->upsertPending([$a], false);
        for ($i = 1; $i <= 3; $i++) {
            $this->repo->claim(1, "t{$i}");
            $this->repo->stageSubmission("t{$i}", [$a => ['hash' => 'h', 'modified_gmt' => '2026-01-01 00:00:00']]);
            BulkJob::markPending("t{$i}");
            update_option(BulkJob::PENDING_OPTION, array_replace(BulkJob::pending(), ['started_at' => time() - BulkJob::PENDING_UNKNOWN_AFTER - 1]), false);
            (new Recovery($this->repo))->run();
            $this->assertNull(BulkJob::pending());
            $this->assertRow($a, ['attempts' => $i, 'queue_status' => $i < 3 ? 'pending' : 'failed']);
        }
        $this->assertStringContainsString('outcome unknown', ErrorLog::all()[0]['message']);
    }

    public function test_young_marker_is_left_alone(): void
    {
        $a = $this->post();
        $this->repo->upsertPending([$a], false);
        $this->repo->claim(1, 't');
        BulkJob::markPending('t');
        (new Recovery($this->repo))->run();
        $this->assertNotNull(BulkJob::pending());
        $this->assertSame('processing', $this->row($a)['queue_status']);
    }

    public function test_stale_processing_and_submitted_without_job(): void
    {
        [$a, $b] = [$this->post(), $this->post()];
        $this->repo->upsertPending([$a, $b], false);
        $this->repo->claim(2, 't');
        $this->repo->stageSubmission('t', [$b => ['hash' => 'h', 'modified_gmt' => '2026-01-01 00:00:00']]);
        $this->repo->markSubmitted('t', [$b], 'lost-job');
        global $wpdb;
        $wpdb->update(ItemsRepository::tableName(), ['claimed_at' => '2020-01-01 00:00:00'], ['post_id' => $a]);
        (new Recovery($this->repo))->run();
        $this->assertRow($a, ['queue_status' => 'pending']);
        $this->assertRow($b, ['queue_status' => 'pending', 'bulk_id' => null]);
    }
}
