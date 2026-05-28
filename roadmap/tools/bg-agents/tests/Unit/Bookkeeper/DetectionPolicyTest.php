<?php

declare(strict_types=1);

namespace BgAgents\Tests\Unit\Bookkeeper;

use BgAgents\Bookkeeper\DetectionPolicy;
use PHPUnit\Framework\TestCase;

final class DetectionPolicyTest extends TestCase
{
    public function test_standard_uses_production_intervals(): void
    {
        $p = DetectionPolicy::standard();

        self::assertSame(60, $p->duplicateWindowSec);
        self::assertSame(300, $p->consolidationIntervalSec);
        self::assertSame(20, $p->consolidationNoiseThreshold);
        self::assertSame(1800, $p->promotionIntervalSec);
    }

    public function test_fast_shrinks_intervals(): void
    {
        $p = DetectionPolicy::fast();

        self::assertLessThan(DetectionPolicy::standard()->consolidationIntervalSec, $p->consolidationIntervalSec);
        self::assertLessThan(DetectionPolicy::standard()->consolidationNoiseThreshold, $p->consolidationNoiseThreshold);
    }

    public function test_from_context_picks_fast_when_flag_set(): void
    {
        $p = DetectionPolicy::fromContext(['BG_AGENTS_BOOKKEEPER_FAST' => '1']);

        self::assertSame(DetectionPolicy::fast()->consolidationIntervalSec, $p->consolidationIntervalSec);
    }

    public function test_from_context_defaults_to_standard(): void
    {
        $p = DetectionPolicy::fromContext([]);

        self::assertSame(DetectionPolicy::standard()->consolidationIntervalSec, $p->consolidationIntervalSec);
    }
}
