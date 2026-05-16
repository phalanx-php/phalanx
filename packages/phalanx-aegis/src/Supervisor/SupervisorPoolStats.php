<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

use Phalanx\Pool\PoolStats;

final class SupervisorPoolStats
{
    public function __construct(
        private(set) PoolStats $taskRun,
        private(set) PoolStats $scopeFrame,
        private(set) TokenPoolStats $token,
    ) {
    }

    /** @return array{taskRun: array<string, int>, scopeFrame: array<string, int>, token: array<string, int>} */
    public function toArray(): array
    {
        return [
            'taskRun' => $this->taskRun->toArray(),
            'scopeFrame' => $this->scopeFrame->toArray(),
            'token' => $this->token->toArray(),
        ];
    }
}
