<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Integration;

use ZWPangram\Queue\Enqueuer;
use ZWPangram\Queue\ScanFilters;
use ZWPangram\Support\Settings;
use ZWPangram\Tests\Support\PluginTestCase;

final class EnqueuerTest extends PluginTestCase
{
    public function test_filters_by_type_status_date_author_and_category(): void
    {
        $author = (int) self::factory()->user->create(['role' => 'author']);
        $otherAuthor = (int) self::factory()->user->create(['role' => 'author']);
        $cat = (int) self::factory()->category->create();
        $otherCat = (int) self::factory()->category->create();
        Settings::update(['post_types' => ['post', 'page'], 'post_statuses' => ['publish', 'draft']]);

        $base = ['post_author' => $author, 'post_date' => '2026-03-10 10:00:00', 'post_category' => [$cat]];
        $match = $this->post('words words words words', $base);
        $this->post('wrong date words words', array_replace($base, ['post_date' => '2025-03-10 10:00:00']));
        $this->post('wrong status words words', array_replace($base, ['post_status' => 'draft']));
        $wrongType = $this->post('wrong type words words', array_replace($base, ['post_type' => 'page']));
        wp_set_object_terms($wrongType, [$cat], 'category');
        $this->post('wrong author words words', array_replace($base, ['post_author' => $otherAuthor]));
        $this->post('wrong category words words', array_replace($base, ['post_category' => [$otherCat]]));

        $totals = (new Enqueuer($this->repo))->enqueue(ScanFilters::fromRequest([
            'post_types' => ['post'], 'post_statuses' => ['publish'], 'date_from' => '2026-03-10',
            'date_to' => '2026-03-10', 'authors' => [$author], 'categories' => [$cat],
        ]));
        $this->assertSame(['matched' => 1, 'enqueued' => 1, 'unchanged' => 0, 'already_queued' => 0], $totals);
        $this->assertSame('pending', $this->row($match)['queue_status']);
    }

    public function test_password_protected_posts_are_excluded_unless_enabled(): void
    {
        $p = $this->post('words words words words', ['post_password' => 'pw']);
        $enqueuer = new Enqueuer($this->repo);
        $this->assertSame(0, $enqueuer->enqueue(ScanFilters::fromRequest([]))['matched']);
        Settings::update(['include_password_protected' => true]);
        $this->assertSame(1, $enqueuer->enqueue(ScanFilters::fromRequest([]))['matched']);
        $this->assertSame('pending', $this->row($p)['queue_status']);
    }

    public function test_unchanged_and_already_queued_are_counted_and_skipped(): void
    {
        $scanned = $this->post();
        $queued = $this->post();
        $fresh = $this->post();

        $this->storeOk($scanned, [], '2030-01-01 00:00:00');
        $this->repo->upsertPending([$queued], false);

        $totals = (new Enqueuer($this->repo))->enqueue(ScanFilters::fromRequest([]));
        $this->assertSame(3, $totals['matched']);
        $this->assertSame(1, $totals['unchanged']);
        $this->assertSame(1, $totals['already_queued']);
        $this->assertSame(1, $totals['enqueued']);
        $this->assertSame('done', $this->row($scanned)['queue_status']);
        $this->assertSame('pending', $this->row($fresh)['queue_status']);

        $totals = (new Enqueuer($this->repo))->enqueue(ScanFilters::fromRequest(['force' => '1']));
        $this->assertSame(0, $totals['unchanged']);
        $this->assertSame('pending', $this->row($scanned)['queue_status']);
        $this->assertTrue($this->row($scanned)['force']);
    }

    public function test_force_on_in_flight_row_only_sets_rescan_flag(): void
    {
        $p = $this->post();
        $this->repo->upsertPending([$p], false);
        $this->repo->claim(1, 't');
        $this->repo->stageSubmission('t', [$p => ['hash' => 'h', 'modified_gmt' => '2026-01-01 00:00:00']]);
        $this->repo->markSubmitted('t', [$p], 'b1');

        (new Enqueuer($this->repo))->enqueue(ScanFilters::fromRequest([]));
        $this->assertFalse($this->row($p)['rescan_requested']);
        $totals = (new Enqueuer($this->repo))->enqueue(ScanFilters::fromRequest(['force' => '1']));
        $this->assertSame(1, $totals['already_queued']);
        $row = $this->row($p);
        $this->assertSame('submitted', $row['queue_status']);
        $this->assertTrue($row['rescan_requested']);
    }

    public function test_pagination_beyond_page_size(): void
    {
        $ids = [];
        for ($i = 0; $i < Enqueuer::PAGE_SIZE + 5; $i++) {
            $ids[] = $this->post('a b c d');
        }
        $totals = (new Enqueuer($this->repo))->enqueue(ScanFilters::fromRequest([]));
        $this->assertSame(Enqueuer::PAGE_SIZE + 5, $totals['enqueued']);
        $this->assertSame(Enqueuer::PAGE_SIZE + 5, $this->repo->counts()['pending']);
    }
}
