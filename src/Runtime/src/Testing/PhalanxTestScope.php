<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Closure;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Runtime\Memory\RuntimeMemory;
use Phalanx\Scope\ExecutionScope;

class PhalanxTestScope
{
    public RuntimeMemory $memory {
        get => $this->runtime->memory;
    }

    public PhalanxTestExpectations $expect {
        get => $this->expectations ??= new PhalanxTestExpectations($this->runtime->memory);
    }

    private ?PhalanxTestExpectations $expectations = null;

    public function __construct(
        private readonly PhalanxTestRuntime $runtime,
    ) {
    }

    /**
     * @template T
     * @param Closure(ExecutionScope): T $test
     * @return T
     */
    public function run(
        Closure $test,
        string $name = 'phalanx.test',
        ?CancellationToken $token = null,
    ): mixed {
        return $this->runtime->run($test, $name, $token);
    }
}
