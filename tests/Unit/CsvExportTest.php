<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Unit;

use ZWPangram\Admin\CsvExport;

final class CsvExportTest extends \WP_UnitTestCase
{
    public function test_formula_prefixes_are_neutralised_even_after_whitespace(): void
    {
        $this->assertSame("'=1+1", CsvExport::sanitizeCell('=1+1'));
        $this->assertSame("' \t=cmd", CsvExport::sanitizeCell(" \t=cmd"));
        $this->assertSame("'\r\n@x", CsvExport::sanitizeCell("\r\n@x"));
        $this->assertSame("'-5", CsvExport::sanitizeCell('-5'));
        $this->assertSame("'+5", CsvExport::sanitizeCell('+5'));
        $this->assertSame('plain', CsvExport::sanitizeCell('plain'));
        $this->assertSame('', CsvExport::sanitizeCell(''));
    }
}
