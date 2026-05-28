<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Showcase\Slice;

use Phalanx\Theatron\Store\Slice;

final class AgentMetricsSlice implements Slice
{
    public string $key {
        get => 'showcase.metrics';
    }

    public function __construct(
        private(set) int $totalTokens = 0,
        private(set) int $activeWorkers = 0,
        private(set) int $completedAgents = 0,
        private(set) float $tokensPerSecond = 0.0,
    ) {
    }
}
