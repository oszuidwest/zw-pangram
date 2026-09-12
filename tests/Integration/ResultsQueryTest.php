<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Integration;

use ZWPangram\Admin\AuthorStats;
use ZWPangram\Admin\ResultsFilters;
use ZWPangram\Admin\ResultsQuery;
use ZWPangram\Tests\Support\PluginTestCase;

final class ResultsQueryTest extends PluginTestCase
{
    private int $author1;
    private int $author2;
    /** @var array<string, int> */
    private array $posts = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->author1 = (int) self::factory()->user->create(['role' => 'author', 'display_name' => 'Alice']);
        $this->author2 = (int) self::factory()->user->create(['role' => 'author', 'display_name' => 'Bob']);
        $this->posts['ai'] = $this->post('a b c d e', ['post_author' => $this->author1, 'post_title' => 'Robot article', 'post_date' => '2026-01-10 10:00:00']);
        $this->posts['human'] = $this->post('a b c d e', ['post_author' => $this->author1, 'post_title' => 'Human article', 'post_date' => '2026-02-10 10:00:00']);
        $this->posts['mixed'] = $this->post('a b c d e', ['post_author' => $this->author2, 'post_title' => 'Mixed article', 'post_date' => '2026-03-10 10:00:00']);
        $this->posts['failed'] = $this->post('a b c d e', ['post_author' => $this->author2, 'post_title' => 'Broken article', 'post_date' => '2026-04-10 10:00:00']);
        $this->posts['unscanned'] = $this->post('a b c d e', ['post_author' => $this->author2]);

        $this->ok($this->posts['ai'], 'AI', 0.9, 0.05, 0.05);
        $this->ok($this->posts['human'], 'Human', 0.0, 0.1, 0.9);
        $this->ok($this->posts['mixed'], 'Mixed', 0.4, 0.4, 0.2, true);
        $this->repo->upsertPending([$this->posts['failed']], false);
        $this->repo->writeFailed($this->row($this->posts['failed']), 'boom');
    }

    public function test_default_order_is_publication_date_newest_first(): void
    {
        $q = new ResultsQuery();
        $filters = ResultsFilters::fromRequest([]);
        $this->assertSame('date', $filters->orderby);
        $this->assertSame('DESC', $filters->order);
        $this->assertSame(4, $q->count($filters));
        $ids = array_column($q->rows($filters, 10, 1), 'ID');
        $this->assertSame([$this->posts['failed'], $this->posts['mixed'], $this->posts['human'], $this->posts['ai']], $ids);
        $asc = array_column($q->rows(ResultsFilters::fromRequest(['order' => 'asc']), 10, 1), 'ID');
        $this->assertSame([$this->posts['ai'], $this->posts['human'], $this->posts['mixed'], $this->posts['failed']], $asc);
    }

    public function test_fraction_order_puts_failed_rows_last(): void
    {
        $q = new ResultsQuery();
        $ids = array_column($q->rows(ResultsFilters::fromRequest(['orderby' => 'fraction_ai']), 10, 1), 'ID');
        $this->assertSame([$this->posts['ai'], $this->posts['mixed'], $this->posts['human'], $this->posts['failed']], $ids);
        $asc = array_column($q->rows(ResultsFilters::fromRequest(['orderby' => 'fraction_ai', 'order' => 'asc']), 10, 1), 'ID');
        $this->assertSame($this->posts['failed'], end($asc), 'NULL fraction still last when ascending');
    }

    public function test_filters(): void
    {
        $q = new ResultsQuery();
        $this->assertSame([$this->posts['ai']], array_column($q->rows(ResultsFilters::fromRequest(['label' => 'AI']), 10, 1), 'ID'));
        $this->assertSame([$this->posts['failed']], array_column($q->rows(ResultsFilters::fromRequest(['status' => 'failed']), 10, 1), 'ID'));
        $this->assertSame(2, $q->count(ResultsFilters::fromRequest(['author' => $this->author1])));
        $this->assertSame([$this->posts['mixed']], array_column($q->rows(ResultsFilters::fromRequest(['stale' => '1']), 10, 1), 'ID'));
        $this->assertSame(2, $q->count(ResultsFilters::fromRequest(['from' => '2026-02-01', 'to' => '2026-03-31'])));
        $this->assertSame([$this->posts['human']], array_column($q->rows(ResultsFilters::fromRequest(['s' => 'human']), 10, 1), 'ID'));
        $this->assertSame([$this->posts['failed'], $this->posts['mixed']], array_column($q->rows(ResultsFilters::fromRequest(['orderby' => 'date']), 2, 1), 'ID'));
        $this->assertSame(1, count($q->rows(ResultsFilters::fromRequest([]), 1, 4)));
    }

    public function test_author_stats(): void
    {
        $rows = (new AuthorStats())->compute(ResultsFilters::fromRequest([]));
        $this->assertCount(2, $rows);
        $alice = $rows[0];
        $this->assertSame(['Alice', 2, 1, 1, 0], [$alice['name'], $alice['scanned'], $alice['n_ai'], $alice['n_human'], $alice['n_mixed']]);
        $this->assertEqualsWithDelta(0.45, $alice['avg_ai'], 0.0001);
        $bob = $rows[1];
        $this->assertSame([1, 1], [$bob['scanned'], $bob['n_mixed']], 'failed rows do not count');

        $ranged = (new AuthorStats())->compute(ResultsFilters::fromRequest(['from' => '2026-03-01']));
        $this->assertCount(1, $ranged);
        $this->assertSame('Bob', $ranged[0]['name']);

        $this->repo->writeFailed($this->row($this->posts['human']), 'later failure');
        $rows = (new AuthorStats())->compute(ResultsFilters::fromRequest([]));
        $this->assertSame(2, $rows[0]['scanned'] + $rows[1]['scanned'], 'cache invalidated by the version bump');
    }

    private function ok(int $p, string $label, float $ai, float $assisted, float $human, bool $stale = false): void
    {
        $this->storeOk($p, ['prediction_short' => $label, 'fraction_ai' => $ai, 'fraction_ai_assisted' => $assisted, 'fraction_human' => $human, 'headline' => $label, 'result_stale' => $stale]);
    }
}
