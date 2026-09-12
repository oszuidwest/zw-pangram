<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Unit;

use ZWPangram\Admin\ResultsFilters;
use ZWPangram\Queue\ScanFilters;
use ZWPangram\Support\Settings;

final class FiltersTest extends \WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        delete_option(Settings::OPTION);
    }

    public function test_results_filters_reject_unknown_values_and_normalize_the_range(): void
    {
        $filters = ResultsFilters::fromRequest([
            'label' => 'Robot', 'status' => 'deleted', 'author' => '-4', 'stale' => '0',
            'from' => '2026-12-31', 'to' => '2026-01-01', 's' => '<b>needle</b>', 'orderby' => 'post_title', 'order' => 'asc',
        ]);

        $this->assertSame(
            [null, null, 0, false, '2026-01-01', '2026-12-31', 'needle', 'date', 'ASC'],
            [$filters->label, $filters->status, $filters->author, $filters->stale, $filters->from, $filters->to, $filters->search, $filters->orderby, $filters->order]
        );
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-12-31', 's' => 'needle', 'orderby' => 'date', 'order' => 'ASC'], $filters->toArgs());
    }

    public function test_scan_filters_are_limited_to_settings_and_positive_unique_ids(): void
    {
        Settings::update(['post_types' => ['post', 'page'], 'post_statuses' => ['publish', 'draft']]);
        $filters = ScanFilters::fromRequest([
            'post_types' => ['page', 'attachment'], 'post_statuses' => ['draft', 'trash'],
            'date_from' => '2026-12-31', 'date_to' => '2026-01-01', 'authors' => ['2', 2, 0, -1, 'nope'],
            'categories' => [3, '3', null], 'force' => '1',
        ]);

        $this->assertSame(
            [['page'], ['draft'], '2026-01-01', '2026-12-31', [2], [3], true],
            [$filters->postTypes, $filters->postStatuses, $filters->dateFrom, $filters->dateTo, $filters->authors, $filters->categories, $filters->force]
        );
    }
}
