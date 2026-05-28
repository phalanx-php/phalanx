<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Showcase;

use Phalanx\Theatron\Demos\Showcase\Slice\AgentMetricsSlice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentMetricsSliceTest extends TestCase
{
    #[Test]
    public function defaults_to_zero(): void
    {
        $metrics = new AgentMetricsSlice();

        self::assertSame(0, $metrics->totalTokens);
        self::assertSame(0, $metrics->activeWorkers);
        self::assertSame(0, $metrics->completedAgents);
        self::assertSame(0.0, $metrics->tokensPerSecond);
    }

    #[Test]
    public function slice_key_is_showcase_metrics(): void
    {
        self::assertSame('showcase.metrics', (new AgentMetricsSlice())->key);
    }
}
