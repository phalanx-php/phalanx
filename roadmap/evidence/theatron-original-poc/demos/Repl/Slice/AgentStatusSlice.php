<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Slice;

use Phalanx\Theatron\Store\Slice;

class AgentStatusSlice implements Slice
{
    public string $key {
        get => 'repl.status';
    }

    public function __construct(
        private(set) string $agentName = 'Pericles',
        private(set) string $role = 'strategist',
        private(set) string $status = 'idle',
        private(set) string $modelName = 'none',
        private(set) int $spinnerFrame = 0,
    ) {
    }

    public function withModelName(string $modelName): self
    {
        $clone = clone $this;
        $clone->modelName = $modelName;

        return $clone;
    }

    public function withStatus(string $status): self
    {
        $clone = clone $this;
        $clone->status = $status;
        $clone->spinnerFrame = 0;

        return $clone;
    }

    public function tick(): self
    {
        $clone = clone $this;
        $clone->spinnerFrame = $this->spinnerFrame + 1;

        return $clone;
    }
}
