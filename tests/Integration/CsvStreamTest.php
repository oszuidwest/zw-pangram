<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Integration;

use ZWPangram\Admin\CsvExport;
use ZWPangram\Admin\ResultsFilters;
use ZWPangram\Tests\Support\PluginTestCase;

final class CsvStreamTest extends PluginTestCase
{
    public function test_streams_bom_header_and_sanitised_rows(): void
    {
        $p = $this->post('a b c d e', ['post_title' => '=1+1 formula', 'post_date' => '2026-02-01 10:00:00']);
        $this->storeOk($p, ['fraction_ai' => 0.75, 'fraction_ai_assisted' => 0.25, 'fraction_human' => 0]);
        $failed = $this->post('a b c d e', ['post_title' => 'Broken', 'post_date' => '2026-01-01 10:00:00']);
        $this->repo->upsertPending([$failed], false);
        $this->repo->writeFailed($this->row($failed), 'boom');

        ob_start();
        CsvExport::stream(ResultsFilters::fromRequest([]));
        $csv = (string) ob_get_clean();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3)))));
        $this->assertCount(3, $lines);
        $this->assertStringStartsWith('post_id,title,url,edit_url,author,post_date,label,headline,fraction_ai', $lines[0]);
        $row = str_getcsv($lines[1]);
        $this->assertSame([(string) $p, "'=1+1 formula", 'AI', '0.7500', '0', 'ok'], [$row[0], $row[1], $row[6], $row[8], $row[12], $row[13]]);
        $this->assertStringContainsString('?p=' . $p, $row[2]);
        $last = str_getcsv($lines[2]);
        $this->assertSame(['failed', 'boom', ''], [$last[13], $last[14], $last[8]]);
    }
}
