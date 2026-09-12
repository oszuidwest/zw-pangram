<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Integration;

use ZWPangram\Admin\ResultsFilters;
use ZWPangram\Admin\ResultsListTable;

final class ResultsListTableTest extends \WP_UnitTestCase
{
    /** @var array<string, mixed> */
    private array $originalGet;

    /** @var array<string, mixed> */
    private array $originalServer;

    public function set_up(): void
    {
        parent::set_up();
        $this->originalGet = $_GET;
        $this->originalServer = $_SERVER;
    }

    public function tear_down(): void
    {
        $_GET = $this->originalGet;
        $_SERVER = $this->originalServer;
        parent::tear_down();
    }

    public function test_supplemental_result_details_use_accessible_toggletips(): void
    {
        $table = new ResultsListTable(ResultsFilters::fromRequest([]));
        $titleMethod = new \ReflectionMethod($table, 'column_title');
        $statusMethod = new \ReflectionMethod($table, 'column_default');
        $item = [
            'ID' => 42,
            'post_title' => 'Example',
            'result_stale' => true,
            'result_status' => 'failed',
            'result_error' => 'Request failed',
            'queue_status' => 'done',
        ];

        $title = (string) $titleMethod->invoke($table, $item);
        $status = (string) $statusMethod->invoke($table, $item, 'status');

        $this->assertStringContainsString('zw-pangram-stale-42', $title);
        $this->assertStringContainsString('Content changed after this scan', $title);
        $this->assertStringContainsString('Why is this result stale?', $title);
        $this->assertStringNotContainsString(' title=', $title);
        $this->assertStringContainsString('zw-pangram-error-42', $status);
        $this->assertStringContainsString('Request failed', $status);
        $this->assertStringContainsString('View error details', $status);
        $this->assertStringNotContainsString(' title=', $status);
    }

    public function test_initial_view_marks_publication_date_as_sorted_newest_first(): void
    {
        $_GET = [];
        $_SERVER['HTTP_HOST'] = 'example.org';
        $_SERVER['REQUEST_URI'] = '/wp-admin/tools.php?page=zw-pangram';

        $table = new ResultsListTable(ResultsFilters::fromRequest([]));

        ob_start();
        $table->print_column_headers();
        $headers = (string) ob_get_clean();

        $this->assertMatchesRegularExpression('/<th[^>]+id=\'date\'[^>]+class=\'[^\']*sorted desc[^\']*\'[^>]+aria-sort="descending"/', $headers);
        $this->assertMatchesRegularExpression('/<th[^>]+id=\'fraction_ai\'[^>]+class=\'[^\']*sortable asc[^\']*\'/', $headers);
    }
}
