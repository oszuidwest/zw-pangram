<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Unit;

use ZWPangram\Support\UnitEstimator;

final class UnitEstimatorTest extends \WP_UnitTestCase
{
    public function test_units_are_ceiling_with_minimum_one(): void
    {
        $this->assertSame(1, UnitEstimator::units(0));
        $this->assertSame(1, UnitEstimator::units(100));
        $this->assertSame(2, UnitEstimator::units(101));
        $this->assertSame(10, UnitEstimator::units(950));
        $this->assertSame(1001, UnitEstimator::units(100001));
    }
}
