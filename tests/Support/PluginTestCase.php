<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Support;

use ZWPangram\Activation;
use ZWPangram\Api\ClientInterface;
use ZWPangram\Cron\BatchBuilder;
use ZWPangram\Cron\BulkJob;
use ZWPangram\Cron\Scheduler;
use ZWPangram\Cron\Tick;
use ZWPangram\Queue\QueueState;
use ZWPangram\Store\ItemsRepository;
use ZWPangram\Support\ErrorLog;
use ZWPangram\Support\Lock;
use ZWPangram\Support\Settings;

abstract class PluginTestCase extends \WP_UnitTestCase
{
    protected ItemsRepository $repo;
    protected FakeClient $client;

    public function set_up(): void
    {
        parent::set_up();
        // SHOW TABLES must see the plugin table; the test transaction isolates its rows.
        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);
        Activation::activate(false);
        global $wpdb;
        $wpdb->query('DELETE FROM ' . ItemsRepository::tableName());
        foreach ([Settings::OPTION, QueueState::OPTION, BulkJob::OPTION, BulkJob::PENDING_OPTION, ErrorLog::OPTION, Lock::OPTION, BatchBuilder::CAP_OPTION, Scheduler::LAST_TICK_OPTION] as $option) {
            delete_option($option);
        }
        Settings::update(['api_key' => 'test-key-1234567890', 'min_words' => 3, 'batch_size' => 20]);
        $this->repo = new ItemsRepository();
        $this->client = new FakeClient();
    }

    protected function tick(): Tick
    {
        return new Tick($this->repo, new Lock(), fn (string $key): ClientInterface => $this->client);
    }

    /** @param array<string, mixed> $args Extra post arguments. */
    protected function post(string $content = 'One two three four five six seven eight nine ten.', array $args = []): int
    {
        return (int) self::factory()->post->create(array_replace(['post_content' => $content, 'post_status' => 'publish', 'post_type' => 'post'], $args));
    }

    /** @return array<string, mixed> */
    protected function stageRow(int $postId, string $token = 't', string $modifiedGmt = '2026-01-01 00:00:00'): array
    {
        $this->repo->upsertPending([$postId], false);
        $this->repo->claim(1, $token);
        $staged = $this->repo->stageSubmission($token, [$postId => ['hash' => 'h', 'modified_gmt' => $modifiedGmt]]);
        $this->assertArrayHasKey($postId, $staged);
        return $staged[$postId];
    }

    /** @return array<string, mixed> */
    protected function submitRow(int $postId, string $token = 't', string $bulkId = 'b1', string $modifiedGmt = '2026-01-01 00:00:00'): array
    {
        $this->stageRow($postId, $token, $modifiedGmt);
        $this->repo->markSubmitted($token, [$postId], $bulkId);
        return $this->row($postId);
    }

    /** @param array<string, mixed> $overrides */
    protected function storeOk(int $postId, array $overrides = [], string $modifiedGmt = '2026-01-01 00:00:00'): void
    {
        $bulkId = 'b1';
        $this->repo->writeOk($this->submitRow($postId, 't' . $postId, $bulkId, $modifiedGmt), array_replace([
            'prediction_short' => 'AI', 'fraction_ai' => 0.8, 'fraction_ai_assisted' => 0.1, 'fraction_human' => 0.1,
            'headline' => 'AI Detected', 'model' => 'pangram-4', 'api_version' => '4.0', 'response_json' => '{}',
            'last_bulk_id' => $bulkId, 'result_stale' => false,
        ], $overrides));
    }

    /** @return array<string, mixed> */
    protected function row(int $postId): array
    {
        $row = $this->repo->find($postId);
        $this->assertNotNull($row, "Row for post {$postId} missing.");
        return $row;
    }

    /** @param array<string, mixed> $expected */
    protected function assertRow(int $postId, array $expected): void
    {
        $row = $this->row($postId);
        $actual = [];
        foreach ($expected as $key => $_) {
            $actual[$key] = $row[$key];
        }
        $this->assertSame($expected, $actual);
    }
}
