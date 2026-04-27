<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit\Validator\Param;

use Phalanx\Stoa\Validator\Param\IntInRange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IntInRangeTest extends TestCase
{
    #[Test]
    public function returns_null_for_value_within_range(): void
    {
        $v = new IntInRange(1, 100);
        $this->assertNull($v->validate('id', '50'));
    }

    #[Test]
    public function returns_null_for_boundary_values(): void
    {
        $v = new IntInRange(1, 100);
        $this->assertNull($v->validate('id', '1'));
        $this->assertNull($v->validate('id', '100'));
    }

    #[Test]
    public function returns_error_for_value_below_min(): void
    {
        $v = new IntInRange(1, 100);
        $error = $v->validate('id', '0');
        $this->assertNotNull($error);
        $this->assertStringContainsString('id', $error);
        $this->assertStringContainsString('1', $error);
    }

    #[Test]
    public function returns_error_for_value_above_max(): void
    {
        $v = new IntInRange(1, 100);
        $error = $v->validate('id', '101');
        $this->assertNotNull($error);
        $this->assertStringContainsString('id', $error);
        $this->assertStringContainsString('100', $error);
    }

    #[Test]
    public function provides_digit_pattern(): void
    {
        $v = new IntInRange();
        $this->assertSame('\d+', $v->toPattern());
    }

    #[Test]
    public function unbounded_range_accepts_any_integer(): void
    {
        $v = new IntInRange();
        $this->assertNull($v->validate('n', '0'));
        $this->assertNull($v->validate('n', '999999'));
    }
}
