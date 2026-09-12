<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Unit;

use ZWPangram\Support\Dates;

final class DatesTest extends \WP_UnitTestCase
{
    public function test_parse_uses_checkdate(): void
    {
        $this->assertSame('2026-02-28', Dates::parse('2026-02-28'));
        $this->assertNull(Dates::parse('2026-02-30'));
        $this->assertNull(Dates::parse('2026-13-01'));
        $this->assertNull(Dates::parse('26-01-01'));
        $this->assertNull(Dates::parse(20260101));
        $this->assertNull(Dates::parse(null));
    }

    public function test_ordered_range_validates_and_reorders_bounds(): void
    {
        $this->assertSame(['2026-01-01', '2026-12-31'], Dates::orderedRange('2026-01-01', '2026-12-31'));
        $this->assertSame(['2026-01-01', '2026-12-31'], Dates::orderedRange('2026-12-31', '2026-01-01'));
        $this->assertSame([null, '2026-12-31'], Dates::orderedRange('invalid', '2026-12-31'));
    }
}
